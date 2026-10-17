<?php

declare(strict_types=1);

namespace BladeCP\CpanelMigrator\Client;

use BladeCP\CpanelMigrator\Queue\JobState;
use BladeCP\CpanelMigrator\Store\FileStore;
use BladeCP\CpanelMigrator\Support\Clock;
use BladeCP\CpanelMigrator\Support\Logger;
use BladeCP\CpanelMigrator\System\CommandRunner;
use BladeCP\CpanelMigrator\System\WhmSystem;

/**
 * Tells the cPanel home page banner (CpanelBanner) which accounts already
 * migrated or have a migration going on, without the include calling any
 * API: the worker writes the account NVData site_migration_state ("hide",
 * or empty to show the banner again after the last migration was cancelled
 * or failed) as the account, through `uapi --user=<account> NVData set`.
 *
 * Only changes are written (the last written value is kept in the cache),
 * a few accounts per run, and a failed write waits RETRY_AFTER before the
 * next try, so a removed account never costs a call every minute.
 */
final class BannerMarks
{
    public const CACHE = 'banner-marks';
    public const UAPI = '/usr/local/cpanel/bin/uapi';
    public const RETRY_AFTER = 21600;
    public const PER_RUN = 20;

    public function __construct(
        private readonly FileStore $store,
        private readonly CommandRunner $runner,
        private readonly Clock $clock,
        private readonly Logger $log,
        private readonly string $uapi = self::UAPI
    ) {
    }

    /**
     * Accounts whose banner stays hidden: a migration that is not cancelled
     * or failed (queued, running, waiting, stopped with an error, complete).
     *
     * @param list<array<string,mixed>> $jobs
     * @return array<string,bool> account => hidden
     */
    public static function wanted(array $jobs): array
    {
        $wanted = [];
        foreach ($jobs as $job) {
            $user = (string) ($job['user'] ?? '');
            if (!WhmSystem::validUser($user)) {
                continue;
            }
            $hidden = !in_array($job['status'] ?? '', [JobState::CANCELLED, JobState::FAILED], true);
            $wanted[$user] = ($wanted[$user] ?? false) || $hidden;
        }
        return $wanted;
    }

    /** Writes the accounts whose mark changed. Returns how many writes ran. */
    public function sync(int $limit = self::PER_RUN): int
    {
        $now = $this->clock->now();
        $cached = $this->store->cacheGet(self::CACHE, 0, $now);
        /** @var array<string,array{hide:bool,at:int,failed?:bool,want?:bool}> $marks */
        $marks = is_array($cached) ? $cached : [];
        $wanted = self::wanted($this->store->jobs());
        $writes = 0;
        $changed = false;
        foreach (array_unique(array_merge(array_keys($wanted), array_keys($marks))) as $user) {
            $user = (string) $user;
            if ($writes >= $limit) {
                break;
            }
            $want = $wanted[$user] ?? false;
            $mark = is_array($marks[$user] ?? null) ? $marks[$user] : null;
            if ($mark === null && !$want) {
                continue;
            }
            if ($mark !== null && empty($mark['failed']) && (bool) ($mark['hide'] ?? false) === $want) {
                continue;
            }
            if ($mark !== null && !empty($mark['failed']) && ($mark['want'] ?? null) === $want && $now - (int) ($mark['at'] ?? 0) < self::RETRY_AFTER) {
                continue;
            }
            $writes++;
            $changed = true;
            if ($this->write($user, $want)) {
                if ($want) {
                    $marks[$user] = ['hide' => true, 'at' => $now];
                } else {
                    unset($marks[$user]);
                }
                continue;
            }
            $marks[$user] = ['hide' => (bool) ($mark['hide'] ?? false), 'at' => $now, 'failed' => true, 'want' => $want];
            $this->log->warning('banner.mark_failed', ['user' => $user]);
        }
        if ($changed) {
            $this->store->cachePut(self::CACHE, $marks, $now);
        }
        return $writes;
    }

    private function write(string $user, bool $hide): bool
    {
        if (!WhmSystem::validUser($user)) {
            return false;
        }
        $value = $hide ? CpanelBanner::HIDDEN : '';
        $result = $this->runner->run([$this->uapi, '--user=' . $user, '--output=json', 'NVData', 'set',
            'names=' . CpanelBanner::STATE_KEY, CpanelBanner::STATE_KEY . '=' . $value], 60);
        if (!$result->ok()) {
            return false;
        }
        $decoded = json_decode($result->stdout, true);
        return is_array($decoded) && (int) ($decoded['result']['status'] ?? 0) === 1;
    }
}
