<?php

declare(strict_types=1);

namespace BladeCP\CpanelMigrator\Client;

use BladeCP\CpanelMigrator\Queue\JobState;
use BladeCP\CpanelMigrator\Web\View;

/**
 * What the account owner may see of a migration: a closed list of fields
 * built from the job. Nothing internal leaves this projection (request
 * payload, installation, lease, events, paths, upload, billing link or the
 * technical error detail).
 *
 * States, from the owner's point of view:
 *   queued, retrying, working, cancelling   the migration runs by itself
 *   review, approval, claim                 the owner has something to do (claim:
 *                                           confirm the account in the provider
 *                                           client area for the billing link)
 *   provider                                waits for the hosting provider (payment)
 *   complete                                done after the approval; pointing the
 *                                           domain is optional and never a step
 *   error, failed, cancelled                stopped
 */
final class JobView
{
    public const STATES = ['queued', 'retrying', 'working', 'cancelling', 'review', 'approval', 'claim', 'provider', 'complete', 'error', 'failed', 'cancelled'];
    /** The owner has something to do (pointing the domain is optional, so it never counts). */
    public const ATTENTION = ['review', 'approval', 'claim'];
    /** Operations the owner may run (the provider keeps payment and retry). */
    public const OPS = ['review', 'approve', 'cancel'];
    /** Fields of a view, in order. */
    public const FIELDS = ['id', 'state', 'stage', 'percent', 'activity', 'domain', 'suggested_domain', 'actions', 'preview', 'preview_url',
        'pointing', 'error', 'retry_minutes', 'origin', 'created_at', 'updated_at'];
    public const ACTIVITIES = ['queued', 'backup', 'upload', 'preparing', 'analysis', 'import', 'preview', 'sync', 'cancelling', 'review', 'approval', 'claim', 'provider'];
    /** Optional domain pointing of a complete migration. */
    public const POINTING = ['pending', 'stalled', 'connected'];
    public const ERRORS = ['copy', 'transfer', 'generic', 'failed', 'retrying'];

    private const COPY_CODES = ['account_not_found', 'whmapi_failed', 'backup_failed', 'backup_missing', 'backup_aborted', 'disk_space_low', 'work_dir_unsafe'];

    /** @param array<string,mixed> $job */
    public static function state(array $job): string
    {
        $status = (string) ($job['status'] ?? '');
        if (!empty($job['cancel_requested']) && !JobState::isTerminal($job)) {
            return 'cancelling';
        }
        if (JobState::isComplete($job)) {
            return 'complete';
        }
        $actions = JobState::actions($job);
        return match ($status) {
            JobState::CANCELLED => 'cancelled',
            JobState::FAILED => 'failed',
            JobState::ERROR => 'error',
            JobState::PENDING => is_array($job['error'] ?? null) ? 'retrying' : 'queued',
            JobState::WAITING => match ($job['wait_reason'] ?? 'processing') {
                'review' => in_array('review', $actions, true) ? 'review' : 'working',
                'approval' => in_array('approve', $actions, true) ? 'approval' : 'working',
                'payment' => 'provider',
                // Billing link: the holder confirms the account (claim) or the provider WHMCS links it.
                'billing' => JobState::billingStatus($job) === 'claim_required' ? 'claim' : 'working',
                default => 'working',
            },
            default => 'working',
        };
    }

    /**
     * @param array<string,mixed> $job
     * @return array<string,mixed>
     */
    public static function from(array $job, int $now): array
    {
        $state = self::state($job);
        $remote = is_array($job['remote'] ?? null) ? $job['remote'] : [];
        $stage = View::stageOf($job);
        $domain = self::domain($job);
        $actions = [];
        if ($state === 'claim') {
            $actions[] = 'claim';
        }
        foreach (JobState::actions($job) as $action) {
            if (($action === 'review' && $state === 'review') || ($action === 'approve' && $state === 'approval') || $action === 'cancel') {
                $actions[] = $action;
            }
        }
        $pointing = $state === 'complete' ? self::pointing($domain, $remote) : null;
        $preview = in_array($state, ['approval', 'working', 'provider', 'complete'], true) && ($stage[0] ?? 0) >= 5
            && ($pointing['status'] ?? null) !== 'connected' ? self::url($remote['staging_url'] ?? null) : null;
        $minutes = $state === 'retrying' ? max(1, (int) ceil(((int) ($job['next_attempt_at'] ?? 0) - $now) / 60)) : null;
        return [
            'id' => (string) ($job['id'] ?? ''),
            'state' => $state,
            'stage' => $stage[0] ?? null,
            'percent' => $stage[1] ?? null,
            'activity' => ($job['wait_reason'] ?? null) === 'billing' && $state === 'working'
                ? 'preparing' : self::activity($state, $stage[0] ?? null, (string) ($remote['phase'] ?? '')),
            'domain' => $domain,
            'suggested_domain' => $state === 'review' ? self::host($remote['source_domain'] ?? null) ?? $domain : null,
            'actions' => $actions,
            'preview' => $preview !== null,
            'preview_url' => $preview,
            'pointing' => $pointing,
            'error' => self::error($job, $state),
            'retry_minutes' => $minutes,
            'origin' => JobState::origin($job),
            'created_at' => (int) ($job['created_at'] ?? 0),
            'updated_at' => (int) ($job['updated_at'] ?? 0),
        ];
    }

    /**
     * One line of the history.
     *
     * @param array<string,mixed> $job
     * @return array{id:string,state:string,domain:string,created_at:int,updated_at:int}
     */
    public static function summary(array $job): array
    {
        return [
            'id' => (string) ($job['id'] ?? ''),
            'state' => self::state($job),
            'domain' => self::domain($job),
            'created_at' => (int) ($job['created_at'] ?? 0),
            'updated_at' => (int) ($job['updated_at'] ?? 0),
        ];
    }

    /** @param array<string,mixed> $job */
    private static function domain(array $job): string
    {
        $remote = is_array($job['remote'] ?? null) ? $job['remote'] : [];
        return self::host($remote['primary_domain'] ?? null) ?? self::host($job['domain'] ?? null) ?? '';
    }

    private static function activity(string $state, ?int $stage, string $phase): ?string
    {
        return match ($state) {
            'queued' => 'queued',
            'cancelling' => 'cancelling',
            'review', 'approval', 'provider', 'claim' => $state,
            'working', 'retrying' => match ($stage) {
                1 => 'backup',
                2 => 'upload',
                3 => 'analysis',
                4 => 'import',
                5 => $phase === 'syncing' ? 'sync' : 'preview',
                default => 'analysis',
            },
            default => null,
        };
    }

    /**
     * Optional domain pointing: connected once live, stalled when the
     * automatic check ended, pending otherwise. The BladeCP nameservers, and
     * as an alternative the records straight to the new server: A (or AAAA)
     * for an IP target, CNAME for a host name. The check deadline is shown
     * only while pending, as information.
     *
     * @param array<string,mixed> $remote
     * @return array{status:string,target:?string,records:list<array{type:string,name:string,host:string,value:string}>,deadline:?int}
     */
    private static function pointing(string $domain, array $remote): array
    {
        $phase = (string) ($remote['phase'] ?? '');
        $status = $phase === 'live' ? 'connected' : ($phase === 'cutover_stalled' ? 'stalled' : 'pending');
        $target = is_string($remote['cutover_target'] ?? null) ? $remote['cutover_target'] : null;
        $records = [];
        if ($target !== null && $domain !== '') {
            $type = filter_var($target, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false ? 'A'
                : (filter_var($target, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? 'AAAA' : 'CNAME');
            foreach (['@' => $domain, 'www' => 'www.' . $domain] as $name => $host) {
                $records[] = ['type' => $type, 'name' => $name, 'host' => $host, 'value' => $target];
            }
        }
        $deadline = $remote['cutover_deadline_at'] ?? null;
        return ['status' => $status, 'nameservers' => View::NAMESERVERS, 'target' => $target, 'records' => $records,
            'deadline' => $status === 'pending' && is_int($deadline) && $deadline > 0 ? $deadline : null];
    }

    /**
     * Plain-language error: the message key and a short code for support.
     *
     * @param array<string,mixed> $job
     * @return array{key:string,code:string}|null
     */
    private static function error(array $job, string $state): ?array
    {
        $raw = is_array($job['error'] ?? null) ? (string) ($job['error']['code'] ?? '') : '';
        $code = preg_match('/^[a-z0-9_.:-]{1,60}$/D', $raw) === 1 ? $raw : 'unexpected';
        return match ($state) {
            'error' => ['key' => match (true) {
                in_array($code, self::COPY_CODES, true) => 'copy',
                str_starts_with($code, 'upload_') || in_array($code, ['backup_changed', 'confirm_not_available'], true) => 'transfer',
                default => 'generic',
            }, 'code' => $code],
            'retrying' => ['key' => 'retrying', 'code' => $code],
            'failed' => ['key' => 'failed', 'code' => 'migration_failed'],
            default => null,
        };
    }

    private static function host(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = strtolower(trim($value));
        return $value !== '' && strlen($value) <= 253 && preg_match('/^[a-z0-9.-]+$/D', $value) === 1 ? $value : null;
    }

    private static function url(mixed $value): ?string
    {
        if (!is_string($value) || strlen($value) > 2048) {
            return null;
        }
        $parts = parse_url($value);
        return is_array($parts) && in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true) && !empty($parts['host']) ? $value : null;
    }
}
