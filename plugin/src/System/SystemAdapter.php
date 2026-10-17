<?php

declare(strict_types=1);

namespace BladeCP\CpanelMigrator\System;

/**
 * Server operations used by the plugin. The production implementation talks
 * to WHM; tests use a fake. No method modifies a cPanel account.
 *
 * Account shape: user, domain, email (null when unknown), suspended,
 * disk_used_bytes (null when unknown), owner, plan, partition.
 */
interface SystemAdapter
{
    /** @return list<array<string,mixed>> */
    public function listAccounts(): array;

    /** @return array<string,mixed>|null */
    public function account(string $user): ?array;

    public function freeBytes(string $directory): ?int;

    /** Host name of this server, in lower case (the billing WHMCS finds the account by it). */
    public function hostname(): string;

    /**
     * Generates the full account backup inside $directory and returns the
     * archive path.
     *
     * @param callable():bool $tick returns false to abort
     */
    public function generateBackup(string $user, string $directory, callable $tick): string;
}
