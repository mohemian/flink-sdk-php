<?php

declare(strict_types=1);

namespace Mohemian\Flink\Exceptions;

/**
 * FLINK will not start a session behind a FLINK link (`Flink::startSession()`, FLNK-1182). Not a
 * FlinkException: nothing was verified. `reason` is FLINK's code: `unknown_country`,
 * `unknown_eid_entry`, `eid_entry_not_redirect` (an eID FLINK cannot link to, such as Swedish
 * BankID), `journey_needs_interaction` (`step` names the step that needs the person),
 * `no_return_link`, `journey_not_runnable`. Handle a code you do not know.
 */
final class SessionRefused extends \RuntimeException
{
    public function __construct(
        public readonly string $reason,
        public readonly ?string $step = null,
    ) {
        parent::__construct("FLINK refused to start the session ({$reason})");
    }
}
