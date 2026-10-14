<?php

use Carbon\CarbonImmutable;
use Mohemian\Flink\Assertions\PrefillAssertion;
use Mohemian\Flink\Assertions\ResultsAssertion;
use Mohemian\Flink\Exceptions\InvalidAssertion;
use Mohemian\Flink\Exceptions\MissingConfiguration;
use Mohemian\Flink\Facades\Flink;
use Mohemian\Flink\Tests\TestCase;

it('verifies a results assertion, which carries no exp', function (): void {
    $iat = time() - 86400 * 30; // a durable assertion of a past fact still verifies

    $results = Flink::results($this->resultsJwt(['iat' => $iat]));

    expect($results)->toBeInstanceOf(ResultsAssertion::class)
        ->and($results->results)->toBe(['identity' => ['givenName' => 'Erika']])
        ->and($results->get('identity.givenName'))->toBe('Erika')
        ->and($results->toArray())->toBe(['identity' => ['givenName' => 'Erika']])
        ->and($results->sessionId)->toBe(TestCase::SESSION)
        ->and($results->id)->toBe('r1')
        ->and($results->issuedAt)->toEqual(CarbonImmutable::createFromTimestamp($iat));
});

it('rejects a results claim', function (array $claims, string $message): void {
    expect(fn () => Flink::results($this->resultsJwt($claims)))->toThrow(InvalidAssertion::class, $message);
})->with([
    'wrong aud' => [['aud' => 'someone-else'], 'Claim aud does not match'],
    'wrong tenant' => [['tenant_id' => 'b0c0c0c0-0000-4000-8000-000000000000'], 'Claim tenant_id does not match'],
    'wrong iss' => [['iss' => 'https://evil.test'], 'Claim iss does not match'],
    'wrong version' => [['ver' => 2], 'Claim ver does not match'],
    'no results' => [['results' => null], 'Claim results is missing'],
    'no session_id' => [['session_id' => null], 'Claim session_id is missing'],
    'no iat' => [['iat' => null], 'Claim iat is missing'],
]);

it('refuses a prefill handed in as a results assertion', function (): void {
    Flink::results($this->jwt([]));
})->throws(InvalidAssertion::class, 'Not a flow-results+jwt');

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
        ->and(fn () => Flink::verify($this->jwt([]), ResultsAssertion::class))->toThrow(InvalidAssertion::class, 'Not a flow-results+jwt');
});

it('refuses a type that is not an assertion', function (): void {
    Flink::verify($this->jwt([]), stdClass::class);
})->throws(InvalidArgumentException::class);

it('needs no configuration for an expectation the caller supplies', function (): void {
    config(['flink.results.audience' => null, 'flink.prefill.journeys' => null]);

    expect(Flink::verify($this->resultsJwt(), ResultsAssertion::class, ['aud' => TestCase::RP]))->toBeInstanceOf(ResultsAssertion::class)
        ->and(Flink::verify($this->jwt([]), PrefillAssertion::class, ['journeys' => [TestCase::JOURNEY], 'reference' => TestCase::REF]))->toBeInstanceOf(PrefillAssertion::class);
});
