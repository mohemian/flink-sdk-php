<?php

declare(strict_types=1);

namespace Mohemian\Flink\Results;

/** A country a journey covers, with the eIDs it offers there (FLNK-1181). */
final readonly class JourneyCountry
{
    /** @param list<Eid> $eids in FLINK's order; empty when the journey offers none there */
    public function __construct(
        /** As session creation takes it: `at`, or a test market `at-test`. */
        public string $country,
        public array $eids,
    ) {}
}
