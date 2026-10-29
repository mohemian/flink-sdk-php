<?php

namespace Mohemian\Flink\Exceptions;

/** Not a JWS signed by one of FLINK's keys: malformed, another algorithm, an unknown key, a bad signature. */
final class InvalidSignature extends FlinkException
{
    public static function malformed(string $alg = 'RS256'): self
    {
        return new self("Not an {$alg} JWS with a key id");
    }

    public static function unknownKey(string $kid): self
    {
        return new self("Unknown key id {$kid}");
    }

    public static function unsupportedKey(string $kid, string $alg = 'RS256'): self
    {
        return new self("Key {$kid} is not an {$alg} key");
    }

    public static function failed(\Throwable $previous): self
    {
        return new self($previous->getMessage(), previous: $previous);
    }
}
