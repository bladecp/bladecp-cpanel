<?php

declare(strict_types=1);

namespace BladeCP\CpanelMigrator\Store;

use BladeCP\CpanelMigrator\Support\Uuid;

/**
 * Root-only JSON store for settings, the job queue, caches and locks.
 *
 * Layout under the data directory (mode 0700):
 *   config.json        settings, including the API key (mode 0600)
 *   jobs/<id>.json     one file per account migration attempt
 *   cache/<name>.json  short lived API data and worker heartbeat
 *   locks/<name>.lock  flock(2) files
 *   logs/migrator.log  structured log
 *
 * Every write is atomic (temporary file + rename) and every read-modify-write
 * of a job runs under that job's exclusive lock.
 */
final class FileStore
{
    public function __construct(private readonly string $dataDir)
    {
    }

    public function dataDir(): string
    {
        return $this->dataDir;
    }

    public function logPath(): string
    {
        return $this->dataDir . '/logs/migrator.log';
    }

    /** Creates the directory layout with root-only permissions. Idempotent. */
    public function ensureLayout(): void
    {
        foreach (['', '/jobs', '/cache', '/locks', '/logs'] as $sub) {
            $dir = $this->dataDir . $sub;
            if (is_link($dir)) {
                throw new \RuntimeException('The data directory must not be a symbolic link.');
            }
            if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
                throw new \RuntimeException('The data directory cannot be created.');
            }
            @chmod($dir, 0700);
        }
    }

    /** @return array<string,mixed> */
    public function loadConfig(): array
    {
        return $this->readJson($this->dataDir . '/config.json') ?? [];
    }

    /** @param array<string,mixed> $config */
    public function saveConfig(array $config): void
    {
        $this->writeJson($this->dataDir . '/config.json', $config);
    }

    /**
     * Applies $mutator to the configuration under the config lock.
     *
     * @param callable(array<string,mixed>):array<string,mixed> $mutator
     * @return array<string,mixed>
     */
    public function updateConfig(callable $mutator): array
    {
        return $this->withLock('config', function () use ($mutator): array {
            $config = $mutator($this->loadConfig());
            $this->saveConfig($config);
            return $config;
        });
    }

    /** @return list<array<string,mixed>> sorted by creation time, oldest first */
    public function jobs(): array
    {
        $jobs = [];
        foreach (glob($this->dataDir . '/jobs/*.json') ?: [] as $file) {
            $job = $this->readJson($file);
            if (is_array($job) && Uuid::isValid($job['id'] ?? null) && basename($file) === $job['id'] . '.json') {
                $jobs[] = $job;
            }
        }
        usort($jobs, static fn (array $a, array $b): int => [(int) ($a['created_at'] ?? 0), (string) $a['id']] <=> [(int) ($b['created_at'] ?? 0), (string) $b['id']]);
        return $jobs;
    }

    /** @return array<string,mixed>|null */
    public function job(string $id): ?array
    {
        if (!Uuid::isValid($id)) {
            return null;
        }
        return $this->readJson($this->jobPath($id));
    }

    /** @param array<string,mixed> $job */
    public function createJob(array $job): void
    {
        $id = (string) ($job['id'] ?? '');
        if (!Uuid::isValid($id)) {
            throw new \InvalidArgumentException('Invalid job identifier.');
        }
        $this->withLock('job-' . $id, function () use ($id, $job): void {
            if (is_file($this->jobPath($id))) {
                throw new \RuntimeException('The job already exists.');
            }
            $this->writeJson($this->jobPath($id), $job);
        });
    }

    /**
     * Read-modify-write of one job under its exclusive lock. The mutator
     * receives the freshest copy; returning null leaves the file untouched.
     *
     * @param callable(array<string,mixed>):(array<string,mixed>|null) $mutator
     * @return array<string,mixed>|null the stored job
     */
    public function updateJob(string $id, callable $mutator): ?array
    {
        if (!Uuid::isValid($id)) {
            return null;
        }
        return $this->withLock('job-' . $id, function () use ($id, $mutator): ?array {
            $current = $this->readJson($this->jobPath($id));
            if ($current === null) {
                return null;
            }
            $next = $mutator($current);
            if ($next === null) {
                return $current;
            }
            $next['id'] = $current['id'];
            $this->writeJson($this->jobPath($id), $next);
            return $next;
        });
    }

    /** @return mixed|null */
    public function cacheGet(string $name, int $maxAgeSeconds, int $now): mixed
    {
        $entry = $this->readJson($this->cachePath($name));
        if (!is_array($entry) || !is_int($entry['at'] ?? null) || ($maxAgeSeconds > 0 && $now - $entry['at'] > $maxAgeSeconds)) {
            return null;
        }
        return $entry['value'] ?? null;
    }

    public function cachePut(string $name, mixed $value, int $now): void
    {
        $this->writeJson($this->cachePath($name), ['at' => $now, 'value' => $value]);
    }

    public function cacheForget(string $name): void
    {
        @unlink($this->cachePath($name));
    }

    /**
     * Runs $callback while holding an exclusive blocking lock.
     *
     * @template T
     * @param callable():T $callback
     * @return T
     */
    public function withLock(string $name, callable $callback): mixed
    {
        $handle = $this->openLock($name);
        try {
            if (!flock($handle, LOCK_EX)) {
                throw new \RuntimeException('Lock unavailable.');
            }
            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * Non-blocking lock. Returns the handle to keep while working, or null when
     * another process holds it.
     *
     * @return resource|null
     */
    public function tryLock(string $name)
    {
        $handle = $this->openLock($name);
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return null;
        }
        return $handle;
    }

    /** @param resource|null $handle */
    public function release($handle): void
    {
        if (is_resource($handle)) {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** True when some process currently holds the named lock. */
    public function isLocked(string $name): bool
    {
        $handle = $this->tryLock($name);
        if ($handle === null) {
            return true;
        }
        $this->release($handle);
        return false;
    }

    /** @return resource */
    private function openLock(string $name)
    {
        if (preg_match('/^[a-z0-9-]{1,80}$/', $name) !== 1) {
            throw new \InvalidArgumentException('Invalid lock name.');
        }
        $path = $this->dataDir . '/locks/' . $name . '.lock';
        $handle = @fopen($path, 'c');
        if ($handle === false) {
            throw new \RuntimeException('The lock file cannot be opened.');
        }
        @chmod($path, 0600);
        return $handle;
    }

    private function jobPath(string $id): string
    {
        return $this->dataDir . '/jobs/' . $id . '.json';
    }

    private function cachePath(string $name): string
    {
        if (preg_match('/^[a-z0-9-]{1,80}$/', $name) !== 1) {
            throw new \InvalidArgumentException('Invalid cache name.');
        }
        return $this->dataDir . '/cache/' . $name . '.json';
    }

    /** @return array<string,mixed>|null */
    private function readJson(string $path): ?array
    {
        if (!is_file($path) || is_link($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    /** @param array<string,mixed> $data */
    private function writeJson(string $path, array $data): void
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $dir = dirname($path);
        $tmp = @tempnam($dir, '.tmp-');
        if ($tmp === false) {
            throw new \RuntimeException('The data directory is not writable.');
        }
        @chmod($tmp, 0600);
        if (@file_put_contents($tmp, $json . "\n", LOCK_EX) === false || !@rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException('The data file cannot be written.');
        }
        @chmod($path, 0600);
    }
}
