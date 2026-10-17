<?php

declare(strict_types=1);

namespace BladeCP\CpanelMigrator\Web;

/**
 * WHM header and footer (top bar, left menu and closing markup) from the PHP
 * library cPanel ships for plugins, as documented in "Guide to WHM Plugins -
 * Interfaces" and "Tutorial - Create a New WHM Interface in PHP":
 *
 *   require_once '/usr/local/cpanel/php/WHM.php';
 *   WHM::header($title, $skipsupport, $skipheader);
 *   ... plugin content ...
 *   WHM::footer();
 *
 * The header opens the whole document (doctype, head, body and the WHM
 * chrome) and the footer closes it. Both calls print, so they are captured
 * with output buffering: the plugin sends its CGI headers first and the body
 * afterwards. The chrome only wraps the outer page with the plugin iframe
 * (View::whmShell); the plugin screens never share a document with it.
 * Without the library (tests, CI, a broken install) parts() returns null and
 * the plugin renders a standalone page.
 */
final class WhmChrome
{
    public const LIBRARY = '/usr/local/cpanel/php/WHM.php';
    public const TITLE = 'BladeCP cPanel Migrator';

    /** @var array{0:string,1:string}|null */
    private ?array $parts = null;
    private bool $resolved = false;

    public function __construct(private readonly string $library = self::LIBRARY)
    {
    }

    /** @return array{0:string,1:string}|null WHM header and footer, or null for a standalone page */
    public function parts(): ?array
    {
        if (!$this->resolved) {
            $this->resolved = true;
            $this->parts = $this->load();
        }
        return $this->parts;
    }

    public function active(): bool
    {
        return $this->parts() !== null;
    }

    /** @return array{0:string,1:string}|null */
    private function load(): ?array
    {
        if (!is_file($this->library) || !is_readable($this->library)) {
            return null;
        }
        $library = $this->library;
        $header = self::capture(static function () use ($library): void {
            require_once $library;
            if (!class_exists('WHM', false) || !method_exists('WHM', 'header') || !method_exists('WHM', 'footer')) {
                throw new \RuntimeException('WHM library without header and footer');
            }
            \WHM::header(self::TITLE, 0, 0);
        });
        $footer = $header === null ? null : self::capture(static function (): void {
            \WHM::footer();
        });
        if ($header === null || $footer === null) {
            return null;
        }
        $header = self::withoutCgiHeaders($header);
        if (trim($header) === '' || trim($footer) === '') {
            return null;
        }
        return [$header, $footer];
    }

    /**
     * Runs a library call and returns what it printed. Notices and warnings
     * from the library (for example a missing header cache) are kept out of
     * the page; an exception means "no chrome".
     */
    private static function capture(callable $call): ?string
    {
        $level = ob_get_level();
        set_error_handler(static fn (): bool => true);
        ob_start();
        try {
            $call();
            return (string) ob_get_clean();
        } catch (\Throwable) {
            return null;
        } finally {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
            restore_error_handler();
        }
    }

    /**
     * Drops CGI header lines ("Content-type: text/html" and a blank line)
     * printed before the markup, so they never end up inside the body.
     */
    private static function withoutCgiHeaders(string $output): string
    {
        if (preg_match('/\A(?:[A-Za-z][A-Za-z0-9-]*:[^\r\n]*\r?\n)+\r?\n/', $output, $match) === 1) {
            return substr($output, strlen($match[0]));
        }
        return $output;
    }
}
