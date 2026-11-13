<?php

use Illuminate\Support\Env;
use Mohemian\Flink\Exceptions\InvalidAssertion;
use Mohemian\Flink\Facades\Flink;
use Mohemian\Flink\FlinkServiceProvider;
use Mohemian\Flink\Tests\TestCase;

// Deliberately leaves `flink.prefill.accept_v2` alone: this pins what the package ships.

function v2Claims(): array
{
    return [
        'ver' => 2,
        'method' => null,
        'data' => ['givenName' => 'Erika'],
        'provenance' => ['givenName' => ['source' => TestCase::EID, 'obtainedAt' => '2026-11-13T12:00:00Z']],
    ];
}

it('ships with v2 receipts off', function (): void {
    expect((require __DIR__.'/../config/flink.php')['prefill']['accept_v2'])->toBeFalse()
        ->and(config('flink.prefill.accept_v2'))->toBeFalse();
});

it('refuses a v2 receipt under the default config', function (): void {
    expect(fn () => Flink::prefill($this->jwt($this->claims(v2Claims())), TestCase::REF))
        ->toThrow(InvalidAssertion::class, 'not enabled');
});

it('honours FLINK_PREFILL_V2 for a config published before accept_v2 existed', function (): void {
    $prefill = config('flink.prefill');
    unset($prefill['accept_v2']);
    config(['flink.prefill' => $prefill]);
    Env::getRepository()->set('FLINK_PREFILL_V2', 'true');
    try {
        (new FlinkServiceProvider($this->app))->register();
        expect(Flink::prefill($this->jwt($this->claims(v2Claims())), TestCase::REF)->version)->toBe(2);
    } finally {
        Env::getRepository()->clear('FLINK_PREFILL_V2');
    }
});
