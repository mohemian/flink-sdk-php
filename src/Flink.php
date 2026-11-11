<?php

namespace Mohemian\Flink;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Str;
use Mohemian\Flink\Assertions\Assertion;
use Mohemian\Flink\Assertions\PrefillAssertion;
use Mohemian\Flink\Assertions\ResultsAssertion;
use Mohemian\Flink\Events\AssertionRejected;
use Mohemian\Flink\Events\AssertionVerified;
use Mohemian\Flink\Exceptions\FlinkException;
use Mohemian\Flink\Exceptions\InvalidSignature;
use Mohemian\Flink\Exceptions\KeysUnavailable;
use Mohemian\Flink\Exceptions\MissingConfiguration;
use Mohemian\Flink\Exceptions\ResultUnavailable;
use Mohemian\Flink\Exceptions\SessionRefused;
use Mohemian\Flink\Results\FlowSession;
use Mohemian\Flink\Results\JourneyCountries;
use Mohemian\Flink\Results\LinkSession;
use Mohemian\Flink\Results\ResultClient;
use Mohemian\Flink\Verification\Verifier;

/** Verifies FLINK's signed assertions, and keeps the per-session reference a prefill is bound to. */
final readonly class Flink
{
    public function __construct(private Container $app) {}

    /**
     * Verify a `flow-prefill+jwt`. `$jwt` may be anything a request carries: what is not a
     * non-empty string is refused like a malformed token. `$reference` defaults to this session's, as issued by
     * `reference()` and passed to `createFlink({ reference })` on the page.
     *
     * @throws FlinkException
     * @throws MissingConfiguration
     */
    public function prefill(mixed $jwt, ?string $reference = null): PrefillAssertion
    {
        return $this->verify($jwt, PrefillAssertion::class, $reference === null ? [] : ['reference' => $reference]);
    }

    /**
     * Verify a session's `flink-result+jwt` (ES256, FLNK-861).
     *
     * @throws FlinkException
     * @throws MissingConfiguration
     */
    public function results(mixed $jwt, ?string $sessionId = null): ResultsAssertion
    {
        return $this->verify($jwt, ResultsAssertion::class, $sessionId === null ? [] : ['session' => $sessionId]);
    }

    /**
     * Fetch a completed session's signed result as your backend's own client and verify it, bound
     * to that session (FLNK-861). The client proves itself with your private key
     * (`FLINK_CLIENT_KEY`); FLINK holds only its public half.
     *
     * @throws ResultUnavailable not ready yet, refused, or FLINK unreachable
     * @throws FlinkException
     * @throws MissingConfiguration
     */
    public function fetchResult(string $sessionId): ResultsAssertion
    {
        return $this->results($this->app->make(ResultClient::class)->signedResult($sessionId), $sessionId);
    }

    /**
     * A session's state, without its results, as a journey API key reads it (FLNK-859): any session
     * of the key's journey, whoever started it. Needs `FLINK_API_KEY`.
     *
     * @throws ResultUnavailable 404 a session of another journey or none at all, or FLINK unreachable
     * @throws MissingConfiguration
     */
    public function fetchSession(string $sessionId): FlowSession
    {
        return $this->app->make(ResultClient::class)->session($sessionId);
    }

    /**
     * The countries a journey covers and, in each, the eIDs a person can choose: what FLINK's own web
     * flow offers, read before any session, so your page can ask first (FLNK-1181). FLINK requires the
     * `flow-session:write` scope for it, so a `private_key_jwt` client must be registered with that scope;
     * a results-only client lacks it and gets 403. Read it when you render the choice: FLINK withdraws an
     * eID without the journey changing.
     *
     * @throws ResultUnavailable 403 a client without `flow-session:write`, 404 a journey that does not exist or this credential may not read, or FLINK unreachable
     * @throws MissingConfiguration
     */
    public function fetchCountries(string $journey): JourneyCountries
    {
        return $this->app->make(ResultClient::class)->countries($journey);
    }

    /**
     * Start a session and get the FLINK link to send the person to (FLNK-1182, FLNK-1272). Keep
     * `sessionId` with your logged-in user.
     *
     * - `$country` and `$eidEntry` (one of the journey's countries and eIDs, as `fetchCountries()`
     *   lists them): the link takes the person straight to that eID's login, with no FLINK screen.
     * - `$country` only: FLINK's own web flow opens at the choice of method for that country (hosted).
     * - neither: it opens at the choice of country (hosted). The person chooses on FLINK's screens
     *   and you still get the session id, so a webhook or a return connects to your user.
     *
     * `$eidEntry` without `$country` is refused (`country_required`). A hosted link opens once and
     * lands on a page that opens nothing until the person's browser continues from it. The journey
     * must then finish, and its return be redeemed, within 8 hours of the start (the session's
     * `hostedDeadlineAt`), while `expiresAt` is only the last moment the link can be opened.
     * Either way FLINK sends the person to the journey's return link, with a `flink_return_code`
     * query parameter when it completed: pass that to `completeReturn()`. A hosted session that was
     * abandoned (`aborted`) returns the person with no code, and no outcome: there is nothing to
     * confirm, and `fetchResult()` answers 409 with `sessionStatus` `aborted`.
     *
     * `$clientIp` and `$userAgent` are the person's, as your server sees them. `$notice` attests that
     * your page showed the person the journey's applicant notice: `revision` as FLINK served it and
     * the BCP 47 `locale` you showed it in. FLINK does not require it yet (FLNK-1184); send it once
     * your start page shows the notice. A hosted start ignores it: FLINK shows its own.
     * `$clientReferenceId` is your own reference for the session, such as an order or case id
     * (FLNK-1279): FLINK signs it into the result, read back as `ResultsAssertion::$clientReferenceId`.
     * Up to 128 characters from `A-Z a-z 0-9 . _ : -`, and no personal data.
     *
     * @param  array{revision?: string, locale?: string}|null  $notice
     *
     * @throws \InvalidArgumentException a `$clientReferenceId` FLINK would refuse
     * @throws SessionRefused FLINK will not start it, and says why
     * @throws ResultUnavailable 403 a client without `flow-session:write`, 404 a journey this credential may not start, 429 too many starts, or FLINK unreachable
     * @throws MissingConfiguration
     */
    public function startSession(string $journey, ?string $country = null, ?int $eidEntry = null, ?string $clientIp = null, ?string $userAgent = null, ?array $notice = null, ?string $clientReferenceId = null): LinkSession
    {
        return $this->app->make(ResultClient::class)->startSession($journey, $country, $eidEntry, $clientIp, $userAgent, $notice, $clientReferenceId);
    }

    /**
     * Confirm the person came back, with the `flink_return_code` your return link received, which
     * releases the session's result for `fetchResult()`. **Only for the session id you stored for the
     * user logged in on this request, never one taken from the URL**: that check is what stops someone
     * forwarding their own link to another person from receiving that person's identity. True when
     * confirmed (again on a reload), false for a wrong, expired or foreign code, and false, without
     * asking FLINK, for a return that came with no code (null or empty): a hosted session that ended
     * `aborted` returns the person that way (FLNK-1272), so check `fetchResult()` or `fetchSession()`
     * for its status instead.
     *
     * @throws ResultUnavailable FLINK refused the client, or was unreachable
     * @throws MissingConfiguration
     */
    public function completeReturn(string $sessionId, ?string $code): bool
    {
        return $this->app->make(ResultClient::class)->completeReturn($sessionId, $code);
    }

    /**
     * Verify any FLINK assertion. The caller names the type it expects, so a token of another type
     * is refused even though FLINK signed it: a results assertion never expires, and accepting it
     * where a prefill is expected would make it replayable forever.
     *
     * @template T of Assertion
     *
     * @param  class-string<T>  $type
     * @param  array<string, mixed>  $expected  overrides for the type's default expectations
     * @return T
     *
     * @throws FlinkException
     * @throws MissingConfiguration
     * @throws KeysUnavailable
     */
    public function verify(mixed $jwt, string $type, array $expected = []): Assertion
    {
        if (! is_subclass_of($type, Assertion::class)) {
            throw new \InvalidArgumentException("{$type} is not a FLINK assertion");
        }
        $expected = $type::expected($this->app, $expected);
        $verifier = $this->app->make(Verifier::class);

        try {
            $claims = $verifier->claims(is_string($jwt) && $jwt !== '' ? $jwt : throw InvalidSignature::malformed($type::algorithm()), $type);
            $assertion = $type::fromClaims($claims, $expected);
        } catch (FlinkException $e) {
            $this->dispatch(new AssertionRejected($type, $e->getMessage(), $e::class));
            throw $e;
        }
        $this->dispatch(new AssertionVerified($assertion));

        return $assertion;
    }

    /** This session's reference, issued on first use. Pass it to `createFlink({ reference })`. */
    public function reference(): string
    {
        $reference = $this->currentReference();
        if ($reference === null) {
            $reference = Str::random(40);
            $this->app->make('session')->put($this->sessionKey(), $reference);
        }

        return $reference;
    }

    /** This session's reference, or `null` when none has been issued. */
    public function currentReference(): ?string
    {
        $reference = $this->app->make('session')->get($this->sessionKey());

        return is_string($reference) ? $reference : null;
    }

    /** Forget this session's reference, so the next journey needs a new one. */
    public function forgetReference(): void
    {
        $this->app->make('session')->forget($this->sessionKey());
    }

    private function sessionKey(): string
    {
        return (string) $this->app->make('config')->get('flink.prefill.session_key', 'flink.reference');
    }

    private function dispatch(object $event): void
    {
        if ($this->app->make('config')->get('flink.events')) {
            $this->app->make(Dispatcher::class)->dispatch($event);
        }
    }
}
