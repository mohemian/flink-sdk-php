<?php

declare(strict_types=1);

namespace Mohemian\Flink\Results;

use Carbon\CarbonImmutable;

final readonly class FlowSessionStep
{
    public function __construct(
        public string $id,
        public bool $completed,
        public ?CarbonImmutable $startedAt,
        public ?CarbonImmutable $completedAt,
        /** When the applicant refused the step's consent. */
        public ?CarbonImmutable $declinedAt,
    ) {}
}
