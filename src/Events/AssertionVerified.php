<?php

namespace Mohemian\Flink\Events;

use Mohemian\Flink\Assertions\Assertion;

/** Dispatched when `flink.events` is on. Carries the verified assertion, never the JWT. */
final class AssertionVerified
{
    public function __construct(public readonly Assertion $assertion)
    {
    }
}
