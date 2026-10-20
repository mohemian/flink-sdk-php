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

PHP 8.2+ (8.3+ for Laravel 13), Laravel 10 to 13, `ext-intl`.

```dotenv
FLINK_TENANT_ID=…   # the console's journey page, "Start & integrate"
FLINK_JOURNEYS=…    # the journey's public id, same place; several comma-separated
# FLINK_URL=https://flink-services.mohemian.rocks  (default)
# FLINK_AUDIENCE=https://www.example.at            (default: APP_URL's origin)
# FLINK_RESULTS_AUDIENCE=…                         (only to verify results: your backend's client id)
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

A relying party's backend fetches a completed session's result from FLINK with its own client token
(`GET /client/v1/tenants/{tenantId}/sessions/{sessionId}/results`, FLNK-861) and gets
`{ "signedResult": "<jwt>" }`. `Flink::results()` verifies that JWT: ES256 only (the same JWKS serves
the prefill's RS256 key, which is refused here), header `typ` = `flink-result+jwt`, `iss` =
`FLINK_URL`, `aud` = `FLINK_RESULTS_AUDIENCE` (FLINK signs each result for the client id that fetched
it), `tenantId` is yours, `ver` = 1. It has no `exp`: it records a fact, so a result you keep stays
verifiable.

Its `attributes` are flat, under the EUDI PID names (`givenName`, `birthdate`, `address.locality`,
`personalAdministrativeNumber`, …), and include values FLINK or a provider derived
(`emailAddressVerified`, `sanctionsMatchFound`, `livenessPassed`, …). Each is
`{ value, source }`, where `source` is a `urn:flink:source:*` identifier keying `sources`, the
provenance. Every attribute is optional: absent means not handed over, never false. Ignore names
you do not know; new ones are added without a version bump.

## Testing your app

`Flink::fake()` verifies against a key generated for the test, and signs assertions your config
accepts, so your tests never reach FLINK:

```php
$fake = Flink::fake();

$jwt = $fake->prefill(['givenName' => 'Erika']);            // bound to this session's reference
$this->post('/flink/prefill', ['prefill_jwt' => $jwt])->assertNoContent();

$fake->prefill(claims: ['method' => 'doc']);                 // override or drop (null) a claim
$fake->results(['givenName' => ['value' => 'Erika', 'source' => 'urn:flink:source:eid:oidc']]);
```

## Upgrading from 0.1

0.2 follows flink-services' results contract (FLNK-861); the prefill is unchanged.

- `Flink::results()` verifies a `flink-result+jwt` (ES256) instead of a `flow-results+jwt`: read
  `attributes`, `sources`, `journeyId` and `get('givenName')` instead of `results` and
  `get('identity.givenName')`. Set `FLINK_RESULTS_AUDIENCE` to your backend's client id.
- `KeySet::key()` takes the expected algorithm as a second argument: a custom key set must return a
  key of exactly that algorithm.
- `Verifier::claims()` takes the assertion class (`ResultsAssertion::class`) instead of its `typ`.
- `Flink::fake()->results()` takes attributes (`['givenName' => ['value' => …, 'source' => …]]`).

## Operations

- `php artisan flink:keys:clear` forgets the cached keys, so the next verification fetches them.
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
