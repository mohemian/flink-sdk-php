<?php

declare(strict_types=1);

namespace Mohemian\Flink\Results;

/** One eID a person can choose in a country of a journey (`Flink::fetchCountries()`, FLNK-1181). */
final readonly class Eid
{
    public function __construct(
        /** FLINK's id for this eID in the journey. */
        public int $id,
        /**
         * Its name as the person knows it, per BCP 47 locale tag. `en` is always there; ignore a tag
         * you do not know.
         *
         * @var array<string, string>
         */
        public array $label,
        /** Its logo, to show beside the label; null when it has none. */
        public ?string $logoUri,
        /**
         * `redirect`: FLINK sends the person to the provider. `login`: FLINK runs the login on its own
         * page. Handle a kind you do not know rather than failing on it.
         */
        public string $kind,
    ) {}
}
