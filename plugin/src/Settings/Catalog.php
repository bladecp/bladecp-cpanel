<?php

declare(strict_types=1);

namespace BladeCP\CpanelMigrator\Settings;

use BladeCP\CpanelMigrator\Api\ApiError;
use BladeCP\CpanelMigrator\Api\Client;
use BladeCP\CpanelMigrator\Store\FileStore;
use BladeCP\CpanelMigrator\Support\Clock;

/**
 * Migration destinations (partner plans and their locations), cached for a
 * few minutes so the settings and review screens stay fast.
 */
final class Catalog
{
    private const CACHE = 'catalog';
    private const TTL = 600;
    private const MAX_PLANS = 50;

    private ?ApiError $lastError = null;

    public function __construct(
        private readonly Client $api,
        private readonly FileStore $store,
        private readonly Clock $clock,
        private readonly string $cacheKey = ''
    ) {
    }

    /**
     * @return array{plans:list<array<string,mixed>>,locations:array<int,list<array<string,mixed>>>}|null
     */
    public function load(bool $refresh = false): ?array
    {
        $this->lastError = null;
        $now = $this->clock->now();
        $cached = $this->store->cacheGet(self::CACHE, 0, $now);
        $fresh = is_array($cached) && ($cached['key'] ?? null) === $this->cacheKey
            && is_int($cached['fetched_at'] ?? null) && $now - $cached['fetched_at'] <= self::TTL;
        if (!$refresh && $fresh) {
            return self::shape($cached);
        }
        try {
            $plans = [];
            $locations = [];
            foreach (array_slice($this->api->migrationPlans(), 0, self::MAX_PLANS) as $plan) {
                $id = (int) $plan['id'];
                $plans[] = [
                    'id' => $id,
                    'name' => self::text($plan['name'] ?? ('#' . $id)),
                    'product' => self::text($plan['productName'] ?? ''),
                ];
                $locations[$id] = [];
                foreach ($this->api->partnerLocations($id) as $location) {
                    $locations[$id][] = [
                        'id' => (int) $location['id'],
                        'city' => self::text($location['city'] ?? ''),
                        'country' => self::text($location['countryCode'] ?? ''),
                        'region' => self::text($location['region'] ?? ''),
                    ];
                }
            }
            $value = ['key' => $this->cacheKey, 'fetched_at' => $now, 'plans' => $plans, 'locations' => $locations];
            $this->store->cachePut(self::CACHE, $value, $now);
            return self::shape($value);
        } catch (ApiError $error) {
            $this->lastError = $error;
            // A stale catalogue is still useful for display; validation uses it too
            // because the API checks the destination again when the migration starts.
            return is_array($cached) && ($cached['key'] ?? null) === $this->cacheKey ? self::shape($cached) : null;
        }
    }

    public function lastError(): ?ApiError
    {
        return $this->lastError;
    }

    public function forget(): void
    {
        $this->store->cacheForget(self::CACHE);
    }

    /**
     * @param array<string,mixed> $value
     * @return array{plans:list<array<string,mixed>>,locations:array<int,list<array<string,mixed>>>}
     */
    private static function shape(array $value): array
    {
        $locations = [];
        foreach (is_array($value['locations'] ?? null) ? $value['locations'] : [] as $planId => $list) {
            $locations[(int) $planId] = is_array($list) ? array_values($list) : [];
        }
        return ['plans' => is_array($value['plans'] ?? null) ? array_values($value['plans']) : [], 'locations' => $locations];
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? substr(trim(preg_replace('/[\x00-\x1F\x7F]/', '', $value) ?? ''), 0, 120) : '';
    }
}
