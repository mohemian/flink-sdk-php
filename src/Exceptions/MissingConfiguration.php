<?php

namespace Mohemian\Flink\Exceptions;

/**
 * The package is not configured. Deliberately not a FlinkException, so that code refusing invalid
 * tokens does not quietly refuse every token when an environment variable is missing.
 */
final class MissingConfiguration extends \LogicException
{
    public static function for(string $variable): self
    {
        return new self("Set {$variable}");
    }
}
