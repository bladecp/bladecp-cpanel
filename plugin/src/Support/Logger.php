<?php

declare(strict_types=1);

namespace BladeCP\CpanelMigrator\Support;

/**
 * Structured logger. Implementations must pass every context through
 * Redactor before persisting it.
 */
interface Logger
{
    /** @param array<string,mixed> $context */
    public function info(string $event, array $context = []): void;

    /** @param array<string,mixed> $context */
    public function warning(string $event, array $context = []): void;

    /** @param array<string,mixed> $context */
    public function error(string $event, array $context = []): void;
}
