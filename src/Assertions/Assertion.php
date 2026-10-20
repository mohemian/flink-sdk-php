<?php

declare(strict_types=1);

namespace Mohemian\Flink\Assertions;

use Illuminate\Contracts\Container\Container;
use Mohemian\Flink\Exceptions\InvalidAssertion;
use Mohemian\Flink\Exceptions\MissingConfiguration;

/**
 * One kind of FLINK assertion. The shared checks (signature, algorithm, `typ`, `iss`, tenant) run before
 * `fromClaims()`; a subclass checks only what is specific to its type and returns itself.
 */
abstract class Assertion
{
    /** The JWS `typ` header this kind of assertion carries. */
    abstract public static function type(): string;

    /** The one algorithm FLINK signs this kind with: the prefill RS256, the results ES256. */
    public static function algorithm(): string
    {
        return 'RS256';
    }

    /** The claim naming FLINK's tenant. */
    public static function tenantClaim(): string
    {
        return 'tenant_id';
    }

    /**
     * What the claims must match by default, read from config (and, for a prefill, the session).
     * Each is a closure, so a default the caller overrides is never read and its configuration is
     * not required.
     *
     * @return array<string, \Closure(): mixed>
     */
    abstract public static function expectations(Container $app): array;

    /**
     * The expectations to check: `$overrides` (from the caller of `Flink::verify()`), and the
     * default for every key they do not supply.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     *
     * @throws MissingConfiguration
     */
    public static function expected(Container $app, array $overrides = []): array
    {
        foreach (static::expectations($app) as $key => $default) {
            if (! array_key_exists($key, $overrides)) {
                $overrides[$key] = $default();
            }
        }

        return $overrides;
    }

    /**
     * @param  array<string, mixed>  $claims  already verified by `Verifier`
     * @param  array<string, mixed>  $expected
     *
     * @throws InvalidAssertion
     */
    abstract public static function fromClaims(array $claims, array $expected): static;

    /** @param array<string, mixed> $claims */
    protected static function expect(array $claims, string $claim, mixed $value): void
    {
        if ($value === null || ($claims[$claim] ?? null) !== $value) {
            throw InvalidAssertion::mismatch($claim);
        }
    }

    /**
     * @param  array<string, mixed>  $claims
     * @return array<string, mixed>
     */
    protected static function arrayClaim(array $claims, string $claim): array
    {
        return is_array($claims[$claim] ?? null) ? $claims[$claim] : throw InvalidAssertion::missing($claim);
    }

    /** @param array<string, mixed> $claims */
    protected static function stringClaim(array $claims, string $claim): string
    {
        return is_string($claims[$claim] ?? null) ? $claims[$claim] : throw InvalidAssertion::missing($claim);
    }

    /** @param array<string, mixed> $claims */
    protected static function intClaim(array $claims, string $claim): int
    {
        return is_int($claims[$claim] ?? null) ? $claims[$claim] : throw InvalidAssertion::missing($claim);
    }
}
