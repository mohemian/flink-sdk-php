<?php

declare(strict_types=1);

namespace Mohemian\Flink\Results;

use Firebase\JWT\JWT;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;
use Mohemian\Flink\Exceptions\MissingConfiguration;
use Mohemian\Flink\Exceptions\ResultUnavailable;
use Mohemian\Flink\Exceptions\SessionRefused;

/**
 * Fetches a session's signed result as your backend's own client (FLNK-861). The client proves
 * itself with `private_key_jwt` (RFC 7523): a short assertion signed with your private key, so
 * FLINK holds only the public half and no shared secret exists. Or with a journey API key
 * (FLNK-859), sent as `X-Api-Key`: it reads any session of its own journey. The client token is
 * cached until shortly before it expires. Neither request follows a redirect: each carries a
 * credential, which must not be sent on to another origin.
 */
final readonly class ResultClient
{
    private const ASSERTION_TYPE = 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer';

    /** Seconds an assertion is good for. FLINK takes at most 300, and each is used at once. */
    private const ASSERTION_LIFETIME = 60;

    /** Renew a token this many seconds before FLINK says it expires. */
    private const EXPIRY_MARGIN = 30;

    /** The subject FLINK stamps on an API key's token, and so the `aud` of its results. */
    private const API_KEY_SUBJECT_PREFIX = 'apikey:';

    public function __construct(
        private Http $http,
        private Cache $cache,
        private string $url,
        private string $tenantId,
        /** Your backend's client id; null with an API key. */
        private ?string $clientId,
        private ?string $privateKey,
        private ?string $keyId,
        private string $cacheKey,
        /** A journey API key (`flk_…`, FLNK-859), instead of the client id and its key. */
        private ?string $apiKey = null,
    ) {}

    /**
     * Whom FLINK signs this client's results for: the client id, or with an API key the key's own
     * subject (`apikey:<key id>`) as FLINK stamped it on the key's token. A key's subject never
     * changes, so it is kept once known, and a kept result verifies without asking FLINK again.
     *
     * @throws ResultUnavailable
     * @throws MissingConfiguration
     */
    public function audience(): string
    {
        if ($this->clientId !== null) {
            return $this->clientId;
        }
        $audience = $this->cache->get($this->audienceKey());
        if (! is_string($audience)) {
            // A cached token says nothing about its subject: ask for a new one.
            $this->cache->forget($this->cacheKey);
            $this->token();
            $audience = $this->cache->get($this->audienceKey());
        }

        // Anything but a key's subject leaves the audience unknown, so no result verifies.
        return is_string($audience) ? $audience : throw new ResultUnavailable('FLINK named no API key as the token subject', 200);
    }

    /**
     * The `signedResult` FLINK hands over for a completed session, not yet verified.
     *
     * @throws ResultUnavailable
     * @throws MissingConfiguration
     */
    public function signedResult(string $sessionId): string
    {
        $response = $this->get('result', $this->sessionPath($sessionId).'/results');
        if ($response->status() === 409) {
            $status = $response->json('status');
            $reason = $response->json('reason');
            $eidError = $response->json('eid_error');
            throw ResultUnavailable::notReady(
                is_string($status) ? $status : null,
                is_string($reason) ? $reason : null,
                is_string($eidError) ? $eidError : null,
            );
        }
        $signed = $response->json('signedResult');
        if (! $response->ok() || ! is_string($signed)) {
            throw ResultUnavailable::answered('result', $response->status());
        }

        return $signed;
    }

    /**
     * A session's state, read with a journey API key's token (FLNK-859): FLINK lets a key read any
     * session of its journey, and a client id none it is not bound to.
     *
     * @throws ResultUnavailable
     * @throws MissingConfiguration
     */
    public function session(string $sessionId): FlowSession
    {
        if ($this->apiKey === null) {
            throw new MissingConfiguration('Set FLINK_API_KEY: only a journey API key reads a session it did not run');
        }
        $response = $this->get('session', $this->sessionPath($sessionId));
        if (! $response->ok()) {
            throw ResultUnavailable::answered('session', $response->status());
        }

        return FlowSession::fromResponse($response->json(), $sessionId)
            ?? throw new ResultUnavailable('FLINK answered the session request with something other than that session', 200);
    }

    /**
     * A journey's countries and the eIDs it offers in each (FLNK-1181). FLINK requires the
     * `flow-session:write` scope for it, so a `private_key_jwt` client must be registered with that scope;
     * a results-only client lacks it and gets 403. A key reads its own journey, a client id any journey
     * of its tenant.
     *
     * @throws ResultUnavailable 403 a client without `flow-session:write`, 404 a journey that does not exist or this credential may not read
     * @throws MissingConfiguration
     */
    public function countries(string $journey): JourneyCountries
    {
        $response = $this->get('countries', '/journeys/'.rawurlencode($journey).'/countries');
        if (! $response->ok()) {
            throw ResultUnavailable::answered('countries', $response->status());
        }

        return JourneyCountries::fromResponse($response->json())
            ?? throw new ResultUnavailable('FLINK answered the countries request with something other than countries', 200);
    }

    /**
     * Start a session behind a FLINK link (FLNK-1182): at one eID of one country, or hosted
     * (FLNK-1272), with the person choosing on FLINK's screens, when `$eidEntry` is null.
     *
     * @param  array{revision?: string, locale?: string}|null  $notice
     *
     * @throws \InvalidArgumentException an `$eidEntry` without a `$country`, or a `$clientReferenceId` FLINK would refuse
     * @throws SessionRefused FLINK will not start it, and says why
     * @throws ResultUnavailable 403 a client without `flow-session:write` or not a relying party's backend, 404 a journey this credential may not start, 429 too many starts, or FLINK unreachable
     * @throws MissingConfiguration
     */
    public function startSession(string $journey, ?string $country, ?int $eidEntry, ?string $clientIp, ?string $userAgent, ?array $notice = null, ?string $clientReferenceId = null): LinkSession
    {
        // A mistake in the caller's code, so refused before a rate-limited start is spent on it.
        if ($eidEntry !== null && $country === null) {
            throw new \InvalidArgumentException('an eID entry needs its country');
        }
        // The shape FLINK accepts (FLNK-1279): a mistake in the caller's code, so refused before any call.
        if ($clientReferenceId !== null && preg_match('/^[A-Za-z0-9._:-]{1,128}$/D', $clientReferenceId) !== 1) {
            throw new \InvalidArgumentException('clientReferenceId must be 1 to 128 characters from A-Z a-z 0-9 . _ : -');
        }
        $response = $this->post('link', '/journeys/'.rawurlencode($journey).'/sessions', array_filter([
            'country' => $country,
            'eid_entry' => $eidEntry,
            'client_ip' => $clientIp,
            'user_agent' => $userAgent,
            'notice' => $notice,
            'client_reference_id' => $clientReferenceId,
        ], fn (mixed $value): bool => ! in_array($value, [null, '', []], true)));
        $code = $response->json('code');
        if ($response->status() === 400 && is_string($code)) {
            $step = $response->json('step');
            throw new SessionRefused($code, is_string($step) ? $step : null);
        }
        if ($response->status() !== 201) {
            throw ResultUnavailable::answered('link', $response->status());
        }

        return LinkSession::fromResponse($response->json())
            ?? throw new ResultUnavailable('FLINK answered the link request with something other than a session and its https link', 201);
    }

    /**
     * Confirm the person came back with the session's return code (FLNK-1182): true when FLINK
     * released the result (again on a repeat), false for a wrong, expired or foreign code, or none:
     * a session that did not complete returns the person with no code.
     *
     * @throws ResultUnavailable
     * @throws MissingConfiguration
     */
    public function completeReturn(string $sessionId, ?string $code): bool
    {
        // FLINK's codes are short; one it could not have issued is false without asking.
        if ($code === null || $code === '' || strlen($code) > 128) {
            return false;
        }
        $response = $this->post('return', $this->sessionPath($sessionId).'/return', ['code' => $code]);

        return match ($response->status()) {
            204 => true,
            400 => false,
            default => throw ResultUnavailable::answered('return', $response->status()),
        };
    }

    /**
     * A POST under FLINK's client API, with the client token.
     *
     * @param  array<string, mixed>  $body
     *
     * @throws ResultUnavailable
     * @throws MissingConfiguration
     */
    private function post(string $step, string $path, array $body): Response
    {
        $response = $this->send($step, fn (): Response => $this->http->timeout(10)->withoutRedirecting()
            ->withToken($this->token())
            ->post("{$this->url}/client/v1{$path}", $body));
        if ($response->status() === 401) {
            $this->cache->forget($this->cacheKey);
        }

        return $response;
    }

    /**
     * A GET under FLINK's client API, with the client token.
     *
     * @throws ResultUnavailable
     * @throws MissingConfiguration
     */
    private function get(string $step, string $path): Response
    {
        $response = $this->send($step, fn (): Response => $this->http->timeout(10)->withoutRedirecting()
            ->withToken($this->token())
            ->get("{$this->url}/client/v1{$path}"));
        if ($response->status() === 401) {
            // Revoked or its signing key rotated before it expired: the next call fetches another.
            $this->cache->forget($this->cacheKey);
        }

        return $response;
    }

    private function tenantPath(): string
    {
        return "/tenants/{$this->tenantId}";
    }

    private function sessionPath(string $sessionId): string
    {
        return $this->tenantPath().'/sessions/'.rawurlencode($sessionId);
    }

    private function token(): string
    {
        $token = $this->cache->get($this->cacheKey);
        if (is_string($token)) {
            return $token;
        }
        // An API key is the whole credential: FLINK refuses it beside an assertion or a client id.
        $response = $this->send('token', fn (): Response => $this->apiKey !== null
            ? $this->http->timeout(10)->withoutRedirecting()->withHeaders(['X-Api-Key' => $this->apiKey])->asForm()
                ->post("{$this->url}/client/v1/auth/token", ['grant_type' => 'client_credentials'])
            : $this->http->timeout(10)->withoutRedirecting()->asForm()->post("{$this->url}/client/v1/auth/token", [
                'grant_type' => 'client_credentials',
                'client_assertion_type' => self::ASSERTION_TYPE,
                'client_assertion' => $this->assertion(),
            ]));
        $token = $response->json('access_token');
        if (! $response->ok() || ! is_string($token)) {
            throw ResultUnavailable::answered('token', $response->status());
        }
        if ($this->apiKey !== null) {
            $subject = $this->subject($token);
            if ($subject !== null && str_starts_with($subject, self::API_KEY_SUBJECT_PREFIX)) {
                $this->cache->forever($this->audienceKey(), $subject);
            }
        }
        $expiresIn = $response->json('expires_in');
        $ttl = (is_int($expiresIn) ? $expiresIn : 60) - self::EXPIRY_MARGIN;
        if ($ttl > 0) {
            $this->cache->put($this->cacheKey, $token, $ttl);
        }

        return $token;
    }

    private function audienceKey(): string
    {
        return "{$this->cacheKey}:audience";
    }

    /**
     * The token's subject. Read, not verified: the token came straight from FLINK over TLS, and
     * FLINK checks it again on every call, so a wrong value can only fail closed.
     */
    private function subject(string $token): ?string
    {
        $claims = json_decode(JWT::urlsafeB64Decode(explode('.', $token)[1] ?? ''), true);
        $subject = is_array($claims) ? ($claims['sub'] ?? null) : null;

        return is_string($subject) ? $subject : null;
    }

    /** A fresh assertion: FLINK accepts each `jti` once, and only its own issuer as `aud`. */
    private function assertion(): string
    {
        $key = openssl_pkey_get_private((string) $this->privateKey);
        $details = $key === false ? false : openssl_pkey_get_details($key);
        $alg = match (true) {
            $details === false => null,
            $details['type'] === OPENSSL_KEYTYPE_EC && ($details['ec']['curve_name'] ?? null) === 'prime256v1' => 'ES256',
            $details['type'] === OPENSSL_KEYTYPE_RSA && $details['bits'] >= 2048 => 'RS256',
            default => null,
        };
        if ($alg === null) {
            throw new MissingConfiguration('Set FLINK_CLIENT_KEY to a private key FLINK accepts, as PEM: EC P-256 (ES256), or RSA of 2048 bits or more (RS256)');
        }
        $now = time();

        return JWT::encode([
            'iss' => $this->clientId,
            'sub' => $this->clientId,
            'aud' => $this->url,
            'iat' => $now,
            'exp' => $now + self::ASSERTION_LIFETIME,
            'jti' => (string) Str::uuid(),
        ], (string) $this->privateKey, $alg, $this->keyId);
    }

    /**
     * @param  \Closure(): Response  $request
     *
     * @throws ResultUnavailable
     */
    private function send(string $step, \Closure $request): Response
    {
        try {
            return $request();
        } catch (ConnectionException $e) {
            throw ResultUnavailable::unreachable($step, $e);
        }
    }
}
