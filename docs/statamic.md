# Statamic: verify an ID Austria prefill

This guide adds FLINK's ID Austria prefill to a Statamic form and makes sure, on submit, that the
names and birthdate are the ones ID Austria returned. You need Statamic 5 (Laravel 10 or later), your
FLINK **tenant id** and **journey id** (both on the journey's *Start & integrate* tab in the FLINK
console), and the form page's origin registered there as an allowed origin.

What you end up with:

| Field                            | On submit                                                                              |
| -------------------------------- | -------------------------------------------------------------------------------------- |
| First name, last name, birthdate | Must equal what ID Austria returned, or the submission is rejected                     |
| Address                          | May be changed (people move); stored with `address_verified` off when it was          |
| Not prefilled at all             | Accepted, stored with `identity_verified` off: your call what that means for you       |

How it works: when the applicant finishes ID Austria, the page receives the data for the form and the
same data signed by FLINK (`prefillJwt`). The page posts the signed copy to your backend right away;
the backend verifies it and keeps the verified data in the visitor's session. On submit, the form's
values are compared with that. The signed copy is valid for 5 minutes, so verifying it immediately
means a slow applicant is never caught out by the expiry.

## 1. Install

```bash
composer require mohemian/flink-sdk-php
```

`.env`:

```dotenv
FLINK_TENANT_ID=1ed9dfea-…
FLINK_JOURNEYS=SCD7DDPK258G
# Only if the form page is not on APP_URL's origin:
# FLINK_AUDIENCE=https://www.example.at
```

The package verifies against FLINK's published keys, which it fetches and caches. It accepts only
data that came from an eID route (`eid`: ID Austria, SkIDentity or SK Authentigate; the JWT does not
say which); to change that, publish the config
(`php artisan vendor:publish --tag=flink-config`) and edit `allowed_methods`.

## 2. A reference per visitor

The reference ties the ID Austria result to the visitor's own session, so a result copied out of
someone else's browser is refused. Create a tag:

```bash
php please make:tag Flink
```

`app/Tags/Flink.php`:

```php
<?php

namespace App\Tags;

use Illuminate\Support\Str;
use Statamic\Tags\Tags;

class Flink extends Tags
{
    /** {{ flink:reference }}: one random reference per session, rotated after a submission. */
    public function reference(): string
    {
        return session('flink.reference') ?? tap(Str::random(40), fn ($r) => session(['flink.reference' => $r]));
    }
}
```

> **Static caching:** the form page renders a per-visitor value, so exclude it from Statamic's static
> cache (`config/statamic/static_caching.php`, `exclude`). A cached page would hand every visitor the
> same reference, and every prefill would be refused.

## 3. Verify the prefill when it arrives

`routes/web.php`:

```php
use Illuminate\Http\Request;
use Mohemian\Flink\Exceptions\InvalidPrefill;
use Mohemian\Flink\PrefillVerifier;

Route::post('/flink/prefill', function (Request $request, PrefillVerifier $flink) {
    try {
        $verified = $flink->verify((string) $request->input('prefill_jwt'), (string) session('flink.reference'));
    } catch (InvalidPrefill $e) {
        logger()->warning('FLINK prefill refused', ['reason' => $e->getMessage()]); // never log the JWT

        return response()->noContent(422);
    }
    session(['flink.verified' => $verified]);

    return response()->noContent();
})->middleware('throttle:20,1');
```

The route sits in the `web` group, so it has the session and Laravel's CSRF protection; the page
sends the token below.

## 4. The form

```antlers
{{ form:application }}
    <button id="flink-prefill" type="button" hidden>Mit ID Austria ausfüllen</button>

    <input name="first_name">
    <input name="last_name">
    <input name="birthdate" type="date">
    <input name="street">
    <input name="house_number">
    <input name="zip">
    <input name="city">

    <button type="submit">Absenden</button>
{{ /form:application }}

<script src="https://cdn.mohemian.rocks/sdk/web/0.3.0/flink.iife.min.js"
        integrity="sha384-…" crossorigin="anonymous"></script>
<script>
  const form = document.querySelector('form');
  const button = document.getElementById('flink-prefill');
  const { open, isAvailable } = Flink.createFlink({
    journey: 'SCD7DDPK258G', // one of FLINK_JOURNEYS
    reference: '{{ flink:reference }}',
    onResult: async ({ data, prefillJwt }) => {
      const verified = await fetch('/flink/prefill', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token }}' },
        body: JSON.stringify({ prefill_jwt: prefillJwt }),
      });
      if (!verified.ok) return; // nothing prefilled; the applicant types it in
      // .value, never innerHTML.
      form.elements.first_name.value = data.givenName ?? '';
      form.elements.last_name.value = data.familyName ?? '';
      form.elements.birthdate.value = data.birthdate ?? '';
      form.elements.street.value = data.address?.street ?? '';
      form.elements.house_number.value = data.address?.buildingNumber ?? '';
      form.elements.zip.value = data.address?.postalCode ?? '';
      form.elements.city.value = data.address?.municipality ?? '';
      // Locked fields: the backend rejects a change anyway, this just says so up front.
      for (const name of ['first_name', 'last_name', 'birthdate']) form.elements[name].readOnly = true;
    },
  });
  button.addEventListener('click', open);
  isAvailable().then((ok) => (button.hidden = !ok));
</script>
```

Take the exact script URL and `integrity` value from the journey's *Start & integrate* tab.

Add two **Toggle** fields to the form's blueprint, `identity_verified` and `address_verified`, so the
result shows next to each submission in the control panel.

## 5. Check the submission

`app/Listeners/VerifyFlinkPrefill.php`:

```php
<?php

namespace App\Listeners;

use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Mohemian\Flink\PrefillVerifier;
use Statamic\Events\FormSubmitted;

class VerifyFlinkPrefill
{
    /** Your field handles => the fields FLINK releases. These must not change. */
    private const LOCKED = [
        'first_name' => 'givenName',
        'last_name' => 'familyName',
        'birthdate' => 'birthdate',
    ];

    /** These may change; the submission records whether they did. */
    private const ADDRESS = [
        'street' => 'address.street',
        'house_number' => 'address.buildingNumber',
        'zip' => 'address.postalCode',
        'city' => 'address.municipality',
    ];

    public function handle(FormSubmitted $event): void
    {
        $submission = $event->submission;
        if ($submission->form()->handle() !== 'application') {
            return;
        }

        $verified = session('flink.verified');
        if (! is_array($verified)) {
            $submission->set('identity_verified', false);
            $submission->set('address_verified', false);

            return; // filled in by hand
        }

        $fields = self::LOCKED + self::ADDRESS;
        $submitted = [];
        foreach ($fields as $handle => $field) {
            $submitted[$field] = $submission->get($handle);
        }
        $released = Arr::dot($verified);
        $changed = PrefillVerifier::diff($submitted, Arr::only($released, array_values($fields)));

        $lockedChanged = array_intersect(self::LOCKED, $changed);
        if ($lockedChanged !== []) {
            throw ValidationException::withMessages(
                array_map(fn () => 'Dieser Wert muss Ihren ID Austria-Daten entsprechen.', $lockedChanged)
            );
        }

        // A locked field the journey did not release was never checked, so it is not verified.
        $submission->set('identity_verified', array_diff(self::LOCKED, array_keys($released)) === []);
        $submission->set('address_verified', array_intersect(self::ADDRESS, $changed) === []);

        // One ID Austria run, one application: the next one in this session needs its own.
        $submission->afterSave(fn () => session()->forget(['flink.reference', 'flink.verified']));
    }
}
```

The rotation runs on `afterSave`, which Statamic only calls when the form stores submissions
(`store: true`, the default). If yours does not store them, clear the two session keys at the end of
`handle()` instead.

Laravel 11 and later discover the listener by its type hint. On Laravel 10, register it in
`App\Providers\EventServiceProvider::$listen`:

```php
\Statamic\Events\FormSubmitted::class => [\App\Listeners\VerifyFlinkPrefill::class],
```

Only fields the journey releases can be compared. A locked field it does not release leaves
`identity_verified` off rather than passing unchecked, so release all three. If yours does not release the address, drop
`ADDRESS` and `address_verified`.

## 6. Try it

1. Open the form page, click *Mit ID Austria ausfüllen*, finish the ID Austria login. The form fills,
   and the browser's network tab shows `POST /flink/prefill` answering `204`.
2. Submit unchanged: saved, both toggles on.
3. Prefill again, change the street, submit: saved, `address_verified` off.
4. Prefill again, change the last name (developer tools, since the field is read-only), submit:
   rejected, with the message on the field.
5. Copy the `prefill_jwt` from that request and post it to `/flink/prefill` from a private window:
   `422`, because that session has another reference.

## When `/flink/prefill` answers 422

The logged reason says which check failed. The usual causes:

| Reason                                           | Cause                                                                          |
| ------------------------------------------------ | ------------------------------------------------------------------------------ |
| `Claim aud does not match`                       | `APP_URL`'s origin is not the page's: set `FLINK_AUDIENCE`                     |
| `Claim reference does not match`                 | The page is statically cached, or the session cookie did not reach the route   |
| `Claim journey does not match`                   | The page's journey is not in `FLINK_JOURNEYS`                                  |
| `Claim tenant_id does not match`                 | `FLINK_TENANT_ID` is another tenant's                                          |
| `The data does not come from an accepted method` | The journey ran a route other than eID that `allowed_methods` excludes, or has no identity step at all (email only) |
