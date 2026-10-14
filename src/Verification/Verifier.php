<?php

namespace Mohemian\Flink\Verification;

use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Mohemian\Flink\Contracts\KeySet;
use Mohemian\Flink\Exceptions\FlinkException;
use Mohemian\Flink\Exceptions\InvalidAssertion;
use Mohemian\Flink\Exceptions\InvalidSignature;
use Mohemian\Flink\Exceptions\KeysUnavailable;

/**
 * What every FLINK assertion shares: an RS256 signature by a key in FLINK's JWKS, chosen by `kid`;
 * the `typ` the caller expects, so one assertion type is never accepted as another; FLINK as `iss`;
 * your tenant. Everything type-specific is the assertion class's to check.
 */
final class Verifier
{
    public function __construct(
        private readonly KeySet $keys,
        public readonly string $issuer,
        public readonly string $tenantId,
    ) {
    }

    /**
     * @return array<string, mixed> the verified claims
     *
     * @throws FlinkException
     * @throws KeysUnavailable
     */
    public function claims(string $jwt, string $type): array
    {
        $header = $this->header($jwt);
        if (($header['typ'] ?? null) !== $type) {
            throw InvalidAssertion::wrongType($type);
        }

        try {
            $payload = JWT::decode($jwt, $this->keys->key($header['kid']));
        } catch (FlinkException|KeysUnavailable $e) {
            throw $e;
        } catch (ExpiredException) {
            throw InvalidAssertion::expired();
        } catch (\Throwable $e) {
            throw InvalidSignature::failed($e);
        }

        /** @var array<string, mixed> $claims */
        $claims = json_decode((string) json_encode($payload), true);
        foreach (['iss' => $this->issuer, 'tenant_id' => $this->tenantId] as $claim => $value) {
            if (($claims[$claim] ?? null) !== $value) {
                throw InvalidAssertion::mismatch($claim);
            }
        }

        return $claims;
    }

    /** @return array{alg: 'RS256', kid: string, typ?: mixed} */
    private function header(string $jwt): array
    {
        $parts = explode('.', $jwt);
        try {
            $header = count($parts) === 3 ? json_decode(JWT::urlsafeB64Decode($parts[0]), true) : null;
        } catch (\Throwable) {
            $header = null;
        }
        if (! is_array($header) || ($header['alg'] ?? null) !== 'RS256' || ! is_string($header['kid'] ?? null)) {
            throw InvalidSignature::malformed();
        }

        return $header;
    }
}
