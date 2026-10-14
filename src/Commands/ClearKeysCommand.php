<?php

declare(strict_types=1);

namespace Mohemian\Flink\Commands;

use Illuminate\Console\Command;
use Mohemian\Flink\Contracts\KeySet;

final class ClearKeysCommand extends Command
{
    protected $signature = 'flink:keys:clear';

    protected $description = 'Forget the cached FLINK signing keys, so the next verification fetches them again';

    public function handle(KeySet $keys): int
    {
        $keys->forget();
        $this->components->info('FLINK keys cleared.');

        return self::SUCCESS;
    }
}
