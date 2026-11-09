<?php

declare(strict_types=1);

namespace Mohemian\Flink\Commands;

use Illuminate\Console\Command;

final class InstallCommand extends Command
{
    protected $signature = 'flink:install';

    protected $description = 'Publish the FLINK config and list the environment variables still to set';

    public function handle(): int
    {
        $this->callSilent('vendor:publish', ['--tag' => 'flink-config']);
        $this->components->info('Published config/flink.php.');

        $config = (array) config('flink');
        $variables = [
            'FLINK_TENANT_ID' => ['required', $config['tenant_id'] ?? null],
            'FLINK_JOURNEYS' => ['required for prefills and results', $config['prefill']['journeys'] ?? null],
            'FLINK_RESULTS_AUDIENCE' => ['required for results, unless FLINK_API_KEY is set', $config['results']['audience'] ?? null],
            'FLINK_API_KEY' => ['to fetch results with a journey API key, from the journey\'s Start tab', $config['results']['api_key'] ?? null],
            'FLINK_CLIENT_KEY' => ['or, instead of FLINK_API_KEY: php artisan flink:client-key', $config['results']['client_key'] ?? null],
            'FLINK_CLIENT_KEY_ID' => ['with FLINK_CLIENT_KEY', $config['results']['client_key_id'] ?? null],
            'FLINK_AUDIENCE' => ['optional, defaults to APP_URL\'s origin', $config['prefill']['audience'] ?? null],
        ];
        foreach ($variables as $name => [$need, $value]) {
            $this->components->twoColumnDetail($name, $value ? '<fg=green>set</>' : "<fg=yellow>not set</> ({$need})");
        }

        return self::SUCCESS;
    }
}
