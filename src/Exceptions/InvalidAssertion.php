<?php

namespace Mohemian\Flink\Exceptions;

/** Signed by FLINK, but not the assertion you asked for, not for you, or no longer valid. */
final class InvalidAssertion extends FlinkException
{
    public static function wrongType(string $expected): self
    {
        return new self("Not a {$expected}");
    }

    public static function mismatch(string $claim): self
    {
        return new self("Claim {$claim} does not match");
    }

    public static function missing(string $claim): self
    {
        return new self("Claim {$claim} is missing");
    }

    public static function expired(\Throwable $previous): self
    {
        return new self('The assertion has expired', previous: $previous);
    }

    public static function methodNotAccepted(): self
    {
        return new self('The data does not come from an accepted method');
    }

    public static function noReference(): self
    {
        return new self('No FLINK reference in this session to check the prefill against');
    }
}
