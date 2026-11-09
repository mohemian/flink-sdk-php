<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Mohemian\Flink\Exceptions\MissingConfiguration;
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

it('accepts a bare https origin, or http on loopback', function (string $apiHost, string $expected): void {
    config(['flink.api_host' => $apiHost, 'flink.tenant_id' => 't', 'flink.results.api_key' => 'flk_abc']);
    Http::fake(['*' => Http::response(['error' => 'invalid_client'], 401)]);

    expect(fn () => Flink::fetchResult(TestCase::SESSION))->toThrow(ResultUnavailable::class);
    Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), $expected.'/client/v1/'));
})->with([
    'https' => ['https://api.demo.test', 'https://api.demo.test'],
    'trailing slash' => ['https://api.demo.test/', 'https://api.demo.test'],
    'https with a port' => ['https://api.demo.test:8443', 'https://api.demo.test:8443'],
    'localhost' => ['http://localhost:3000', 'http://localhost:3000'],
    'loopback v4' => ['http://127.0.0.1:3000', 'http://127.0.0.1:3000'],
    'loopback v6' => ['http://[::1]:3000', 'http://[::1]:3000'],
]);

it('refuses an API host that is not a bare https origin', function (string $apiHost): void {
    config(['flink.api_host' => $apiHost, 'flink.tenant_id' => 't', 'flink.results.api_key' => 'flk_abc']);
    Http::fake();

    expect(fn () => Flink::fetchResult(TestCase::SESSION))->toThrow(MissingConfiguration::class, 'FLINK_API_HOST');
    Http::assertNothingSent();
})->with([
    'http, not loopback' => ['http://api.demo.test'],
    'no scheme' => ['api.demo.test'],
    'a path' => ['https://api.demo.test/client/v1'],
    'userinfo' => ['https://user:pw@api.demo.test'],
    'userinfo hiding the host' => ['https://api.demo.test@evil.test'],
    'a query' => ['https://api.demo.test?x=1'],
    'a fragment' => ['https://api.demo.test#x'],
    'another scheme' => ['ftp://api.demo.test'],
]);

it('refuses a FLINK_JWKS_URL that would fetch the keys in cleartext or through userinfo', function (string $jwksUrl): void {
    config(['flink.jwks_url' => $jwksUrl]);
    Http::fake();

    expect(fn () => Flink::prefill('x.y.z', TestCase::REF))->toThrow(MissingConfiguration::class, 'FLINK_JWKS_URL');
    Http::assertNothingSent();
})->with([
    'http, not loopback' => ['http://keys.test/jwks.json'],
    'userinfo' => ['https://user:pw@keys.test/jwks.json'],
    'userinfo hiding the host' => ['https://keys.test@evil.test/jwks.json'],
    'no scheme' => ['keys.test/jwks.json'],
]);

it('accepts an https FLINK_JWKS_URL with a path, and http on loopback', function (string $jwksUrl): void {
    config(['flink.jwks_url' => $jwksUrl]);
    Http::fake([$jwksUrl => Http::response(['keys' => [TestCase::key('k1')['jwk']]])]);

    Flink::prefill($this->jwt([]), TestCase::REF);
    Http::assertSent(fn (Request $request): bool => $request->url() === $jwksUrl);
})->with([
    'https' => ['https://keys.test/.well-known/jwks.json'],
    'loopback http' => ['http://localhost:3000/jwks.json'],
]);
