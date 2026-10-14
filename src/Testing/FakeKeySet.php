<?php

declare(strict_types=1);

namespace Mohemian\Flink\Testing;

use Firebase\JWT\JWK;
use Firebase\JWT\Key;
use Mohemian\Flink\Contracts\KeySet;
use Mohemian\Flink\Exceptions\InvalidSignature;

/** The fake's one key, in place of FLINK's JWKS. */
final readonly class FakeKeySet implements KeySet
{
    /** @param array<string, string> $jwk */
    public function __construct(private array $jwk) {}

    public function key(string $kid): Key
    {
        if ($kid !== $this->jwk['kid']) {
            throw InvalidSignature::unknownKey($kid);
        }

        return JWK::parseKey($this->jwk) ?? throw InvalidSignature::unsupportedKey($kid);
    }

    public function forget(): void
    {
        // Nothing is cached: the fake's one key lives in memory for the test's lifetime.
    }
}
