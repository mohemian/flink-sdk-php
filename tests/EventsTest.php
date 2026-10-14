<?php

use Illuminate\Support\Facades\Event;
use Mohemian\Flink\Assertions\PrefillAssertion;
use Mohemian\Flink\Events\AssertionRejected;
use Mohemian\Flink\Events\AssertionVerified;
use Mohemian\Flink\Exceptions\InvalidAssertion;
use Mohemian\Flink\Facades\Flink;
use Mohemian\Flink\Tests\TestCase;

it('dispatches nothing unless events are enabled', function () {
    Event::fake([AssertionVerified::class, AssertionRejected::class]);

    Flink::prefill($this->jwt([]), TestCase::REF);
    rescue(fn () => Flink::prefill($this->jwt(['aud' => 'x']), TestCase::REF), report: false);

    Event::assertNotDispatched(AssertionVerified::class);
    Event::assertNotDispatched(AssertionRejected::class);
});

it('dispatches verified and rejected events when enabled', function () {
    config(['flink.events' => true]);
    Event::fake([AssertionVerified::class, AssertionRejected::class]);
    $jwt = $this->jwt(['aud' => 'x']);

    $prefill = Flink::prefill($this->jwt([]), TestCase::REF);
    rescue(fn () => Flink::prefill($jwt, TestCase::REF), report: false);

    Event::assertDispatched(AssertionVerified::class, fn ($e) => $e->assertion === $prefill);
    Event::assertDispatched(AssertionRejected::class, fn ($e) => $e->type === PrefillAssertion::class
        && $e->exception === InvalidAssertion::class
        && $e->reason === 'Claim aud does not match');
});

it('dispatches a rejection that serializes for a queued listener and carries no claims', function (array $claims) {
    config(['flink.events' => true]);
    Event::fake([AssertionRejected::class]);

    rescue(fn () => Flink::prefill($this->jwt($claims), TestCase::REF), report: false);

    Event::assertDispatched(AssertionRejected::class, function ($e) {
        $serialized = serialize($e);

        return ! str_contains($serialized, 'Erika') && ! str_contains($serialized, TestCase::REF);
    });
})->with(['mismatch' => [['aud' => 'x']], 'expired' => [['exp' => time() - 1]]]);
