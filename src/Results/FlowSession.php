<?php

declare(strict_types=1);

namespace Mohemian\Flink\Results;

use Carbon\CarbonImmutable;

/** A FLINK session's state, without any of its results (`Flink::fetchSession()`, FLNK-859). */
final readonly class FlowSession
{
    /** @param list<FlowSessionStep> $steps */
    public function __construct(
        public string $sessionId,
        /**
         * `provisioned`, `started`, `completed` or `aborted` today. `aborted` is final: the session
         * went idle and takes nothing more. Handle a status you do not know rather than failing on it.
         */
        public string $status,
        public array $steps,
        public CarbonImmutable $createdAt,
        public CarbonImmutable $updatedAt,
        public ?CarbonImmutable $completedAt,
    ) {}

    /** FLINK's session answer, or null when it is not one for `$sessionId`. */
    public static function fromResponse(mixed $body, string $sessionId): ?self
    {
        if (! is_array($body) || ! is_string($body['id'] ?? null) || strtolower($body['id']) !== strtolower($sessionId)
            || ! is_string($body['status'] ?? null) || ! is_array($body['steps'] ?? null)
            || ! is_string($body['created_at'] ?? null) || ! is_string($body['updated_at'] ?? null)) {
            return null;
        }
        $steps = [];
        foreach ($body['steps'] as $step) {
            if (! is_array($step) || ! is_string($step['id'] ?? null) || ! is_bool($step['completed'] ?? null)) {
                return null;
            }
            $steps[] = new FlowSessionStep(
                $step['id'],
                $step['completed'],
                self::time($step['started_at'] ?? null),
                self::time($step['completed_at'] ?? null),
                self::time($step['declined_at'] ?? null),
            );
        }

        return new self(
            strtolower($body['id']),
            $body['status'],
            $steps,
            CarbonImmutable::parse($body['created_at']),
            CarbonImmutable::parse($body['updated_at']),
            self::time($body['completed_at'] ?? null),
        );
    }

    private static function time(mixed $value): ?CarbonImmutable
    {
        return is_string($value) ? CarbonImmutable::parse($value) : null;
    }
}
