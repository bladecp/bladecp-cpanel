<?php

declare(strict_types=1);

namespace BladeCP\CpanelMigrator\Web;

/**
 * HTTP request as seen by a WHM CGI or a cPanel LiveAPI page.
 *
 * cpsrvd runs the plugin as a CGI. Depending on the PHP binary the
 * superglobals may be empty (CLI SAPI), so the query string, the body and
 * the cookies are also read from the CGI environment and standard input.
 */
final class Request
{
    private const MAX_BODY = 1048576;

    /**
     * @param array<string,mixed> $query
     * @param array<string,mixed> $post
     * @param array<string,string> $server
     * @param array<string,string> $cookies
     */
    public function __construct(
        public readonly string $method,
        public readonly array $query,
        public readonly array $post,
        public readonly array $server,
        public readonly array $cookies
    ) {
    }

    public static function fromGlobals(): self
    {
        $server = [];
        foreach (['REQUEST_METHOD', 'QUERY_STRING', 'CONTENT_TYPE', 'CONTENT_LENGTH', 'REMOTE_USER', 'cp_security_token',
            'HTTP_HOST', 'HTTP_ORIGIN', 'HTTP_REFERER', 'HTTP_COOKIE', 'HTTPS', 'SERVER_PORT', 'REQUEST_URI'] as $name) {
            $value = $_SERVER[$name] ?? getenv($name);
            if (is_string($value)) {
                $server[$name] = $value;
            }
        }
        $method = strtoupper($server['REQUEST_METHOD'] ?? 'GET');
        $query = $_GET;
        if ($query === [] && ($server['QUERY_STRING'] ?? '') !== '') {
            parse_str($server['QUERY_STRING'], $query);
        }
        $post = $_POST;
        if ($method === 'POST' && $post === []) {
            $type = strtolower($server['CONTENT_TYPE'] ?? '');
            $length = (int) ($server['CONTENT_LENGTH'] ?? 0);
            if (str_starts_with($type, 'application/x-www-form-urlencoded') && $length > 0 && $length <= self::MAX_BODY) {
                $raw = (string) file_get_contents(PHP_SAPI === 'cli' ? 'php://stdin' : 'php://input', false, null, 0, $length);
                parse_str($raw, $post);
            }
        }
        $cookies = [];
        foreach ($_COOKIE as $name => $value) {
            if (is_string($name) && is_string($value)) {
                $cookies[$name] = $value;
            }
        }
        if ($cookies === [] && ($server['HTTP_COOKIE'] ?? '') !== '') {
            foreach (explode(';', $server['HTTP_COOKIE']) as $pair) {
                $parts = explode('=', trim($pair), 2);
                if (count($parts) === 2 && $parts[0] !== '') {
                    $cookies[$parts[0]] = urldecode($parts[1]);
                }
            }
        }
        return new self($method, $query, $post, $server, $cookies);
    }

    public function isPost(): bool
    {
        return $this->method === 'POST';
    }

    public function query(string $key): ?string
    {
        $value = $this->query[$key] ?? null;
        return is_string($value) ? $value : null;
    }

    public function input(string $key): mixed
    {
        return $this->post[$key] ?? null;
    }

    public function inputString(string $key): string
    {
        $value = $this->post[$key] ?? '';
        return is_string($value) ? $value : '';
    }

    /** WHM session security token path segment, for example "/cpsess0123456789". */
    public function sessionToken(): string
    {
        $token = $this->server['cp_security_token'] ?? '';
        return preg_match('#^/cpsess\d{10}$#D', $token) === 1 ? $token : '';
    }

    public function remoteUser(): string
    {
        return $this->server['REMOTE_USER'] ?? '';
    }

    public function host(): string
    {
        return $this->server['HTTP_HOST'] ?? '';
    }

    public function header(string $name): ?string
    {
        return $this->server[$name] ?? null;
    }
}
