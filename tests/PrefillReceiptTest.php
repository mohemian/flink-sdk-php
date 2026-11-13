<?php

use Mohemian\Flink\Exceptions\InvalidAssertion;
use Mohemian\Flink\Facades\Flink;
use Mohemian\Flink\Tests\TestCase;

beforeEach(fn () => config(['flink.prefill.accept_v2' => true]));

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

it('restores legacy prefills kept in server sessions before the SDK upgrade', function (): void {
    $current = Flink::prefill($this->jwt([]), TestCase::REF);
    // The seven public properties serialized by the original v1 PrefillAssertion.
    $properties = [
        'data' => $current->data, 'journey' => $current->journey, 'method' => $current->method,
        'reference' => $current->reference, 'sessionId' => $current->sessionId,
        'id' => $current->id, 'expiresAt' => $current->expiresAt,
    ];
    $class = $current::class;
    $serialized = 'O:'.strlen($class).':"'.$class.'":'.count($properties).':{';
    foreach ($properties as $key => $value) {
        $serialized .= serialize($key).serialize($value);
    }
    $restored = unserialize($serialized.'}');
    expect($restored->version)->toBe(1)
        ->and($restored->provenance)->toBe([])
        ->and($restored->identityVerified('givenName'))->toBeTrue()
        ->and($restored->changed(['givenName' => 'Erika']))->toBe([]);
});

it('keeps declared provenance after a v2 prefill is restored from a server session', function (): void {
    $original = Flink::prefill($this->jwt(receiptClaims()), TestCase::REF);
    $restored = unserialize(serialize($original));
    expect($restored->version)->toBe(2)
        ->and($restored->source('address'))->toBe($original->source('address'))
        ->and($restored->identityVerified('address'))->toBeFalse()
        ->and($restored->changed($original->data))->toBe([]);
});

it('refuses a v2 receipt until it is enabled, whatever methods are allowed', function (?bool $enabled): void {
    config(['flink.prefill.accept_v2' => $enabled]);
    expect(fn () => Flink::prefill($this->jwt(receiptClaims()), TestCase::REF))
        ->toThrow(InvalidAssertion::class, 'not enabled');
    config(['flink.prefill.accept_v2' => true]);
    expect(Flink::prefill($this->jwt(receiptClaims()), TestCase::REF)->version)->toBe(2);
})->with([false, null]);

it('never treats the email an eID or wallet journey signs as identity-verified', function (string $method, string $source, bool $verified): void {
    config(['flink.prefill.allowed_methods' => ['eid', 'wallet']]);
    $claims = receiptClaims();
    $claims['method'] = $method;
    $claims['data']['email'] = 'erika@example.test';
    $claims['provenance']['email'] = ['source' => $source, 'obtainedAt' => '2026-11-13T12:00:00Z'];
    expect(Flink::prefill($this->jwt($claims), TestCase::REF)->identityVerified('email'))->toBe($verified);
})->with([
    'eid, email source' => ['eid', 'urn:flink:source:email-verification:flink', false],
    'wallet, email source' => ['wallet', 'urn:flink:source:email-verification:flink', false],
    'eid, wallet source' => ['eid', 'urn:flink:source:wallet:openid4vp', false],
    'wallet, eid source' => ['wallet', TestCase::EID, false],
    'eid, eid source' => ['eid', TestCase::EID, true],
    'wallet, wallet source' => ['wallet', 'urn:flink:source:wallet:openid4vp', true],
]);

it('refuses a provenance check it does not know', function (): void {
    $claims = receiptClaims();
    $claims['method'] = 'eid';
    $claims['provenance']['givenName']['check'] = 'other';
    expect(fn () => Flink::prefill($this->jwt($claims), TestCase::REF))->toThrow(InvalidAssertion::class);
});

it('keeps email out of a v1 prefill\'s identity-verified fields', function (string $field, bool $verified): void {
    $claims = $this->claims(['data' => ['givenName' => 'Erika', 'email' => 'e@example.test', 'emailVerified' => true]]);
    expect(Flink::prefill($this->jwt($claims), TestCase::REF)->identityVerified($field))->toBe($verified);
})->with([['givenName', true], ['email', false], ['emailVerified', false]]);

it('refuses to treat a v1 prefill from a document or device route as identity-verified', function (string $method, bool $verified): void {
    config(['flink.prefill.allowed_methods' => ['eid', 'wallet', 'nfc', 'doc']]);
    $claims = $this->claims(['method' => $method]);
    expect(Flink::prefill($this->jwt($claims), TestCase::REF)->identityVerified('givenName'))->toBe($verified);
})->with([['eid', true], ['wallet', true], ['nfc', false], ['doc', false]]);
