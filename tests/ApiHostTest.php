<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Mohemian\Flink\Exceptions\ResultUnavailable;
use Mohemian\Flink\Facades\Flink;
use Mohemian\Flink\FlinkServiceProvider;
use Mohemian\Flink\Tests\TestCase;

// FLNK-1191: the host is the API host. FLINK_URL and a published `url` key stay as deprecated names.
it('talks to the API host, falling back to a published url, then to the stage FLINK', function (?string $apiHost, ?string $url, string $expected): void {
    config([
        'flink.api_host' => $apiHost,
        'flink.url' => $url,
        'flink.results.client_key' => TestCase::key('ec1')['private'],
        'flink.results.client_key_id' => 'ec1',
    ]);
    Http::fake(['*' => Http::response(['error' => 'invalid_client'], 401)]);

    expect(fn () => Flink::fetchResult(TestCase::SESSION))->toThrow(ResultUnavailable::class);
    Http::assertSent(fn (Request $request): bool => $request->url() === $expected.'/client/v1/auth/token');
})->with([
    'api host, over a published url' => ['https://api.demo.test/', 'https://old.test', 'https://api.demo.test'],
    'a url published before the rename' => [null, 'https://old.test', 'https://old.test'],
    'neither: the stage FLINK' => [null, null, FlinkServiceProvider::DEFAULT_API_HOST],
]);

it('reads the deprecated FLINK_URL when FLINK_API_HOST is unset', function (): void {
    $config = fn (): array => require __DIR__.'/../config/flink.php';
    putenv('FLINK_URL=https://deprecated.test');
    try {
        expect($config()['api_host'])->toBe('https://deprecated.test');
        putenv('FLINK_API_HOST=https://api.demo.test');
        expect($config()['api_host'])->toBe('https://api.demo.test');
    } finally {
        putenv('FLINK_URL');
        putenv('FLINK_API_HOST');
    }
});
