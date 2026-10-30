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
                $config['jwks_url'] ?? $this->url($config).'/client/v1/.well-known/flow-results-jwks.json',
                (string) ($config['cache']['key'] ?? 'flink:jwks'),
                (int) ($config['cache']['ttl'] ?? 86400),
            );
        });
        // Not a singleton: it is cheap, and `Flink::fake()` swaps the KeySet it is built with.
        $this->app->bind(Verifier::class, fn ($app): Verifier => new Verifier(
            $app->make(KeySet::class),
            $this->url($app['config']['flink']),
            (string) ($app['config']['flink.tenant_id'] ?: throw MissingConfiguration::for('FLINK_TENANT_ID')),
        ));
        $this->app->bind(ResultClient::class, function ($app): ResultClient {
            $config = $app['config']['flink'];
            $url = $this->url($config);
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
                    'URL' => config('flink.url'),
                    'Tenant' => config('flink.tenant_id') ?: '<fg=yellow;options=bold>NOT SET</>',
                    'Journeys' => (string) (is_array($journeys = config('flink.prefill.journeys')) ? implode(',', $journeys) : $journeys),
                    'Events' => config('flink.events') ? 'ENABLED' : 'OFF',
                ]);
            }
        }
    }

    /** @param array<string, mixed> $config */
    private function url(array $config): string
    {
        return rtrim((string) $config['url'], '/');
    }
}
