<?php

declare(strict_types=1);

namespace Mohemian\Flink\Commands;

use Illuminate\Console\Command;
use Mohemian\Flink\Testing\KeyPair;

final class ClientKeyCommand extends Command
{
    protected $signature = 'flink:client-key {kid : a name for the key, unique for your client, e.g. my-backend-2026-10}';

    protected $description = 'Make the key pair your backend fetches results with: the private key for your env, the public key set for FLINK';

    public function handle(): int
    {
        $kid = $this->argument('kid');
        if (! is_string($kid) || $kid === '') {
            $this->components->error('Name the key: php artisan flink:client-key <kid>');

            return self::FAILURE;
        }
        $pair = KeyPair::generate($kid, 'ES256');

        $this->components->info('Your env, and nowhere else (FLINK_CLIENT_KEY_ID='.$pair['jwk']['kid'].'):');
        $this->line('FLINK_CLIENT_KEY="'.trim($pair['private']).'"');
        $this->newLine();
        $this->components->info('For FLINK, to register for your client: the public key set.');
        $this->line((string) json_encode(['keys' => [$pair['jwk']]], JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
