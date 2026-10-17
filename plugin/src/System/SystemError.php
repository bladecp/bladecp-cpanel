<?php

declare(strict_types=1);

namespace BladeCP\CpanelMigrator\System;

/** A local server operation failed. $code is a stable identifier used for messages. */
final class SystemError extends \RuntimeException
{
    public const ACCOUNT_NOT_FOUND = 'account_not_found';
    public const WHMAPI_FAILED = 'whmapi_failed';
    public const BACKUP_FAILED = 'backup_failed';
    public const BACKUP_MISSING = 'backup_missing';
    public const BACKUP_ABORTED = 'backup_aborted';
    public const DISK_SPACE_LOW = 'disk_space_low';
    public const WORK_DIR_UNSAFE = 'work_dir_unsafe';

    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly string $detail = '',
        public readonly bool $retryable = false
    ) {
        parent::__construct($message);
    }
}
