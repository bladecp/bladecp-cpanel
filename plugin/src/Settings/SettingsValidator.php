<?php

declare(strict_types=1);

namespace BladeCP\CpanelMigrator\Settings;

use BladeCP\CpanelMigrator\Api\Client;

/**
 * Validates settings forms. Returns a patch for the stored settings plus a
 * map of field => message key. Nothing is persisted when there are errors.
 */
final class SettingsValidator
{
    private const API_KEY = '/^bcp_[a-z]{2,10}_[A-Za-z0-9]{20,128}$/D';
    private const MAX_INT = 2147483647;

    /** Directories that can never hold backups (exact path or any child). */
    private const FORBIDDEN_TREES = [
        '/etc', '/usr', '/proc', '/sys', '/dev', '/boot', '/run', '/bin', '/sbin', '/lib', '/lib64',
        '/tmp', '/var/tmp', '/var/cpanel', '/var/lib', '/var/log', '/var/run', '/scripts',
    ];

    /** Directories that are too broad to be used directly. */
    private const FORBIDDEN_EXACT = ['/', '/home', '/root', '/var', '/opt', '/srv', '/backup', '/mnt', '/media'];

    /**
     * @param array<string,mixed> $input
     * @return array{0:array<string,mixed>,1:array<string,string>}
     */
    public static function connection(array $input): array
    {
        $patch = [];
        $errors = [];
        $key = self::string($input['api_key'] ?? '');
        if ($key !== '') {
            if (preg_match(self::API_KEY, $key) !== 1) {
                $errors['api_key'] = 'settings.error.api_key_format';
            } else {
                $patch['api_key'] = $key;
            }
        }
        [$url, $urlError] = self::apiBaseUrl(self::string($input['api_base_url'] ?? ''));
        if ($urlError !== null) {
            $errors['api_base_url'] = $urlError;
        } else {
            $patch['api_base_url'] = $url;
        }
        return [$errors === [] ? $patch : [], $errors];
    }

    /**
     * @param array<string,mixed> $input
     * @param array{plans:list<array<string,mixed>>,locations:array<int,list<array<string,mixed>>>}|null $catalog
     * @return array{0:array<string,mixed>,1:array<string,string>}
     */
    public static function defaults(array $input, ?array $catalog): array
    {
        $patch = [];
        $errors = [];

        [$destination, $destinationErrors] = self::destination($input, $catalog);
        $errors += $destinationErrors;
        $patch += $destination;

        [$commercial, $commercialErrors] = self::commercial($input);
        $errors += $commercialErrors;
        $patch += $commercial;

        $native = self::int($input['native_product_id'] ?? null, 1, self::MAX_INT);
        if ($native === null) {
            $errors['native_product_id'] = 'settings.error.native_product_id';
        } else {
            $patch['native_product_id'] = $native;
        }

        $concurrency = self::int($input['concurrency'] ?? null, 1, Settings::MAX_CONCURRENCY);
        if ($concurrency === null) {
            $errors['concurrency'] = 'settings.error.concurrency';
        } else {
            $patch['concurrency'] = $concurrency;
        }

        [$workDir, $workDirError] = self::workDir(self::string($input['work_dir'] ?? ''));
        if ($workDirError !== null) {
            $errors['work_dir'] = $workDirError;
        } else {
            $patch['work_dir'] = $workDir;
        }

        $domainMode = self::string($input['domain_mode'] ?? '');
        if (!in_array($domainMode, Settings::DOMAIN_MODES, true)) {
            $errors['domain_mode'] = 'settings.error.choice';
        } else {
            $patch['domain_mode'] = $domainMode;
        }

        $language = self::string($input['ui_language'] ?? 'auto');
        if (!in_array($language, Settings::LANGUAGES, true)) {
            $errors['ui_language'] = 'settings.error.choice';
        } else {
            $patch['ui_language'] = $language;
        }

        return [$errors === [] ? $patch : [], $errors];
    }

    /**
     * Plan and location must be offered by the API for this partner.
     *
     * @param array<string,mixed> $input
     * @param array{plans:list<array<string,mixed>>,locations:array<int,list<array<string,mixed>>>}|null $catalog
     * @return array{0:array{plan_id?:int,location_id?:int},1:array<string,string>}
     */
    public static function destination(array $input, ?array $catalog): array
    {
        if ($catalog === null) {
            return [[], ['plan_id' => 'settings.error.catalog_unavailable']];
        }
        $planId = self::int($input['plan_id'] ?? null, 1, self::MAX_INT);
        $planIds = array_map(static fn (array $plan): int => (int) $plan['id'], $catalog['plans']);
        if ($planId === null || !in_array($planId, $planIds, true)) {
            return [[], ['plan_id' => 'settings.error.plan']];
        }
        $locationId = self::int($input['location_id'] ?? null, 1, self::MAX_INT);
        $locationIds = array_map(static fn (array $location): int => (int) $location['id'], $catalog['locations'][$planId] ?? []);
        if ($locationId === null || !in_array($locationId, $locationIds, true)) {
            return [[], ['location_id' => 'settings.error.location']];
        }
        return [['plan_id' => $planId, 'location_id' => $locationId], []];
    }

    /**
     * @param array<string,mixed> $input
     * @return array{0:array<string,mixed>,1:array<string,string>}
     */
    public static function commercial(array $input): array
    {
        $mode = self::string($input['commercial_mode'] ?? '');
        if (!in_array($mode, Settings::COMMERCIAL_MODES, true)) {
            return [[], ['commercial_mode' => 'settings.error.choice']];
        }
        $patch = ['commercial_mode' => $mode];
        $errors = [];
        foreach (['trial_days' => 'trial', 'payment_days' => 'payment_after'] as $field => $requiredFor) {
            $raw = $input[$field] ?? '';
            if ($mode !== $requiredFor && self::string($raw) === '') {
                continue;
            }
            $days = self::int($raw, 1, 365);
            if ($days === null) {
                if ($mode === $requiredFor) {
                    $errors[$field] = 'settings.error.days';
                }
                continue;
            }
            $patch[$field] = $days;
        }
        return [$errors === [] ? $patch : [], $errors];
    }

    /**
     * What the cPanel app shows to the account owners: the brand name (it
     * replaces the one from the API) and a support link. Both optional.
     *
     * @param array<string,mixed> $input
     * @return array{0:array<string,mixed>,1:array<string,string>}
     */
    public static function clients(array $input): array
    {
        $errors = [];
        $name = self::string($input['client_brand_name'] ?? '');
        $name = (string) preg_replace('/[ \t]+/', ' ', $name);
        if ($name !== '' && (preg_match('//u', $name) !== 1 || preg_match('/[\x00-\x1F\x7F]/', $name) === 1 || preg_match('/^.{1,60}$/u', $name) !== 1)) {
            $errors['client_brand_name'] = 'settings.error.brand';
        }
        [$url, $urlError] = self::supportUrl(self::string($input['client_support_url'] ?? ''));
        if ($urlError !== null) {
            $errors['client_support_url'] = $urlError;
        }
        return $errors === [] ? [['client_brand_name' => $name, 'client_support_url' => $url], []] : [[], $errors];
    }

    /** @return array{0:string,1:?string} normalized link ('' for none) and error key */
    public static function supportUrl(string $value): array
    {
        if ($value === '') {
            return ['', null];
        }
        if (strlen($value) > 300 || preg_match('/[\s<>"\'\\\\`]/', $value) === 1) {
            return ['', 'settings.error.support_url'];
        }
        if (stripos($value, 'mailto:') === 0) {
            $email = substr($value, 7);
            return filter_var($email, FILTER_VALIDATE_EMAIL) !== false && strlen($email) <= 254 ? ['mailto:' . $email, null] : ['', 'settings.error.support_url'];
        }
        $parts = parse_url($value);
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || preg_match('/^[a-z0-9.-]+$/i', (string) $parts['host']) !== 1) {
            return ['', 'settings.error.support_url'];
        }
        return [$value, null];
    }

    /** @return array{0:string,1:?string} normalized URL ('' for production) and error key */
    public static function apiBaseUrl(string $value): array
    {
        $value = rtrim($value, '/');
        if ($value === '' || $value === Client::DEFAULT_BASE_URL) {
            return ['', null];
        }
        if (strlen($value) > 255 || preg_match('/[\s<>"\'\\\\]/', $value) === 1) {
            return ['', 'settings.error.api_base_url'];
        }
        $parts = parse_url($value);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return ['', 'settings.error.api_base_url'];
        }
        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);
        $local = in_array($host, ['localhost', '127.0.0.1', '[::1]', '::1'], true);
        if ($scheme !== 'https' && !($scheme === 'http' && $local)) {
            return ['', 'settings.error.api_base_url_https'];
        }
        return [$value, null];
    }

    /** @return array{0:string,1:?string} normalized path and error key */
    public static function workDir(string $value): array
    {
        $value = rtrim($value, '/');
        if ($value === '' || $value[0] !== '/' || strlen($value) > 200
            || preg_match('#^(/[A-Za-z0-9._-]+)+$#D', $value) !== 1
            || preg_match('#/\.{1,2}(/|$)#', $value) === 1) {
            return ['', 'settings.error.work_dir'];
        }
        if (in_array($value, self::FORBIDDEN_EXACT, true)) {
            return ['', 'settings.error.work_dir_forbidden'];
        }
        foreach (self::FORBIDDEN_TREES as $tree) {
            if ($value === $tree || str_starts_with($value, $tree . '/')) {
                return ['', 'settings.error.work_dir_forbidden'];
            }
        }
        return [$value, null];
    }

    private static function string(mixed $value): string
    {
        return is_string($value) ? trim($value) : (is_int($value) ? (string) $value : '');
    }

    private static function int(mixed $value, int $min, int $max): ?int
    {
        if (is_int($value)) {
            return $value >= $min && $value <= $max ? $value : null;
        }
        $value = self::string($value);
        if (preg_match('/^\d{1,10}$/', $value) !== 1) {
            return null;
        }
        $int = (int) $value;
        return $int >= $min && $int <= $max ? $int : null;
    }
}
