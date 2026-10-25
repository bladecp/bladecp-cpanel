<?php

declare(strict_types=1);

namespace BladeCP\CpanelMigrator\System;

use BladeCP\CpanelMigrator\Support\Redactor;

/**
 * WHM implementation of the server operations.
 *
 * - Accounts and contact e-mail: `whmapi1 listaccts` (JSON output).
 * - Full backup: `/usr/local/cpanel/scripts/pkgacct <user> <directory>`,
 *   which writes `cpmove-<user>.tar.gz` and does not modify the account.
 * - Free disk space: disk_free_space() on the work directory.
 *
 * Paths and output formats are documented as items to confirm on a licensed
 * server in AGENTS.md.
 */
final class WhmSystem implements SystemAdapter
{
    public const WHMAPI1 = '/usr/local/cpanel/bin/whmapi1';
    public const PKGACCT = '/usr/local/cpanel/scripts/pkgacct';
    private const NICE = '/bin/nice';
    private const LIST_FIELDS = 'user,domain,email,suspended,diskused,owner,plan,partition,unix_startdate';
    private const BACKUP_TIMEOUT = 86400;

    public function __construct(
        private readonly CommandRunner $runner = new ProcRunner(),
        private readonly string $whmapi1 = self::WHMAPI1,
        private readonly string $pkgacct = self::PKGACCT
    ) {
    }

    public function listAccounts(): array
    {
        return $this->query([]);
    }

    public function account(string $user): ?array
    {
        if (!self::validUser($user)) {
            return null;
        }
        foreach ($this->query(['search=' . $user, 'searchtype=user', 'searchmethod=exact']) as $account) {
            if ($account['user'] === $user) {
                return $account;
            }
        }
        return null;
    }

    public function hostname(): string
    {
        $name = gethostname();
        return is_string($name) ? strtolower(trim($name)) : '';
    }

    public function freeBytes(string $directory): ?int
    {
        $free = @disk_free_space($directory);
        return $free === false ? null : (int) $free;
    }

    public function generateBackup(string $user, string $directory, callable $tick): string
    {
        if (!self::validUser($user)) {
            throw new SystemError(SystemError::ACCOUNT_NOT_FOUND, 'Invalid account name.');
        }
        $argv = [$this->pkgacct, $user, $directory];
        if (is_executable(self::NICE)) {
            // Lower CPU priority so the backup does not compete with live sites.
            $argv = array_merge([self::NICE, '-n', '10'], $argv);
        }
        $result = $this->runner->run($argv, self::BACKUP_TIMEOUT, $tick);
        if ($result->aborted) {
            throw new SystemError(SystemError::BACKUP_ABORTED, 'The backup was stopped.');
        }
        $detail = Redactor::string(trim(substr($result->stdout . "\n" . $result->stderr, -1500)));
        if (!$result->ok()) {
            throw new SystemError(
                SystemError::BACKUP_FAILED,
                'pkgacct finished with exit code ' . $result->exitCode . ($result->timedOut ? ' (timeout)' : ''),
                $detail,
                true
            );
        }
        $archive = rtrim($directory, '/') . '/cpmove-' . $user . '.tar.gz';
        clearstatcache(true, $archive);
        if (!is_file($archive) || is_link($archive) || (int) filesize($archive) <= 0) {
            throw new SystemError(SystemError::BACKUP_MISSING, 'pkgacct did not produce the expected archive.', $detail, true);
        }
        return $archive;
    }

    /**
     * @param list<string> $filters
     * @return list<array<string,mixed>>
     */
    private function query(array $filters): array
    {
        $argv = array_merge([$this->whmapi1, '--output=json', 'listaccts', 'want=' . self::LIST_FIELDS], $filters);
        $result = $this->runner->run($argv, 120, null, 64 * 1024 * 1024);
        if (!$result->ok() || $result->truncated) {
            throw new SystemError(SystemError::WHMAPI_FAILED, 'whmapi1 listaccts failed.', Redactor::string(substr($result->stderr, -500)), true);
        }
        return self::parseListaccts($result->stdout);
    }

    /**
     * Parses `whmapi1 --output=json listaccts`.
     *
     * @return list<array<string,mixed>>
     */
    public static function parseListaccts(string $json): array
    {
        $decoded = json_decode($json, true);
        if (!is_array($decoded) || (int) ($decoded['metadata']['result'] ?? 0) !== 1) {
            throw new SystemError(SystemError::WHMAPI_FAILED, 'whmapi1 listaccts returned an error.', '', true);
        }
        $rows = $decoded['data']['acct'] ?? [];
        if (!is_array($rows)) {
            return [];
        }
        $accounts = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !self::validUser((string) ($row['user'] ?? ''))) {
                continue;
            }
            $email = trim((string) ($row['email'] ?? ''));
            $accounts[] = [
                'user' => (string) $row['user'],
                'domain' => strtolower(trim((string) ($row['domain'] ?? ''))),
                'email' => filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : null,
                'suspended' => in_array((string) ($row['suspended'] ?? '0'), ['1', 'true'], true) || ($row['suspended'] ?? null) === true,
                'disk_used_bytes' => self::parseSize((string) ($row['diskused'] ?? '')),
                'owner' => (string) ($row['owner'] ?? ''),
                'plan' => (string) ($row['plan'] ?? ''),
                'partition' => (string) ($row['partition'] ?? ''),
                'started' => self::startDate($row['unix_startdate'] ?? null),
            ];
        }
        usort($accounts, static fn (array $a, array $b): int => strcmp($a['user'], $b['user']));
        return $accounts;
    }

    /** Creation time of the account (listaccts unix_startdate), or null when the server does not report it. */
    public static function startDate(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        return is_string($value) && preg_match('/^[1-9]\d{0,11}$/D', $value) === 1 ? (int) $value : null;
    }

    /** Converts values such as "512M", "1.5G" or "2048" (MiB) to bytes. */
    public static function parseSize(string $value): ?int
    {
        $value = trim($value);
        if (preg_match('/^(\d+(?:\.\d+)?)\s*([KMGT]?)i?B?$/i', $value, $match) !== 1) {
            return null;
        }
        $number = (float) $match[1];
        $unit = strtoupper($match[2] === '' ? 'M' : $match[2]);
        $factor = ['K' => 1024, 'M' => 1024 ** 2, 'G' => 1024 ** 3, 'T' => 1024 ** 4][$unit];
        return (int) round($number * $factor);
    }

    public static function validUser(string $user): bool
    {
        return preg_match('/^[a-z][a-z0-9]{0,15}$/', $user) === 1;
    }
}
