<?php

namespace Mohemian\Flink\Assertions;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Arr;
use Mohemian\Flink\Exceptions\InvalidAssertion;
use Mohemian\Flink\Exceptions\MissingConfiguration;
use Mohemian\Flink\Flink;

/**
 * The `flow-prefill+jwt` an embedded journey hands the page: the data it prefilled, signed by FLINK,
 * so a backend can trust those values rather than whatever the form submitted.
 *
 * @implements Arrayable<string, mixed>
 */
final class PrefillAssertion extends Assertion implements \JsonSerializable, Arrayable
{
    /** @param array<string, mixed> $data */
    public function __construct(
        public readonly array $data,
        public readonly string $journey,
        public readonly string $method,
        public readonly string $reference,
        public readonly string $sessionId,
        /** The JWT's `jti`: one per release, the same on a retry, so accept each for one submission. */
        public readonly string $id,
        public readonly CarbonImmutable $expiresAt,
    ) {}

    public static function type(): string
    {
        return 'flow-prefill+jwt';
    }

    public static function expectations(Container $app): array
    {
        $config = fn (string $key): mixed => $app->make('config')->get("flink.prefill.{$key}");

        return [
            'aud' => fn (): string => self::origin((string) ($config('audience') ?: $app->make('config')->get('app.url'))),
            'journeys' => fn (): array => self::list($config('journeys')) ?: throw MissingConfiguration::for('FLINK_JOURNEYS'),
            'methods' => fn (): array => array_values((array) $config('allowed_methods')),
            'reference' => fn (): ?string => $app->make(Flink::class)->currentReference(),
        ];
    }

    public static function fromClaims(array $claims, array $expected): static
    {
        self::expect($claims, 'ver', 1);
        self::expect($claims, 'aud', $expected['aud']);
        if (! in_array($claims['journey'] ?? null, $expected['journeys'], true)) {
            throw InvalidAssertion::mismatch('journey');
        }
        if (! in_array($claims['method'] ?? null, $expected['methods'], true)) {
            throw InvalidAssertion::methodNotAccepted();
        }
        // A JWT verifies only in the session that launched it, so a stolen or borrowed one is
        // refused, while a retried submit still works.
        if (! is_string($expected['reference'])) {
            throw InvalidAssertion::noReference();
        }
        self::expect($claims, 'reference', $expected['reference']);

        return new self(
            self::arrayClaim($claims, 'data'),
            $claims['journey'],
            $claims['method'],
            $expected['reference'],
            self::stringClaim($claims, 'session_id'),
            self::stringClaim($claims, 'jti'),
            // php-jwt checks `exp` only when present; FLINK always sets it, so its absence is a forgery.
            CarbonImmutable::createFromTimestamp(self::intClaim($claims, 'exp')),
        );
    }

    /** A verified value by dotted key (`address.street`). */
    public function get(string $key, mixed $default = null): mixed
    {
        return data_get($this->data, $key, $default);
    }

    /**
     * The verified fields the submission does not match, as dotted keys (`address.street`). Values
     * are compared as strings after trimming and Unicode NFC normalisation, nothing more; a verified
     * field missing from the submission counts as changed, and fields the JWT does not carry are
     * not compared. `$only` limits the comparison to those verified fields.
     *
     * @param  array<string, mixed>  $submitted
     * @param  list<string>|null  $only
     * @return list<string>
     */
    public function changed(array $submitted, ?array $only = null): array
    {
        $verified = Arr::dot($this->data);
        if ($only !== null) {
            $verified = Arr::only($verified, $only);
        }
        $submitted = Arr::dot($submitted);
        $changed = [];
        foreach ($verified as $field => $value) {
            $theirs = array_key_exists($field, $submitted) ? $this->normalise($submitted[$field]) : null;
            if ($theirs === null || $theirs !== $this->normalise($value)) {
                $changed[] = (string) $field;
            }
        }

        return $changed;
    }

    /** @return array<string, mixed> the verified data */
    public function toArray(): array
    {
        return $this->data;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->data;
    }

    /** `null` for what is not a scalar (a client can post `field[]=`), which never matches. */
    private function normalise(mixed $value): ?string
    {
        if ($value !== null && ! is_scalar($value)) {
            return null;
        }
        if (is_bool($value)) {
            $value = $value ? '1' : '0';
        }
        $string = trim((string) $value);

        return \Normalizer::normalize($string, \Normalizer::FORM_C) ?: $string;
    }

    /**
     * `FLINK_JOURNEYS` is a comma-separated string; a published config may hold an array instead.
     *
     * @return list<string>
     */
    private static function list(mixed $journeys): array
    {
        $list = is_array($journeys) ? $journeys : explode(',', (string) $journeys);

        return array_values(array_filter(array_map(fn ($journey): string => trim((string) $journey), $list), fn (string $journey): bool => $journey !== ''));
    }

    /**
     * `https://www.Example.com:443/apply` → `https://www.example.com`: the browser Origin, which is
     * what FLINK puts in `aud`. Scheme and host lowercased, a default port and the path dropped.
     */
    private static function origin(string $url): string
    {
        $parts = parse_url($url) ?: [];
        $scheme = strtolower($parts['scheme'] ?? 'https');
        $port = $parts['port'] ?? null;
        $default = ['https' => 443, 'http' => 80][$scheme] ?? null;

        return $scheme.'://'.strtolower($parts['host'] ?? '').($port === null || $port === $default ? '' : ':'.$port);
    }
}
