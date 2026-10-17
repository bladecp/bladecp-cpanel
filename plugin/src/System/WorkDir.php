<?php

declare(strict_types=1);

namespace BladeCP\CpanelMigrator\System;

use BladeCP\CpanelMigrator\Support\Uuid;

/**
 * Root-only directory that holds backups while they are uploaded.
 *
 * Every ancestor must be a real directory owned by root and not writable by
 * group or others, so an account cannot swap a path component for a
 * symbolic link. Each job gets its own sub directory, removed after upload.
 */
final class WorkDir
{
    public const MARKER = '.bladecp-migrator';

    /**
     * @param string $trustedBase ancestors at or above this path are not
     *                            checked (only used by the local test suite)
     */
    public function __construct(
        private readonly string $root,
        private readonly int $requiredUid = 0,
        private readonly string $trustedBase = ''
    ) {
    }

    public function root(): string
    {
        return $this->root;
    }

    /** Creates the root (mode 0700) when missing and checks its ancestry. */
    public function prepare(): void
    {
        $parent = dirname($this->root);
        self::assertSafe($parent, $this->requiredUid, $this->trustedBase);
        if (!is_dir($this->root)) {
            if (is_link($this->root) || (!@mkdir($this->root, 0700) && !is_dir($this->root))) {
                throw new SystemError(SystemError::WORK_DIR_UNSAFE, 'The work directory cannot be created.');
            }
        }
        @chmod($this->root, 0700);
        self::assertSafe($this->root, $this->requiredUid, $this->trustedBase);
        $marker = $this->root . '/' . self::MARKER;
        if (!is_file($marker)) {
            @file_put_contents($marker, "Created by BladeCP cPanel Migrator. Backups are removed after upload.\n");
            @chmod($marker, 0600);
        }
    }

    /** Empty, root-only directory for one job. */
    public function jobDir(string $jobId): string
    {
        if (!Uuid::isValid($jobId)) {
            throw new \InvalidArgumentException('Invalid job identifier.');
        }
        $this->prepare();
        $dir = $this->root . '/' . $jobId;
        if (is_link($dir)) {
            throw new SystemError(SystemError::WORK_DIR_UNSAFE, 'The job directory is a symbolic link.');
        }
        if (is_dir($dir)) {
            self::removeTree($dir);
        }
        if (!@mkdir($dir, 0700) && !is_dir($dir)) {
            throw new SystemError(SystemError::WORK_DIR_UNSAFE, 'The job directory cannot be created.');
        }
        @chmod($dir, 0700);
        return $dir;
    }

    /** Removes a job directory and everything in it. Missing directories are fine. */
    public function removeJob(string $jobId): void
    {
        if (!Uuid::isValid($jobId)) {
            return;
        }
        $dir = $this->root . '/' . $jobId;
        if (is_link($dir)) {
            @unlink($dir);
            return;
        }
        if (is_dir($dir)) {
            self::removeTree($dir);
        }
    }

    /** True when the path belongs to a job directory inside this work directory. */
    public function owns(string $path): bool
    {
        $prefix = rtrim($this->root, '/') . '/';
        if (!str_starts_with($path, $prefix)) {
            return false;
        }
        $jobId = explode('/', substr($path, strlen($prefix)))[0];
        return Uuid::isValid($jobId);
    }

    public static function assertSafe(string $path, int $requiredUid, string $trustedBase = ''): void
    {
        $trusted = $trustedBase === '' ? '' : rtrim($trustedBase, '/');
        $components = ['/'];
        $current = '';
        foreach (explode('/', trim($path, '/')) as $segment) {
            if ($segment === '') {
                continue;
            }
            $current .= '/' . $segment;
            $components[] = $current;
        }
        foreach ($components as $component) {
            if ($trusted !== '' && ($component === '/' || $trusted === $component || str_starts_with($trusted . '/', rtrim($component, '/') . '/'))) {
                continue;
            }
            self::assertSafeEntry($component, $requiredUid);
        }
    }

    private static function assertSafeEntry(string $path, int $requiredUid): void
    {
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if ($stat === false) {
            throw new SystemError(SystemError::WORK_DIR_UNSAFE, 'A work directory component is missing.');
        }
        $mode = (int) $stat['mode'];
        $isDir = ($mode & 0170000) === 0040000;
        if (!$isDir) {
            throw new SystemError(SystemError::WORK_DIR_UNSAFE, 'A work directory component is not a directory.');
        }
        if ((int) $stat['uid'] !== $requiredUid && (int) $stat['uid'] !== 0) {
            throw new SystemError(SystemError::WORK_DIR_UNSAFE, 'A work directory component is not owned by root.');
        }
        if (($mode & 0022) !== 0) {
            throw new SystemError(SystemError::WORK_DIR_UNSAFE, 'A work directory component is writable by other users.');
        }
    }

    private static function removeTree(string $dir): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $entry) {
            /** @var \SplFileInfo $entry */
            $path = $entry->getPathname();
            if (is_link($path) || !is_dir($path)) {
                @unlink($path);
            } else {
                @rmdir($path);
            }
        }
        @rmdir($dir);
    }
}
