<?php

namespace Mohemian\Flink;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\ServiceProvider;

final class FlinkServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/flink.php', 'flink');

        $this->app->singleton(PrefillVerifier::class, function ($app) {
            $config = $app['config']['flink'];
            $url = rtrim((string) $config['url'], '/');
            return new PrefillVerifier(
                new Jwks($app->make(Http::class), $app->make(Cache::class), $url.'/client/v1/.well-known/flow-results-jwks.json'),
                $url,
                $config['audience'] ?? self::origin((string) $app['config']['app.url']),
                (string) ($config['tenant_id'] ?? throw new \RuntimeException('Set FLINK_TENANT_ID')),
                (string) ($config['journey'] ?? throw new \RuntimeException('Set FLINK_JOURNEY')),
                array_values($config['allowed_methods']),
            );
        });
    }

    public function boot(): void
    {
        $this->publishes([__DIR__.'/../config/flink.php' => config_path('flink.php')], 'flink-config');
    }

    /** `https://example.com/apply` → `https://example.com`, the form the JWT's `aud` takes. */
    private static function origin(string $url): string
    {
        $parts = parse_url($url);
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '').$port;
    }
}
