<?php

use Firebase\JWT\JWT;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Mohemian\Flink\Exceptions\ResultUnavailable;
use Mohemian\Flink\Exceptions\SessionRefused;
use Mohemian\Flink\Facades\Flink;
use Mohemian\Flink\Tests\TestCase;

// A session started at a chosen eID behind a FLINK link, and its return (FLNK-1182).
const LINK_TOKEN_URL = TestCase::URL.'/client/v1/auth/token';
const LINK_START_URL = TestCase::URL.'/client/v1/journeys/'.TestCase::JOURNEY.'/sessions';
const LINK_RETURN_URL = TestCase::URL.'/client/v1/tenants/'.TestCase::TENANT.'/sessions/'.TestCase::SESSION.'/return';
const LINK_RESULTS_URL = TestCase::URL.'/client/v1/tenants/'.TestCase::TENANT.'/sessions/'.TestCase::SESSION.'/results';

beforeEach(function (): void {
    config(['flink.results.api_key' => 'flk_test_0123456789abcdef', 'flink.results.audience' => null]);
    $this->token = 'e30.'.JWT::urlsafeB64Encode((string) json_encode(['sub' => 'apikey:key-1', 'tenant_id' => TestCase::TENANT])).'.';
    $this->startAnswer = fn (): mixed => Http::response([
        'session_id' => TestCase::SESSION,
        'tenant_id' => TestCase::TENANT,
        'link' => ['url' => 'https://web.test/l/handle-1', 'expires_at' => '2026-11-04T12:15:00.000Z'],
    ], 201);
    $this->returnAnswer = fn (): mixed => Http::response(null, 204);
    $this->resultsAnswer = fn (): mixed => Http::response(['statusCode' => 409, 'status' => 'completed', 'reason' => 'awaiting_return'], 409);
    $authorised = fn (Request $request, Closure $answer): mixed => $request->header('Authorization') === ['Bearer '.$this->token]
        ? $answer()
        : Http::response(null, 403);
    Http::fake([
        LINK_TOKEN_URL => fn (): mixed => Http::response(['access_token' => $this->token, 'token_type' => 'Bearer', 'expires_in' => 600]),
        LINK_START_URL => fn (Request $request): mixed => $authorised($request, $this->startAnswer),
        LINK_RETURN_URL => fn (Request $request): mixed => $authorised($request, $this->returnAnswer),
        LINK_RESULTS_URL => fn (Request $request): mixed => $authorised($request, $this->resultsAnswer),
    ]);
});

it('starts a session at the chosen eID and hands back the FLINK link', function (): void {
    $session = Flink::startSession(TestCase::JOURNEY, 'at', 2, '203.0.113.7', 'Browser/1');

    expect($session->sessionId)->toBe(TestCase::SESSION)
        ->and($session->tenantId)->toBe(TestCase::TENANT)
        ->and($session->url)->toBe('https://web.test/l/handle-1')
        ->and($session->expiresAt)->toBe('2026-11-04T12:15:00.000Z');
    Http::assertSent(fn (Request $request): bool => $request->url() === LINK_START_URL && $request->method() === 'POST'
        && $request->data() === ['country' => 'at', 'eid_entry' => 2, 'client_ip' => '203.0.113.7', 'user_agent' => 'Browser/1']);
});

it('sends the notice attestation it is given (FLNK-1184)', function (): void {
    Flink::startSession(TestCase::JOURNEY, 'at', 2, notice: ['revision' => '0123456789abcdef', 'locale' => 'de-AT']);

    Http::assertSent(fn (Request $request): bool => $request->url() === LINK_START_URL
        && $request->data() === ['country' => 'at', 'eid_entry' => 2, 'notice' => ['revision' => '0123456789abcdef', 'locale' => 'de-AT']]);
});

it('leaves out an empty notice attestation', function (): void {
    Flink::startSession(TestCase::JOURNEY, 'at', 2, notice: []);

    Http::assertSent(fn (Request $request): bool => $request->url() === LINK_START_URL
        && $request->data() === ['country' => 'at', 'eid_entry' => 2]);
});

it('leaves out the person fields it was not given', function (): void {
    Flink::startSession(TestCase::JOURNEY, 'at', 2);

    Http::assertSent(fn (Request $request): bool => $request->url() === LINK_START_URL
        && $request->data() === ['country' => 'at', 'eid_entry' => 2]);
});

it("passes FLINK's refusal on, with the step it names", function (): void {
    $this->startAnswer = fn (): mixed => Http::response(['statusCode' => 400, 'code' => 'journey_needs_interaction', 'step' => 'authentication:f'], 400);

    try {
        Flink::startSession(TestCase::JOURNEY, 'at', 2);
        $this->fail('no exception');
    } catch (SessionRefused $e) {
        expect($e->reason)->toBe('journey_needs_interaction')
            ->and($e->step)->toBe('authentication:f');
    }
});

it('says why FLINK did not start the session, by its status', function (int $status): void {
    $this->startAnswer = fn (): mixed => Http::response(['message' => 'no'], $status);

    try {
        Flink::startSession(TestCase::JOURNEY, 'at', 2);
        $this->fail('no exception');
    } catch (ResultUnavailable $e) {
        expect($e->status)->toBe($status);
    }
})->with([400, 403, 404, 429, 500]);

// Defence in depth: the link goes to a browser, so only an https one is passed on.
it('refuses a link that is not an absolute https url with a host and no credentials', function (string $url): void {
    $this->startAnswer = fn (): mixed => Http::response([
        'session_id' => TestCase::SESSION,
        'tenant_id' => TestCase::TENANT,
        'link' => ['url' => $url, 'expires_at' => '2026-11-04T12:15:00.000Z'],
    ], 201);

    expect(fn () => Flink::startSession(TestCase::JOURNEY, 'at', 2))->toThrow(ResultUnavailable::class);
})->with([
    'http://web.test/l/handle-1',
    'https://',
    'https:///l/x',
    // Parses with no host: a browser would follow it to https://l/x.
    'https:/l/x',
    'https://user:pass@web.test/l/x',
    'https://user@web.test/l/x',
]);

// Each call carries the client token, and the start carries the person's IP and browser: a redirect
// must never send them on to another origin.
it('never follows a redirect on the start or the return', function (string $answer): void {
    $this->{$answer} = fn (): mixed => Http::response(null, 307, ['Location' => 'https://elsewhere.test/collect']);
    Http::fake(['https://elsewhere.test/*' => Http::response(null, 204)]);

    expect(fn () => $answer === 'startAnswer'
        ? Flink::startSession(TestCase::JOURNEY, 'at', 2, '203.0.113.7', 'Browser/1')
        : Flink::completeReturn(TestCase::SESSION, 'code-1'))->toThrow(ResultUnavailable::class);
    Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://elsewhere.test/collect');
})->with(['the start' => 'startAnswer', 'the return' => 'returnAnswer']);

// A token FLINK stopped accepting (revoked, its key rotated) is dropped on the 401, so the next call
// fetches a new one instead of failing until the cached one expires.
it('drops the client token FLINK rejected on a return', function (): void {
    $this->returnAnswer = fn (): mixed => Http::response(null, 401);
    expect(fn () => Flink::completeReturn(TestCase::SESSION, 'code-1'))->toThrow(ResultUnavailable::class);

    $this->returnAnswer = fn (): mixed => Http::response(null, 204);
    expect(Flink::completeReturn(TestCase::SESSION, 'code-1'))->toBeTrue();
    expect(Http::recorded(fn (Request $request): bool => $request->url() === LINK_TOKEN_URL))->toHaveCount(2);
});

it('confirms the return with the code, and answers false for one FLINK refuses', function (): void {
    expect(Flink::completeReturn(TestCase::SESSION, 'code-1'))->toBeTrue();
    Http::assertSent(fn (Request $request): bool => $request->url() === LINK_RETURN_URL && $request->method() === 'POST'
        && $request->data() === ['code' => 'code-1']);

    $this->returnAnswer = fn (): mixed => Http::response(null, 400);
    expect(Flink::completeReturn(TestCase::SESSION, 'wrong'))->toBeFalse();
});

it('asks FLINK nothing for a code it could not have issued', function (): void {
    expect(Flink::completeReturn(TestCase::SESSION, ''))->toBeFalse()
        ->and(Flink::completeReturn(TestCase::SESSION, str_repeat('x', 129)))->toBeFalse();
    Http::assertNotSent(fn (Request $request): bool => $request->url() === LINK_RETURN_URL);
});

it('throws on a return FLINK could not answer', function (): void {
    $this->returnAnswer = fn (): mixed => Http::response(null, 500);

    expect(fn () => Flink::completeReturn(TestCase::SESSION, 'code-1'))->toThrow(ResultUnavailable::class);
});

it('says why a session has no result yet, with the eID error', function (): void {
    try {
        Flink::fetchResult(TestCase::SESSION);
        $this->fail('no exception');
    } catch (ResultUnavailable $e) {
        expect($e->status)->toBe(409)
            ->and($e->sessionStatus)->toBe('completed')
            ->and($e->reason)->toBe('awaiting_return')
            ->and($e->eidError)->toBeNull();
    }

    $this->resultsAnswer = fn (): mixed => Http::response(['status' => 'started', 'reason' => 'eid_failed', 'eid_error' => 'country_mismatch'], 409);
    try {
        Flink::fetchResult(TestCase::SESSION);
        $this->fail('no exception');
    } catch (ResultUnavailable $e) {
        expect($e->reason)->toBe('eid_failed')
            ->and($e->eidError)->toBe('country_mismatch');
    }
});

// The result reader is generic: a FLINK-link session's identity reads like any other result.
it("reads a confirmed link session's identity through fetchResult", function (): void {
    $fake = Flink::fake();
    $this->resultsAnswer = fn (): mixed => Http::response(['signedResult' => $fake->results([
        'givenName' => ['value' => 'Jane', 'source' => TestCase::EID],
        'familyName' => ['value' => 'Doe', 'source' => TestCase::EID],
    ], ['sessionId' => TestCase::SESSION])]);

    $result = Flink::fetchResult(TestCase::SESSION);

    expect($result->attributes['givenName']['value'])->toBe('Jane')
        ->and($result->sources)->toHaveKey(TestCase::EID);
});
