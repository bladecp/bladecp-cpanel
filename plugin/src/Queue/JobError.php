<?php

declare(strict_types=1);

namespace BladeCP\CpanelMigrator\Queue;

/** Local pipeline failure with a stable code (message key: error.local.<code>). */
final class JobError extends \RuntimeException
{
    public const UPLOAD_PLAN_INVALID = 'upload_plan_invalid';
    public const UPLOAD_PART_FAILED = 'upload_part_failed';
    public const UPLOAD_URLS_UNAVAILABLE = 'upload_urls_unavailable';
    public const UPLOAD_RESTART_LIMIT = 'upload_restart_limit';
    public const CANCELLED = 'cancelled';
    public const BACKUP_CHANGED = 'backup_changed';
    public const CONFIRM_NOT_AVAILABLE = 'confirm_not_available';
    public const INVALID_JOB = 'invalid_job';

    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly bool $retryable = false,
        public readonly string $detail = ''
    ) {
        parent::__construct($message);
    }
}
