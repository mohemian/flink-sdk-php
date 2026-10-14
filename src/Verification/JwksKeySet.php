<?php

declare(strict_types=1);

namespace Mohemian\Flink\Verification;

use Firebase\JWT\JWK;
use Firebase\JWT\Key;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\Factory as Http;
use Mohemian\Flink\Contracts\KeySet;
use Mohemian\Flink\Exceptions\InvalidSignature;
use Mohemian\Flink\Exceptions\KeysUnavailable;

/** FLINK's flow-results keys, cached for `$ttl` (the endpoint's max-age), refetched on an unknown kid. */
final readonly class JwksKeySet implements KeySet
{
    public function __construct(
        private Http $http,
        private Cache $cache,
        private string $url,
        private string $cacheKey,
        private int $ttl,
    ) {}

    public function key(string $kid): Key
    {
        $keys = $this->cache->remember($this->cacheKey, $this->ttl, fn (): array => $this->fetch());
        // An unknown kid is either a rotation or junk: refetch, but at most once a minute.
        if (! isset($keys[$kid]) && $this->cache->add($this->cacheKey.':refetch', true, 60)) {
            $keys = $this->fetch();
            $this->cache->put($this->cacheKey, $keys, $this->ttl);
        }
        if (! isset($keys[$kid])) {
            throw InvalidSignature::unknownKey($kid);
        }

        $key = JWK::parseKey($keys[$kid]);
        if (! $key instanceof Key || $key->getAlgorithm() !== 'RS256') {
            throw InvalidSignature::unsupportedKey($kid);
        }

        return $key;
    }

    public function forget(): void
    {
        $this->cache->forget($this->cacheKey);
        $this->cache->forget($this->cacheKey.':refetch');
    }

    /**
     * @return array<string, array<string, mixed>> the JWKs by kid
     *
     * @throws KeysUnavailable
     */
    private function fetch(): array
    {
        try {
            $keys = $this->http->timeout(5)->get($this->url)->throw()->json('keys');
        } catch (\Throwable $e) {
            throw KeysUnavailable::from($this->url, $e);
        }

        // Only entries with a string kid: array_column() would index the others numerically.
        $keys = is_array($keys) ? array_column(array_filter($keys, fn (mixed $key): bool => is_string($key['kid'] ?? null)), null, 'kid') : [];
        // A response without a usable key set is an outage, not a rotation: never cache it over
        // the keys that work.
        if ($keys === []) {
            throw KeysUnavailable::from($this->url, new \UnexpectedValueException('the response holds no keys'));
        }

        return $keys;
    }
}
