<?php

declare(strict_types=1);

namespace BladeCP\CpanelMigrator\Web;

/** CGI response with security headers. */
final class Response
{
    /**
     * Every plugin document (standalone or inside the WHM frame): only its
     * nonce-tagged script runs, and only same-origin pages may frame it.
     */
    private const CSP_PLUGIN = "default-src 'self'; img-src 'self' data:; style-src 'unsafe-inline'; script-src 'nonce-%s'; "
        . "form-action 'self'; frame-ancestors 'self'; base-uri 'none'";

    /**
     * Outer WHM page: the WHM header and footer printed by cPanel around the
     * plugin iframe. That chrome carries inline scripts without a nonce,
     * inline event handlers and, on branded servers, resources from other
     * origins, so this page has no fetch directives. It holds no plugin
     * content, form or account data (see View::whmShell).
     */
    private const CSP_WHM_SHELL = "object-src 'none'; form-action 'self'; frame-ancestors 'self'; base-uri 'none'";

    /** @param array<string,string> $headers */
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly array $headers = []
    ) {
    }

    /** Plugin document, always under the strict nonce policy. */
    public static function html(string $body, string $nonce, int $status = 200): self
    {
        return new self($status, $body, self::protection(sprintf(self::CSP_PLUGIN, $nonce)));
    }

    /** Same-origin font file, cached by the browser for a year (the URL carries the plugin version). */
    public static function font(string $bytes): self
    {
        return new self(200, $bytes, [
            'Content-Type' => 'font/woff2',
            'Cache-Control' => 'private, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
            'Cross-Origin-Resource-Policy' => 'same-origin',
        ]);
    }

    public static function notFound(): self
    {
        return new self(404, "Not found\n", ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    /** Outer WHM page that only frames the plugin document. */
    public static function whmShell(string $body): self
    {
        return new self(200, $body, self::protection(self::CSP_WHM_SHELL));
    }

    /** @return array<string,string> */
    private static function protection(string $policy): array
    {
        return [
            'Content-Type' => 'text/html; charset=utf-8',
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'same-origin',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Content-Security-Policy' => $policy,
        ];
    }

    public static function redirect(string $location): self
    {
        // If a panel ever ignores the CGI status, the body still takes the browser there.
        $href = htmlspecialchars($location, ENT_QUOTES);
        return new self(303, '<!doctype html><title>Redirect</title><meta http-equiv="refresh" content="0;url=' . $href . '"><a href="' . $href . '">Continue</a>', [
            'Location' => $location,
            'Content-Type' => 'text/html; charset=utf-8',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * Redirect to another site (the migration preview): the panel URL, with
     * its session token, never goes along as the referrer.
     */
    public static function away(string $location): self
    {
        $redirect = self::redirect($location);
        return new self(303, $redirect->body, $redirect->headers + ['Referrer-Policy' => 'no-referrer', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function header(string $name): ?string
    {
        return $this->headers[$name] ?? null;
    }

    /** Writes the response. Under the CLI SAPI the CGI headers are printed directly. */
    public function emit(): void
    {
        $reason = [200 => 'OK', 303 => 'See Other', 400 => 'Bad Request', 403 => 'Forbidden', 404 => 'Not Found', 422 => 'Unprocessable Entity', 500 => 'Internal Server Error'][$this->status] ?? 'OK';
        if (PHP_SAPI === 'cli') {
            $out = 'Status: ' . $this->status . ' ' . $reason . "\r\n";
            foreach ($this->headers as $name => $value) {
                $out .= $name . ': ' . str_replace(["\r", "\n"], '', $value) . "\r\n";
            }
            echo $out . "\r\n" . $this->body;
            return;
        }
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . str_replace(["\r", "\n"], '', $value));
        }
        echo $this->body;
    }
}
