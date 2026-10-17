<?php

declare(strict_types=1);

namespace BladeCP\CpanelMigrator\Api;

/**
 * Reads one byte range of a file in small chunks, so a 64 MiB (or larger)
 * upload part is never loaded in memory at once.
 */
final class PartStream
{
    public const CHUNK = 1024 * 1024;

    /** @var resource */
    private $handle;
    private int $remaining;
    private int $read = 0;

    public function __construct(string $path, int $offset, int $length)
    {
        if ($offset < 0 || $length < 0) {
            throw new \InvalidArgumentException('Invalid byte range.');
        }
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('The backup file cannot be opened.');
        }
        if (fseek($handle, $offset) !== 0) {
            fclose($handle);
            throw new \RuntimeException('The backup file cannot be read at the requested offset.');
        }
        $this->handle = $handle;
        $this->remaining = $length;
    }

    /** Returns at most min($max, CHUNK) bytes, or '' at the end of the range. */
    public function read(int $max): string
    {
        if ($this->remaining <= 0) {
            return '';
        }
        $size = max(1, min($max, self::CHUNK, $this->remaining));
        $chunk = fread($this->handle, $size);
        if ($chunk === false || $chunk === '') {
            throw new \RuntimeException('The backup file ended before the expected size.');
        }
        $this->remaining -= strlen($chunk);
        $this->read += strlen($chunk);
        return $chunk;
    }

    public function bytesRead(): int
    {
        return $this->read;
    }

    public function close(): void
    {
        if (is_resource($this->handle)) {
            fclose($this->handle);
        }
    }

    public function __destruct()
    {
        $this->close();
    }
}
