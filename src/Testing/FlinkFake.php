<?php

namespace Mohemian\Flink\Testing;

use Firebase\JWT\JWT;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Str;
use Mohemian\Flink\Assertions\PrefillAssertion;
use Mohemian\Flink\Assertions\ResultsAssertion;
use Mohemian\Flink\Flink;
use Mohemian\Flink\Verification\Verifier;

/**
 * Signs assertions the app under test accepts, from `Flink::fake()`. Every claim defaults to what
 * the app's config expects; pass `$claims` to override one, or `null` to drop it.
 */
final readonly class FlinkFake
{
    private const KID = 'flink-fake';

    public FakeKeySet $keys;

    private string $privateKey;

    public function __construct(private Container $app)
    {
        $pair = KeyPair::generate(self::KID);
        $this->privateKey = $pair['private'];
        $this->keys = new FakeKeySet($pair['jwk']);
    }

    /**
     * A prefill for this session's reference (issued if there is none yet), the first configured
     * journey and the first allowed method.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $claims
     */
    public function prefill(array $data = ['givenName' => 'Erika', 'familyName' => 'Mustermann'], array $claims = []): string
    {
        $expected = PrefillAssertion::expectations($this->app);

        return $this->sign(PrefillAssertion::type(), $claims + [
            'aud' => $expected['aud'],
            'exp' => time() + 300,
            'journey' => $expected['journeys'][0],
            'method' => $expected['methods'][0] ?? 'eid',
            'reference' => $expected['reference'] ?? $this->app->make(Flink::class)->reference(),
            'data' => $data,
        ]);
    }

    /**
     * @param  array<string, mixed>  $results
     * @param  array<string, mixed>  $claims
     */
    public function results(array $results = [], array $claims = []): string
    {
        return $this->sign(ResultsAssertion::type(), $claims + [
            'aud' => ResultsAssertion::expectations($this->app)['aud'],
            'results' => $results,
        ]);
    }

    /** @param array<string, mixed> $claims */
    private function sign(string $type, array $claims): string
    {
        $verifier = $this->app->make(Verifier::class);
        $claims += [
            'iss' => $verifier->issuer,
            'iat' => time(),
            'jti' => (string) Str::uuid(),
            'ver' => 1,
            'tenant_id' => $verifier->tenantId,
            'session_id' => (string) Str::uuid(),
        ];

        return JWT::encode(array_filter($claims, fn ($v): bool => $v !== null), $this->privateKey, 'RS256', self::KID, ['typ' => $type]);
    }
}
