<?php

namespace Mohemian\Flink\Events;

use Mohemian\Flink\Assertions\Assertion;
use Mohemian\Flink\Exceptions\FlinkException;

/** Dispatched when `flink.events` is on. Carries the reason, never the JWT, so it is safe to log. */
final class AssertionRejected
{
    /** @param class-string<Assertion> $type */
    public function __construct(
        public readonly string $type,
        public readonly FlinkException $exception,
    ) {
    }
}
