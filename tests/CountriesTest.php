<?php

use Firebase\JWT\JWT;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Mohemian\Flink\Exceptions\ResultUnavailable;
use Mohemian\Flink\Facades\Flink;
use Mohemian\Flink\Tests\TestCase;

// A journey's countries and the eIDs it offers in each (FLNK-1181).
const COUNTRIES_TOKEN_URL = TestCase::URL.'/client/v1/auth/token';
const COUNTRIES_URL = TestCase::URL.'/client/v1/journeys/'.TestCase::JOURNEY.'/countries';

beforeEach(function (): void {
    config(['flink.results.api_key' => 'flk_test_0123456789abcdef', 'flink.results.audience' => null]);
    $this->token = 'e30.'.JWT::urlsafeB64Encode((string) json_encode(['sub' => 'apikey:key-1', 'tenant_id' => TestCase::TENANT])).'.';
    $this->countriesAnswer = fn (): mixed => Http::response([
        'countries' => [
            ['country' => 'se', 'eids' => [
                ['id' => 41, 'label' => ['en' => 'Freja'], 'logo_uri' => 'https://cdn.test/freja.svg', 'kind' => 'redirect'],
                ['id' => 42, 'label' => ['en' => 'BankID', 'sv' => 'BankID'], 'kind' => 'login'],
            ]],
            ['country' => 'de', 'eids' => []],
        ],
        'country_labels' => ['XA' => ['en' => 'Conformance A']],
    ]);
    Http::fake([
        COUNTRIES_TOKEN_URL => fn (): mixed => Http::response(['access_token' => $this->token, 'token_type' => 'Bearer', 'expires_in' => 600]),
        COUNTRIES_URL => fn (Request $request): mixed => $request->header('Authorization') === ['Bearer '.$this->token]
            ? ($this->countriesAnswer)()
            : Http::response(null, 403),
    ]);
});

it("reads a journey's countries and the eIDs it offers in each", function (): void {
    $read = Flink::fetchCountries(TestCase::JOURNEY);

    expect($read->countries)->toHaveCount(2)
        ->and($read->countries[0]->country)->toBe('se')
        ->and($read->countries[0]->eids)->toHaveCount(2)
        ->and($read->countries[0]->eids[0]->id)->toBe(41)
        ->and($read->countries[0]->eids[0]->label)->toBe(['en' => 'Freja'])
        ->and($read->countries[0]->eids[0]->logoUri)->toBe('https://cdn.test/freja.svg')
        ->and($read->countries[0]->eids[0]->kind)->toBe('redirect')
        ->and($read->countries[0]->eids[1]->label)->toBe(['en' => 'BankID', 'sv' => 'BankID'])
        ->and($read->countries[0]->eids[1]->logoUri)->toBeNull()
        ->and($read->countries[1]->country)->toBe('de')
        ->and($read->countries[1]->eids)->toBe([])
        ->and($read->countryLabels)->toBe(['XA' => ['en' => 'Conformance A']]);
    Http::assertSent(fn (Request $request): bool => $request->url() === COUNTRIES_URL && $request->method() === 'GET'
        && ! $request->hasHeader('X-Api-Key'));
});

it('says why FLINK refused, by its status', function (): void {
    $this->countriesAnswer = fn (): mixed => Http::response(['message' => 'not found'], 404);

    try {
        Flink::fetchCountries(TestCase::JOURNEY);
        $this->fail('no exception');
    } catch (ResultUnavailable $e) {
        expect($e->status)->toBe(404);
    }
});

it('refuses an answer that is not a countries answer', function (mixed $body): void {
    $this->countriesAnswer = fn (): mixed => Http::response($body);

    try {
        Flink::fetchCountries(TestCase::JOURNEY);
        $this->fail('no exception');
    } catch (ResultUnavailable $e) {
        expect($e->status)->toBe(200);
    }
})->with([
    'no countries' => [['country_labels' => []]],
    'a country with no eID list' => [['countries' => [['country' => 'se']], 'country_labels' => []]],
    'an eID with no English label' => [['countries' => [['country' => 'se', 'eids' => [['id' => 1, 'label' => [], 'kind' => 'redirect']]]]]],
    'an eID whose id is a string' => [['countries' => [['country' => 'se', 'eids' => [['id' => '41', 'label' => ['en' => 'Freja'], 'kind' => 'redirect']]]]]],
    'no body' => [null],
]);
