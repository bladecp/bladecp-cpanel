<?php

declare(strict_types=1);

namespace BladeCP\CpanelMigrator\Support;

/**
 * Resolves the interface language from the WHM user locale.
 *
 * Order: explicit plugin setting, the WHM session locale cookie, the locale
 * stored for the WHM user, the server default locale, then English.
 * The cPanel sources are best effort and fail soft (see AGENTS.md, "Fatos
 * do cPanel a confirmar").
 */
final class LocaleResolver
{
    public function __construct(
        private readonly string $cpanelUsersDir = '/var/cpanel/users',
        private readonly string $cpanelConfig = '/var/cpanel/cpanel.config'
    ) {
    }

    /** @param array<string,string> $cookies */
    public function resolve(string $setting, array $cookies, string $remoteUser): string
    {
        if ($setting !== 'auto') {
            return I18n::normalize($setting) ?? I18n::DEFAULT_LOCALE;
        }
        $fromCookie = I18n::normalize($cookies['session_locale'] ?? null);
        if ($fromCookie !== null) {
            return $fromCookie;
        }
        if (preg_match('/^[a-z][a-z0-9]{0,15}$/', $remoteUser) === 1) {
            $fromUser = I18n::normalize($this->readKey($this->cpanelUsersDir . '/' . $remoteUser, 'LOCALE'));
            if ($fromUser !== null) {
                return $fromUser;
            }
        }
        return I18n::normalize($this->readKey($this->cpanelConfig, 'server_locale')) ?? I18n::DEFAULT_LOCALE;
    }

    private function readKey(string $file, string $key): ?string
    {
        if (!is_file($file) || !is_readable($file) || filesize($file) > 1024 * 1024) {
            return null;
        }
        $handle = @fopen($file, 'rb');
        if ($handle === false) {
            return null;
        }
        $value = null;
        while (($line = fgets($handle)) !== false) {
            if (str_starts_with($line, $key . '=')) {
                $value = trim(substr($line, strlen($key) + 1));
                break;
            }
        }
        fclose($handle);
        return $value;
    }
}
