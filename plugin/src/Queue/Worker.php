<?php

declare(strict_types=1);

namespace BladeCP\CpanelMigrator\Queue;

use BladeCP\CpanelMigrator\Api\ApiError;
use BladeCP\CpanelMigrator\Api\Client;
use BladeCP\CpanelMigrator\Client\Branding;
use BladeCP\CpanelMigrator\Settings\Settings;
use BladeCP\CpanelMigrator\Store\FileStore;
use BladeCP\CpanelMigrator\Support\Clock;
use BladeCP\CpanelMigrator\Support\Logger;
use BladeCP\CpanelMigrator\Support\Uuid;
use BladeCP\CpanelMigrator\System\SystemAdapter;
use BladeCP\CpanelMigrator\System\SystemError;
use BladeCP\CpanelMigrator\System\WorkDir;

/**
 * Queue worker, started by cron every minute.
 *
 * Each run (1) polls migrations that BladeCP is processing, under the "poll"
 * lock, then (2) takes the first free worker slot (1..concurrency) and
 * processes queued jobs until none is left. A slot is an flock, so a long
 * backup or upload keeps its slot while later cron runs only poll or use
 * another free slot.
 */
final class Worker
{
    private const POLL_LIMIT = 20;
    private const POLL_INTERVAL = ['processing' => 60, 'dns' => 300, 'review' => 120, 'approval' => 120, 'payment' => 120, 'billing' => 60];
    private const HEARTBEAT_EVERY = 30;
    private const CANCEL_CHECK_EVERY = 5;
    private const MAX_UPLOAD_RESTARTS = 3;
    private const DISK_RESERVE = 2 * 1024 * 1024 * 1024;

    private readonly int $pid;

    public function __construct(
        private readonly FileStore $store,
        private readonly Settings $settings,
        private readonly Client $api,
        private readonly Uploader $uploader,
        private readonly SystemAdapter $system,
        private readonly WorkDir $workDir,
        private readonly JobActions $actions,
        private readonly Logger $log,
        private readonly Clock $clock,
        ?int $pid = null
    ) {
        $this->pid = $pid ?? (int) getmypid();
    }

    /** @return array{polled:int,processed:int,slot:?int,configured:bool} */
    public function runCron(int $maxSeconds = 3000): array
    {
        $started = $this->clock->now();
        $summary = ['polled' => 0, 'processed' => 0, 'slot' => null, 'configured' => true];
        $this->store->cachePut('worker', ['at' => $started, 'version' => BLADECP_MIGRATOR_VERSION], $started);
        if (!$this->settings->hasApiKey() || !Uuid::isValid($this->settings->installationId())) {
            $summary['configured'] = false;
            return $summary;
        }
        $pollLock = $this->store->tryLock('poll');
        if ($pollLock !== null) {
            try {
                $summary['polled'] = $this->poll();
                // Brand name for the cPanel app, a few times a day.
                Branding::refreshIfDue($this->api, $this->store, $this->log, $this->clock->now());
            } finally {
                $this->store->release($pollLock);
            }
        }
        for ($slot = 1; $slot <= $this->settings->concurrency(); $slot++) {
            $lock = $this->store->tryLock('slot-' . $slot);
            if ($lock === null) {
                continue;
            }
            try {
                $summary['slot'] = $slot;
                $summary['processed'] = $this->work($slot, $started + $maxSeconds);
            } finally {
                $this->store->release($lock);
            }
            break;
        }
        return $summary;
    }

    /** Refreshes migrations waiting on BladeCP or on a person. */
    public function poll(int $limit = self::POLL_LIMIT): int
    {
        $now = $this->clock->now();
        $due = [];
        foreach ($this->store->jobs() as $job) {
            if (($job['status'] ?? '') !== JobState::WAITING || empty($job['request_created'])) {
                continue;
            }
            $interval = self::POLL_INTERVAL[(string) ($job['wait_reason'] ?? 'processing')] ?? 60;
            if ($now - (int) ($job['polled_at'] ?? 0) >= $interval) {
                $due[] = $job;
            }
        }
        usort($due, static fn (array $a, array $b): int => (int) ($a['polled_at'] ?? 0) <=> (int) ($b['polled_at'] ?? 0));
        $due = array_slice($due, 0, $limit);
        foreach ($due as $job) {
            $this->pollJob($job);
        }
        return count($due);
    }

    /** Processes claimable jobs until the queue is empty or the deadline passes. */
    public function work(int $slot, int $deadline): int
    {
        $count = 0;
        while ($this->clock->now() < $deadline) {
            $job = $this->claim($slot);
            if ($job === null) {
                break;
            }
            $this->advance($job);
            $count++;
        }
        return $count;
    }

    /** @return array<string,mixed>|null */
    public function claim(int $slot): ?array
    {
        return $this->store->withLock('claim', function () use ($slot): ?array {
            $now = $this->clock->now();
            foreach ($this->store->jobs() as $job) {
                if (!$this->claimable($job, $now)) {
                    continue;
                }
                $token = bin2hex(random_bytes(8));
                $stored = $this->store->updateJob((string) $job['id'], function (array $fresh) use ($now, $slot, $token): ?array {
                    if (!$this->claimable($fresh, $now)) {
                        return null;
                    }
                    $reclaimed = $fresh['status'] === JobState::RUNNING;
                    $fresh['status'] = JobState::RUNNING;
                    $fresh['wait_reason'] = null;
                    $fresh['lease'] = ['slot' => $slot, 'pid' => $this->pid, 'token' => $token, 'heartbeat_at' => $now];
                    $fresh['version'] = (int) ($fresh['version'] ?? 0) + 1;
                    $fresh['updated_at'] = $now;
                    return JobState::event($fresh, $now, $reclaimed ? 'reclaimed' : 'started');
                });
                if (is_array($stored) && ($stored['lease']['token'] ?? null) === $token) {
                    return $stored;
                }
            }
            return null;
        });
    }

    /**
     * Runs the job's steps until it waits, stops or finishes.
     *
     * @param array<string,mixed> $job a job claimed by this worker
     * @return array<string,mixed>
     */
    public function advance(array $job): array
    {
        while (true) {
            if (!empty($job['cancel_requested'])) {
                return $this->cancelFromWorker($job);
            }
            if ($job['status'] !== JobState::RUNNING) {
                return $job;
            }
            $step = (string) $job['step'];
            try {
                $job = match ($step) {
                    'backup' => $this->stepBackup($job),
                    'request' => $this->stepRequest($job),
                    'service' => $this->stepService($job),
                    'upload' => $this->stepUpload($job),
                    'complete' => $this->stepComplete($job),
                    'confirm' => $this->stepConfirm($job),
                    'track' => $this->stepTrack($job),
                    default => throw new JobError(JobError::INVALID_JOB, 'Unknown step.'),
                };
                if ($job['step'] !== $step && $job['status'] === JobState::RUNNING) {
                    $job['attempts'] = 0;
                    $job['error'] = null;
                }
            } catch (\Throwable $error) {
                // The stored copy holds progress persisted during the step
                // (uploaded parts), which the in-memory copy may not have.
                $job = $this->stored($job);
                if (self::isCancellation($error) && !empty($job['cancel_requested'])) {
                    continue;
                }
                $job = JobState::fail($job, $error, $this->clock->now());
                $this->log->error('job.step_failed', [
                    'job' => $job['id'],
                    'user' => $job['user'],
                    'step' => $step,
                    'code' => $job['error']['code'] ?? '',
                    'retry_at' => $job['next_attempt_at'] ?: null,
                    'detail' => $job['error']['detail'] ?? '',
                ]);
            }
            $job = $this->save($job);
        }
    }

    /** @param array<string,mixed> $job */
    private function stepBackup(array $job): array
    {
        $user = (string) $job['user'];
        $account = $this->system->account($user);
        if ($account === null) {
            throw new SystemError(SystemError::ACCOUNT_NOT_FOUND, 'The cPanel account no longer exists on this server.');
        }
        if (!JobState::sameAccount($job, is_int($account['started'] ?? null) ? $account['started'] : null)) {
            throw new SystemError(SystemError::ACCOUNT_NOT_FOUND, 'The cPanel account of this migration was removed and created again.');
        }
        $dir = $this->workDir->jobDir((string) $job['id']);
        $estimate = $account['disk_used_bytes'] ?? ($job['estimated_bytes'] ?? null);
        $required = is_int($estimate) ? $estimate + max(self::DISK_RESERVE, intdiv($estimate, 10)) : self::DISK_RESERVE;
        $free = $this->system->freeBytes($dir);
        if ($free !== null && $free < $required) {
            $this->workDir->removeJob((string) $job['id']);
            throw new SystemError(
                SystemError::DISK_SPACE_LOW,
                'Not enough free disk space for the backup.',
                sprintf('free=%.1f GiB required=%.1f GiB', $free / 1024 ** 3, $required / 1024 ** 3),
                true
            );
        }
        $this->log->info('backup.start', ['job' => $job['id'], 'user' => $user]);
        $job['progress'] = 0;
        $path = $this->system->generateBackup($user, $dir, $this->ticker($job));
        clearstatcache(true, $path);
        if (!$this->workDir->owns($path) || !is_file($path) || is_link($path)) {
            throw new SystemError(SystemError::BACKUP_MISSING, 'The backup archive is not in the work directory.', '', true);
        }
        $size = (int) filesize($path);
        $job['backup'] = ['path' => $path, 'size_bytes' => $size, 'generation' => Uuid::v4(), 'created_at' => $this->clock->now()];
        $job['upload'] = null;
        $job['step'] = JobState::stepAfterBackup($job);
        $this->log->info('backup.done', ['job' => $job['id'], 'user' => $user, 'bytes' => $size]);
        return JobState::event($job, $this->clock->now(), 'backup_ready');
    }

    /** @param array<string,mixed> $job */
    private function stepRequest(array $job): array
    {
        $view = $this->api->createMigration((array) $job['payload']);
        $job['request_created'] = true;
        if (is_array($view['migration'] ?? null)) {
            $job = JobState::applyRemote($job, $view, $this->clock->now());
        }
        if ($job['status'] === JobState::RUNNING && $job['step'] !== 'track') {
            // A billing link request gets its service from the partner WHMCS, never from here.
            $job['step'] = JobState::usesBillingLink($job) || !empty($job['service_bound']) ? 'upload' : 'service';
        }
        $this->log->info('request.created', ['job' => $job['id'], 'user' => $job['user']]);
        return JobState::event($job, $this->clock->now(), 'request_created');
    }

    /** @param array<string,mixed> $job */
    private function stepService(array $job): array
    {
        $this->api->bindService((string) $job['id'], JobState::scope($job), (string) $job['external_service_id']);
        $job['service_bound'] = true;
        $job['step'] = 'upload';
        return JobState::event($job, $this->clock->now(), 'service_bound');
    }

    /** @param array<string,mixed> $job */
    private function stepUpload(array $job): array
    {
        $job = JobState::applyRemote($job, $this->api->getMigration((string) $job['id'], JobState::scope($job)), $this->clock->now());
        if ($job['status'] !== JobState::RUNNING) {
            return $job;
        }
        if ($job['remote']['backup_ready']) {
            $job = $this->discardBackup($job);
            $job['step'] = 'confirm';
            $job['progress'] = 100;
            return $job;
        }
        if (!$job['remote']['service_bound'] && empty($job['service_bound']) && !JobState::usesBillingLink($job)) {
            $job['step'] = 'service';
            return $job;
        }
        $backup = is_array($job['backup'] ?? null) ? $job['backup'] : null;
        $path = (string) ($backup['path'] ?? '');
        clearstatcache(true, $path);
        if ($backup === null || !is_file($path) || (int) filesize($path) !== (int) $backup['size_bytes'] || !$this->workDir->owns($path)) {
            // The local backup is gone or changed: generate it again (new upload).
            $job['backup'] = null;
            $job['upload'] = null;
            $job['step'] = 'backup';
            return JobState::event($job, $this->clock->now(), 'backup_missing');
        }
        $this->log->info('upload.start', ['job' => $job['id'], 'user' => $job['user'], 'bytes' => $backup['size_bytes']]);
        $persist = function (array $state) use (&$job): void {
            $job['upload'] = $state;
            $job['progress'] = UploadPlan::percent($state);
            $job = $this->save($job);
        };
        $total = max(1, (int) $backup['size_bytes']);
        $state = $this->uploader->upload(
            (string) $job['id'],
            JobState::scope($job),
            $path,
            (string) $backup['generation'],
            is_array($job['upload'] ?? null) ? $job['upload'] : null,
            $persist,
            $this->ticker($job, $total)
        );
        $job['upload'] = $state;
        $job['progress'] = 100;
        $job['step'] = 'complete';
        return $job;
    }

    /** @param array<string,mixed> $job */
    private function stepComplete(array $job): array
    {
        if (!is_array($job['upload'] ?? null) || UploadPlan::missing($job['upload']) !== []) {
            $job['step'] = 'upload';
            return $job;
        }
        try {
            $this->api->completeUpload((string) $job['id'], JobState::scope($job), UploadPlan::completed($job['upload']));
        } catch (ApiError $error) {
            if (in_array($error->httpStatus, [400, 409], true) && !$error->is('MIGRATION_REQUEST_NOT_FOUND')) {
                // Expired/aborted upload session or rejected parts: upload again.
                $job['upload_restarts'] = (int) ($job['upload_restarts'] ?? 0) + 1;
                if ($job['upload_restarts'] > self::MAX_UPLOAD_RESTARTS) {
                    throw new JobError(JobError::UPLOAD_RESTART_LIMIT, 'The upload was rejected too many times.', false, $error->getMessage());
                }
                $this->log->warning('upload.restart', ['job' => $job['id'], 'status' => $error->httpStatus, 'code' => $error->apiCode]);
                $job['upload'] = null;
                $job['step'] = 'upload';
                return JobState::event($job, $this->clock->now(), 'upload_restart');
            }
            throw $error;
        }
        $job = $this->discardBackup($job);
        $job['step'] = 'confirm';
        $job['progress'] = 100;
        $this->log->info('upload.completed', ['job' => $job['id'], 'user' => $job['user']]);
        return JobState::event($job, $this->clock->now(), 'uploaded');
    }

    /** @param array<string,mixed> $job */
    private function stepConfirm(array $job): array
    {
        $now = $this->clock->now();
        $job = JobState::applyRemote($job, $this->api->getMigration((string) $job['id'], JobState::scope($job)), $now);
        if ($job['status'] !== JobState::RUNNING) {
            return $job;
        }
        if (!$job['remote']['service_bound'] && !JobState::usesBillingLink($job)) {
            $job['service_bound'] = false;
            $job['step'] = 'service';
            return $job;
        }
        if (!$job['remote']['backup_ready']) {
            $job['upload'] = null;
            $hasBackup = is_array($job['backup'] ?? null) && is_file((string) $job['backup']['path']);
            $job['step'] = $hasBackup ? 'upload' : 'backup';
            return JobState::event($job, $now, 'backup_not_ready');
        }
        if (!$job['remote']['actions']['confirm_upload']) {
            if (JobState::usesBillingLink($job) && JobState::billingBlocks($job)) {
                return $this->waitForBilling($job, $now);
            }
            if ($job['commercial_mode'] === 'payment_before' && JobState::needsPayment($job)) {
                return $this->waitForPayment($job, $now);
            }
            throw new JobError(JobError::CONFIRM_NOT_AVAILABLE, 'The upload cannot be confirmed yet.', true);
        }
        try {
            $result = $this->api->confirmUpload((string) $job['id'], JobState::scope($job));
        } catch (ApiError $error) {
            if ($error->is('MIGRATION_BILLING_LINK_PENDING')) {
                return $this->waitForBilling($job, $now);
            }
            if ($error->is('MIGRATION_PAYMENT_REQUIRED')) {
                return $this->waitForPayment($job, $now);
            }
            throw $error;
        }
        $job['remote']['phase'] = is_string($result['phase'] ?? null) ? $result['phase'] : 'queued';
        $job = JobState::mapPhase($job, $now);
        $job['polled_at'] = $now;
        $this->log->info('upload.confirmed', ['job' => $job['id'], 'user' => $job['user']]);
        return $job;
    }

    /** @param array<string,mixed> $job */
    private function stepTrack(array $job): array
    {
        $job['status'] = JobState::WAITING;
        $job['wait_reason'] = $job['wait_reason'] ?? 'processing';
        $job['polled_at'] = 0;
        return $job;
    }

    /**
     * The backup is uploaded; the partner billing WHMCS still has to link the
     * owner and the service (or the account holder to confirm the account).
     *
     * @param array<string,mixed> $job
     */
    private function waitForBilling(array $job, int $now): array
    {
        $job['status'] = JobState::WAITING;
        $job['wait_reason'] = 'billing';
        $job['polled_at'] = $now;
        $job['billing_wait_since'] = is_int($job['billing_wait_since'] ?? null) ? $job['billing_wait_since'] : $now;
        return JobState::event($job, $now, 'waiting_billing_link');
    }

    /** @param array<string,mixed> $job */
    private function waitForPayment(array $job, int $now): array
    {
        $job['status'] = JobState::WAITING;
        $job['wait_reason'] = 'payment';
        $job['polled_at'] = $now;
        return JobState::event($job, $now, 'waiting_payment');
    }

    /** @param array<string,mixed> $job */
    private function pollJob(array $job): void
    {
        $now = $this->clock->now();
        try {
            $view = $this->api->getMigration((string) $job['id'], JobState::scope($job));
        } catch (ApiError $error) {
            $this->log->warning('poll.failed', ['job' => $job['id'], 'kind' => $error->kind, 'status' => $error->httpStatus]);
            $this->store->updateJob((string) $job['id'], function (array $fresh) use ($job, $error, $now): ?array {
                if (($fresh['version'] ?? 0) !== ($job['version'] ?? 0)) {
                    return null;
                }
                $fresh['polled_at'] = $now;
                // Only a migration that no longer exists stops the job. Key or
                // network problems keep it waiting until the connection is fixed.
                if ($error->kind === ApiError::NOT_FOUND) {
                    $fresh = JobState::fail($fresh, $error, $now);
                }
                $fresh['version'] = (int) ($fresh['version'] ?? 0) + 1;
                return $fresh;
            });
            return;
        }
        $this->store->updateJob((string) $job['id'], function (array $fresh) use ($job, $view, $now): ?array {
            if (($fresh['version'] ?? 0) !== ($job['version'] ?? 0) || $fresh['status'] !== JobState::WAITING) {
                return null;
            }
            $next = JobState::applyRemote($fresh, $view, $now);
            $next['polled_at'] = $now;
            if ($next['step'] === 'confirm' && $next['status'] === JobState::WAITING && in_array($next['wait_reason'], ['payment', 'billing'], true)) {
                if ($next['remote']['actions']['confirm_upload'] ?? false) {
                    // Payment confirmed or billing linked: the worker confirms the upload on its next run.
                    $next['status'] = JobState::PENDING;
                    $next['wait_reason'] = null;
                    $next['next_attempt_at'] = 0;
                } elseif ($next['wait_reason'] === 'billing' && !JobState::billingBlocks($next) && JobState::needsPayment($next)) {
                    // Linked; the WHMCS invoice must be paid before the migration starts.
                    $next['wait_reason'] = 'payment';
                }
                if (($next['wait_reason'] ?? null) !== ($fresh['wait_reason'] ?? null)) {
                    $next = JobState::event($next, $now, 'wait:' . ($next['wait_reason'] ?? 'confirm'));
                }
            }
            if (JobState::isTerminal($next)) {
                $this->workDir->removeJob((string) $next['id']);
                $next['backup'] = null;
            }
            $next['version'] = (int) ($fresh['version'] ?? 0) + 1;
            $next['updated_at'] = $now;
            return $next;
        });
    }

    /** @param array<string,mixed> $job */
    private function cancelFromWorker(array $job): array
    {
        try {
            $job = $this->actions->performCancel($job);
        } catch (\Throwable $error) {
            $job = JobState::fail($job, $error, $this->clock->now());
            if ($job['status'] === JobState::ERROR) {
                $job['cancel_requested'] = false;
            }
        }
        return $this->save($job, true);
    }

    /** @param array<string,mixed> $job */
    private function discardBackup(array $job): array
    {
        $this->workDir->removeJob((string) $job['id']);
        if ($job['backup'] !== null) {
            $this->log->info('backup.deleted', ['job' => $job['id'], 'user' => $job['user']]);
        }
        $job['backup'] = null;
        return $job;
    }

    /** @param array<string,mixed> $job */
    private function claimable(array $job, int $now): bool
    {
        $status = $job['status'] ?? '';
        if ($status === JobState::PENDING) {
            return (int) ($job['next_attempt_at'] ?? 0) <= $now && ($job['step'] ?? '') !== '';
        }
        // A job left "running" by a worker that died is taken over.
        return $status === JobState::RUNNING && !JobActions::leaseAlive($job, $now);
    }

    /**
     * Heartbeat and cancellation checkpoint for long operations. The cancel
     * flag is read every few seconds; the heartbeat is written every 30 s.
     *
     * @param array<string,mixed> $job
     * @return callable(int=):bool
     */
    private function ticker(array $job, int $totalBytes = 0): callable
    {
        $id = (string) $job['id'];
        $lastBeat = $this->clock->now();
        $lastCheck = $lastBeat;
        $cancelled = false;
        return function (int $bytes = 0) use ($id, $totalBytes, &$lastBeat, &$lastCheck, &$cancelled): bool {
            $now = $this->clock->now();
            if ($now - $lastBeat >= self::HEARTBEAT_EVERY) {
                $lastBeat = $now;
                $lastCheck = $now;
                $stored = $this->store->updateJob($id, function (array $fresh) use ($now, $bytes, $totalBytes): array {
                    if (is_array($fresh['lease'] ?? null)) {
                        $fresh['lease']['heartbeat_at'] = $now;
                    }
                    if ($totalBytes > 0) {
                        $fresh['progress'] = max((int) ($fresh['progress'] ?? 0), min(99, (int) floor($bytes * 100 / $totalBytes)));
                    }
                    return $fresh;
                });
                $cancelled = !empty($stored['cancel_requested']);
            } elseif ($now - $lastCheck >= self::CANCEL_CHECK_EVERY) {
                $lastCheck = $now;
                $cancelled = !empty($this->store->job($id)['cancel_requested']);
            }
            return !$cancelled;
        };
    }

    /**
     * Persists the worker's copy of a job, keeping a cancellation requested
     * meanwhile by the administrator.
     *
     * @param array<string,mixed> $job
     * @return array<string,mixed>
     */
    private function save(array $job, bool $clearCancel = false): array
    {
        $now = $this->clock->now();
        $stored = $this->store->updateJob((string) $job['id'], function (array $fresh) use ($job, $now, $clearCancel): array {
            if (!$clearCancel) {
                $job['cancel_requested'] = !empty($job['cancel_requested']) || !empty($fresh['cancel_requested']);
            }
            if (($job['step'] ?? null) === ($fresh['step'] ?? null) && $job['status'] === JobState::RUNNING) {
                $job['progress'] = max((int) ($job['progress'] ?? 0), (int) ($fresh['progress'] ?? 0));
            }
            if ($job['status'] === JobState::RUNNING && is_array($job['lease'] ?? null)) {
                $job['lease']['heartbeat_at'] = $now;
            } elseif ($job['status'] !== JobState::RUNNING) {
                $job['lease'] = null;
            }
            $job['version'] = (int) ($fresh['version'] ?? 0) + 1;
            $job['updated_at'] = $now;
            return $job;
        });
        return $stored ?? $job;
    }

    /**
     * Latest persisted copy of a job this worker holds.
     *
     * @param array<string,mixed> $job
     * @return array<string,mixed>
     */
    private function stored(array $job): array
    {
        $fresh = $this->store->job((string) $job['id']);
        return is_array($fresh) && ($fresh['status'] ?? '') === JobState::RUNNING ? $fresh : $job;
    }

    private static function isCancellation(\Throwable $error): bool
    {
        return ($error instanceof JobError && $error->errorCode === JobError::CANCELLED)
            || ($error instanceof SystemError && $error->errorCode === SystemError::BACKUP_ABORTED);
    }
}
