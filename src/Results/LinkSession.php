<?php

declare(strict_types=1);

namespace Mohemian\Flink\Results;

/**
 * A session started at a chosen country and eID (`Flink::startSession()`, FLNK-1182), and the FLINK
 * link that takes the person straight to that eID's login. Keep `sessionId` with your logged-in user:
 * `completeReturn()` must only ever confirm their own session.
 */
final readonly class LinkSession
{
    public function __construct(
        public string $sessionId,
        public string $tenantId,
        /** The FLINK link to send the person to; never the provider's own address. */
        public string $url,
        /** ISO 8601: until when the link can start a login. */
        public string $expiresAt,
    ) {}

    /** FLINK's answer to a start, or null when it is not one, or its link is not https. */
    public static function fromResponse(mixed $body): ?self
    {
        if (! is_array($body) || ! is_array($body['link'] ?? null)) {
            return null;
        }
        $sessionId = $body['session_id'] ?? null;
        $tenantId = $body['tenant_id'] ?? null;
        $url = $body['link']['url'] ?? null;
        $expiresAt = $body['link']['expires_at'] ?? null;
        // The link goes to a browser: only an absolute https url with a host and no credentials, as
        // FLINK always sends.
        if (! is_string($sessionId) || ! is_string($tenantId) || ! is_string($url) || ! self::isHttpsLink($url) || ! is_string($expiresAt)) {
            return null;
        }

        return new self($sessionId, $tenantId, $url, $expiresAt);
    }

    /**
     * Only an absolute `https` URL with a host and no credentials is a link this package hands
     * back to send a person to: anything else is not FLINK's link.
     */
    private static function isHttpsLink(string $url): bool
    {
        $parts = parse_url($url);

        return is_array($parts)
            && ($parts['scheme'] ?? null) === 'https'
            && ($parts['host'] ?? '') !== ''
            && ! isset($parts['user'])
            && ! isset($parts['pass']);
    }
}
