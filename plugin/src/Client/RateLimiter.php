<?php

declare(strict_types=1);

namespace BladeCP\CpanelMigrator\Client;

use BladeCP\CpanelMigrator\Store\FileStore;
use BladeCP\CpanelMigrator\Support\Clock;

/**
 * Sliding window limits per cPanel account, kept in the root-only cache
 * (cache/rate-<rule>-<account>.json) under a per-account lock. A request
 * counts only when every rule still has room.
 */
final class RateLimiter
{
    public function __construct(
        private readonly FileStore $store,
        private readonly Clock $clock
    ) {
    }

    /**
     * @param array<string,array{0:int,1:int}> $rules rule name => [limit, window in seconds]
     */
    public function allow(string $user, array $rules): bool
    {
        if (preg_match('/^[a-z][a-z0-9]{0,15}$/D', $user) !== 1) {
            return false;
        }
        return $this->store->withLock('rate-' . $user, function () use ($user, $rules): bool {
            $now = $this->clock->now();
            $windows = [];
            foreach ($rules as $name => [$limit, $window]) {
                $key = 'rate-' . $name . '-' . $user;
                $stored = $this->store->cacheGet($key, 0, $now);
                $hits = array_values(array_filter(is_array($stored) ? $stored : [], static fn (mixed $at): bool => is_int($at) && $at > $now - $window));
                if (count($hits) >= $limit) {
                    return false;
                }
                $windows[$key] = $hits;
            }
            foreach ($windows as $key => $hits) {
                $hits[] = $now;
                $this->store->cachePut($key, $hits, $now);
            }
            return true;
        });
    }
}
