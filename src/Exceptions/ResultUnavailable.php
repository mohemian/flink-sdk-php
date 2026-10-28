<?php

namespace Mohemian\Flink\Exceptions;

/**
 * FLINK did not hand over a session's result: not ready yet (`409`, with the session's
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
    ) {
        parent::__construct($message, previous: $previous);
    }

    public static function notReady(?string $sessionStatus): self
    {
        return new self('The session has no result yet (status '.($sessionStatus ?? 'unknown').')', 409, $sessionStatus);
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
