<?php

declare(strict_types=1);

namespace Mohemian\Flink\Events;

use Mohemian\Flink\Assertions\Assertion;

/** Dispatched when `flink.events` is on. Carries the verified assertion, never the JWT. */
final readonly class AssertionVerified
{
    public function __construct(public Assertion $assertion) {}
}
