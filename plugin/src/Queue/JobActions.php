<?php

declare(strict_types=1);

namespace BladeCP\CpanelMigrator\Queue;

use BladeCP\CpanelMigrator\Api\ApiError;
use BladeCP\CpanelMigrator\Api\Client;
use BladeCP\CpanelMigrator\Store\FileStore;
use BladeCP\CpanelMigrator\Support\Clock;
use BladeCP\CpanelMigrator\Support\Logger;
use BladeCP\CpanelMigrator\System\WorkDir;

/**
 * Administrator actions on a job: cancel, retry, approve, domain review and
 * payment confirmation. Each action runs under the job lock and checks the
 * freshest state before calling the API.
 */
final class JobActions
{
    public const CANCEL_REASON = 'Cancelled by the server administrator.';
    public const ACCOUNT_CANCEL_REASON = 'Cancelled by the account owner.';
    private const LEASE_STALE_SECONDS = 1800;

    public function __construct(
        private readonly FileStore $store,
        private readonly Client $api,
        private readonly WorkDir $workDir,
        private readonly Clock $clock,
        private readonly Logger $log
    ) {
    }

    /** @param string $reason sent to the API (administrator or account owner) */
    public function cancel(string $id, string $reason = self::CANCEL_REASON): ActionResult
    {
        return $this->run($id, function (array $job) use ($reason): array {
            if (!in_array('cancel', JobState::actions($job), true)) {
                return [null, ActionResult::fail('action.not_available')];
            }
            $job['cancel_reason'] = $reason;
            if ($job['status'] === JobState::RUNNING && self::leaseAlive($job, $this->clock->now())) {
                // The worker stops at its next checkpoint and finishes the cancellation.
                $job['cancel_requested'] = true;
                return [JobState::event($job, $this->clock->now(), 'cancel_requested'), ActionResult::ok('action.cancel_requested')];
            }
            try {
                $job = $this->performCancel($job);
            } catch (ApiError $error) {
                return [null, ActionResult::fail($error->messageKey())];
            }
            return [$job, ActionResult::ok('action.cancelled')];
        });
    }

    /**
     * Cancels at BladeCP when a request exists, removes the local backup and
     * marks the job as cancelled. Used by the interface and by the worker.
     *
     * @param array<string,mixed> $job
     * @return array<string,mixed>
     */
    public function performCancel(array $job): array
    {
        $now = $this->clock->now();
        $phase = (string) ($job['remote']['phase'] ?? '');
        if (!empty($job['request_created']) && $phase !== 'cancelled') {
            try {
                $reason = in_array($job['cancel_reason'] ?? null, [self::CANCEL_REASON, self::ACCOUNT_CANCEL_REASON], true) ? $job['cancel_reason'] : self::CANCEL_REASON;
                $result = $this->api->cancel($job['id'], JobState::scope($job), $reason);
                $job['remote']['phase'] = is_string($result['phase'] ?? null) ? $result['phase'] : 'cancelled';
            } catch (ApiError $error) {
                if (!$error->is('MIGRATION_CANCEL_NOT_ALLOWED')) {
                    throw $error;
                }
                // Already past the cancellable phases: record the real state.
                $job = JobState::applyRemote($job, $this->api->getMigration($job['id'], JobState::scope($job)), $now);
                if (($job['remote']['phase'] ?? '') !== 'cancelled') {
                    throw $error;
                }
            }
        }
        $this->workDir->removeJob($job['id']);
        $job['backup'] = null;
        $job['status'] = JobState::CANCELLED;
        $job['wait_reason'] = null;
        $job['lease'] = null;
        $job['cancel_requested'] = false;
        $this->log->info('job.cancelled', ['job' => $job['id'], 'user' => $job['user']]);
        return JobState::event($job, $now, 'cancelled');
    }

    public function retry(string $id): ActionResult
    {
        return $this->run($id, function (array $job): array {
            $now = $this->clock->now();
            if ($job['status'] === JobState::ERROR) {
                $job['status'] = JobState::PENDING;
                $job['attempts'] = 0;
                $job['next_attempt_at'] = 0;
                $job['error'] = null;
                return [JobState::event($job, $now, 'retry'), ActionResult::ok('action.retry_scheduled')];
            }
            if ($job['status'] === JobState::FAILED && ($job['remote']['actions']['retry'] ?? false)) {
                try {
                    $result = $this->api->retry($job['id'], JobState::scope($job));
                } catch (ApiError $error) {
                    return [null, ActionResult::fail($error->messageKey())];
                }
                $job['remote']['phase'] = is_string($result['migration']['phase'] ?? null) ? $result['migration']['phase'] : 'queued';
                $job['remote']['actions']['retry'] = false;
                $job['status'] = JobState::WAITING;
                $job['wait_reason'] = 'processing';
                $job['step'] = 'track';
                $job['polled_at'] = 0;
                $job['error'] = null;
                return [JobState::event($job, $now, 'remote_retry'), ActionResult::ok('action.retry_requested')];
            }
            return [null, ActionResult::fail('action.not_available')];
        });
    }

    public function approve(string $id): ActionResult
    {
        return $this->run($id, function (array $job): array {
            if (!in_array('approve', JobState::actions($job), true)) {
                return [null, ActionResult::fail('action.not_available')];
            }
            try {
                $result = $this->api->approve($job['id'], JobState::scope($job));
            } catch (ApiError $error) {
                return [null, ActionResult::fail($error->messageKey())];
            }
            $job['remote']['phase'] = is_string($result['phase'] ?? null) ? $result['phase'] : 'syncing';
            $job['remote']['actions']['approve'] = false;
            $job['wait_reason'] = 'processing';
            $job['polled_at'] = 0;
            return [JobState::event($job, $this->clock->now(), 'approved'), ActionResult::ok('action.approved')];
        });
    }

    public function review(string $id, string $domain): ActionResult
    {
        $domain = strtolower(trim($domain));
        if (!self::validDomain($domain)) {
            return ActionResult::fail('action.invalid_domain');
        }
        return $this->run($id, function (array $job) use ($domain): array {
            if (!in_array('review', JobState::actions($job), true)) {
                return [null, ActionResult::fail('action.not_available')];
            }
            try {
                $this->api->discoveryReview($job['id'], JobState::scope($job), $domain);
            } catch (ApiError $error) {
                return [null, ActionResult::fail($error->messageKey())];
            }
            $job['review_domain'] = $domain;
            $job['remote']['actions']['review'] = false;
            $job['wait_reason'] = 'processing';
            $job['polled_at'] = 0;
            return [JobState::event($job, $this->clock->now(), 'domain_reviewed'), ActionResult::ok('action.domain_confirmed', ['domain' => $domain])];
        });
    }

    public function markPaid(string $id): ActionResult
    {
        return $this->run($id, function (array $job): array {
            if (!in_array('mark_paid', JobState::actions($job), true)) {
                return [null, ActionResult::fail('action.not_available')];
            }
            try {
                $result = $this->api->commercialEvent($job['id'], JobState::scope($job), 'payment_verified');
            } catch (ApiError $error) {
                return [null, ActionResult::fail($error->messageKey())];
            }
            $state = $result['commercial']['state'] ?? 'paid';
            $job['remote']['commercial_state'] = is_string($state) ? $state : 'paid';
            if ($job['status'] === JobState::WAITING && $job['wait_reason'] === 'payment') {
                if ($job['step'] === 'confirm') {
                    // The worker confirms the upload on its next run.
                    $job['status'] = JobState::PENDING;
                    $job['wait_reason'] = null;
                    $job['next_attempt_at'] = 0;
                } else {
                    $job['wait_reason'] = 'approval';
                    $job['polled_at'] = 0;
                }
            }
            return [JobState::event($job, $this->clock->now(), 'payment_verified'), ActionResult::ok('action.payment_recorded')];
        });
    }

    /** @param array<string,mixed> $job */
    public static function leaseAlive(array $job, int $now): bool
    {
        $lease = $job['lease'] ?? null;
        if (!is_array($lease) || !is_int($lease['pid'] ?? null) || !is_int($lease['heartbeat_at'] ?? null)) {
            return false;
        }
        if ($now - $lease['heartbeat_at'] > self::LEASE_STALE_SECONDS) {
            return false;
        }
        $pid = $lease['pid'];
        if ($pid <= 0) {
            return false;
        }
        if (function_exists('posix_kill')) {
            return @posix_kill($pid, 0) || posix_get_last_error() === 1;
        }
        return !is_dir('/proc') || is_dir('/proc/' . $pid);
    }

    public static function validDomain(string $domain): bool
    {
        return strlen($domain) <= 63
            && preg_match('/^(?=.{1,63}$)(?!-)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/D', $domain) === 1;
    }

    /**
     * @param callable(array<string,mixed>):array{0:?array<string,mixed>,1:ActionResult} $mutation
     */
    private function run(string $id, callable $mutation): ActionResult
    {
        $result = ActionResult::fail('action.not_found');
        $this->store->updateJob($id, function (array $job) use ($mutation, &$result): ?array {
            [$next, $result] = $mutation($job);
            if ($next === null) {
                return null;
            }
            $next['version'] = (int) ($job['version'] ?? 0) + 1;
            $next['updated_at'] = $this->clock->now();
            return $next;
        });
        $this->log->info('action', ['job' => $id, 'ok' => $result->ok, 'result' => $result->messageKey]);
        return $result;
    }
}
