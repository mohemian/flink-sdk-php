<?php

namespace Mohemian\Flink\Tests;

use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Http;
use Mohemian\Flink\Exceptions\InvalidPrefill;
use Mohemian\Flink\FlinkServiceProvider;
use Mohemian\Flink\PrefillVerifier;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class PrefillVerifierTest extends TestCase
{
    private const URL = 'https://flink.test';
    private const JWKS = self::URL.'/client/v1/.well-known/flow-results-jwks.json';
    private const ORIGIN = 'https://rp.test';
    private const TENANT = '1ed9dfea-a7f6-401e-a93d-ebb4ee1ebd57';
    private const JOURNEY = 'SCD7DDPK258G';
    private const REF = 'gutmann:sess-4711';

    /** @var array<string, array{private: string, jwk: array<string, string>}> */
    private static array $keys = [];

    /** @var list<list<string>> kids the JWKS endpoint serves, one list per fetch; then just k1 */
    private array $served = [];

    protected function getPackageProviders($app): array
    {
        return [FlinkServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.url', self::ORIGIN.'/apply');
        $app['config']->set('cache.default', 'array');
        $app['config']->set('flink.url', self::URL);
        $app['config']->set('flink.tenant_id', self::TENANT);
        $app['config']->set('flink.journey', self::JOURNEY);
    }

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake([self::JWKS => fn () => Http::response([
            'keys' => array_map(fn ($kid) => self::key($kid)['jwk'], array_shift($this->served) ?? ['k1']),
        ])]);
    }

    public function test_returns_the_verified_data(): void
    {
        $data = ['givenName' => 'Erika', 'familyName' => 'Mustermann', 'address' => ['street' => 'Hauptstraße']];

        $this->assertSame($data, $this->verifier()->verify($this->jwt(['data' => $data]), self::REF));
    }

    /** @return iterable<string, array{0: array<string, mixed>, 1?: array<string, mixed>}> */
    public static function rejected(): iterable
    {
        yield 'wrong iss' => [['iss' => 'https://evil.test']];
        yield 'wrong aud' => [['aud' => 'https://evil.test']];
        yield 'wrong tenant' => [['tenant_id' => 'b0c0c0c0-0000-4000-8000-000000000000']];
        yield 'wrong journey' => [['journey' => 'OTHERJOURNEY']];
        yield 'method not allowed' => [['method' => 'doc']];
        yield 'missing journey' => [['journey' => null]];
        yield 'missing method' => [['method' => null]];
        yield 'missing reference' => [['reference' => null]];
        yield 'expired' => [['exp' => time() - 1]];
        yield 'no exp' => [['exp' => null]];
        yield 'wrong version' => [['ver' => 2]];
        yield 'typ of the results assertion' => [[], ['typ' => 'flow-results+jwt']];
        yield 'unknown kid' => [[], ['kid' => 'k9']];
    }

    /**
     * @param  array<string, mixed>  $claims
     * @param  array<string, mixed>  $header
     */
    #[DataProvider('rejected')]
    public function test_rejects(array $claims, array $header = []): void
    {
        $this->expectException(InvalidPrefill::class);
        $this->verifier()->verify($this->jwt($claims, $header), self::REF);
    }

    public function test_rejects_a_signature_by_another_key(): void
    {
        $forged = JWT::encode(
            $this->claims([]),
            self::key('attacker')['private'],
            'RS256',
            'k1',
            ['typ' => 'flow-prefill+jwt']
        );

        $this->expectException(InvalidPrefill::class);
        $this->verifier()->verify($forged, self::REF);
    }

    public function test_rejects_an_hmac_token_under_a_known_kid(): void
    {
        // The classic confusion: HS256 keyed with the public key's text.
        $hmac = JWT::encode($this->claims([]), str_repeat('s', 32), 'HS256', 'k1', ['typ' => 'flow-prefill+jwt']);

        $this->expectException(InvalidPrefill::class);
        $this->verifier()->verify($hmac, self::REF);
    }

    public function test_rejects_garbage(): void
    {
        $this->expectException(InvalidPrefill::class);
        $this->verifier()->verify('not.a.jwt', self::REF);
    }

    public function test_a_jwt_verifies_only_in_the_session_whose_reference_it_carries(): void
    {
        $jwt = $this->jwt([]);
        $verifier = $this->verifier();

        $verifier->verify($jwt, self::REF);
        $verifier->verify($jwt, self::REF); // a retried submit

        $this->expectException(InvalidPrefill::class);
        $verifier->verify($jwt, 'gutmann:sess-other');
    }

    public function test_refetches_the_jwks_once_for_an_unknown_kid(): void
    {
        $this->served = [['k1'], ['k1', 'k2']];
        $verifier = $this->verifier();

        $verifier->verify($this->jwt([]), self::REF);
        $this->assertSame(
            ['givenName' => 'Erika'],
            $verifier->verify($this->jwt(['jti' => 'j2'], ['kid' => 'k2']), self::REF)
        );

        // A third unknown kid within the minute does not hit the endpoint again.
        try {
            $verifier->verify($this->jwt(['jti' => 'j3'], ['kid' => 'k3']), self::REF);
            $this->fail('k3 verified');
        } catch (InvalidPrefill) {
        }
        Http::assertSentCount(2);
    }

    public function test_diff_reports_changed_and_missing_fields_only(): void
    {
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

        $this->assertSame(
            ['familyName', 'address.street'],
            PrefillVerifier::diff($submitted, $verified)
        );
        $this->assertSame(['familyName'], PrefillVerifier::diff([], ['familyName' => 'Mustermann']));
    }

    private function verifier(): PrefillVerifier
    {
        return $this->app->make(PrefillVerifier::class);
    }

    /**
     * @param  array<string, mixed>  $overrides  null removes the claim
     * @return array<string, mixed>
     */
    private function claims(array $overrides): array
    {
        $claims = array_merge([
            'iss' => self::URL,
            'aud' => self::ORIGIN,
            'iat' => time(),
            'exp' => time() + 300,
            'jti' => 'j1',
            'ver' => 1,
            'tenant_id' => self::TENANT,
            'session_id' => '7d5c3a8e-0000-4000-8000-000000000001',
            'journey' => self::JOURNEY,
            'method' => 'eid',
            'reference' => self::REF,
            'data' => ['givenName' => 'Erika'],
            'nonce' => 'state',
        ], $overrides);

        return array_filter($claims, fn ($v) => $v !== null);
    }

    /**
     * @param  array<string, mixed>  $claims
     * @param  array<string, mixed>  $header
     */
    private function jwt(array $claims, array $header = []): string
    {
        $kid = $header['kid'] ?? 'k1';

        return JWT::encode(
            $this->claims($claims),
            self::key($kid)['private'],
            'RS256',
            $kid,
            ['typ' => $header['typ'] ?? 'flow-prefill+jwt']
        );
    }

    /** @return array{private: string, jwk: array<string, string>} */
    private static function key(string $kid): array
    {
        if (! isset(self::$keys[$kid])) {
            $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            openssl_pkey_export($key, $private);
            $rsa = openssl_pkey_get_details($key)['rsa'];
            $b64 = fn (string $bin) => rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
            self::$keys[$kid] = [
                'private' => $private,
                'jwk' => ['kty' => 'RSA', 'kid' => $kid, 'alg' => 'RS256', 'use' => 'sig', 'n' => $b64($rsa['n']), 'e' => $b64($rsa['e'])],
            ];
        }

        return self::$keys[$kid];
    }
}
