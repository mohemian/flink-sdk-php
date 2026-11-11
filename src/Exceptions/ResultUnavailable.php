<?php

namespace Mohemian\Flink\Exceptions;

/**
 * FLINK did not hand over a session's result, or its state (`Flink::fetchSession()`): not ready yet (`409`, with the session's
 * `sessionStatus`), refused (a 4xx: a wrong client id or key, a session this client may not read),
 * or an outage (`status` 0 or a 5xx). Deliberately not a FlinkException: nothing was verified, so
 * nothing was found invalid. Never carries the token, the key or FLINK's response body.
 */
final class ResultUnavailable extends \RuntimeException
{
    public function __construct(
        string $message,
        /** FLINK's HTTP status, or 0 when there was no answer. */
        public readonly int $status,
        /** On a `409`: the session's status (`started`, `aborted`, …). */
        public readonly ?string $sessionStatus = null,
        ?\Throwable $previous = null,
        /**
         * On a `409` for a session from `Flink::startSession()` (FLNK-1182), why it has no result:
         * `awaiting_return` (call `completeReturn()` first), `eid_failed`, `sanctions_unavailable`,
         * `return_expired`, `link_expired`. Decide from `sessionStatus` alone whether to ask again:
         * `aborted` is final, and a hosted session (FLNK-1272) that was abandoned reads `started`
         * until its deadline, then `aborted` with reason `link_expired` (`return_expired` if a code was
         * issued and not redeemed). Ignore a reason you do not know.
         */
        public readonly ?string $reason = null,
        /** With `reason` `eid_failed`: FLINK's code for the failed login, e.g. `country_mismatch`. */
        public readonly ?string $eidError = null,
    ) {
        parent::__construct($message, previous: $previous);
    }

    /**
     * FLINK answered 409: the session has no result to read yet. `$reason` says why when FLINK
     * gives one (for a session from `startSession()`: `awaiting_return`, `eid_failed` with
     * `$eidError`, `sanctions_unavailable`, `return_expired`, `link_expired`); handle a reason you
     * do not know.
     */
    public static function notReady(?string $sessionStatus, ?string $reason = null, ?string $eidError = null): self
    {
        return new self(
            'The session has no result yet (status '.($sessionStatus ?? 'unknown').($reason === null ? '' : ", {$reason}").')',
            409,
            $sessionStatus,
            reason: $reason,
            eidError: $eidError,
        );
    }

    public static function answered(string $step, int $status): self
    {
        return new self("FLINK answered the {$step} request with {$status}", $status);
    }

    public static function unreachable(string $step, \Throwable $previous): self
    {
        return new self("FLINK could not be reached for the {$step} request", 0, previous: $previous);
    }
}
