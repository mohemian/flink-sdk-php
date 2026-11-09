<?php

namespace Mohemian\Flink\Assertions;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Support\Arrayable;
use Mohemian\Flink\Exceptions\InvalidAssertion;
use Mohemian\Flink\Exceptions\MissingConfiguration;
use Mohemian\Flink\Results\ResultClient;

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
     * @param  array<string, array{value: mixed, source: string, derivedAsOf?: string}>  $attributes
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
        $config = $app->make('config');

        return [
            // With an API key (FLNK-859), the key's own subject, as FLINK stamps it on the key's token.
            'aud' => function () use ($app, $config): mixed {
                $audience = $config->get('flink.results.audience');
                if (! $config->get('flink.results.api_key')) {
                    return $audience ?: throw MissingConfiguration::for('FLINK_RESULTS_AUDIENCE');
                }
                // A client id left over from 0.2 is not the key's subject: refuse it rather than every result.
                if ($audience && ! str_starts_with((string) $audience, 'apikey:')) {
                    throw new MissingConfiguration('Unset FLINK_RESULTS_AUDIENCE: with FLINK_API_KEY the audience is the key\'s subject (apikey:<id>), not a client id');
                }

                return $audience ?: $app->make(ResultClient::class)->audience();
            },
            // `aud` is the bare client id and names no journey: a result is bound to its journey here.
            'journeys' => fn (): array => self::list($config->get('flink.prefill.journeys')) ?: throw MissingConfiguration::for('FLINK_JOURNEYS'),
            // The session the caller fetched the result for, when it names one.
            'session' => fn (): ?string => null,
        ];
    }

    public static function fromClaims(array $claims, array $expected): static
    {
        self::expect($claims, 'ver', 1);
        // RFC 7519 allows an array: the client id must be in it, which a later intermediary relies on.
        $aud = $claims['aud'] ?? null;
        if ($expected['aud'] === null || ! in_array($expected['aud'], is_array($aud) ? $aud : [$aud], true)) {
            throw InvalidAssertion::mismatch('aud');
        }
        if (! in_array($claims['journeyId'] ?? null, $expected['journeys'], true)) {
            throw InvalidAssertion::mismatch('journeyId');
        }
        if ($expected['session'] !== null) {
            // services signs the stored id and its lookup takes a UUID in any case, so compare without case.
            $signed = $claims['sessionId'] ?? null;
            if (! is_string($signed) || ! is_string($expected['session']) || strtolower($signed) !== strtolower($expected['session'])) {
                throw InvalidAssertion::mismatch('sessionId');
            }
        }
        /** @var array<string, array{value: mixed, source: string, derivedAsOf?: string}> $attributes */
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

    /**
     * The UTC day FLINK derived an attribute on (an `ageOver*` from a verified `birthdate`), or null
     * when it came from its `source` as is. A derived value holds as of that day only.
     */
    public function derivedAsOf(string $name): ?string
    {
        $day = $this->attributes[$name]['derivedAsOf'] ?? null;

        return is_string($day) ? $day : null;
    }

    /** @return array<string, array{value: mixed, source: string, derivedAsOf?: string}> the verified attributes */
    public function toArray(): array
    {
        return $this->attributes;
    }

    /** @return array<string, array{value: mixed, source: string, derivedAsOf?: string}> */
    public function jsonSerialize(): array
    {
        return $this->attributes;
    }
}
