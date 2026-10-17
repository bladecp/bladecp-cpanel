<?php

declare(strict_types=1);

namespace BladeCP\CpanelMigrator\Client;

use BladeCP\CpanelMigrator\Api\ApiError;
use BladeCP\CpanelMigrator\Api\Client;
use BladeCP\CpanelMigrator\Settings\Settings;
use BladeCP\CpanelMigrator\Store\FileStore;
use BladeCP\CpanelMigrator\Support\Logger;

/**
 * Name the cPanel app shows in place of the platform brand (white label).
 *
 * Order: the name saved in the WHM settings, then the public name of the
 * partner brand from the API (GET /customer/branding/assets, read with the
 * partner key and kept in the root-only cache), then nothing, and the app
 * shows a neutral text. The account-side code never calls the API: the
 * worker refreshes the cache every few hours and the WHM connection test
 * refreshes it at once.
 */
final class Branding
{
    public const CACHE = 'branding';
    public const REFRESH_EVERY = 21600;
    public const RETRY_AFTER = 1800;
    public const MAX_LENGTH = 60;

    /** Brand name for the cPanel app, or null for the neutral text. */
    public static function name(Settings $settings, FileStore $store): ?string
    {
        $saved = self::clean($settings->clientBrandName());
        return $saved ?? self::apiName($store);
    }

    /** Support link saved in WHM, or null. */
    public static function supportUrl(Settings $settings): ?string
    {
        $url = $settings->clientSupportUrl();
        return $url === '' ? null : $url;
    }

    /** Public name of the partner brand last read from the API, or null. */
    public static function apiName(FileStore $store): ?string
    {
        $cached = $store->cacheGet(self::CACHE, 0, 0);
        return is_array($cached) ? self::clean($cached['name'] ?? null) : null;
    }

    /** Refreshes the cached brand name when it is old enough. Never throws. */
    public static function refreshIfDue(Client $api, FileStore $store, Logger $log, int $now): void
    {
        $cached = $store->cacheGet(self::CACHE, 0, $now);
        $checked = is_array($cached) && is_int($cached['checked_at'] ?? null) ? $cached['checked_at'] : 0;
        $wait = is_array($cached) && !empty($cached['failed']) ? self::RETRY_AFTER : self::REFRESH_EVERY;
        if ($now - $checked >= $wait) {
            self::refresh($api, $store, $log, $now);
        }
    }

    /** Reads the brand from the API now. A failure keeps the last known name. Never throws. */
    public static function refresh(Client $api, FileStore $store, Logger $log, int $now): void
    {
        $cached = $store->cacheGet(self::CACHE, 0, $now);
        $previous = is_array($cached) ? self::clean($cached['name'] ?? null) : null;
        try {
            $data = $api->branding();
            $store->cachePut(self::CACHE, ['name' => self::clean($data['publicName'] ?? null), 'checked_at' => $now, 'failed' => false], $now);
        } catch (ApiError $error) {
            // 404: no partner brand for this key. Anything else: keep what we had and try again later.
            $name = $error->httpStatus === 404 ? null : $previous;
            $store->cachePut(self::CACHE, ['name' => $name, 'checked_at' => $now, 'failed' => $error->httpStatus !== 404], $now);
            $log->info('branding.unavailable', ['kind' => $error->kind, 'status' => $error->httpStatus]);
        } catch (\Throwable $error) {
            $log->warning('branding.failed', ['error' => $error]);
        }
    }

    /** One line of printable text, at most MAX_LENGTH characters, or null. */
    public static function clean(mixed $value): ?string
    {
        if (!is_string($value) || preg_match('//u', $value) !== 1) {
            return null;
        }
        $value = trim((string) preg_replace('/[\x00-\x1F\x7F\s]+/u', ' ', $value));
        if ($value === '') {
            return null;
        }
        if (preg_match('/^.{1,' . self::MAX_LENGTH . '}$/u', $value) !== 1) {
            preg_match('/^.{' . self::MAX_LENGTH . '}/u', $value, $match);
            $value = rtrim($match[0] ?? '');
        }
        return $value === '' ? null : $value;
    }
}
