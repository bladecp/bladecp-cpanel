<?php

declare(strict_types=1);

namespace BladeCP\CpanelMigrator\Queue;

use BladeCP\CpanelMigrator\Api\ApiError;
use BladeCP\CpanelMigrator\Support\Redactor;
use BladeCP\CpanelMigrator\System\SystemError;

/**
 * Pure state machine of one account migration attempt.
 *
 * Local steps, in order:
 *   backup -> request -> service -> upload -> complete -> confirm -> track
 *
 * Status:
 *   pending   waiting for a worker slot (or for an automatic retry time)
 *   running   a worker holds the job lease
 *   waiting   BladeCP is working, or a person must act (wait_reason)
 *   error     stopped; the administrator can retry
 *   failed    BladeCP reported the migration as failed
 *   done      the migration is live
 *   cancelled cancelled by the administrator
 *
 * Wait reasons: processing, payment, billing, review, approval, dns.
 * "billing" means the upload is done and the request waits for the partner
 * billing WHMCS to link its owner and service (billing_link pending or
 * claim_required); the API refuses confirm-upload until then. "dns" means the
 * migration is complete and only the optional domain pointing is followed
 * (see isComplete()); cutover_stalled and live are done.
 */
final class JobState
{
    public const STEPS = ['backup', 'request', 'service', 'upload', 'complete', 'confirm', 'track'];

    public const PENDING = 'pending';
    public const RUNNING = 'running';
    public const WAITING = 'waiting';
    public const ERROR = 'error';
    public const FAILED = 'failed';
    public const DONE = 'done';
    public const CANCELLED = 'cancelled';

    public const TERMINAL = [self::FAILED, self::DONE, self::CANCELLED];

    /** Remote phases before the backup is confirmed. */
    public const PRE_CONFIRM_PHASES = ['draft', 'uploading'];
    public const CANCELLABLE_PHASES = [
        'draft', 'uploading', 'queued', 'parsing', 'discovery_review', 'provisioning',
        'importing', 'validating', 'awaiting_approval',
    ];

    /** Who started the migration: the WHM administrator or the account itself (cPanel app). */
    public const ORIGINS = ['whm', 'cpanel'];

    public const MAX_AUTO_ATTEMPTS = 6;
    private const BACKOFF = [60, 300, 900, 1800, 3600];
    private const MAX_EVENTS = 30;
    private const PAID_STATES = ['paid', 'regularized'];

    /**
     * New job for one account. The create payload is frozen here so a retry
     * always replays exactly the same request (same request_id).
     *
     * @param array<string,mixed> $payload API body for POST /customer/migrations
     * @param string $origin whm (WHM administrator) or cpanel (the account, from the cPanel app)
     * @param string $initiatedBy root or the account name
     * @return array<string,mixed>
     */
    public static function create(string $requestId, string $user, string $domain, string $externalServiceId, array $payload, int $now,
        string $origin = 'whm', string $initiatedBy = 'root'): array
    {
        return [
            'id' => $requestId,
            'version' => 1,
            'user' => $user,
            'origin' => in_array($origin, self::ORIGINS, true) ? $origin : 'whm',
            'initiated_by' => $initiatedBy,
            'domain' => $domain,
            'external_client_id' => (string) $payload['external_client_id'],
            'external_service_id' => $externalServiceId,
            'commercial_mode' => (string) ($payload['commercial']['mode'] ?? 'trial'),
            'payload' => $payload,
            'step' => 'backup',
            'status' => self::PENDING,
            'wait_reason' => null,
            'request_created' => false,
            'service_bound' => false,
            'remote' => null,
            'backup' => null,
            'upload' => null,
            'upload_restarts' => 0,
            'progress' => 0,
            'attempts' => 0,
            'next_attempt_at' => 0,
            'error' => null,
            'lease' => null,
            'cancel_requested' => false,
            'review_domain' => null,
            'created_at' => $now,
            'updated_at' => $now,
            'polled_at' => 0,
            'events' => [['at' => $now, 'event' => 'queued']],
        ];
    }

    /**
     * The migration is complete: live, or waiting only for the optional
     * domain pointing (cutover_pending). Pointing the domain is never a step.
     *
     * @param array<string,mixed> $job
     */
    public static function isComplete(array $job): bool
    {
        return ($job['status'] ?? '') === self::DONE || (($job['status'] ?? '') === self::WAITING && ($job['wait_reason'] ?? null) === 'dns');
    }

    /** Billing link states that hold the request before confirm-upload. */
    public const BILLING_WAITING = ['pending', 'claim_required'];

    /**
     * New format: the request carries `source` and no owner, and the partner
     * billing WHMCS links the owner and the service. The plugin never calls
     * `/service` or `/commercial` for it.
     *
     * @param array<string,mixed> $job
     */
    public static function usesBillingLink(array $job): bool
    {
        return is_array($job['payload']['source'] ?? null);
    }

    /** Billing link status from the API (not_required, pending, claim_required, linked) or null before the first view. */
    public static function billingStatus(array $job): ?string
    {
        $status = $job['remote']['billing_link']['status'] ?? null;
        return is_string($status) ? $status : null;
    }

    /** True while the billing link holds the request (pending or claim_required). */
    public static function billingBlocks(array $job): bool
    {
        $status = self::billingStatus($job);
        return $status !== null ? in_array($status, self::BILLING_WAITING, true) : self::usesBillingLink($job);
    }

    /** Origin of a job; jobs created before the cPanel app came from WHM. */
    public static function origin(array $job): string
    {
        return ($job['origin'] ?? 'whm') === 'cpanel' ? 'cpanel' : 'whm';
    }

    /** @param array<string,mixed> $job */
    public static function isTerminal(array $job): bool
    {
        return in_array($job['status'] ?? '', self::TERMINAL, true);
    }

    /** @return array{installation_id:string,external_client_id:string} */
    public static function scope(array $job): array
    {
        return [
            'installation_id' => (string) ($job['payload']['installation_id'] ?? ''),
            'external_client_id' => (string) ($job['external_client_id'] ?? ''),
        ];
    }

    /**
     * Next step after the backup exists, skipping what the API already has.
     *
     * @param array<string,mixed> $job
     */
    public static function stepAfterBackup(array $job): string
    {
        if (empty($job['request_created'])) {
            return 'request';
        }
        // The billing link brings the service; the plugin never binds one.
        return self::usesBillingLink($job) || !empty($job['service_bound']) ? 'upload' : 'service';
    }

    /**
     * Records the API view of the migration and, once the backup is
     * confirmed, maps the remote phase to the local status.
     *
     * @param array<string,mixed> $job
     * @param array<string,mixed> $view request view from the Customer API
     * @return array<string,mixed>
     */
    public static function applyRemote(array $job, array $view, int $now): array
    {
        $migration = is_array($view['migration'] ?? null) ? $view['migration'] : [];
        $commercial = is_array($view['commercial'] ?? null) ? $view['commercial'] : [];
        $billing = is_array($view['billing_link'] ?? null) ? $view['billing_link'] : [];
        $billingStatus = in_array($billing['status'] ?? null, ['not_required', 'pending', 'claim_required', 'linked'], true) ? $billing['status'] : null;
        $whmcsService = $billing['whmcs_service_id'] ?? null;
        $whmcsService = (is_string($whmcsService) || is_int($whmcsService)) && preg_match('/^[A-Za-z0-9._:-]{1,64}$/D', (string) $whmcsService) === 1 ? (string) $whmcsService : null;
        $actions = is_array($view['actions'] ?? null) ? $view['actions'] : [];
        $phase = is_string($migration['phase'] ?? null) ? $migration['phase'] : (string) ($job['remote']['phase'] ?? '');
        $components = [];
        foreach (is_array($migration['components'] ?? null) ? $migration['components'] : [] as $component) {
            if (is_array($component) && is_string($component['name'] ?? null) && is_string($component['status'] ?? null)) {
                $components[] = ['name' => $component['name'], 'status' => $component['status']];
            }
        }
        $job['remote'] = [
            'phase' => $phase,
            'staging_url' => self::safeUrl($migration['stagingUrl'] ?? null),
            'source_domain' => self::stringOrNull($migration['sourcePrimaryDomain'] ?? null),
            'primary_domain' => self::stringOrNull($migration['primaryDomain'] ?? null),
            'requested_domain' => self::stringOrNull($migration['requestedPrimaryDomain'] ?? null),
            'backup_ready' => ($migration['backupReady'] ?? false) === true,
            'commercial_state' => self::stringOrNull($commercial['state'] ?? null),
            'service_bound' => self::stringOrNull($commercial['externalServiceId'] ?? null) !== null,
            'components' => $components,
            'billing_link' => $billingStatus === null ? null
                : ['status' => $billingStatus, 'whmcs_service_id' => $billingStatus === 'linked' ? $whmcsService : null],
            'waiting_reason' => ($view['waiting_reason'] ?? null) === 'waiting_for_provider' ? 'waiting_for_provider' : null,
            'cutover_target' => self::cutoverTarget($migration['cutoverTarget'] ?? null),
            'cutover_deadline_at' => self::timestamp($migration['cutoverDeadlineAt'] ?? null),
            'actions' => [
                'upload' => ($actions['upload'] ?? false) === true,
                'confirm_upload' => ($actions['confirmUpload'] ?? false) === true,
                'review' => ($actions['review'] ?? false) === true,
                'approve' => ($actions['approve'] ?? false) === true,
                'cancel' => ($actions['cancel'] ?? false) === true,
                'retry' => ($actions['retry'] ?? false) === true,
            ],
            'updated_at' => $now,
        ];
        if ($job['remote']['service_bound']) {
            $job['service_bound'] = true;
        }
        return self::mapPhase($job, $now);
    }

    /**
     * @param array<string,mixed> $job
     * @return array<string,mixed>
     */
    public static function mapPhase(array $job, int $now): array
    {
        $phase = (string) ($job['remote']['phase'] ?? '');
        $before = [$job['status'], $job['wait_reason'] ?? null, $phase];
        if ($phase === 'cancelled') {
            $job['status'] = self::CANCELLED;
            $job['wait_reason'] = null;
        } elseif ($phase === 'failed') {
            $job['status'] = self::FAILED;
            $job['wait_reason'] = null;
        } elseif ($phase === 'live' || $phase === 'cutover_stalled') {
            // Live, or the automatic DNS check ended: nothing left to follow.
            $job['status'] = self::DONE;
            $job['wait_reason'] = null;
            $job['progress'] = 100;
        } elseif ($phase !== '' && !in_array($phase, self::PRE_CONFIRM_PHASES, true)) {
            // The backup is confirmed: BladeCP drives the migration from here.
            $job['step'] = 'track';
            $job['status'] = self::WAITING;
            $job['wait_reason'] = self::waitReason($job, $phase);
        }
        if (in_array($job['status'], self::TERMINAL, true)) {
            $job['lease'] = null;
        }
        $after = [$job['status'], $job['wait_reason'] ?? null, $phase];
        if ($before !== $after) {
            $job = self::event($job, $now, 'phase:' . ($phase === '' ? 'unknown' : $phase));
        }
        return $job;
    }

    /** @param array<string,mixed> $job */
    private static function waitReason(array $job, string $phase): string
    {
        $remote = $job['remote'] ?? [];
        return match ($phase) {
            'discovery_review' => ($remote['requested_domain'] ?? null) !== null || ($job['review_domain'] ?? null) !== null
                || !($remote['actions']['review'] ?? false) ? 'processing' : 'review',
            'awaiting_approval' => ($remote['actions']['approve'] ?? false)
                ? 'approval'
                : (self::needsPayment($job) ? 'payment' : 'approval'),
            // Complete; pointing the domain is optional. Polled until live or the check ends.
            'cutover_pending' => 'dns',
            default => 'processing',
        };
    }

    /** True when the commercial rule still waits for a payment confirmation. */
    public static function needsPayment(array $job): bool
    {
        if (($job['commercial_mode'] ?? 'trial') === 'trial') {
            return false;
        }
        return !in_array($job['remote']['commercial_state'] ?? null, self::PAID_STATES, true);
    }

    /**
     * Administrator actions available for a job, in display order.
     *
     * @param array<string,mixed> $job
     * @return list<string>
     */
    public static function actions(array $job): array
    {
        $status = $job['status'] ?? '';
        $reason = $job['wait_reason'] ?? null;
        $remote = is_array($job['remote'] ?? null) ? $job['remote'] : [];
        $remoteActions = is_array($remote['actions'] ?? null) ? $remote['actions'] : [];
        $actions = [];
        if ($status === self::WAITING && $reason === 'review' && ($remoteActions['review'] ?? false)) {
            $actions[] = 'review';
        }
        if ($status === self::WAITING && $reason === 'approval' && ($remoteActions['approve'] ?? false)) {
            $actions[] = 'approve';
        }
        // A billing link request is paid in the partner WHMCS: never marked as paid here.
        if (!empty($job['request_created']) && !self::isTerminal($job) && self::needsPayment($job) && !self::usesBillingLink($job)
            && in_array(self::billingStatus($job), [null, 'not_required'], true)
            && ($remote['commercial_state'] ?? null) !== 'terminated') {
            $actions[] = 'mark_paid';
        }
        if ($status === self::ERROR || ($status === self::FAILED && ($remoteActions['retry'] ?? false))) {
            $actions[] = 'retry';
        }
        $phase = (string) ($remote['phase'] ?? '');
        if (!self::isTerminal($job) && empty($job['cancel_requested'])
            && (empty($job['request_created']) || $phase === '' || in_array($phase, self::CANCELLABLE_PHASES, true))) {
            $actions[] = 'cancel';
        }
        return $actions;
    }

    /**
     * Records a failure. Retryable failures go back to the queue with an
     * exponential delay until MAX_AUTO_ATTEMPTS; the rest stop as "error".
     *
     * @param array<string,mixed> $job
     * @return array<string,mixed>
     */
    public static function fail(array $job, \Throwable $error, int $now): array
    {
        [$code, $key, $detail, $retryable, $retryAfter] = self::classify($error);
        $attempts = (int) ($job['attempts'] ?? 0) + 1;
        $job['attempts'] = $attempts;
        $job['error'] = [
            'code' => $code,
            'key' => $key,
            'detail' => $detail,
            'step' => (string) ($job['step'] ?? ''),
            'at' => $now,
            'retryable' => $retryable,
        ];
        $job['lease'] = null;
        if ($retryable && $attempts < self::MAX_AUTO_ATTEMPTS) {
            $job['status'] = self::PENDING;
            $job['next_attempt_at'] = $now + max($retryAfter ?? 0, self::backoff($attempts));
        } else {
            $job['status'] = self::ERROR;
            $job['next_attempt_at'] = 0;
        }
        return self::event($job, $now, 'error:' . $code);
    }

    /**
     * @return array{0:string,1:string,2:string,3:bool,4:?int} code, message key, detail, retryable, retry after
     */
    public static function classify(\Throwable $error): array
    {
        if ($error instanceof ApiError) {
            $code = $error->apiCode !== null ? strtolower($error->apiCode) : $error->kind;
            return [$code, $error->messageKey(), Redactor::string($error->getMessage()), $error->retryable, $error->retryAfter];
        }
        if ($error instanceof SystemError) {
            return [$error->errorCode, 'error.local.' . $error->errorCode, Redactor::string(trim($error->getMessage() . ' ' . $error->detail)), $error->retryable, null];
        }
        if ($error instanceof JobError) {
            return [$error->errorCode, 'error.local.' . $error->errorCode, Redactor::string(trim($error->getMessage() . ' ' . $error->detail)), $error->retryable, null];
        }
        return ['unexpected', 'error.local.unexpected', Redactor::string($error->getMessage()), true, null];
    }

    public static function backoff(int $attempt): int
    {
        return self::BACKOFF[max(0, min(count(self::BACKOFF) - 1, $attempt - 1))];
    }

    /**
     * @param array<string,mixed> $job
     * @return array<string,mixed>
     */
    public static function event(array $job, int $now, string $event): array
    {
        $events = is_array($job['events'] ?? null) ? $job['events'] : [];
        $events[] = ['at' => $now, 'event' => substr(preg_replace('/[^a-z0-9_:.-]/i', '_', $event) ?? 'event', 0, 80)];
        $job['events'] = array_slice($events, -self::MAX_EVENTS);
        return $job;
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /** DNS target for the cutover: an IPv4, an IPv6 or a host name, or null. */
    private static function cutoverTarget(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = strtolower(trim($value));
        if (filter_var($value, FILTER_VALIDATE_IP) !== false) {
            return $value;
        }
        $host = rtrim($value, '.');
        return strlen($host) <= 253 && preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{0,62}$/D', $host) === 1 ? $host : null;
    }

    private static function timestamp(mixed $value): ?int
    {
        if (!is_string($value) || strlen($value) > 40) {
            return null;
        }
        $time = strtotime($value);
        return $time === false || $time <= 0 ? null : $time;
    }

    private static function safeUrl(mixed $value): ?string
    {
        if (!is_string($value) || strlen($value) > 2048) {
            return null;
        }
        $parts = parse_url($value);
        if (!is_array($parts) || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true) || empty($parts['host'])) {
            return null;
        }
        return $value;
    }
}
