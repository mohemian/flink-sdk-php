<?php

namespace Mohemian\Flink\Verification;

use Firebase\JWT\BeforeValidException;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
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
final readonly class Verifier
{
    /** Seconds of clock skew between FLINK and this server tolerated on `iat`, `nbf` and `exp`. */
    public const LEEWAY = 5;

    public function __construct(
        private KeySet $keys,
        public string $issuer,
        public string $tenantId,
    ) {}

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
            $payload = $this->decode($jwt, $this->keys->key($header['kid']));
        } catch (ExpiredException) {
            throw InvalidAssertion::expired();
        } catch (BeforeValidException) {
            throw InvalidAssertion::notYetValid();
        } catch (\UnexpectedValueException|\DomainException $e) {
            // php-jwt's own failures only: anything else, a cache outage say, is not a forgery.
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

    /**
     * php-jwt takes its leeway only from a static the host app shares, so set it for this decode
     * alone. The key is fetched before, so nothing in between can yield to other code.
     */
    private function decode(string $jwt, Key $key): \stdClass
    {
        $leeway = JWT::$leeway;
        JWT::$leeway = self::LEEWAY;
        try {
            return JWT::decode($jwt, $key);
        } finally {
            JWT::$leeway = $leeway;
        }
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
