<?php

declare(strict_types=1);

namespace BladeCP\CpanelMigrator\Web;

/**
 * CSRF protection bound to the WHM session.
 *
 * WHM already rejects requests whose URL lacks the session security token
 * (/cpsessNNNNNNNNNN/). On top of that every form carries an HMAC of that
 * token and of the WHM user, keyed by a random secret stored root-only at
 * install time, and cross-origin POSTs are refused.
 */
final class Csrf
{
    public const FIELD = 'csrf_token';

    public function __construct(
        private readonly string $secret,
        private readonly string $sessionToken,
        private readonly string $user
    ) {
    }

    public function token(): string
    {
        return hash_hmac('sha256', 'bladecp-cpanel-migrator|csrf|' . $this->sessionToken . '|' . $this->user, $this->secret);
    }

    public function valid(mixed $provided): bool
    {
        return strlen($this->secret) >= 32
            && is_string($provided)
            && preg_match('/^[a-f0-9]{64}$/D', $provided) === 1
            && hash_equals($this->token(), $provided);
    }

    /**
     * Rejects a POST whose Origin (or, without Origin, Referer) names
     * another host or port. Requests without both headers are accepted
     * because the token check above still applies.
     *
     * cpsrvd strips the port from HTTP_HOST and reports the port the browser
     * used in SERVER_PORT (2087 directly, 443 behind the whm. proxy), so the
     * expected port comes from the Host value when it has one, otherwise from
     * SERVER_PORT. Without either, only the host name is compared.
     */
    public static function sameOrigin(?string $origin, ?string $referer, string $host, ?string $serverPort = null): bool
    {
        $expected = parse_url('//' . strtolower(trim($host)));
        if (!is_array($expected) || !isset($expected['host']) || $expected['host'] === '') {
            return false;
        }
        $expectedPort = $expected['port'] ?? (preg_match('/^\d{1,5}$/D', (string) $serverPort) === 1 ? (int) $serverPort : null);
        foreach ([$origin, $referer] as $value) {
            if ($value === null || $value === '') {
                continue;
            }
            if ($value === 'null') {
                return false;
            }
            $parts = parse_url($value);
            if (!is_array($parts) || !isset($parts['host'], $parts['scheme'])) {
                return false;
            }
            $port = $parts['port'] ?? match (strtolower($parts['scheme'])) {
                'https' => 443,
                'http' => 80,
                default => null,
            };
            return strtolower($parts['host']) === $expected['host'] && ($expectedPort === null || $port === $expectedPort);
        }
        return true;
    }
}
