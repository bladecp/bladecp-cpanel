<?php

declare(strict_types=1);

namespace BladeCP\CpanelMigrator\System;

final class CommandResult
{
    public function __construct(
        public readonly int $exitCode,
        public readonly string $stdout,
        public readonly string $stderr,
        public readonly bool $aborted = false,
        public readonly bool $timedOut = false,
        public readonly bool $truncated = false
    ) {
    }

    public function ok(): bool
    {
        return $this->exitCode === 0 && !$this->aborted && !$this->timedOut;
    }
}
