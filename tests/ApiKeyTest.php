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
const KEY_SESSION_URL = TestCase::URL.'/client/v1/tenants/'.TestCase::TENANT.'/sessions/'.TestCase::SESSION;

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
    $this->sessionBody = [
        'id' => TestCase::SESSION,
        'tenant_id' => TestCase::TENANT,
        'template_id' => '22222222-2222-4222-8222-222222222222',
        'status' => 'completed',
        'steps' => [
            ['id' => 'enrichment:email', 'completed' => true, 'started_at' => '2026-10-29T10:22:46.088Z', 'completed_at' => '2026-10-29T10:22:52.164Z'],
            ['id' => 'consent', 'completed' => false, 'declined_at' => '2026-10-29T10:22:50.000Z'],
        ],
        'onboarding_candidates' => [],
        'created_at' => '2026-10-29T10:22:45.897Z',
        'updated_at' => '2026-10-29T10:22:52.183Z',
        'completed_at' => '2026-10-29T10:22:52.183Z',
    ];
    $this->sessionAnswer = fn (): mixed => Http::response($this->sessionBody);
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
        KEY_SESSION_URL => fn (Request $request): mixed => $request->header('Authorization') === ['Bearer '.$this->token]
            ? ($this->sessionAnswer)()
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

it('refuses a client id left in FLINK_RESULTS_AUDIENCE from 0.2 rather than every result', function (): void {
    config(['flink.results.audience' => TestCase::RP]);

    Flink::results($this->resultsJwt(['aud' => KEY_SUBJECT]));
})->throws(MissingConfiguration::class, 'Unset FLINK_RESULTS_AUDIENCE');

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

it("reads a session's state with the key, without its results", function (): void {
    // services may spell the id in another case than the caller did.
    $this->sessionBody['id'] = strtoupper(TestCase::SESSION);

    $session = Flink::fetchSession(TestCase::SESSION);

    expect($session->sessionId)->toBe(TestCase::SESSION)
        ->and($session->status)->toBe('completed')
        ->and($session->createdAt->toIso8601ZuluString('millisecond'))->toBe('2026-10-29T10:22:45.897Z')
        ->and($session->completedAt?->toIso8601ZuluString('millisecond'))->toBe('2026-10-29T10:22:52.183Z')
        ->and($session->steps)->toHaveCount(2)
        ->and($session->steps[0]->id)->toBe('enrichment:email')
        ->and($session->steps[0]->completed)->toBeTrue()
        ->and($session->steps[0]->completedAt?->toIso8601ZuluString('millisecond'))->toBe('2026-10-29T10:22:52.164Z')
        ->and($session->steps[1]->declinedAt)->not->toBeNull()
        ->and($session->steps[1]->startedAt)->toBeNull();
    Http::assertSent(fn (Request $request): bool => $request->url() === KEY_SESSION_URL
        && $request->method() === 'GET' && ! $request->hasHeader('X-Api-Key'));
});

it('leaves completedAt empty while the session runs', function (): void {
    $this->sessionAnswer = fn (): mixed => Http::response(['id' => TestCase::SESSION, 'status' => 'started', 'steps' => [],
        'created_at' => '2026-10-29T10:22:45.897Z', 'updated_at' => '2026-10-29T10:22:45.897Z']);

    $session = Flink::fetchSession(TestCase::SESSION);

    expect($session->status)->toBe('started')->and($session->completedAt)->toBeNull()->and($session->steps)->toBe([]);
});

it('says a session of another journey, or none, is one this key may not read', function (): void {
    $this->sessionAnswer = fn (): mixed => Http::response(['message' => 'not found'], 404);

    try {
        Flink::fetchSession(TestCase::SESSION);
        $this->fail('no exception');
    } catch (ResultUnavailable $e) {
        expect($e->status)->toBe(404);
    }
});

it('refuses an answer that is not the session it asked for', function (mixed $body): void {
    $this->sessionAnswer = fn (): mixed => Http::response($body);

    try {
        Flink::fetchSession(TestCase::SESSION);
        $this->fail('no exception');
    } catch (ResultUnavailable $e) {
        expect($e->status)->toBe(200);
    }
})->with([
    'another session' => [['id' => '7d5c3a8e-0000-4000-8000-000000000002', 'status' => 'started', 'steps' => [], 'created_at' => 'x', 'updated_at' => 'x']],
    'a step without completed' => [['id' => TestCase::SESSION, 'status' => 'started', 'steps' => [['id' => 'email']], 'created_at' => 'x', 'updated_at' => 'x']],
    'no status' => [['id' => TestCase::SESSION, 'steps' => [], 'created_at' => 'x', 'updated_at' => 'x']],
    'a malformed created_at' => [['id' => TestCase::SESSION, 'status' => 'started', 'steps' => [], 'created_at' => 'not-a-date-999', 'updated_at' => '2026-10-29T10:22:45.897Z']],
    'a malformed step time' => [['id' => TestCase::SESSION, 'status' => 'started', 'steps' => [['id' => 'email', 'completed' => true, 'completed_at' => 'not-a-date-999']], 'created_at' => '2026-10-29T10:22:45.897Z', 'updated_at' => '2026-10-29T10:22:45.897Z']],
    'a malformed completed_at' => [['id' => TestCase::SESSION, 'status' => 'completed', 'steps' => [], 'created_at' => '2026-10-29T10:22:45.897Z', 'updated_at' => '2026-10-29T10:22:45.897Z', 'completed_at' => 'not-a-date-999']],
    'no body' => [null],
]);

it('drops a token FLINK no longer accepts on a session read, so the next one gets a new token', function () use ($tokenRequests): void {
    $this->sessionAnswer = fn (): mixed => Http::response(null, 401);
    expect(fn () => Flink::fetchSession(TestCase::SESSION))->toThrow(ResultUnavailable::class);

    Flink::fetchResult(TestCase::SESSION);

    expect($tokenRequests())->toBe(2);
});

it('needs an API key to read a session, and asks FLINK nothing without one', function (): void {
    config(['flink.results.api_key' => null, 'flink.results.audience' => TestCase::RP,
        'flink.results.client_key' => TestCase::key('ec1')['private'], 'flink.results.client_key_id' => 'ec1']);

    expect(fn () => Flink::fetchSession(TestCase::SESSION))->toThrow(MissingConfiguration::class, 'FLINK_API_KEY');
    Http::assertNothingSent();
});
