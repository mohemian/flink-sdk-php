<?php

use Carbon\CarbonImmutable;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Support\Facades\Http;
use Mohemian\Flink\Assertions\PrefillAssertion;
use Mohemian\Flink\Exceptions\FlinkException;
use Mohemian\Flink\Exceptions\InvalidAssertion;
use Mohemian\Flink\Exceptions\InvalidSignature;
use Mohemian\Flink\Exceptions\KeysUnavailable;
use Mohemian\Flink\Exceptions\MissingConfiguration;
use Mohemian\Flink\Facades\Flink;
use Mohemian\Flink\Tests\TestCase;

it('returns the verified assertion', function (): void {
    $data = ['givenName' => 'Erika', 'familyName' => 'Mustermann', 'address' => ['street' => 'Hauptstraße']];
    $exp = time() + 300;

    $prefill = Flink::prefill($this->jwt(['data' => $data, 'exp' => $exp]), TestCase::REF);

    expect($prefill)->toBeInstanceOf(PrefillAssertion::class)
        ->and($prefill->data)->toBe($data)
        ->and($prefill->toArray())->toBe($data)
        ->and($prefill->get('address.street'))->toBe('Hauptstraße')
        ->and($prefill->get('address.city', 'none'))->toBe('none')
        ->and($prefill->journey)->toBe(TestCase::JOURNEY)
        ->and($prefill->method)->toBe('eid')
        ->and($prefill->reference)->toBe(TestCase::REF)
        ->and($prefill->sessionId)->toBe(TestCase::SESSION)
        ->and($prefill->expiresAt)->toEqual(CarbonImmutable::createFromTimestamp($exp));
});

it('takes the reference from the session by default', function (): void {
    session(['flink.reference' => TestCase::REF]);

    expect(Flink::prefill($this->jwt([]))->data)->toBe(['givenName' => 'Erika']);
});

it('refuses when the session has no reference', function (): void {
    Flink::prefill($this->jwt([]));
})->throws(InvalidAssertion::class, 'No FLINK reference');

it('refuses an absent or empty token as a FlinkException, not a TypeError', function (?string $jwt): void {
    Flink::prefill($jwt, TestCase::REF);
})->throws(InvalidSignature::class)->with(['null' => [null], 'empty' => ['']]);

it('rejects a claim', function (array $claims, string $message): void {
    expect(fn () => Flink::prefill($this->jwt($claims), TestCase::REF))->toThrow(InvalidAssertion::class, $message);
})->with([
    'wrong iss' => [['iss' => 'https://evil.test'], 'Claim iss does not match'],
    'wrong aud' => [['aud' => 'https://evil.test'], 'Claim aud does not match'],
    'wrong tenant' => [['tenant_id' => 'b0c0c0c0-0000-4000-8000-000000000000'], 'Claim tenant_id does not match'],
    'wrong journey' => [['journey' => 'OTHERJOURNEY'], 'Claim journey does not match'],
    'missing journey' => [['journey' => null], 'Claim journey does not match'],
    'journey not a string' => [['journey' => true], 'Claim journey does not match'],
    'method not allowed' => [['method' => 'doc'], 'not come from an accepted method'],
    'missing method' => [['method' => null], 'not come from an accepted method'],
    'wrong reference' => [['reference' => 'gutmann:sess-other'], 'Claim reference does not match'],
    'missing reference' => [['reference' => null], 'Claim reference does not match'],
    'expired' => [['exp' => time() - 1], 'expired'],
    'no exp' => [['exp' => null], 'Claim exp is missing'],
    'wrong version' => [['ver' => 2], 'Claim ver does not match'],
    'no data' => [['data' => null], 'Claim data is missing'],
    'no session_id' => [['session_id' => null], 'Claim session_id is missing'],
]);

it('rejects what FLINK did not sign', function (array $header): void {
    Flink::prefill($this->jwt([], $header), TestCase::REF);
})->throws(InvalidSignature::class)->with([
    'unknown kid' => [['kid' => 'k9']],
]);

it('refuses a results assertion handed in as a prefill', function (): void {
    Flink::prefill($this->jwt([], ['typ' => 'flow-results+jwt']), TestCase::REF);
})->throws(InvalidAssertion::class, 'Not a flow-prefill+jwt');

it('verifies a JWT from any configured journey', function (): void {
    config(['flink.prefill.journeys' => [TestCase::JOURNEY, 'WAE67ZF4XNAT']]);

    expect(Flink::prefill($this->jwt(['journey' => 'WAE67ZF4XNAT']), TestCase::REF)->journey)->toBe('WAE67ZF4XNAT')
        ->and(fn () => Flink::prefill($this->jwt(['journey' => 'OTHERJOURNEY']), TestCase::REF))->toThrow(InvalidAssertion::class);
});

it('reads FLINK_JOURNEYS as a comma-separated list', function (): void {
    config(['flink.prefill.journeys' => ' SCD7DDPK258G, WAE67ZF4XNAT ,']);

    expect(Flink::prefill($this->jwt(['journey' => 'WAE67ZF4XNAT']), TestCase::REF)->journey)->toBe('WAE67ZF4XNAT')
        ->and(Flink::prefill($this->jwt([]), TestCase::REF)->journey)->toBe(TestCase::JOURNEY);
});

it('requires at least one journey', function (mixed $journeys): void {
    config(['flink.prefill.journeys' => $journeys]);

    Flink::prefill($this->jwt([]), TestCase::REF);
})->throws(MissingConfiguration::class, 'Set FLINK_JOURNEYS')->with(['unset' => [null], 'empty' => ['']]);

it('does not treat missing configuration as a rejected token', function (): void {
    config(['flink.prefill.journeys' => ' , ']);

    expect(fn () => Flink::prefill($this->jwt([]), TestCase::REF))->toThrow(MissingConfiguration::class)
        ->and(new MissingConfiguration('x'))->not->toBeInstanceOf(FlinkException::class);
});

it('requires a tenant', function (): void {
    config(['flink.tenant_id' => null]);

    Flink::prefill($this->jwt([]), TestCase::REF);
})->throws(MissingConfiguration::class, 'Set FLINK_TENANT_ID');

it('rejects a signature by another key', function (): void {
    $forged = JWT::encode($this->claims([]), TestCase::key('attacker')['private'], 'RS256', 'k1', ['typ' => 'flow-prefill+jwt']);

    Flink::prefill($forged, TestCase::REF);
})->throws(InvalidSignature::class);

it('rejects an HMAC token under a known kid', function (): void {
    // The classic confusion: HS256 keyed with the published key's PEM text, which anyone can derive
    // from the JWKS. The token is valid under that confusion, so only the verifier stops it.
    $pem = openssl_pkey_get_details(JWK::parseKey(TestCase::key('k1')['jwk'])->getKeyMaterial())['key'];
    $hmac = JWT::encode($this->claims([]), $pem, 'HS256', 'k1', ['typ' => 'flow-prefill+jwt']);
    expect(JWT::decode($hmac, new Key($pem, 'HS256'))->tenant_id)->toBe(TestCase::TENANT);

    expect(fn () => Flink::prefill($hmac, TestCase::REF))->toThrow(InvalidSignature::class);
});

it('rejects garbage', function (string $jwt): void {
    Flink::prefill($jwt, TestCase::REF);
})->throws(InvalidSignature::class)->with(['three parts' => ['not.a.jwt'], 'one part' => ['garbage'], 'bad base64' => ['%%%.e30.x']]);

it('verifies a JWT only in the session whose reference it carries', function (): void {
    $jwt = $this->jwt([]);

    Flink::prefill($jwt, TestCase::REF);
    Flink::prefill($jwt, TestCase::REF); // a retried submit

    expect(fn () => Flink::prefill($jwt, 'gutmann:sess-other'))->toThrow(InvalidAssertion::class);
});

it('refetches the JWKS once for an unknown kid', function (): void {
    $this->served = [['k1'], ['k1', 'k2']];

    Flink::prefill($this->jwt([]), TestCase::REF);
    expect(Flink::prefill($this->jwt(['jti' => 'j2'], ['kid' => 'k2']), TestCase::REF)->data)->toBe(['givenName' => 'Erika']);

    // The refetched set is cached: the next k2 token verifies without another fetch, even though
    // the minute's refetch is spent.
    expect(Flink::prefill($this->jwt(['jti' => 'j3'], ['kid' => 'k2']), TestCase::REF)->data)->toBe(['givenName' => 'Erika']);

    // A third unknown kid within the minute does not hit the endpoint again.
    expect(fn () => Flink::prefill($this->jwt(['jti' => 'j4'], ['kid' => 'k3']), TestCase::REF))->toThrow(InvalidSignature::class);
    Http::assertSentCount(2);
});

it('caches the keys in the configured store under the configured key', function (): void {
    config(['cache.stores.flink' => ['driver' => 'array'], 'flink.cache.store' => 'flink', 'flink.cache.key' => 'rp:jwks']);

    Flink::prefill($this->jwt([]), TestCase::REF);

    expect(cache()->store('flink')->has('rp:jwks'))->toBeTrue()
        ->and(cache()->store()->has('rp:jwks'))->toBeFalse();
});

it('fetches the keys from FLINK_JWKS_URL when set', function (): void {
    config(['flink.jwks_url' => 'https://keys.test/jwks.json']);
    Http::fake(['https://keys.test/jwks.json' => Http::response(['keys' => [TestCase::key('k1')['jwk']]])]);

    expect(Flink::prefill($this->jwt([]), TestCase::REF)->data)->toBe(['givenName' => 'Erika']);
});

it('lists the verified fields a submission changed or left out', function (): void {
    $prefill = Flink::prefill($this->jwt(['data' => [
        'givenName' => 'Zoë',
        'familyName' => 'Mustermann',
        'address' => ['street' => 'Hauptstraße', 'postalCode' => '1010'],
        'ageOver18' => true,
    ]]), TestCase::REF);
    $submitted = [
        'givenName' => " Zo\u{0065}\u{0308} ",  // decomposed ë, padded: equal after trim + NFC
        'familyName' => 'Musterfrau',
        'address' => ['street' => 'Ringstraße', 'postalCode' => '1010'],
        'ageOver18' => '1',
        'phone' => '+43 1 234',                  // not in the JWT: not compared
    ];

    expect($prefill->changed($submitted))->toBe(['familyName', 'address.street'])
        ->and($prefill->changed([]))->toBe(['givenName', 'familyName', 'address.street', 'address.postalCode', 'ageOver18']);
});

it('counts a non-scalar submitted value as changed instead of failing', function (): void {
    // A client can post givenName[]=, which reaches changed() as an array.
    $prefill = Flink::prefill($this->jwt(['data' => ['givenName' => 'Erika', 'familyName' => 'Mustermann']]), TestCase::REF);

    expect($prefill->changed(['givenName' => [], 'familyName' => ['x' => 'y']]))->toBe(['givenName', 'familyName']);
});

it('compares only the fields asked for', function (): void {
    $prefill = Flink::prefill($this->jwt(['data' => ['givenName' => 'Erika', 'familyName' => 'Mustermann']]), TestCase::REF);

    expect($prefill->changed(['givenName' => 'Erika'], only: ['givenName', 'birthdate']))->toBe([]);
});

it('reports unreachable keys as unavailable, not as an invalid token', function (): void {
    config(['flink.jwks_url' => 'https://keys.test/jwks.json']);
    Http::fake(['https://keys.test/jwks.json' => Http::response('', 500)]);

    expect(fn () => Flink::prefill($this->jwt([]), TestCase::REF))->toThrow(KeysUnavailable::class)
        ->and(new KeysUnavailable('x'))->not->toBeInstanceOf(FlinkException::class);
});

it('does not chain the expired payload into the exception', function (): void {
    try {
        Flink::prefill($this->jwt(['exp' => time() - 1]), TestCase::REF);
    } catch (InvalidAssertion $e) {
        expect($e->getPrevious())->toBeNull();

        return;
    }
    $this->fail('not rejected');
});

it('refuses a null expectation instead of matching a missing claim', function (): void {
    Flink::verify($this->jwt(['aud' => null]), PrefillAssertion::class, ['aud' => null, 'reference' => TestCase::REF]);
})->throws(InvalidAssertion::class, 'Claim aud does not match');

it('refuses a non-string token as a FlinkException, not a TypeError', function (mixed $jwt): void {
    Flink::prefill($jwt, TestCase::REF);
})->throws(InvalidSignature::class)->with(['array' => [['a']], 'int' => [1]]);

it('treats an empty FLINK_AUDIENCE as unset', function (): void {
    config(['flink.prefill.audience' => '']);

    expect(Flink::prefill($this->jwt([]), TestCase::REF)->data)->toBe(['givenName' => 'Erika']);
});

it('keeps the cached keys when a refetch returns no key set', function (mixed $body): void {
    Flink::prefill($this->jwt([]), TestCase::REF); // caches k1
    $this->bodies = [$body];

    expect(fn () => Flink::prefill($this->jwt([], ['kid' => 'k2']), TestCase::REF))->toThrow(KeysUnavailable::class)
        ->and(Flink::prefill($this->jwt([]), TestCase::REF)->data)->toBe(['givenName' => 'Erika']);
})->with(['no keys' => [['foo' => 'bar']], 'keys not a list' => [['keys' => 'x']], 'empty keys' => [['keys' => []]]]);

it('does not cache an unusable first key set', function (): void {
    $this->bodies = [['keys' => []]]; // then the real key set

    expect(fn () => Flink::prefill($this->jwt([]), TestCase::REF))->toThrow(KeysUnavailable::class)
        ->and(Flink::prefill($this->jwt([]), TestCase::REF)->data)->toBe(['givenName' => 'Erika']);
});
