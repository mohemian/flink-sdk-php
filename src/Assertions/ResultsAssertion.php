<?php

namespace Mohemian\Flink\Assertions;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Support\Arrayable;
use Mohemian\Flink\Exceptions\MissingConfiguration;

/**
 * The `flow-results+jwt` that comes with a session's results: FLINK's durable assertion that it
 * produced exactly these results for this session. It has no `exp` by design, being a record of a
 * past fact rather than a credential.
 *
 * @implements Arrayable<string, mixed>
 */
final class ResultsAssertion extends Assertion implements \JsonSerializable, Arrayable
{
    /** @param array<string, mixed> $results */
    public function __construct(
        public readonly array $results,
        public readonly string $sessionId,
        public readonly string $id,
        public readonly CarbonImmutable $issuedAt,
    ) {}

    public static function type(): string
    {
        return 'flow-results+jwt';
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

        return new self(
            self::arrayClaim($claims, 'results'),
            self::stringClaim($claims, 'session_id'),
            self::stringClaim($claims, 'jti'),
            CarbonImmutable::createFromTimestamp(self::intClaim($claims, 'iat')),
        );
    }

    /** A result by dotted key (`identity.givenName`). */
    public function get(string $key, mixed $default = null): mixed
    {
        return data_get($this->results, $key, $default);
    }

    /** @return array<string, mixed> the verified results */
    public function toArray(): array
    {
        return $this->results;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->results;
    }
}
