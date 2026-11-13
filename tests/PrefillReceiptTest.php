<?php

use Mohemian\Flink\Exceptions\InvalidAssertion;
use Mohemian\Flink\Facades\Flink;
use Mohemian\Flink\Tests\TestCase;

function receiptClaims(): array
{
    return [
        'ver' => 2,
        'data' => ['givenName' => 'Erika', 'address' => ['street' => 'New Street 2']],
        'provenance' => [
            'givenName' => ['source' => TestCase::EID, 'obtainedAt' => '2026-11-13T12:00:00Z'],
            'address' => ['source' => 'urn:flink:source:address-capture:applicant', 'check' => 'applicant_declared', 'obtainedAt' => '2026-11-13T12:01:00Z'],
        ],
    ];
}

it('authenticates declared prefill and compares it separately from identity verification', function (): void {
    $prefill = Flink::prefill($this->jwt(receiptClaims()), TestCase::REF);
    expect($prefill->version)->toBe(2)
        ->and($prefill->data)->toBe(receiptClaims()['data'])
        ->and($prefill->source('address.street'))->toBe('urn:flink:source:address-capture:applicant')
        ->and($prefill->check('address'))->toBe('applicant_declared')
        ->and($prefill->identityVerified('address'))->toBeFalse()
        ->and($prefill->identityVerified('givenName'))->toBeTrue()
        ->and($prefill->identityVerified('birthdate'))->toBeFalse()
        ->and($prefill->identityVerified('givenName.missing'))->toBeFalse()
        ->and($prefill->changed($prefill->data))->toBe([])
        ->and($prefill->changed(['givenName' => 'Erika', 'address' => ['street' => 'Changed Street 3']]))->toBe(['address.street']);
});

it('does not upgrade device assertions, declarations or pending document review', function (string $method, string $source, ?string $check, ?bool $reviewRequired, bool $verified): void {
    config(['flink.prefill.allowed_methods' => ['eid', 'wallet', 'nfc', 'doc']]);
    $claims = receiptClaims();
    $claims['method'] = $method;
    $claims['provenance']['address'] = array_filter([
        'source' => $source, 'check' => $check, 'reviewRequired' => $reviewRequired,
        'obtainedAt' => '2026-11-13T12:00:00Z',
    ], fn (string|bool|null $value): bool => $value !== null);
    expect(Flink::prefill($this->jwt($claims), TestCase::REF)->identityVerified('address'))->toBe($verified);
})->with([
    'wallet' => ['wallet', 'urn:flink:source:wallet:openid4vp', null, null, true],
    'device NFC' => ['nfc', 'urn:flink:source:nfc:device', 'device_asserted', null, false],
    'server document' => ['doc', 'urn:flink:source:document:regula', 'server_verified', false, true],
    'document needs review' => ['doc', 'urn:flink:source:document:regula', 'server_verified', true, false],
    'device document' => ['doc', 'urn:flink:source:document:device', 'device_asserted', null, false],
    'conflicting declaration' => ['eid', TestCase::EID, 'applicant_declared', null, false],
]);

it('recognizes an identity-provider address independently of the form comparison', function (): void {
    $claims = receiptClaims();
    $claims['provenance']['address'] = ['source' => TestCase::EID, 'obtainedAt' => '2026-11-13T12:00:00Z'];
    $prefill = Flink::prefill($this->jwt($claims), TestCase::REF);
    expect($prefill->identityVerified('address'))->toBeTrue()
        ->and($prefill->changed(['givenName' => 'Erika']))->toBe(['address.street']);
});

it('accepts a receipt without an identity route without claiming identity verification', function (): void {
    $claims = receiptClaims();
    $claims['method'] = null;
    $claims['data'] = ['address' => $claims['data']['address']];
    $claims['provenance'] = ['address' => $claims['provenance']['address']];
    $prefill = Flink::prefill($this->jwt($claims), TestCase::REF);
    expect($prefill->method)->toBeNull()
        ->and($prefill->identityVerified('address'))->toBeFalse()
        ->and($prefill->changed($prefill->data))->toBe([]);
});

it('refuses missing, inconsistent or malformed receipt provenance', function (array $provenance): void {
    Flink::prefill($this->jwt(array_replace(receiptClaims(), ['provenance' => $provenance])), TestCase::REF);
})->throws(InvalidAssertion::class)->with([
    'missing fields' => [[]],
    'missing address' => [['givenName' => ['source' => TestCase::EID, 'obtainedAt' => '2026-11-13T12:00:00Z']]],
    'wrong shape' => [['givenName' => 'eid', 'address' => 'declared']],
    'unknown source' => [['givenName' => ['source' => '', 'obtainedAt' => '2026-11-13T12:00:00Z'], 'address' => ['source' => 'urn:flink:source:address-capture:applicant', 'obtainedAt' => '2026-11-13T12:00:00Z']]],
]);

it('keeps legacy prefill behavior and refuses unknown token versions', function (): void {
    $legacy = Flink::prefill($this->jwt([]), TestCase::REF);
    expect($legacy->version)->toBe(1)
        ->and($legacy->source('givenName'))->toBeNull()
        ->and($legacy->changed(['givenName' => 'Erika']))->toBe([])
        ->and(fn () => Flink::prefill($this->jwt(['ver' => 3]), TestCase::REF))->toThrow(InvalidAssertion::class);
});
