<?php

use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Http;
use Mohemian\Flink\Exceptions\InvalidPrefill;
use Mohemian\Flink\PrefillVerifier;
use Mohemian\Flink\Tests\TestCase;

it('returns the verified data', function () {
    $data = ['givenName' => 'Erika', 'familyName' => 'Mustermann', 'address' => ['street' => 'Hauptstraße']];

    expect($this->verifier()->verify($this->jwt(['data' => $data]), TestCase::REF))->toBe($data);
});

it('rejects', function (array $claims, array $header = []) {
    $this->verifier()->verify($this->jwt($claims, $header), TestCase::REF);
})->throws(InvalidPrefill::class)->with([
    'wrong iss' => [['iss' => 'https://evil.test']],
    'wrong aud' => [['aud' => 'https://evil.test']],
    'wrong tenant' => [['tenant_id' => 'b0c0c0c0-0000-4000-8000-000000000000']],
    'wrong journey' => [['journey' => 'OTHERJOURNEY']],
    'method not allowed' => [['method' => 'doc']],
    'missing journey' => [['journey' => null]],
    'missing method' => [['method' => null]],
    'missing reference' => [['reference' => null]],
    'expired' => [['exp' => time() - 1]],
    'no exp' => [['exp' => null]],
    'wrong version' => [['ver' => 2]],
    'typ of the results assertion' => [[], ['typ' => 'flow-results+jwt']],
    'unknown kid' => [[], ['kid' => 'k9']],
]);

it('rejects a signature by another key', function () {
    $forged = JWT::encode($this->claims([]), TestCase::key('attacker')['private'], 'RS256', 'k1', ['typ' => 'flow-prefill+jwt']);

    $this->verifier()->verify($forged, TestCase::REF);
})->throws(InvalidPrefill::class);

it('rejects an HMAC token under a known kid', function () {
    // The classic confusion: HS256 keyed with the public key's text.
    $hmac = JWT::encode($this->claims([]), str_repeat('s', 32), 'HS256', 'k1', ['typ' => 'flow-prefill+jwt']);

    $this->verifier()->verify($hmac, TestCase::REF);
})->throws(InvalidPrefill::class);

it('rejects garbage', function () {
    $this->verifier()->verify('not.a.jwt', TestCase::REF);
})->throws(InvalidPrefill::class);

it('verifies a JWT only in the session whose reference it carries', function () {
    $jwt = $this->jwt([]);
    $verifier = $this->verifier();

    $verifier->verify($jwt, TestCase::REF);
    $verifier->verify($jwt, TestCase::REF); // a retried submit

    expect(fn () => $verifier->verify($jwt, 'gutmann:sess-other'))->toThrow(InvalidPrefill::class);
});

it('refetches the JWKS once for an unknown kid', function () {
    $this->served = [['k1'], ['k1', 'k2']];
    $verifier = $this->verifier();

    $verifier->verify($this->jwt([]), TestCase::REF);
    expect($verifier->verify($this->jwt(['jti' => 'j2'], ['kid' => 'k2']), TestCase::REF))->toBe(['givenName' => 'Erika']);

    // A third unknown kid within the minute does not hit the endpoint again.
    expect(fn () => $verifier->verify($this->jwt(['jti' => 'j3'], ['kid' => 'k3']), TestCase::REF))->toThrow(InvalidPrefill::class);
    Http::assertSentCount(2);
});

it('diffs changed and missing fields only', function () {
    $verified = [
        'givenName' => 'Zoë',
        'familyName' => 'Mustermann',
        'address' => ['street' => 'Hauptstraße', 'postalCode' => '1010'],
        'ageOver18' => true,
    ];
    $submitted = [
        'givenName' => " Zo\u{0065}\u{0308} ",  // decomposed ë, padded: equal after trim + NFC
        'familyName' => 'Musterfrau',
        'address' => ['street' => 'Ringstraße', 'postalCode' => '1010'],
        'ageOver18' => '1',
        'phone' => '+43 1 234',                  // not in the JWT: not compared
    ];

    expect(PrefillVerifier::diff($submitted, $verified))->toBe(['familyName', 'address.street'])
        ->and(PrefillVerifier::diff([], ['familyName' => 'Mustermann']))->toBe(['familyName']);
});
