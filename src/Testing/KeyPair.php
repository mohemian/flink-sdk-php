<?php

namespace Mohemian\Flink\Testing;

/** An RS256 key pair for tests, the public half as a JWK the way FLINK's JWKS serves it. */
final class KeyPair
{
    /** @return array{private: string, jwk: array<string, string>} */
    public static function generate(string $kid): array
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if ($key === false || ! openssl_pkey_export($key, $private) || ($details = openssl_pkey_get_details($key)) === false) {
            throw new \RuntimeException('Could not generate an RSA key: '.openssl_error_string());
        }
        $b64 = fn (string $bin): string => rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');

        return [
            'private' => $private,
            'jwk' => ['kty' => 'RSA', 'kid' => $kid, 'alg' => 'RS256', 'use' => 'sig', 'n' => $b64($details['rsa']['n']), 'e' => $b64($details['rsa']['e'])],
        ];
    }
}
