<?php

use Carbon\CarbonImmutable;
use Firebase\JWT\JWT;
use Mohemian\Flink\Assertions\PrefillAssertion;
use Mohemian\Flink\Assertions\ResultsAssertion;
use Mohemian\Flink\Exceptions\InvalidAssertion;
use Mohemian\Flink\Exceptions\InvalidSignature;
use Mohemian\Flink\Exceptions\MissingConfiguration;
use Mohemian\Flink\Facades\Flink;
use Mohemian\Flink\Tests\TestCase;

it('verifies a results assertion, which carries no exp', function (): void {
    $iat = time() - 86400 * 30; // a durable assertion of a past fact still verifies

    $results = Flink::results($this->resultsJwt(['iat' => $iat]));

    expect($results)->toBeInstanceOf(ResultsAssertion::class)
        ->and($results->get('givenName'))->toBe('Erika')
        ->and($results->get('address.locality'))->toBe('Wien')
        ->and($results->get('birthdate', 'absent'))->toBe('absent')
        ->and($results->source('givenName'))->toBe(TestCase::EID)
        ->and($results->source('birthdate'))->toBeNull()
        ->and($results->sources)->toBe([TestCase::EID => ['obtainedAt' => '2026-10-20T08:30:00.000Z']])
        ->and($results->toArray())->toBe($results->attributes)
        ->and($results->sessionId)->toBe(TestCase::SESSION)
        ->and($results->journeyId)->toBe(TestCase::JOURNEY)
        ->and($results->id)->toBe('r1')
        ->and($results->issuedAt)->toEqual(CarbonImmutable::createFromTimestamp($iat));
});

it('rejects a results claim', function (array $claims, string $message): void {
    expect(fn () => Flink::results($this->resultsJwt($claims)))->toThrow(InvalidAssertion::class, $message);
})->with([
    'wrong aud' => [['aud' => 'someone-else'], 'Claim aud does not match'],
    'wrong tenant' => [['tenantId' => 'b0c0c0c0-0000-4000-8000-000000000000'], 'Claim tenantId does not match'],
    'wrong iss' => [['iss' => 'https://evil.test'], 'Claim iss does not match'],
    'wrong version' => [['ver' => 2], 'Claim ver does not match'],
    'no attributes' => [['attributes' => null], 'Claim attributes is missing'],
    'no sources' => [['sources' => null], 'Claim sources is missing'],
    'no sessionId' => [['sessionId' => null], 'Claim sessionId is missing'],
    'no journeyId' => [['journeyId' => null], 'Claim journeyId is missing'],
    'no iat' => [['iat' => null], 'Claim iat is missing'],
]);

it('refuses a result signed RS256, even by a key FLINK publishes', function (): void {
    // The same JWKS serves the prefill's RS256 key; only ES256 is a result.
    Flink::results($this->resultsJwt(kid: 'k1'));
})->throws(InvalidSignature::class, 'Not an ES256 JWS with a key id');

it('refuses a prefill signed with the results key', function (): void {
    session(['flink.reference' => TestCase::REF]);
    $claims = $this->claims([]);
    $jwt = JWT::encode($claims, TestCase::key('e1')['private'], 'ES256', 'e1', ['typ' => 'flow-prefill+jwt']);

    Flink::prefill($jwt, TestCase::REF);
})->throws(InvalidSignature::class, 'Not an RS256 JWS with a key id');

it('refuses a key of the wrong algorithm under the right kid', function (): void {
    // A JWKS entry that claims ES256 for the kid but holds an RSA key is not the results key.
    $this->bodies = [['keys' => [array_merge(TestCase::key('k1')['jwk'], ['kid' => 'e1'])]]];

    Flink::results($this->resultsJwt());
})->throws(InvalidSignature::class, 'Key e1 is not an ES256 key');

it('refuses a prefill handed in as a results assertion', function (): void {
    Flink::results($this->jwt([]));
})->throws(InvalidAssertion::class, 'Not a flink-result+jwt');

it('requires the results audience', function (): void {
    config(['flink.results.audience' => null]);

    Flink::results($this->resultsJwt());
})->throws(MissingConfiguration::class, 'Set FLINK_RESULTS_AUDIENCE');

it('verifies any assertion type through verify(), with the type stated by the caller', function (): void {
    session(['flink.reference' => TestCase::REF]);

    expect(Flink::verify($this->jwt([]), PrefillAssertion::class))->toBeInstanceOf(PrefillAssertion::class)
        ->and(Flink::verify($this->resultsJwt(), ResultsAssertion::class))->toBeInstanceOf(ResultsAssertion::class);
});

it('lets verify() override an expectation', function (): void {
    session(['flink.reference' => TestCase::REF]);

    Flink::verify($this->jwt([]), PrefillAssertion::class, ['reference' => 'other']);
})->throws(InvalidAssertion::class, 'Claim reference does not match');

it('never accepts one type as another through verify()', function (): void {
    session(['flink.reference' => TestCase::REF]);

    expect(fn () => Flink::verify($this->resultsJwt(), PrefillAssertion::class))->toThrow(InvalidAssertion::class, 'Not a flow-prefill+jwt')
        ->and(fn () => Flink::verify($this->jwt([]), ResultsAssertion::class))->toThrow(InvalidAssertion::class, 'Not a flink-result+jwt');
});

it('refuses a type that is not an assertion', function (): void {
    Flink::verify($this->jwt([]), stdClass::class);
})->throws(InvalidArgumentException::class);

it('needs no configuration for an expectation the caller supplies', function (): void {
    config(['flink.results.audience' => null, 'flink.prefill.journeys' => null]);

    expect(Flink::verify($this->resultsJwt(), ResultsAssertion::class, ['aud' => TestCase::RP]))->toBeInstanceOf(ResultsAssertion::class)
        ->and(Flink::verify($this->jwt([]), PrefillAssertion::class, ['journeys' => [TestCase::JOURNEY], 'reference' => TestCase::REF]))->toBeInstanceOf(PrefillAssertion::class);
});
