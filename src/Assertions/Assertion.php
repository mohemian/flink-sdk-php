<?php

declare(strict_types=1);

namespace Mohemian\Flink\Assertions;

use Illuminate\Contracts\Container\Container;
use Mohemian\Flink\Exceptions\InvalidAssertion;
use Mohemian\Flink\Exceptions\MissingConfiguration;

/**
 * One kind of FLINK assertion. The shared checks (signature, `typ`, `iss`, tenant) run before
 * `fromClaims()`; a subclass checks only what is specific to its type and returns itself.
 */
abstract class Assertion
{
    /** The JWS `typ` header this kind of assertion carries. */
    abstract public static function type(): string;

    /**
     * What the claims must match by default, read from config (and, for a prefill, the session).
     * The caller of `Flink::verify()` can override any of them.
     *
     * @return array<string, mixed>
     *
     * @throws MissingConfiguration
     */
    abstract public static function expectations(Container $app): array;

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
