<?php

namespace Mohemian\Flink\Exceptions;

/** Not a JWS signed by one of FLINK's keys: malformed, another algorithm, an unknown key, a bad signature. */
final class InvalidSignature extends FlinkException
{
    public static function malformed(): self
    {
        return new self('Not an RS256 JWS with a key id');
    }

    public static function unknownKey(string $kid): self
    {
        return new self("Unknown key id {$kid}");
    }

    public static function unsupportedKey(string $kid): self
    {
        return new self("Key {$kid} is not an RS256 key");
    }

    public static function failed(\Throwable $previous): self
    {
        return new self($previous->getMessage(), previous: $previous);
    }
}
