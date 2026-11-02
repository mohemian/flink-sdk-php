<?php

declare(strict_types=1);

namespace Mohemian\Flink\Results;

/**
 * The countries a journey covers and the eIDs it offers in each, as FLINK's own web flow offers them
 * (`Flink::fetchCountries()`, FLNK-1181).
 */
final readonly class JourneyCountries
{
    public function __construct(
        /** @var list<JourneyCountry> in the journey's order, its primary market first */
        public array $countries,
        /**
         * FLINK's own name for a country where locale data gets it wrong or lacks it, keyed by
         * uppercase alpha-2. Name every other country with your own locale data.
         *
         * @var array<string, array<string, string>>
         */
        public array $countryLabels,
    ) {}

    /** FLINK's countries answer, or null when it is not one. */
    public static function fromResponse(mixed $body): ?self
    {
        if (! is_array($body) || ! is_array($body['countries'] ?? null)) {
            return null;
        }
        $countries = [];
        foreach ($body['countries'] as $country) {
            if (! is_array($country) || ! is_string($country['country'] ?? null) || ! is_array($country['eids'] ?? null)) {
                return null;
            }
            $eids = [];
            foreach ($country['eids'] as $eid) {
                $label = is_array($eid) ? self::text($eid['label'] ?? null) : null;
                if ($label === null || ! is_int($eid['id'] ?? null) || ! is_string($eid['kind'] ?? null)) {
                    return null;
                }
                $logo = $eid['logo_uri'] ?? null;
                $eids[] = new Eid($eid['id'], $label, is_string($logo) ? $logo : null, $eid['kind']);
            }
            $countries[] = new JourneyCountry($country['country'], $eids);
        }
        $labels = [];
        foreach (is_array($body['country_labels'] ?? null) ? $body['country_labels'] : [] as $code => $label) {
            $text = self::text($label);
            if (is_string($code) && $text !== null) {
                $labels[$code] = $text;
            }
        }

        return new self($countries, $labels);
    }

    /**
     * Text per locale, its string values only; null without an `en`.
     *
     * @return array<string, string>|null
     */
    private static function text(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }
        $text = array_filter($value, fn (mixed $v, mixed $k): bool => is_string($k) && is_string($v), ARRAY_FILTER_USE_BOTH);

        return isset($text['en']) ? $text : null;
    }
}
