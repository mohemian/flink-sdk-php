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
    /** @param list<array<string, string>> $jwks */
    public function __construct(private array $jwks) {}

    public function key(string $kid, string $alg): Key
    {
        foreach ($this->jwks as $jwk) {
            if ($jwk['kid'] === $kid) {
                $key = JWK::parseKey($jwk);

                return $key instanceof Key && $key->getAlgorithm() === $alg ? $key : throw InvalidSignature::unsupportedKey($kid, $alg);
            }
        }

        throw InvalidSignature::unknownKey($kid);
    }

    public function forget(): void
    {
        // Nothing is cached: the fake's keys live in memory for the test's lifetime.
    }
}
