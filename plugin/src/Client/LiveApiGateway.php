<?php

declare(strict_types=1);

namespace BladeCP\CpanelMigrator\Client;

/**
 * Gateway over the cPanel LiveAPI for PHP (/usr/local/cpanel/php/cpanel.php,
 * class CPANEL), available to .live.php pages. The app functions go through
 * UAPI to the module Cpanel::API::BladeCPMigrator, which forwards them to
 * the root AdminBin.
 */
final class LiveApiGateway implements Gateway
{
    public const LIBRARY = '/usr/local/cpanel/php/cpanel.php';
    public const MODULE = 'BladeCPMigrator';
    public const APP_KEY = 'site_migration';

    /** @param object $cpanel a CPANEL instance (or an object with the same methods) */
    public function __construct(private readonly object $cpanel)
    {
    }

    /** Connects to the LiveAPI socket of this request, or null outside a .live.php page. */
    public static function connect(string $library = self::LIBRARY): ?self
    {
        if (!is_file($library) || !is_readable($library)) {
            return null;
        }
        try {
            $level = ob_get_level();
            ob_start();
            require_once $library;
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
            if (!class_exists('CPANEL', false)) {
                return null;
            }
            return new self(new \CPANEL());
        } catch (\Throwable) {
            return null;
        }
    }

    public function call(string $function, array $args): array
    {
        try {
            $response = $this->cpanel->uapi(self::MODULE, $function, $args);
        } catch (\Throwable) {
            throw new GatewayError('cPanel did not answer.');
        }
        $result = is_array($response) ? ($response['cpanelresult']['result'] ?? null) : null;
        if (!is_array($result) || (int) ($result['status'] ?? 0) !== 1 || !is_array($result['data'] ?? null)) {
            throw new GatewayError('The app is not available.');
        }
        return $result['data'];
    }

    public function locale(): ?string
    {
        try {
            $response = $this->cpanel->uapi('Locale', 'get_attributes', []);
        } catch (\Throwable) {
            return null;
        }
        $locale = is_array($response) ? ($response['cpanelresult']['result']['data']['locale'] ?? null) : null;
        return is_string($locale) && preg_match('/^[a-z]{2}(?:[_-][a-z0-9]{2,4})?$/i', $locale) === 1 ? $locale : null;
    }

    public function chrome(string $title): ?array
    {
        $level = ob_get_level();
        set_error_handler(static fn (): bool => true);
        ob_start();
        try {
            $header = $this->cpanel->header($title, self::APP_KEY);
            $footer = $this->cpanel->footer();
        } catch (\Throwable) {
            return null;
        } finally {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
            restore_error_handler();
        }
        if (!is_string($header) || !is_string($footer) || trim($header) === '' || trim($footer) === '') {
            return null;
        }
        return [$header, $footer];
    }
}
