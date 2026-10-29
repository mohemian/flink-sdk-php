<?php

namespace Mohemian\Flink\Verification;

use Firebase\JWT\BeforeValidException;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Mohemian\Flink\Assertions\Assertion;
use Mohemian\Flink\Contracts\KeySet;
use Mohemian\Flink\Exceptions\FlinkException;
use Mohemian\Flink\Exceptions\InvalidAssertion;
use Mohemian\Flink\Exceptions\InvalidSignature;
use Mohemian\Flink\Exceptions\KeysUnavailable;

/**
 * What every FLINK assertion shares: a signature by a key in FLINK's JWKS, chosen by `kid`, in the
 * one algorithm the assertion type is signed with (the JWKS serves keys of more than one); the `typ`
 * the caller expects, so one assertion type is never accepted as another; FLINK as `iss`; your
 * tenant. Everything type-specific is the assertion class's to check.
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
     * @param  class-string<Assertion>  $assertion
     * @return array<string, mixed> the verified claims
     *
     * @throws FlinkException
     * @throws KeysUnavailable
     */
    public function claims(string $jwt, string $assertion): array
    {
        $alg = $assertion::algorithm();
        $header = $this->header($jwt, $alg);
        if (($header['typ'] ?? null) !== $assertion::type()) {
            throw InvalidAssertion::wrongType($assertion::type());
        }
        // After the typ, so a token of another kind is named as such rather than as a bad algorithm.
        if ($header['alg'] !== $alg) {
            throw InvalidSignature::malformed($alg);
        }

        try {
            $payload = $this->decode($jwt, $this->keys->key($header['kid'], $alg));
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
        foreach (['iss' => $this->issuer, $assertion::tenantClaim() => $this->tenantId] as $claim => $value) {
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

    /** @return array{alg: string, kid: string, typ?: mixed} */
    private function header(string $jwt, string $alg): array
    {
        $parts = explode('.', $jwt);
        try {
            $header = count($parts) === 3 ? json_decode(JWT::urlsafeB64Decode($parts[0]), true) : null;
        } catch (\Throwable) {
            $header = null;
        }
        if (! is_array($header) || ! is_string($header['alg'] ?? null) || ! is_string($header['kid'] ?? null)) {
            throw InvalidSignature::malformed($alg);
        }

        return $header;
    }
}
