<?php

declare(strict_types=1);

namespace Mohemian\Flink\Exceptions;

/**
 * FLINK will not start a session behind a FLINK link (`Flink::startSession()`, FLNK-1182, FLNK-1272). Not a
 * FlinkException: nothing was verified. `reason` is FLINK's code: `unknown_country`,
 * `unknown_eid_entry`, `eid_entry_not_redirect` (an eID FLINK cannot link to, such as Swedish
 * BankID), `journey_needs_interaction` (`step` names the step that needs the person),
 * `no_return_link`, `journey_not_runnable`. A start without an eID (hosted, FLNK-1272) adds
 * `country_required` (an eID without a country, which this package refuses before asking), `hosted_mode_unavailable` (FLINK has not turned hosted
 * starts on yet: start with a country and an eID, or send a journey link), and, for a journey that
 * requires the person's consent or notice on some route, `notice_required` or `consent_required`
 * (a hosted person picks the route on FLINK's screens, so FLINK cannot collect it). Also
 * `consent_needs_eid_entry`, FLINK's refusal of a hosted start that carries versioned consent capture,
 * which this package does not send. Handle a code you do not know.
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
