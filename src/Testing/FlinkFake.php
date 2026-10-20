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

    /** The results key's kid (FLNK-861): FLINK signs results ES256, beside the prefill's RS256 key. */
    private const RESULTS_KID = 'flink-fake-results';

    public FakeKeySet $keys;

    private string $privateKey;

    private string $resultsKey;

    public function __construct(private Container $app)
    {
        $pair = KeyPair::generate(self::KID);
        $results = KeyPair::generate(self::RESULTS_KID, 'ES256');
        $this->privateKey = $pair['private'];
        $this->resultsKey = $results['private'];
        $this->keys = new FakeKeySet([$pair['jwk'], $results['jwk']]);
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
        $default = PrefillAssertion::expectations($this->app);

        return $this->sign(PrefillAssertion::type(), $claims + $this->defaults($claims, [
            'aud' => $default['aud'],
            'journey' => fn (): mixed => $default['journeys']()[0],
            'method' => fn (): mixed => $default['methods']()[0] ?? 'eid',
            'reference' => fn (): mixed => $default['reference']() ?? $this->app->make(Flink::class)->reference(),
        ]) + ['exp' => time() + 300, 'data' => $data]);
    }

    /**
     * A result as flink-services signs it (FLNK-861): ES256, camelCase claims, flat attributes.
     *
     * @param  array<string, array{value: mixed, source: string}>  $attributes
     * @param  array<string, mixed>  $claims
     */
    public function results(array $attributes = [], array $claims = []): string
    {
        $verifier = $this->app->make(Verifier::class);
        $sources = [];
        foreach ($attributes as $attribute) {
            $sources[$attribute['source']] = ['obtainedAt' => gmdate('Y-m-d\\TH:i:s.000\\Z')];
        }
        $default = ResultsAssertion::expectations($this->app);
        $claims += $this->defaults($claims, [
            'aud' => $default['aud'],
            'journeyId' => fn (): mixed => $default['journeys']()[0],
        ]) + [
            'iss' => $verifier->issuer,
            'iat' => time(),
            'jti' => (string) Str::uuid(),
            'ver' => 1,
            'tenantId' => $verifier->tenantId,
            'sessionId' => (string) Str::uuid(),
            'completedAt' => gmdate('Y-m-d\\TH:i:s.000\\Z'),
            'attributes' => $attributes,
            'sources' => (object) $sources,
        ];

        return JWT::encode(
            array_filter($claims, fn ($v): bool => $v !== null),
            $this->resultsKey,
            'ES256',
            self::RESULTS_KID,
            ['typ' => ResultsAssertion::type()]
        );
    }

    /**
     * Resolve a default only for a claim the test does not supply, so its configuration is not
     * required then.
     *
     * @param  array<string, mixed>  $claims
     * @param  array<string, \Closure(): mixed>  $defaults
     * @return array<string, mixed>
     */
    private function defaults(array $claims, array $defaults): array
    {
        $resolved = [];
        foreach (array_diff_key($defaults, $claims) as $claim => $default) {
            $resolved[$claim] = $default();
        }

        return $resolved;
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
