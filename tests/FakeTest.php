<?php

use Illuminate\Support\Facades\Http;
use Mohemian\Flink\Exceptions\InvalidAssertion;
use Mohemian\Flink\Exceptions\InvalidSignature;
use Mohemian\Flink\Facades\Flink;
use Mohemian\Flink\Tests\TestCase;

it('signs prefills the app verifies, without reaching FLINK', function () {
    $fake = Flink::fake();

    $jwt = $fake->prefill(['givenName' => 'Max']);

    expect(Flink::prefill($jwt)->data)->toBe(['givenName' => 'Max']);
    Http::assertNothingSent();
});

it('binds a fake prefill to the session reference, issuing one if needed', function () {
    $jwt = Flink::fake()->prefill();

    expect(Flink::currentReference())->not->toBeNull()
        ->and(Flink::prefill($jwt)->data)->toBe(['givenName' => 'Erika', 'familyName' => 'Mustermann']);
});

it('lets a test override or drop claims to build a rejection', function () {
    $fake = Flink::fake();

    expect(fn () => Flink::prefill($fake->prefill(claims: ['method' => 'doc'])))->toThrow(InvalidAssertion::class)
        ->and(fn () => Flink::prefill($fake->prefill(claims: ['exp' => null])))->toThrow(InvalidAssertion::class, 'exp');
});

it('signs results assertions too', function () {
    $jwt = Flink::fake()->results(['identity' => ['givenName' => 'Max']]);

    expect(Flink::results($jwt)->get('identity.givenName'))->toBe('Max');
});

it('no longer accepts tokens signed by the real keys once faked', function () {
    $real = $this->jwt([]);
    Flink::fake();

    Flink::prefill($real, TestCase::REF);
})->throws(InvalidSignature::class, 'Unknown key id k1');
