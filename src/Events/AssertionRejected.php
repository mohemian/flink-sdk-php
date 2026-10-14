<?php

namespace Mohemian\Flink\Events;

use Mohemian\Flink\Assertions\Assertion;
use Mohemian\Flink\Exceptions\FlinkException;

/**
 * Dispatched when `flink.events` is on. Carries strings only, never the exception, the JWT or its
 * claims, so it is safe to log and serializes for a queued listener.
 */
final class AssertionRejected
{
    /**
     * @param  class-string<Assertion>  $type  the assertion that was expected
     * @param  string  $reason  which check failed
     * @param  class-string<FlinkException>  $exception
     */
    public function __construct(
        public readonly string $type,
        public readonly string $reason,
        public readonly string $exception,
    ) {
    }
}
