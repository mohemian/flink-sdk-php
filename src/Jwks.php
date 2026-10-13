<?php

namespace Mohemian\Flink;

use Firebase\JWT\JWK;
use Firebase\JWT\Key;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\Factory as Http;
use Mohemian\Flink\Exceptions\InvalidPrefill;

/** FLINK's flow-results keys, cached for a day (the endpoint's max-age), refetched on an unknown kid. */
final class Jwks
{
    private const CACHE_KEY = 'flink:jwks';

    public function __construct(
        private readonly Http $http,
        private readonly Cache $cache,
        private readonly string $url,
    ) {
    }

    public function key(string $kid): Key
    {
        $keys = $this->cache->remember(self::CACHE_KEY, 86400, fn () => $this->fetch());
        // An unknown kid is either a rotation or junk: refetch, but at most once a minute.
        if (! isset($keys[$kid]) && $this->cache->add(self::CACHE_KEY.':refetch', true, 60)) {
            $keys = $this->fetch();
            $this->cache->put(self::CACHE_KEY, $keys, 86400);
        }
        if (! isset($keys[$kid])) {
            throw new InvalidPrefill("Unknown key id {$kid}");
        }

        $key = JWK::parseKey($keys[$kid]);
        if ($key === null || $key->getAlgorithm() !== 'RS256') {
            throw new InvalidPrefill("Key {$kid} is not an RS256 key");
        }

        return $key;
    }

    /** @return array<string, array<string, mixed>> the JWKs by kid */
    private function fetch(): array
    {
        $keys = $this->http->timeout(5)->get($this->url)->throw()->json('keys');

        return array_column(is_array($keys) ? $keys : [], null, 'kid');
    }
}
