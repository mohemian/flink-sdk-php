<?php

namespace Mohemian\Flink\Tests;

use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Http;
use Mohemian\Flink\Facades\Flink;
use Mohemian\Flink\FlinkServiceProvider;
use Mohemian\Flink\Testing\KeyPair;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    public const URL = 'https://flink.test';

    public const JWKS = self::URL.'/client/v1/.well-known/flow-results-jwks.json';

    public const ORIGIN = 'https://rp.test';

    public const RP = 'rp-backend';

    public const TENANT = '1ed9dfea-a7f6-401e-a93d-ebb4ee1ebd57';

    public const SESSION = '7d5c3a8e-0000-4000-8000-000000000001';

    public const JOURNEY = 'SCD7DDPK258G';

    public const REF = 'gutmann:sess-4711';

    /** @var array<string, array{private: string, jwk: array<string, string>}> */
    private static array $keys = [];

    /** @var list<list<string>> kids the JWKS endpoint serves, one list per fetch; then just k1 */
    public array $served = [];

    protected function getPackageProviders($app): array
    {
        return [FlinkServiceProvider::class];
    }

    protected function getPackageAliases($app): array
    {
        return ['Flink' => Flink::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.url', self::ORIGIN.'/apply');
        $app['config']->set('cache.default', 'array');
        $app['config']->set('flink.url', self::URL);
        $app['config']->set('flink.tenant_id', self::TENANT);
        $app['config']->set('flink.prefill.journeys', [self::JOURNEY]);
        $app['config']->set('flink.results.audience', self::RP);
    }

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake([self::JWKS => fn () => Http::response([
            'keys' => array_map(fn (string $kid): array => self::key($kid)['jwk'], array_shift($this->served) ?? ['k1']),
        ])]);
    }

    /**
     * @param  array<string, mixed>  $overrides  null removes the claim
     * @return array<string, mixed>
     */
    public function claims(array $overrides): array
    {
        $claims = array_merge([
            'iss' => self::URL,
            'aud' => self::ORIGIN,
            'iat' => time(),
            'exp' => time() + 300,
            'jti' => 'j1',
            'ver' => 1,
            'tenant_id' => self::TENANT,
            'session_id' => self::SESSION,
            'journey' => self::JOURNEY,
            'method' => 'eid',
            'reference' => self::REF,
            'data' => ['givenName' => 'Erika'],
            'nonce' => 'state',
        ], $overrides);

        return array_filter($claims, fn ($v): bool => $v !== null);
    }

    /**
     * @param  array<string, mixed>  $claims
     * @param  array<string, mixed>  $header
     */
    public function jwt(array $claims, array $header = []): string
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

    /**
     * A `flow-results+jwt` as flink-services signs it: no `exp`, `results` instead of `data`.
     *
     * @param  array<string, mixed>  $claims  null removes the claim
     */
    public function resultsJwt(array $claims = [], string $typ = 'flow-results+jwt'): string
    {
        $claims = array_filter(array_merge([
            'iss' => self::URL,
            'aud' => self::RP,
            'iat' => time(),
            'jti' => 'r1',
            'ver' => 1,
            'tenant_id' => self::TENANT,
            'session_id' => self::SESSION,
            'results' => ['identity' => ['givenName' => 'Erika']],
        ], $claims), fn ($v): bool => $v !== null);

        return JWT::encode($claims, self::key('k1')['private'], 'RS256', 'k1', ['typ' => $typ]);
    }

    /** @return array{private: string, jwk: array<string, string>} */
    public static function key(string $kid): array
    {
        return self::$keys[$kid] ??= KeyPair::generate($kid);
    }
}
