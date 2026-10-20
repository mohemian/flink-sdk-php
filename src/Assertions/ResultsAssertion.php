<?php

namespace Mohemian\Flink\Assertions;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Support\Arrayable;
use Mohemian\Flink\Exceptions\InvalidAssertion;
use Mohemian\Flink\Exceptions\MissingConfiguration;

/**
 * The `flink-result+jwt` a relying party's backend fetches for a completed session (FLNK-861):
 * FLINK's signed statement of the session's attributes, each naming its `urn:flink:source:*` source,
 * with provenance per source. ES256, and no `exp` by design: a record of a past fact rather than a
 * credential. Every attribute is optional: absent means not handed over, never false or empty.
 *
 * @implements Arrayable<string, mixed>
 */
final class ResultsAssertion extends Assertion implements \JsonSerializable, Arrayable
{
    /**
     * @param  array<string, array{value: mixed, source: string}>  $attributes
     * @param  array<string, array<string, mixed>>  $sources
     * @param  list<string>  $derivedAttributes  the attributes FLINK derived (an `ageOver*` from a
     *                                           verified birthdate) rather than read from a source
     * @param  ?string  $derivedAsOf  the UTC day those were derived; a derived `ageOver18` holds as of it
     */
    public function __construct(
        public readonly array $attributes,
        public readonly array $sources,
        public readonly string $sessionId,
        public readonly string $journeyId,
        public readonly string $id,
        public readonly CarbonImmutable $issuedAt,
        public readonly array $derivedAttributes = [],
        public readonly ?string $derivedAsOf = null,
    ) {}

    public static function type(): string
    {
        return 'flink-result+jwt';
    }

    public static function algorithm(): string
    {
        return 'ES256';
    }

    public static function tenantClaim(): string
    {
        return 'tenantId';
    }

    public static function expectations(Container $app): array
    {
        $config = $app->make('config');

        return [
            'aud' => fn (): mixed => $config->get('flink.results.audience') ?: throw MissingConfiguration::for('FLINK_RESULTS_AUDIENCE'),
            // `aud` is the bare client id and names no journey: a result is bound to its journey here.
            'journeys' => fn (): array => self::list($config->get('flink.prefill.journeys')) ?: throw MissingConfiguration::for('FLINK_JOURNEYS'),
            // The session the caller fetched the result for, when it names one.
            'session' => fn (): ?string => null,
        ];
    }

    public static function fromClaims(array $claims, array $expected): static
    {
        self::expect($claims, 'ver', 1);
        self::expect($claims, 'aud', $expected['aud']);
        if (! in_array($claims['journeyId'] ?? null, $expected['journeys'], true)) {
            throw InvalidAssertion::mismatch('journeyId');
        }
        if ($expected['session'] !== null) {
            self::expect($claims, 'sessionId', $expected['session']);
        }
        $derived = $claims['derivedAttributes'] ?? [];

        /** @var array<string, array{value: mixed, source: string}> $attributes */
        $attributes = self::arrayClaim($claims, 'attributes');
        /** @var array<string, array<string, mixed>> $sources */
        $sources = self::arrayClaim($claims, 'sources');

        return new self(
            $attributes,
            $sources,
            self::stringClaim($claims, 'sessionId'),
            self::stringClaim($claims, 'journeyId'),
            self::stringClaim($claims, 'jti'),
            CarbonImmutable::createFromTimestamp(self::intClaim($claims, 'iat')),
            is_array($derived) ? array_values(array_filter($derived, is_string(...))) : [],
            is_string($claims['derivedAsOf'] ?? null) ? $claims['derivedAsOf'] : null,
        );
    }

    /** An attribute's value, or a part of it by dotted key (`givenName`, `address.locality`). */
    public function get(string $key, mixed $default = null): mixed
    {
        [$name, $part] = array_pad(explode('.', $key, 2), 2, null);

        return data_get($this->attributes, $part === null ? "{$name}.value" : "{$name}.value.{$part}", $default);
    }

    /** The `urn:flink:source:*` an attribute came from, or null when it was not handed over. */
    public function source(string $name): ?string
    {
        $source = $this->attributes[$name]['source'] ?? null;

        return is_string($source) ? $source : null;
    }

    /** @return array<string, array{value: mixed, source: string}> the verified attributes */
    public function toArray(): array
    {
        return $this->attributes;
    }

    /** @return array<string, array{value: mixed, source: string}> */
    public function jsonSerialize(): array
    {
        return $this->attributes;
    }
}
