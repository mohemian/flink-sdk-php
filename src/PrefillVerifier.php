<?php

namespace Mohemian\Flink;

use Firebase\JWT\JWT;
use Illuminate\Support\Arr;
use Mohemian\Flink\Exceptions\InvalidPrefill;

/**
 * Verifies the `flow-prefill+jwt` an embedded journey hands the page, so a backend can trust the
 * values it prefilled rather than whatever the form submitted.
 */
final class PrefillVerifier
{
    /**
     * @param  list<string>  $allowedMethods
     */
    public function __construct(
        private readonly Jwks $jwks,
        private readonly string $issuer,
        private readonly string $audience,
        private readonly string $tenantId,
        private readonly string $journey,
        private readonly array $allowedMethods,
    ) {
    }

    /**
     * Returns the verified `data`. `$reference` is the one your backend issued for this user's
     * session and passed to `createFlink({ reference })`: a JWT only verifies in the session that
     * launched it, so a stolen or borrowed one is refused, while a retried submit still works.
     *
     * @return array<string, mixed>
     *
     * @throws InvalidPrefill
     */
    public function verify(string $jwt, string $reference): array
    {
        $claims = $this->decode($jwt);

        $expected = [
            'iss' => $this->issuer,
            'aud' => $this->audience,
            'ver' => 1,
            'tenant_id' => $this->tenantId,
            'journey' => $this->journey,
            'reference' => $reference,
        ];
        foreach ($expected as $claim => $value) {
            if (($claims[$claim] ?? null) !== $value) {
                throw new InvalidPrefill("Claim {$claim} does not match");
            }
        }
        if (! in_array($claims['method'] ?? null, $this->allowedMethods, true)) {
            throw new InvalidPrefill('The data does not come from an accepted method');
        }
        // php-jwt checks `exp` only when present; FLINK always sets it, so its absence is a forgery.
        if (! isset($claims['exp']) || ! is_array($claims['data'] ?? null)) {
            throw new InvalidPrefill('Missing exp or data');
        }

        return $claims['data'];
    }

    /**
     * The verified fields the submission does not match, as dotted keys (`address.street`). Values
     * are compared as strings after trimming and Unicode NFC normalisation, nothing more; a verified
     * field missing from the submission counts as changed, and fields the JWT does not carry are
     * not compared.
     *
     * @param  array<string, mixed>  $submitted
     * @param  array<string, mixed>  $verified
     * @return list<string>
     */
    public static function diff(array $submitted, array $verified): array
    {
        $submitted = Arr::dot($submitted);
        $changed = [];
        foreach (Arr::dot($verified) as $field => $value) {
            if (! array_key_exists($field, $submitted) || self::normalise($submitted[$field]) !== self::normalise($value)) {
                $changed[] = (string) $field;
            }
        }

        return $changed;
    }

    /** @return array<string, mixed> */
    private function decode(string $jwt): array
    {
        $header = json_decode(JWT::urlsafeB64Decode(explode('.', $jwt)[0]), true);
        if (! is_array($header) || ($header['alg'] ?? null) !== 'RS256' || ($header['typ'] ?? null) !== 'flow-prefill+jwt' || ! is_string($header['kid'] ?? null)) {
            throw new InvalidPrefill('Not an RS256 flow-prefill+jwt');
        }

        try {
            $payload = JWT::decode($jwt, $this->jwks->key($header['kid']));
        } catch (InvalidPrefill $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new InvalidPrefill($e->getMessage(), previous: $e);
        }

        return json_decode((string) json_encode($payload), true);
    }

    private static function normalise(mixed $value): string
    {
        $string = is_bool($value) ? ($value ? '1' : '0') : trim((string) $value);

        return \Normalizer::normalize($string, \Normalizer::FORM_C) ?: $string;
    }
}
