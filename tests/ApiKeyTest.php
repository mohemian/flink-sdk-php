<?php

use Firebase\JWT\JWT;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Mohemian\Flink\Exceptions\InvalidAssertion;
use Mohemian\Flink\Exceptions\MissingConfiguration;
use Mohemian\Flink\Exceptions\ResultUnavailable;
use Mohemian\Flink\Facades\Flink;
use Mohemian\Flink\Tests\TestCase;

// A journey API key (FLNK-859) instead of the client id and its key.
const API_KEY = 'flk_test_0123456789abcdef';
const KEY_SUBJECT = 'apikey:key-1';
const KEY_TOKEN_URL = TestCase::URL.'/client/v1/auth/token';
const KEY_RESULT_URL = TestCase::URL.'/client/v1/tenants/'.TestCase::TENANT.'/sessions/'.TestCase::SESSION.'/results';

/** A token as FLINK issues it for a key: its subject is the key. Unsigned: the SDK only reads it. */
function keyToken(?string $subject = KEY_SUBJECT): string
{
    return 'e30.'.JWT::urlsafeB64Encode((string) json_encode(['sub' => $subject, 'tenant_id' => TestCase::TENANT])).'.';
}

beforeEach(function (): void {
    // The audience is the key's subject, read from its token: nothing names it in the config.
    config(['flink.results.api_key' => API_KEY, 'flink.results.audience' => null]);
    $this->token = keyToken();
    $this->tokenAnswer = fn (): mixed => Http::response(['access_token' => $this->token, 'token_type' => 'Bearer', 'expires_in' => 600]);
    $this->resultAnswer = fn (): mixed => Http::response(['signedResult' => $this->resultsJwt(['aud' => KEY_SUBJECT])]);
    Http::fake([
        KEY_TOKEN_URL => function (Request $request): mixed {
            // What flink-services checks for a key: nothing beside it, and the key itself.
            parse_str($request->body(), $form);
            if ($request->hasHeader('Authorization') || isset($form['client_assertion']) || isset($form['client_id'])) {
                return Http::response(['error' => 'invalid_request'], 400);
            }
            if ($request->header('X-Api-Key') !== [API_KEY] || ($form['grant_type'] ?? null) !== 'client_credentials') {
                return Http::response(['error' => 'invalid_client'], 401);
            }

            return ($this->tokenAnswer)();
        },
        KEY_RESULT_URL => fn (Request $request): mixed => $request->header('Authorization') === ['Bearer '.$this->token]
            ? ($this->resultAnswer)()
            : Http::response(null, 403),
    ]);
});

$tokenRequests = fn (): int => collect(Http::recorded())->filter(fn ($pair): bool => $pair[0]->url() === KEY_TOKEN_URL)->count();

it('fetches a result with the key, signed for the key, and verifies it', function (): void {
    $results = Flink::fetchResult(TestCase::SESSION);

    expect($results->sessionId)->toBe(TestCase::SESSION)->and($results->get('givenName'))->toBe('Erika');
    Http::assertSent(fn (Request $request): bool => $request->url() === KEY_TOKEN_URL
        && $request->header('X-Api-Key') === [API_KEY] && $request->body() === 'grant_type=client_credentials');
    // The key goes to the token endpoint only.
    Http::assertNotSent(fn (Request $request): bool => $request->url() === KEY_RESULT_URL && $request->hasHeader('X-Api-Key'));
});

it('asks for one token for several fetches', function () use ($tokenRequests): void {
    Flink::fetchResult(TestCase::SESSION);
    Flink::fetchResult(TestCase::SESSION);

    expect($tokenRequests())->toBe(1);
});

it('refuses a result signed for another client, the plain client id included', function (string $audience): void {
    $this->resultAnswer = fn (): mixed => Http::response(['signedResult' => $this->resultsJwt(['aud' => $audience])]);

    Flink::fetchResult(TestCase::SESSION);
})->with([TestCase::RP, 'apikey:key-2'])->throws(InvalidAssertion::class, 'Claim aud does not match');

it('verifies a kept result, asking FLINK for the key\'s subject only the first time', function () use ($tokenRequests): void {
    $jwt = $this->resultsJwt(['aud' => KEY_SUBJECT]);

    expect(Flink::results($jwt)->sessionId)->toBe(TestCase::SESSION);
    expect(Flink::results($jwt)->sessionId)->toBe(TestCase::SESSION);
    expect($tokenRequests())->toBe(1);
    Http::assertNotSent(fn (Request $request): bool => $request->url() === KEY_RESULT_URL);
});

it('asks for a new token when it holds one but not its subject', function () use ($tokenRequests): void {
    Flink::fetchResult(TestCase::SESSION);
    // The subject's entry evicted on its own, the token still cached.
    cache()->forget('flink:jwks:client-token:'.hash('sha256', TestCase::URL.'|'.TestCase::TENANT.'|key:'.API_KEY).':audience');

    expect(Flink::results($this->resultsJwt(['aud' => KEY_SUBJECT]))->sessionId)->toBe(TestCase::SESSION);
    expect($tokenRequests())->toBe(2);
});

it('takes FLINK_RESULTS_AUDIENCE when it is set, without asking FLINK', function (): void {
    config(['flink.results.audience' => KEY_SUBJECT]);

    expect(Flink::results($this->resultsJwt(['aud' => KEY_SUBJECT]))->sessionId)->toBe(TestCase::SESSION);
    Http::assertNotSent(fn (Request $request): bool => $request->url() === KEY_TOKEN_URL);
});

it('verifies nothing when the token names no key as its subject', function (?string $subject): void {
    $this->token = keyToken($subject);

    Flink::results($this->resultsJwt(['aud' => KEY_SUBJECT]));
})->with([TestCase::RP, null])->throws(ResultUnavailable::class);

it('reports a key FLINK refuses as a 401 on the token, without quoting the key', function (): void {
    $this->tokenAnswer = fn (): mixed => Http::response(['error' => 'invalid_client'], 401);

    try {
        Flink::fetchResult(TestCase::SESSION);
        $this->fail('no exception');
    } catch (ResultUnavailable $e) {
        expect($e->status)->toBe(401)->and($e->getMessage())->not->toContain('flk_');
    }
    Http::assertNotSent(fn (Request $request): bool => $request->url() === KEY_RESULT_URL);
});

it('says a session of another journey is one this key may not read', function (): void {
    $this->resultAnswer = fn (): mixed => Http::response(null, 404);

    try {
        Flink::fetchResult(TestCase::SESSION);
        $this->fail('no exception');
    } catch (ResultUnavailable $e) {
        expect($e->status)->toBe(404);
    }
});

it('keeps a token per key, so a rotated key never uses the old one\'s token', function () use ($tokenRequests): void {
    Flink::fetchResult(TestCase::SESSION);
    config(['flink.results.api_key' => 'flk_another']);

    expect(fn () => Flink::fetchResult(TestCase::SESSION))->toThrow(ResultUnavailable::class);
    expect($tokenRequests())->toBe(2);
});

it('takes exactly one credential, and only a flk_ key, without quoting it', function (array $settings, string $message): void {
    config($settings);

    try {
        Flink::fetchResult(TestCase::SESSION);
        $this->fail('no exception');
    } catch (MissingConfiguration $e) {
        expect($e->getMessage())->toContain($message)->not->toContain('secret');
    }
    Http::assertNothingSent();
})->with([
    'both' => [['flink.results.client_key' => TestCase::key('ec1')['private']], 'not both'],
    'no flk_ prefix' => [['flink.results.api_key' => 'sk_live_secret'], 'FLINK_API_KEY'],
    'the prefix alone' => [['flink.results.api_key' => 'flk_'], 'FLINK_API_KEY'],
]);
