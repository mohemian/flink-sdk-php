<?php

namespace Mohemian\Flink\Exceptions;

/**
 * FLINK's signing keys could not be fetched: a wrong FLINK_URL or FLINK_JWKS_URL, or an outage.
 * Deliberately not a FlinkException, so an outage is not reported as every applicant's token being
 * invalid.
 */
final class KeysUnavailable extends \RuntimeException
{
    public static function from(string $url, \Throwable $previous): self
    {
        return new self("FLINK's signing keys could not be fetched from {$url}: {$previous->getMessage()}", previous: $previous);
    }
}
