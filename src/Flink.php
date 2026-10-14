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
use Mohemian\Flink\Exceptions\MissingConfiguration;
use Mohemian\Flink\Verification\Verifier;

/** Verifies FLINK's signed assertions, and keeps the per-session reference a prefill is bound to. */
final class Flink
{
    public function __construct(private readonly Container $app)
    {
    }

    /**
     * Verify a `flow-prefill+jwt`. `$reference` defaults to this session's, as issued by
     * `reference()` and passed to `createFlink({ reference })` on the page.
     *
     * @throws FlinkException
     * @throws MissingConfiguration
     */
    public function prefill(?string $jwt, ?string $reference = null): PrefillAssertion
    {
        return $this->verify($jwt, PrefillAssertion::class, $reference === null ? [] : ['reference' => $reference]);
    }

    /**
     * Verify a `flow-results+jwt`.
     *
     * @throws FlinkException
     * @throws MissingConfiguration
     */
    public function results(?string $jwt): ResultsAssertion
    {
        return $this->verify($jwt, ResultsAssertion::class);
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
     */
    public function verify(?string $jwt, string $type, array $expected = []): Assertion
    {
        if (! is_subclass_of($type, Assertion::class)) {
            throw new \InvalidArgumentException("{$type} is not a FLINK assertion");
        }
        $expected = $expected + $type::expectations($this->app);
        $verifier = $this->app->make(Verifier::class);

        try {
            $claims = $verifier->claims($jwt === null || $jwt === '' ? throw InvalidSignature::malformed() : $jwt, $type::type());
            $assertion = $type::fromClaims($claims, $expected);
        } catch (FlinkException $e) {
            $this->dispatch(new AssertionRejected($type, $e));
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
