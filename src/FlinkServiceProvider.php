<?php

namespace Mohemian\Flink;

use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\ServiceProvider;
use Mohemian\Flink\Contracts\KeySet;
use Mohemian\Flink\Exceptions\MissingConfiguration;
use Mohemian\Flink\Verification\JwksKeySet;
use Mohemian\Flink\Verification\Verifier;

final class FlinkServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/flink.php', 'flink');

        $this->app->singleton(KeySet::class, function ($app) {
            $config = $app['config']['flink'];

            return new JwksKeySet(
                $app->make(Http::class),
                $app['cache']->store($config['cache']['store'] ?? null),
                $config['jwks_url'] ?? self::url($config).'/client/v1/.well-known/flow-results-jwks.json',
                (string) ($config['cache']['key'] ?? 'flink:jwks'),
                (int) ($config['cache']['ttl'] ?? 86400),
            );
        });
        // Not a singleton: it is cheap, and `Flink::fake()` swaps the KeySet it is built with.
        $this->app->bind(Verifier::class, fn ($app) => new Verifier(
            $app->make(KeySet::class),
            self::url($app['config']['flink']),
            (string) ($app['config']['flink.tenant_id'] ?: throw MissingConfiguration::for('FLINK_TENANT_ID')),
        ));
        $this->app->singleton(Flink::class, fn ($app) => new Flink($app));
    }

    public function boot(): void
    {
        $this->publishes([__DIR__.'/../config/flink.php' => config_path('flink.php')], 'flink-config');

        $this->callAfterResolving('blade.compiler', function ($blade) {
            $blade->directive('flinkReference', fn () => '<?php echo e(app(\\'.Flink::class.'::class)->reference()); ?>');
        });

        if ($this->app->runningInConsole()) {
            $this->commands([Commands\InstallCommand::class, Commands\ClearKeysCommand::class]);
            if (class_exists(AboutCommand::class)) {
                AboutCommand::add('FLINK', fn () => [
                    'URL' => config('flink.url'),
                    'Tenant' => config('flink.tenant_id') ?: '<fg=yellow;options=bold>NOT SET</>',
                    'Journeys' => (string) (is_array($journeys = config('flink.prefill.journeys')) ? implode(',', $journeys) : $journeys),
                    'Events' => config('flink.events') ? 'ENABLED' : 'OFF',
                ]);
            }
        }
    }

    /** @param array<string, mixed> $config */
    private static function url(array $config): string
    {
        return rtrim((string) $config['url'], '/');
    }
}
