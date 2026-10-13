# flink-sdk-php

Verify FLINK results in a Laravel backend. Today: the signed ID Austria **prefill** an embedded
FLINK journey hands your page, so your backend knows the names and birthdate in a submitted form are
the ones ID Austria returned and not whatever the browser sent.

Using Statamic? Follow [docs/statamic.md](docs/statamic.md) end to end.

## Install

```bash
composer require mohemian/flink-sdk-php
```

PHP 8.2+, Laravel 10, 11 or 12, `ext-intl`.

```dotenv
FLINK_TENANT_ID=…   # the console's journey page, "Start & integrate"
FLINK_JOURNEY=…     # the journey's public id, same place
# FLINK_URL=https://flink-services.mohemian.rocks  (default)
# FLINK_AUDIENCE=https://www.example.at            (default: APP_URL's origin)
```

## Use

On the page, launch the journey with a `reference` your backend issued for this visitor's session,
and post `prefillJwt` from `onResult` to your backend (see the
[browser SDK docs](https://github.com/mohemian/flink-sdk-web/blob/main/docs/security.md#verifying-on-your-backend)).
On the backend:

```php
use Mohemian\Flink\Exceptions\InvalidPrefill;
use Mohemian\Flink\PrefillVerifier;

$verified = app(PrefillVerifier::class)->verify($request->input('prefill_jwt'), session('flink.reference'));
// ['givenName' => 'Erika', 'familyName' => 'Mustermann', 'birthdate' => '1964-08-12', 'address' => [...]]

$changed = PrefillVerifier::diff($submittedValues, $verified); // e.g. ['address.street']
```

`verify()` throws `InvalidPrefill` unless every one of these holds:

- signed by FLINK: RS256 only, key chosen by `kid` from FLINK's JWKS (cached a day, refetched at most
  once a minute for an unknown `kid`), header `typ` = `flow-prefill+jwt`
- `iss` = `FLINK_URL`, `aud` = your page's origin, not expired, `ver` = 1
- `tenant_id` and `journey` are yours: any FLINK tenant can register your origin, so `aud` alone is
  not enough
- `method` is one you accept (`allowed_methods`, default `['eid']`: ID Austria only)
- `reference` is the one you issued for this session, so a JWT copied from someone else's browser
  fails

`diff()` returns the verified fields the submission changed or left out, as dotted keys. It compares
strings after trimming and Unicode NFC normalisation, and nothing more. Which changes you accept is
your policy: [docs/statamic.md](docs/statamic.md) locks names and birthdate and lets the address
change.

The JWT is personal data that stays verifiable after it expires: send it in a request body, verify
it as soon as it arrives (it expires 5 minutes after the journey ends), and do not log or store it.

## Develop

```bash
composer install
composer test      # Pest
composer analyse   # PHPStan
```

## License

MIT
