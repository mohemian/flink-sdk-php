<?php

namespace Mohemian\Flink\Exceptions;

/** Signed by FLINK, but not the assertion you asked for, not for you, or not (or no longer) valid. */
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

    /** Not chained to php-jwt's ExpiredException: that carries the decoded claims, personal data included. */
    public static function expired(): self
    {
        return new self('The assertion has expired');
    }

    /** Issued later than this server's clock allows; not chained, for the same reason as expired(). */
    public static function notYetValid(): self
    {
        return new self('The assertion is not yet valid');
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
