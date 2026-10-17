<?php

declare(strict_types=1);

namespace BladeCP\CpanelMigrator\Support;

/**
 * JSON lines logger with size based rotation. The file is created with mode
 * 0600 inside the root-only data directory.
 */
final class FileLogger implements Logger
{
    private const MAX_BYTES = 5 * 1024 * 1024;
    private const KEEP = 3;

    public function __construct(private readonly string $path, private readonly Clock $clock = new SystemClock())
    {
    }

    public function info(string $event, array $context = []): void
    {
        $this->write('info', $event, $context);
    }

    public function warning(string $event, array $context = []): void
    {
        $this->write('warning', $event, $context);
    }

    public function error(string $event, array $context = []): void
    {
        $this->write('error', $event, $context);
    }

    /** @param array<string,mixed> $context */
    private function write(string $level, string $event, array $context): void
    {
        $record = [
            'ts' => gmdate('Y-m-d\TH:i:s\Z', $this->clock->now()),
            'level' => $level,
            'event' => preg_replace('/[^a-z0-9_.-]/i', '_', $event),
            'pid' => getmypid(),
        ] + (array) Redactor::value($context);
        $line = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if (!is_string($line)) {
            return;
        }
        try {
            $this->rotate();
            $handle = @fopen($this->path, 'ab');
            if ($handle === false) {
                return;
            }
            @chmod($this->path, 0600);
            if (flock($handle, LOCK_EX)) {
                fwrite($handle, $line . "\n");
                flock($handle, LOCK_UN);
            }
            fclose($handle);
        } catch (\Throwable) {
            // Logging must never break the migration flow.
        }
    }

    private function rotate(): void
    {
        clearstatcache(true, $this->path);
        if (!is_file($this->path) || (int) filesize($this->path) < self::MAX_BYTES) {
            return;
        }
        for ($i = self::KEEP - 1; $i >= 1; $i--) {
            if (is_file($this->path . '.' . $i)) {
                @rename($this->path . '.' . $i, $this->path . '.' . ($i + 1));
            }
        }
        @rename($this->path, $this->path . '.1');
    }
}
