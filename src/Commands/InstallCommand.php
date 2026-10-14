<?php

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
            'FLINK_JOURNEYS' => ['required for prefills', $config['prefill']['journeys'] ?? null],
            'FLINK_RESULTS_AUDIENCE' => ['required for results', $config['results']['audience'] ?? null],
            'FLINK_AUDIENCE' => ['optional, defaults to APP_URL\'s origin', $config['prefill']['audience'] ?? null],
        ];
        foreach ($variables as $name => [$need, $value]) {
            $this->components->twoColumnDetail($name, $value ? '<fg=green>set</>' : "<fg=yellow>not set</> ({$need})");
        }

        return self::SUCCESS;
    }
}
