<?php

namespace Mohemian\Flink\Testing;

/**
 * A key pair for tests, the public half as a JWK the way FLINK's JWKS serves it: RS256 (the prefill's
 * key) or ES256 (the results key, FLNK-861).
 */
final class KeyPair
{
    /** @return array{private: string, jwk: array<string, string>} */
    public static function generate(string $kid, string $alg = 'RS256'): array
    {
        $b64 = fn (string $bin): string => rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
        $options = $alg === 'ES256'
            ? ['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']
            : ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
        $key = openssl_pkey_new($options);
        if ($key === false || ! openssl_pkey_export($key, $private) || ($details = openssl_pkey_get_details($key)) === false) {
            throw new \RuntimeException("Could not generate an {$alg} key: ".openssl_error_string());
        }
        $public = $alg === 'ES256'
            // P-256 coordinates are 32 bytes; OpenSSL drops leading zero bytes.
            ? ['kty' => 'EC', 'crv' => 'P-256', 'x' => $b64(str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT)), 'y' => $b64(str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT))]
            : ['kty' => 'RSA', 'n' => $b64($details['rsa']['n']), 'e' => $b64($details['rsa']['e'])];

        return [
            'private' => $private,
            'jwk' => $public + ['kid' => $kid, 'alg' => $alg, 'use' => 'sig'],
        ];
    }
}
