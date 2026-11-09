<?php

namespace Mohemian\Flink;

use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\ServiceProvider;
use Mohemian\Flink\Contracts\KeySet;
use Mohemian\Flink\Exceptions\MissingConfiguration;
use Mohemian\Flink\Results\ResultClient;
use Mohemian\Flink\Verification\JwksKeySet;
use Mohemian\Flink\Verification\Verifier;

final class FlinkServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/flink.php', 'flink');

        $this->app->singleton(KeySet::class, function ($app): JwksKeySet {
            $config = $app['config']['flink'];

            return new JwksKeySet(
                $app->make(Http::class),
                $app['cache']->store($config['cache']['store'] ?? null),
                $config['jwks_url'] ?? $this->apiHost($config).'/client/v1/.well-known/flow-results-jwks.json',
                (string) ($config['cache']['key'] ?? 'flink:jwks'),
                (int) ($config['cache']['ttl'] ?? 86400),
            );
        });
        // Not a singleton: it is cheap, and `Flink::fake()` swaps the KeySet it is built with.
        $this->app->bind(Verifier::class, fn ($app): Verifier => new Verifier(
            $app->make(KeySet::class),
            $this->apiHost($app['config']['flink']),
            (string) ($app['config']['flink.tenant_id'] ?: throw MissingConfiguration::for('FLINK_TENANT_ID')),
        ));
        $this->app->bind(ResultClient::class, function ($app): ResultClient {
            $config = $app['config']['flink'];
            $url = $this->apiHost($config);
            $tenantId = (string) ($config['tenant_id'] ?: throw MissingConfiguration::for('FLINK_TENANT_ID'));
            // Per FLINK, tenant and client: apps sharing a cache store (stage and production on one
            // Redis) must never pick up each other's token.
            $cacheKey = fn (string $client): string => ($config['cache']['key'] ?? 'flink:jwks').':client-token:'.hash('sha256', "{$url}|{$tenantId}|{$client}");
            $apiKey = ($config['results']['api_key'] ?? null) ?: null;
            if ($apiKey !== null) {
                // Two credentials would leave it unclear which one FLINK should hold the backend to.
                if (($config['results']['client_key'] ?? null) ?: null) {
                    throw new MissingConfiguration('Set either FLINK_API_KEY or FLINK_CLIENT_KEY, not both');
                }
                if (! is_string($apiKey) || ! str_starts_with($apiKey, 'flk_') || strlen($apiKey) === 4) {
                    throw new MissingConfiguration("Set FLINK_API_KEY to the flk_… key from the journey's Start tab in the console");
                }

                return new ResultClient($app->make(Http::class), $app['cache']->store($config['cache']['store'] ?? null), $url, $tenantId, null, null, null, $cacheKey('key:'.$apiKey), $apiKey);
            }
            $clientId = (string) (($config['results']['audience'] ?? null) ?: throw MissingConfiguration::for('FLINK_RESULTS_AUDIENCE'));

            return new ResultClient(
                $app->make(Http::class),
                $app['cache']->store($config['cache']['store'] ?? null),
                $url,
                $tenantId,
                $clientId,
                (string) (($config['results']['client_key'] ?? null) ?: throw MissingConfiguration::for('FLINK_CLIENT_KEY')),
                (string) (($config['results']['client_key_id'] ?? null) ?: throw MissingConfiguration::for('FLINK_CLIENT_KEY_ID')),
                $cacheKey($clientId),
            );
        });
        $this->app->singleton(Flink::class, fn ($app): Flink => new Flink($app));
    }

    public function boot(): void
    {
        $this->publishes([__DIR__.'/../config/flink.php' => config_path('flink.php')], 'flink-config');

        $this->callAfterResolving('blade.compiler', function ($blade): void {
            $blade->directive('flinkReference', fn (): string => '<?php echo e(app(\\'.Flink::class.'::class)->reference()); ?>');
        });

        if ($this->app->runningInConsole()) {
            $this->commands([Commands\InstallCommand::class, Commands\ClearKeysCommand::class, Commands\ClientKeyCommand::class]);
            if (class_exists(AboutCommand::class)) {
                AboutCommand::add('FLINK', fn (): array => [
                    'API host' => rescue(fn (): string => $this->apiHost((array) config('flink')), '<fg=yellow;options=bold>INVALID</>', false),
                    'Tenant' => config('flink.tenant_id') ?: '<fg=yellow;options=bold>NOT SET</>',
                    'Journeys' => (string) (is_array($journeys = config('flink.prefill.journeys')) ? implode(',', $journeys) : $journeys),
                    'Events' => config('flink.events') ? 'ENABLED' : 'OFF',
                ]);
            }
        }
    }

    /** The stage FLINK, used when neither FLINK_API_HOST nor its deprecated FLINK_URL is set. */
    public const DEFAULT_API_HOST = 'https://flink-services.mohemian.rocks';

    /**
     * FLINK_API_HOST (or the deprecated FLINK_URL), else the `url` key of a config/flink.php published
     * before FLNK-1191, else the stage FLINK.
     *
     * @param  array<string, mixed>  $config
     */
    private function apiHost(array $config): string
    {
        $host = rtrim((string) (($config['api_host'] ?? null) ?: (($config['url'] ?? null) ?: self::DEFAULT_API_HOST)), '/');
        $parts = parse_url($host);
        $loopback = in_array($parts['host'] ?? '', ['localhost', '127.0.0.1', '[::1]'], true);
        // A bare origin over https (http only for loopback): the key fetch and the client assertion
        // must never go out in cleartext, nor to a host smuggled in through userinfo or a path.
        if ($parts === false || ! in_array($parts['scheme'] ?? '', $loopback ? ['https', 'http'] : ['https'], true)
            || ($parts['host'] ?? '') === '' || array_diff(array_keys($parts), ['scheme', 'host', 'port'])
            || preg_match('/[\\\\\s@?#]/', $host)) {
            throw new MissingConfiguration('Set FLINK_API_HOST to a bare https origin such as https://flink-services.mohemian.rocks (http only for localhost)');
        }

        return $host;
    }
}
