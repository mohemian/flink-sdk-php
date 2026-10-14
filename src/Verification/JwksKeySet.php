<?php

namespace Mohemian\Flink\Verification;

use Firebase\JWT\JWK;
use Firebase\JWT\Key;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\Factory as Http;
use Mohemian\Flink\Contracts\KeySet;
use Mohemian\Flink\Exceptions\InvalidSignature;
use Mohemian\Flink\Exceptions\KeysUnavailable;

/** FLINK's flow-results keys, cached for `$ttl` (the endpoint's max-age), refetched on an unknown kid. */
final class JwksKeySet implements KeySet
{
    public function __construct(
        private readonly Http $http,
        private readonly Cache $cache,
        private readonly string $url,
        private readonly string $cacheKey,
        private readonly int $ttl,
    ) {
    }

    public function key(string $kid): Key
    {
        $keys = $this->cache->remember($this->cacheKey, $this->ttl, fn () => $this->fetch());
        // An unknown kid is either a rotation or junk: refetch, but at most once a minute.
        if (! isset($keys[$kid]) && $this->cache->add($this->cacheKey.':refetch', true, 60)) {
            $keys = $this->fetch();
            $this->cache->put($this->cacheKey, $keys, $this->ttl);
        }
        if (! isset($keys[$kid])) {
            throw InvalidSignature::unknownKey($kid);
        }

        $key = JWK::parseKey($keys[$kid]);
        if ($key === null || $key->getAlgorithm() !== 'RS256') {
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

        return array_column(is_array($keys) ? $keys : [], null, 'kid');
    }
}
