<?php

use Illuminate\Support\Facades\Http;
use Mohemian\Flink\Facades\Flink;
use Mohemian\Flink\Tests\TestCase;

it('clears the cached keys so the next verification refetches them', function () {
    Flink::prefill($this->jwt([]), TestCase::REF);
    Flink::prefill($this->jwt([]), TestCase::REF);
    Http::assertSentCount(1);

    $this->artisan('flink:keys:clear')->assertSuccessful();
    Flink::prefill($this->jwt([]), TestCase::REF);

    Http::assertSentCount(2);
});

it('publishes the config and names the variables still missing', function () {
    $published = config_path('flink.php');
    @unlink($published);
    config(['flink.tenant_id' => null]);

    try {
        $this->artisan('flink:install')
            ->expectsOutputToContain('FLINK_TENANT_ID')
            ->assertSuccessful();
        expect($published)->toBeFile();
    } finally {
        @unlink($published);
    }
});
