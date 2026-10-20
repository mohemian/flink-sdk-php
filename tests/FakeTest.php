<?php

use Illuminate\Support\Facades\Http;
use Mohemian\Flink\Assertions\PrefillAssertion;
use Mohemian\Flink\Assertions\ResultsAssertion;
use Mohemian\Flink\Exceptions\InvalidAssertion;
use Mohemian\Flink\Exceptions\InvalidSignature;
use Mohemian\Flink\Facades\Flink;
use Mohemian\Flink\Tests\TestCase;

it('signs prefills the app verifies, without reaching FLINK', function (): void {
    $fake = Flink::fake();

    $jwt = $fake->prefill(['givenName' => 'Max']);

    expect(Flink::prefill($jwt)->data)->toBe(['givenName' => 'Max']);
    Http::assertNothingSent();
});

it('binds a fake prefill to the session reference, issuing one if needed', function (): void {
    $jwt = Flink::fake()->prefill();

    expect(Flink::currentReference())->not->toBeNull()
        ->and(Flink::prefill($jwt)->data)->toBe(['givenName' => 'Erika', 'familyName' => 'Mustermann']);
});

it('lets a test override or drop claims to build a rejection', function (): void {
    $fake = Flink::fake();

    expect(fn () => Flink::prefill($fake->prefill(claims: ['method' => 'doc'])))->toThrow(InvalidAssertion::class)
        ->and(fn () => Flink::prefill($fake->prefill(claims: ['exp' => null])))->toThrow(InvalidAssertion::class, 'exp');
});

it('signs results assertions too', function (): void {
    $jwt = Flink::fake()->results(['givenName' => ['value' => 'Max', 'source' => 'urn:flink:source:eid:oidc']]);

    expect(Flink::results($jwt)->get('givenName'))->toBe('Max');
});

it('no longer accepts tokens signed by the real keys once faked', function (): void {
    $real = $this->jwt([]);
    Flink::fake();

    Flink::prefill($real, TestCase::REF);
})->throws(InvalidSignature::class, 'Unknown key id k1');

it('needs no configuration for a claim the test supplies', function (): void {
    config(['flink.prefill.journeys' => null, 'flink.results.audience' => null]);
    $fake = Flink::fake();

    $prefill = $fake->prefill(claims: ['journey' => 'J1']);
    $results = $fake->results(claims: ['aud' => 'rp']);

    expect(Flink::verify($prefill, PrefillAssertion::class, ['journeys' => ['J1']])->journey)->toBe('J1')
        ->and(Flink::verify($results, ResultsAssertion::class, ['aud' => 'rp'])->sessionId)->toBeString();
});
