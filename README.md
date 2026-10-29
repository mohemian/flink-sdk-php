# flink-sdk-php

Verify FLINK's signed assertions in a Laravel backend. An embedded FLINK journey hands your page the applicant's
eID data (from ID Austria, for example) together with a copy signed by FLINK. This package verifies
that signed copy, so your backend can check that the names and birthdate in a submitted form are the
ones the eID returned. The same verifier checks FLINK's other signed assertions, such as a
session's results.

Using Statamic? Follow [docs/statamic.md](docs/statamic.md) end to end.

## Install

```bash
composer require mohemian/flink-sdk-php
php artisan flink:install   # publishes config/flink.php and lists what is still unset
```

PHP 8.2+ (8.3+ for Laravel 13), Laravel 12 or 13, `ext-intl`, `ext-openssl`.

```dotenv
FLINK_TENANT_ID=…   # the console's journey page, "Start & integrate"
FLINK_JOURNEYS=…    # the journey's public id, same place; several comma-separated
# FLINK_URL=https://flink-services.mohemian.rocks  (default)
# FLINK_AUDIENCE=https://www.example.at            (default: APP_URL's origin)
# FLINK_API_KEY=flk_…                              (to fetch results: the journey's API key, from its Start tab)
# or, instead of the API key:
# FLINK_RESULTS_AUDIENCE=…                         (only for results: your backend's client id)
# FLINK_CLIENT_KEY="-----BEGIN PRIVATE KEY-----…"  (only to fetch results: php artisan flink:client-key)
# FLINK_CLIENT_KEY_ID=…                            (the kid FLINK registered its public half under)
```

## Verify a prefill

On the page, launch the journey with this session's reference and post `prefillJwt` from `onResult`
to your backend (see the
[browser SDK docs](https://github.com/mohemian/flink-sdk-web/blob/main/docs/security.md#verifying-on-your-backend)):

```blade
<script>
  Flink.createFlink({ journey: 'SCD7DDPK258G', reference: '@flinkReference', onResult: … });
</script>
```

On the backend:

```php
use Mohemian\Flink\Exceptions\FlinkException;
use Mohemian\Flink\Facades\Flink;

try {
    $prefill = Flink::prefill($request->input('prefill_jwt')); // checked against this session's reference
} catch (FlinkException $e) {
    logger()->warning('FLINK prefill refused', ['reason' => $e->getMessage()]); // never log the JWT

    return response()->noContent(422);
}

$prefill->data;                    // ['givenName' => 'Erika', 'familyName' => 'Mustermann', 'address' => [...]]
$prefill->get('address.street');   // 'Hauptstraße'
$prefill->journey; $prefill->method; $prefill->sessionId; $prefill->id; $prefill->expiresAt;
$prefill->changed($submitted);     // e.g. ['address.street']
```

Or as a validation rule, which keeps the verified prefill for you:

```php
use Mohemian\Flink\Rules\ValidPrefill;

$request->validate(['prefill_jwt' => ['required', $rule = new ValidPrefill]]);
$rule->assertion->data;
```

`Flink::prefill()` throws a `FlinkException` unless every one of these holds:

- signed by FLINK: RS256 only, key chosen by `kid` from FLINK's JWKS (cached a day, refetched at most
  once a minute for an unknown `kid`), header `typ` = `flow-prefill+jwt`; otherwise
  `InvalidSignature`, or `InvalidAssertion` for another `typ`
- `iss` = `FLINK_URL`, `aud` = your page's origin, not expired, `ver` = 1 (`iat` and `exp` allow
  5 seconds of clock skew)
- `tenant_id` is yours and `journey` is one of `FLINK_JOURNEYS`: any FLINK tenant can register your
  origin, so `aud` alone is not enough
- `method` is one you accept (`prefill.allowed_methods`, default `['eid']`). `eid` is any eID
  provider route (ID Austria, SkIDentity or SK Authentigate); the claim does not say which one
- `reference` is this session's (or the one you pass as the second argument), so a JWT copied from
  someone else's browser fails

Everything but the signature is an `InvalidAssertion`. Two failures are deliberately not a
`FlinkException`, because a broken setup should fail loudly rather than refuse every applicant
quietly: `MissingConfiguration` for an unset variable, and `KeysUnavailable` when FLINK's keys cannot
be fetched (a wrong `FLINK_URL`, or an outage).

A journey with no identity step (email only, say) issues a JWT without `method`, and the prefill is
refused. That is deliberate: nothing in such a JWT is identity-backed, the email address included,
so it cannot stand in for a verified identity.

`changed()` returns the verified fields the submission changed or left out, as dotted keys; pass
`only:` to compare a subset. It compares strings after trimming and Unicode NFC normalisation, and
nothing more. Which changes you accept is your policy: [docs/statamic.md](docs/statamic.md) locks
names and birthdate and lets the address change.

`Flink::reference()` issues this session's reference on first use (`@flinkReference` in Blade),
`Flink::forgetReference()` rotates it, so one journey run backs one submission. `$prefill->id` is
the JWT's `jti`: the same on every retry of one release, so to be sure of one submission per
release regardless of the session, accept each `id` once.

The JWT is personal data that stays verifiable after it expires: send it in a request body, verify
it as soon as it arrives (it expires 5 minutes after the journey ends), and do not log or store it.

## Verify any FLINK assertion

Every assertion FLINK signs verifies the same way; the caller always names the type it expects, so
one type is never accepted as another (a results assertion never expires, so accepting it as a
prefill would make it replayable forever):

```php
use Mohemian\Flink\Assertions\PrefillAssertion;
use Mohemian\Flink\Assertions\ResultsAssertion;

$results = Flink::results($jwt);                         // flink-result+jwt, aud = FLINK_RESULTS_AUDIENCE
$results->get('givenName'); $results->get('address.locality'); $results->source('givenName');
$results->attributes; $results->sources; $results->journeyId;

Flink::verify($jwt, ResultsAssertion::class);            // the same, for any Assertion subclass
Flink::verify($jwt, PrefillAssertion::class, ['reference' => $reference]); // override an expectation
```

### A session's result

Fetch a completed session's result and verify it in one call:

```php
use Mohemian\Flink\Exceptions\ResultUnavailable;

try {
    $results = Flink::fetchResult($sessionId);
} catch (ResultUnavailable $e) {
    // $e->status 409: not completed yet, $e->sessionStatus says how far it is ('started', 'aborted').
    // 401 a client id or key FLINK does not hold, 400 a grant this client may not use,
    // 403 a token bound to another tenant (a wrong FLINK_TENANT_ID), a missing scope, or a session-bound token;
    // 404 a session this client may not read. 0 or 5xx an outage. Never carries the token or the key.
}
```

Your backend calls FLINK as its own client, bound to your tenant
(`GET /client/v1/tenants/{tenantId}/sessions/{sessionId}/results`, FLNK-861).

The simplest way to prove itself is a **journey API key** (FLNK-859): create it on the journey's
Start tab in the console and set it as `FLINK_API_KEY`. The key is bound to your tenant and that one
journey, and reads the result of any session of it, whoever started it (your page, the flow link, a
QR code or the app); test runs and other journeys' sessions answer `404`. It is sent as `X-Api-Key`
to FLINK's token endpoint only. FLINK signs each result for the key's own subject,
`apikey:<key id>`, which the package reads from the key's token and keeps, so `FLINK_RESULTS_AUDIENCE`
can stay unset; set it to that subject if a kept result should verify before the package ever
asked FLINK for a token. Revoking the key in the console stops it on the next request (`401`).
Set the key or the client key below, not both.

Or it proves itself with `private_key_jwt`: a 60-second assertion signed with your private key, `FLINK_CLIENT_KEY` (EC P-256,
or RSA of 2048 bits or more registered as RS256), so FLINK
holds only the public half and no shared secret exists. `php artisan flink:client-key <kid>` makes
the pair: the private key goes into your env with its `kid` as `FLINK_CLIENT_KEY_ID`, the public key
set goes to FLINK, which registers it for your client. The client token is cached in `flink.cache`'s
store until shortly before it expires. A failure to hand the result over is a `ResultUnavailable`,
deliberately not a `FlinkException`: nothing was verified, so nothing was found invalid.

With an API key you can also read a session's state, without its results, to see how far it got:

```php
$session = Flink::fetchSession($sessionId);  // any session of the key's journey
$session->status;        // 'provisioned', 'started', 'completed' or 'aborted' (final); tolerate others
$session->steps;         // each: id, completed, startedAt, completedAt, declinedAt (null when not)
$session->createdAt; $session->updatedAt; $session->completedAt;
```

It throws `ResultUnavailable` like `fetchResult()` (404 for a session of another journey, a test run,
or none at all), and `MissingConfiguration` without `FLINK_API_KEY`: FLINK lets only a journey API
key read a session it did not run. A session id is not a secret, so decide who may ask your backend
for which session before you call either method.

Fetched some other way, FLINK answers `{ "signedResult": "<jwt>" }`, and `Flink::results()` verifies
that JWT: ES256 only (the same JWKS serves
the prefill's RS256 key, which is refused here), header `typ` = `flink-result+jwt`, `iss` =
`FLINK_URL`, `aud` is or contains `FLINK_RESULTS_AUDIENCE` (FLINK signs each result for the client id that fetched
it), `tenantId` is yours, `journeyId` is one of `FLINK_JOURNEYS` (`aud` names no journey, so this is
what keeps a result from your other journey out), and `ver` = 1. Pass the session you fetched it for,
`Flink::results($jwt, $sessionId)`, and a result for another session is refused too. It has no `exp`: it records a fact, so a result you keep stays
verifiable.

Its `attributes` are flat, under the EUDI PID names (`givenName`, `birthdate`, `address.locality`,
`personalAdministrativeNumber`, …), and include values FLINK or a provider derived
(`emailAddressVerified`, `sanctionsMatchFound`, `livenessPassed`, …). Each is
`{ value, source }`, where `source` is a `urn:flink:source:*` identifier keying `sources`, the
provenance. Every attribute is optional: absent means not handed over, never false. Ignore names
you do not know; new ones are added without a version bump. An `ageOver*` FLINK derived from a
verified birthdate, rather than read from the issuer, also carries `derivedAsOf`, the day it holds as
of: `$results->derivedAsOf('ageOver18')` returns it, or null for an issuer's own value.

## Testing your app

`Flink::fake()` verifies against a key generated for the test, and signs assertions your config
accepts, so your tests never reach FLINK:

```php
$fake = Flink::fake();

$jwt = $fake->prefill(['givenName' => 'Erika']);            // bound to this session's reference
$this->post('/flink/prefill', ['prefill_jwt' => $jwt])->assertNoContent();

$fake->prefill(claims: ['method' => 'doc']);                 // override or drop (null) a claim
$fake->results(['givenName' => ['value' => 'Erika', 'source' => 'urn:flink:source:eid:oidc']]);

$fake->serveResults(['givenName' => ['value' => 'Erika', 'source' => 'urn:flink:source:eid:oidc']]);
Flink::fetchResult($sessionId);                               // answered without FLINK, no key needed
```

## Upgrading from 0.2

0.3 adds `FLINK_API_KEY`: `Flink::fetchResult()` can authenticate with a journey API key instead of
`FLINK_CLIENT_KEY` (needs flink-services with FLNK-859), and `Flink::fetchSession()` reads a
session's state with it. Nothing changes for a client key. A custom
binding that constructs `ResultClient` itself: its client id, key and kid are nullable now, and the
API key is a new last argument.

## Upgrading from 0.1

0.2 follows flink-services' results contract (FLNK-861); the prefill is unchanged.

- New: `Flink::fetchResult($sessionId)` fetches and verifies a session's result with your own key
  (`FLINK_CLIENT_KEY`, `FLINK_CLIENT_KEY_ID`; `php artisan flink:client-key` makes them). A client
  that reads results authenticates to FLINK with its key only; a client secret no longer works for it.

- Laravel 10 and 11 are no longer supported: 0.2 needs Laravel 12 or 13.
- An archived `flow-results+jwt` no longer verifies under 0.2: it has no class for that type, so
  `Flink::results()` on a result you stored under 0.1 throws `InvalidAssertion`. FLINK keeps the old RS256
  keys in its JWKS, so to re-verify archives stay on 0.1, or subclass `Assertion` (RS256, `tenant_id`) and
  pass it to `Flink::verify()`.
- `Flink::results()` verifies a `flink-result+jwt` (ES256) instead of a `flow-results+jwt`: read
  `attributes`, `sources`, `journeyId` and `get('givenName')` instead of `results` and
  `get('identity.givenName')`. Set `FLINK_RESULTS_AUDIENCE` to your backend's client id, and
  `FLINK_JOURNEYS` to the journeys whose results you accept.
- `KeySet::key()` takes the expected algorithm as a second argument: a custom key set must return a
  key of exactly that algorithm.
- `Verifier::claims()` takes the assertion class (`ResultsAssertion::class`) instead of its `typ`.
- `Flink::fake()->results()` takes attributes (`['givenName' => ['value' => …, 'source' => …]]`).
- `ResultsAssertion::toArray()` and `jsonSerialize()` return the `attributes` (each `{ value, source }`),
  not `results`.
- `FakeKeySet` is built from a list of JWKs rather than one, and `key()` takes the algorithm.

## Operations

- `php artisan flink:keys:clear` forgets the cached keys, so the next verification fetches them.
- Telescope (or any watcher of the HTTP client) records request headers and response bodies: hide
  the `X-Api-Key` header, `access_token` and `signedResult` there, your API key, a live token and the
  applicant's data.
- `php artisan flink:client-key <kid>` makes the key pair `Flink::fetchResult()` uses. To rotate,
  register the new public key with FLINK beside the old one, switch your env, then have the old one
  removed.
- `php artisan about` shows the FLINK settings.
- Set `flink.events` to dispatch `AssertionVerified` and `AssertionRejected` for an audit log. The
  rejection carries the expected type, the reason and the exception class as strings, never the JWT
  or its claims, so a queued listener can take it.
- `flink.cache` picks the store, key and lifetime of the cached keys; `FLINK_JWKS_URL` overrides
  where they come from.

## Develop

```bash
composer install
composer test           # Pest
composer analyse        # PHPStan
composer format         # Rector, then Pint: rewrites the code in place
composer format:check   # what CI runs: fails on anything `format` would change
```

## License

MIT
