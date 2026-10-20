<?php

namespace Mohemian\Flink\Assertions;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Support\Arrayable;
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
     */
    public function __construct(
        public readonly array $attributes,
        public readonly array $sources,
        public readonly string $sessionId,
        public readonly string $journeyId,
        public readonly string $id,
        public readonly CarbonImmutable $issuedAt,
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
        return [
            'aud' => fn (): mixed => $app->make('config')->get('flink.results.audience') ?: throw MissingConfiguration::for('FLINK_RESULTS_AUDIENCE'),
        ];
    }

    public static function fromClaims(array $claims, array $expected): static
    {
        self::expect($claims, 'ver', 1);
        self::expect($claims, 'aud', $expected['aud']);

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
