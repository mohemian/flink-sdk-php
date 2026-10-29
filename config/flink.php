<?php

declare(strict_types=1);

return [
    // Base URL of flink-services. It must equal FLINK's issuer: the expected `iss` of every assertion and
    // the `aud` of the client assertion that fetches a result. The JWKS is served under it.
    'url' => env('FLINK_URL', 'https://flink-services.mohemian.rocks'),

    // Where FLINK's signing keys are fetched from. Defaults to the flow-results JWKS under `url`.
    'jwks_url' => env('FLINK_JWKS_URL'),

    // Your tenant, as on the console's Start & integrate tab. Every assertion must carry it.
    'tenant_id' => env('FLINK_TENANT_ID'),

    // `flow-prefill+jwt`: the data an embedded journey prefilled, signed.
    'prefill' => [
        // The origin of the page that embeds the journey: the JWT's `aud`. Defaults to APP_URL's.
        // Compared as a browser Origin: lowercased, without a path or a default port.
        'audience' => env('FLINK_AUDIENCE'),

        // The journeys whose prefill and results you accept: their public ids, comma-separated, as on the
        // console's Start & integrate tab. Required: any tenant can list your origin as an allowed
        // origin, so `aud` alone is not enough.
        'journeys' => env('FLINK_JOURNEYS'),

        // The onboarding methods whose data you accept as verified. `eid` is any eID provider route
        // (ID Austria, SkIDentity or SK Authentigate); the claim does not name the provider.
        'allowed_methods' => ['eid'],

        // Where `Flink::reference()` keeps this session's reference.
        'session_key' => 'flink.reference',
    ],

    // `flink-result+jwt` (FLNK-861): FLINK's signed result of a session, ES256.
    'results' => [
        // FLINK signs each result for the client that fetched it: your backend's client id is `aud`.
        'audience' => env('FLINK_RESULTS_AUDIENCE'),

        // To fetch results (`Flink::fetchResult()`): your backend's private key as PEM, EC P-256 or
        // RSA of 2048 bits or more (registered as RS256), and the kid FLINK registered its public half under. FLINK holds
        // no secret of yours. `php artisan flink:client-key` makes a pair.
        'client_key' => env('FLINK_CLIENT_KEY'),
        'client_key_id' => env('FLINK_CLIENT_KEY_ID'),

        // Or, instead of the client key, a journey API key (`flk_…`, FLNK-859) from the journey's Start
        // tab in the console: it reads any session of that journey. `audience` may then stay unset:
        // FLINK signs results for the key's own subject, `apikey:<key id>`, which is read from its token.
        'api_key' => env('FLINK_API_KEY'),
    ],

    // The signing keys are cached for a day (the JWKS endpoint's max-age). `store` null is the
    // default cache store.
    'cache' => [
        'store' => env('FLINK_CACHE_STORE'),
        'key' => 'flink:jwks',
        'ttl' => 86400,
    ],

    // Dispatch AssertionVerified / AssertionRejected, for an audit log. Neither carries the JWT.
    'events' => false,
];
