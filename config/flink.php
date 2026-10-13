<?php

return [
    // Base URL of flink-services. It is the `iss` of every assertion, and the JWKS is served under it.
    'url' => env('FLINK_URL', 'https://flink-services.mohemian.rocks'),

    // The origin of the page that embeds the journey: the prefill JWT's `aud`. Defaults to APP_URL's.
    'audience' => env('FLINK_AUDIENCE'),

    // Your tenant, and the journeys whose prefill you accept: their public ids, comma-separated, as on
    // the console's Start & integrate tab. Both are required: any tenant can list your origin as an
    // allowed origin, so `aud` alone is not enough.
    'tenant_id' => env('FLINK_TENANT_ID'),
    'journeys' => env('FLINK_JOURNEYS'),

    // The onboarding methods whose data you accept as verified. `eid` is any eID provider route
    // (ID Austria, SkIDentity or SK Authentigate); the claim does not name the provider.
    'allowed_methods' => ['eid'],
];
