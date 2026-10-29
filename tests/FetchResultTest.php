<?php

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Mohemian\Flink\Exceptions\InvalidAssertion;
use Mohemian\Flink\Exceptions\MissingConfiguration;
use Mohemian\Flink\Exceptions\ResultUnavailable;
use Mohemian\Flink\Facades\Flink;
use Mohemian\Flink\Tests\TestCase;

const TOKEN_URL = TestCase::URL.'/client/v1/auth/token';
const RESULT_URL = TestCase::URL.'/client/v1/tenants/'.TestCase::TENANT.'/sessions/'.TestCase::SESSION.'/results';

beforeEach(function (): void {
    config([
        'flink.results.client_key' => TestCase::key('ec1')['private'],
        'flink.results.client_key_id' => 'ec1',
    ]);
    // Every assertion FLINK has seen: each is good for one request.
    $this->jtis = [];
    $this->tokenAnswer = fn (): mixed => Http::response(['access_token' => 'client-token', 'token_type' => 'Bearer', 'expires_in' => 600]);
    $this->resultAnswer = fn (): mixed => Http::response(['signedResult' => $this->resultsJwt()]);
    Http::fake([
        TOKEN_URL => function (Request $request): mixed {
            // What flink-services' verifyClientAssertion checks (FLNK-861), against the client's public key.
            parse_str($request->body(), $form);
            if ($request->hasHeader('Authorization') || ($form['grant_type'] ?? null) !== 'client_credentials'
                || ($form['client_assertion_type'] ?? null) !== 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer') {
                return Http::response(['error' => 'invalid_request'], 400);
            }
            [$header] = explode('.', (string) $form['client_assertion']);
            $header = json_decode(JWT::urlsafeB64Decode($header), true);
            $claims = (array) JWT::decode((string) $form['client_assertion'], JWK::parseKey(TestCase::key('ec1')['jwk']));
            if ($header['kid'] !== 'ec1' || $header['alg'] !== 'ES256' || $claims['iss'] !== TestCase::RP || $claims['sub'] !== TestCase::RP
                || $claims['aud'] !== TestCase::URL || $claims['exp'] - $claims['iat'] > 300 || in_array($claims['jti'], $this->jtis, true)) {
                return Http::response(['error' => 'invalid_client'], 401);
            }
            $this->jtis[] = $claims['jti'];

            return ($this->tokenAnswer)();
        },
        RESULT_URL => fn (Request $request): mixed => $request->header('Authorization') === ['Bearer client-token']
            ? ($this->resultAnswer)()
            : Http::response(null, 403),
    ]);
});

it('fetches a session result with a token it proves with its own key, and verifies it', function (): void {
    $results = Flink::fetchResult(TestCase::SESSION);

    expect($results->sessionId)->toBe(TestCase::SESSION)
        ->and($results->get('givenName'))->toBe('Erika');
    Http::assertSent(fn (Request $request): bool => $request->url() === RESULT_URL && $request->method() === 'GET');
});

it('reuses the client token until shortly before it expires', function (): void {
    Flink::fetchResult(TestCase::SESSION);
    Flink::fetchResult(TestCase::SESSION);

    expect($this->jtis)->toHaveCount(1);
});

it('signs a new assertion for every token it asks for', function (): void {
    $this->tokenAnswer = fn (): mixed => Http::response(['access_token' => 'client-token', 'expires_in' => 20]);

    Flink::fetchResult(TestCase::SESSION);
    Flink::fetchResult(TestCase::SESSION);

    expect($this->jtis)->toHaveCount(2)->and(array_unique($this->jtis))->toHaveCount(2);
});

it('says a result is not ready yet, with the session status FLINK reports', function (): void {
    $this->resultAnswer = fn (): mixed => Http::response(['status' => 'started'], 409);

    try {
        Flink::fetchResult(TestCase::SESSION);
        $this->fail('no exception');
    } catch (ResultUnavailable $e) {
        expect($e->status)->toBe(409)->and($e->sessionStatus)->toBe('started');
    }
});

it('reports a refused token without asking for the result, and never quotes the key', function (): void {
    $this->tokenAnswer = fn (): mixed => Http::response(['error' => 'invalid_client'], 401);

    try {
        Flink::fetchResult(TestCase::SESSION);
        $this->fail('no exception');
    } catch (ResultUnavailable $e) {
        expect($e->status)->toBe(401)
            ->and($e->getMessage())->not->toContain('PRIVATE KEY')->not->toContain('eyJ');
    }
    Http::assertNotSent(fn (Request $request): bool => $request->url() === RESULT_URL);
});

it('drops a token FLINK no longer accepts, so the next fetch gets a new one', function (): void {
    $this->resultAnswer = fn (): mixed => Http::response(null, 401);
    expect(fn () => Flink::fetchResult(TestCase::SESSION))->toThrow(ResultUnavailable::class);

    $this->resultAnswer = fn (): mixed => Http::response(['signedResult' => $this->resultsJwt()]);
    Flink::fetchResult(TestCase::SESSION);

    expect($this->jtis)->toHaveCount(2);
});

it('refuses a result for another session than the one it asked for', function (): void {
    $this->resultAnswer = fn (): mixed => Http::response(['signedResult' => $this->resultsJwt(['sessionId' => '7d5c3a8e-0000-4000-8000-000000000002'])]);

    Flink::fetchResult(TestCase::SESSION);
})->throws(InvalidAssertion::class, 'Claim sessionId does not match');

it('reports an outage as unavailable, status 0', function (): void {
    $this->resultAnswer = fn (): never => throw new ConnectionException('down');

    try {
        Flink::fetchResult(TestCase::SESSION);
        $this->fail('no exception');
    } catch (ResultUnavailable $e) {
        expect($e->status)->toBe(0);
    }
});

it('keeps a token per FLINK, tenant and client, so apps sharing a cache never swap tokens', function (): void {
    Flink::fetchResult(TestCase::SESSION);
    config(['flink.results.audience' => 'another-client']);
    $this->resultAnswer = fn (): mixed => Http::response(null, 404);

    expect(fn () => Flink::fetchResult(TestCase::SESSION))->toThrow(ResultUnavailable::class);
    // The other client asked for a token of its own rather than reusing the first one's.
    Http::assertSent(fn (Request $request): bool => $request->url() === TOKEN_URL && str_contains($request->body(), 'client_assertion'));
    expect(collect(Http::recorded())->filter(fn ($pair): bool => $pair[0]->url() === TOKEN_URL))->toHaveCount(2);
});

it('needs the client key and its kid', function (string $missing, string $variable): void {
    config(["flink.results.{$missing}" => null]);

    expect(fn () => Flink::fetchResult(TestCase::SESSION))->toThrow(MissingConfiguration::class, "Set {$variable}");
})->with([
    ['client_key', 'FLINK_CLIENT_KEY'],
    ['client_key_id', 'FLINK_CLIENT_KEY_ID'],
]);

it('refuses a client key FLINK would not accept', function (): void {
    config(['flink.results.client_key' => 'not a key']);

    Flink::fetchResult(TestCase::SESSION);
})->throws(MissingConfiguration::class, 'FLINK_CLIENT_KEY');

it('makes a key pair: the private key for the env, the public key set for FLINK', function (): void {
    $this->artisan('flink:client-key', ['kid' => 'my-backend-1'])
        ->expectsOutputToContain('BEGIN PRIVATE KEY')
        ->expectsOutputToContain('"kid":"my-backend-1"')
        ->assertSuccessful();
});

it('never follows a redirect, so neither the client assertion nor the token reaches another origin', function (string $answer, int $status): void {
    $this->{$answer} = fn (): mixed => Http::response(null, $status, ['Location' => 'https://elsewhere.test/collect']);
    Http::fake(['https://elsewhere.test/*' => Http::response(['access_token' => 'stolen', 'signedResult' => $this->resultsJwt()])]);

    expect(fn () => Flink::fetchResult(TestCase::SESSION))->toThrow(ResultUnavailable::class);
    Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://elsewhere.test/collect');
})->with([
    'the token request' => ['tokenAnswer', 307],
    'the result request' => ['resultAnswer', 302],
]);

it('refuses a client key FLINK would not accept: a curve other than P-256, or RSA under 2048 bits', function (array $options): void {
    openssl_pkey_export(openssl_pkey_new($options), $pem);
    config(['flink.results.client_key' => $pem]);

    expect(fn () => Flink::fetchResult(TestCase::SESSION))->toThrow(MissingConfiguration::class, 'FLINK_CLIENT_KEY');
    Http::assertNothingSent();
})->with([
    'P-384' => [['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'secp384r1']],
    'RSA 1024' => [['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 1024]],
]);

it('keeps a token per tenant and per FLINK url, not only per client', function (string $setting, string $value): void {
    Flink::fetchResult(TestCase::SESSION);
    config(["flink.{$setting}" => $value]);
    Http::fake(['*' => Http::response(['access_token' => 'other-token', 'expires_in' => 600])]);

    expect(fn () => Flink::fetchResult(TestCase::SESSION))->toThrow(ResultUnavailable::class);
    // Faking again starts a fresh record, so a token request here means the first token was not reused.
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && str_ends_with($request->url(), '/client/v1/auth/token'));
})->with([
    'tenant' => ['tenant_id', 'b0c0c0c0-0000-4000-8000-000000000000'],
    'url' => ['url', 'https://other-flink.test'],
]);
