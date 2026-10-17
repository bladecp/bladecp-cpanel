<?php

declare(strict_types=1);

namespace BladeCP\CpanelMigrator\Queue;

use BladeCP\CpanelMigrator\Store\FileStore;
use BladeCP\CpanelMigrator\Support\Clock;
use BladeCP\CpanelMigrator\Support\Logger;
use BladeCP\CpanelMigrator\Support\Uuid;
use BladeCP\CpanelMigrator\System\SystemAdapter;

/**
 * Validates a "Migrate selected" submission and queues one job per account.
 * All rows are validated first; nothing is queued when any row is invalid.
 *
 * New migrations use the billing link (`billing_link` true, the default):
 * the request carries `source` (this server, the account, its cPanel OWNER,
 * the main domain and who started it) and no owner, and the partner billing
 * WHMCS links the owner and the service. The old format (owner e-mail and
 * name, billing service id) stays for jobs created before it.
 */
final class Enqueuer
{
    public const MAX_BATCH = 100;
    private const SERVICE_ID = '/^[\x21-\x7E]{1,191}$/D';

    public function __construct(
        private readonly FileStore $store,
        private readonly SystemAdapter $system,
        private readonly Clock $clock,
        private readonly Logger $log
    ) {
    }

    /**
     * @param array{installation_id:string,plan_id:int,location_id:int,native_product_id:int,commercial:array<string,mixed>,domain_mode:string,origin?:string,initiated_by?:string,source_initiated_by?:string,billing_link?:bool} $batch
     * @param list<array<string,mixed>> $rows user, email, name, service_id
     * @return array{created:list<string>,errors:array<string,array<string,string>>,general:?string}
     */
    public function enqueue(array $batch, array $rows): array
    {
        if ($rows === []) {
            return ['created' => [], 'errors' => [], 'general' => 'accounts.error.none_selected'];
        }
        if (count($rows) > self::MAX_BATCH) {
            return ['created' => [], 'errors' => [], 'general' => 'accounts.error.too_many'];
        }
        if (!Uuid::isValid($batch['installation_id'] ?? null)) {
            return ['created' => [], 'errors' => [], 'general' => 'error.local.installation_missing'];
        }
        $linked = ($batch['billing_link'] ?? true) === true;
        $hostname = '';
        if ($linked) {
            $hostname = $this->system->hostname();
            if (!self::validHost($hostname) && filter_var($hostname, FILTER_VALIDATE_IP) === false) {
                return ['created' => [], 'errors' => [], 'general' => 'accounts.error.hostname'];
            }
        }
        $accounts = [];
        foreach ($this->system->listAccounts() as $account) {
            $accounts[$account['user']] = $account;
        }
        $active = self::activeUsers($this->store->jobs());

        $errors = [];
        $prepared = [];
        $seen = [];
        foreach ($rows as $row) {
            $user = is_string($row['user'] ?? null) ? $row['user'] : '';
            $account = $accounts[$user] ?? null;
            if ($account === null || isset($seen[$user])) {
                $errors[$user === '' ? '?' : $user]['user'] = 'accounts.error.unknown_account';
                continue;
            }
            $seen[$user] = true;
            if (isset($active[$user])) {
                $errors[$user]['user'] = 'accounts.error.already_queued';
                continue;
            }
            if ($linked) {
                // The owner and the billing service come from the partner WHMCS.
                if (!self::validHost((string) $account['domain'])) {
                    $errors[$user]['user'] = 'accounts.error.domain';
                    continue;
                }
                $prepared[] = ['account' => $account, 'email' => '', 'name' => '', 'service_id' => ''];
                continue;
            }
            $email = trim(is_string($row['email'] ?? null) ? $row['email'] : '');
            if ($email === '' || strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $errors[$user]['email'] = 'accounts.error.email';
            }
            $name = trim(is_string($row['name'] ?? null) ? $row['name'] : '');
            if ($name === '') {
                $name = $account['domain'] !== '' ? $account['domain'] : $user;
            }
            if (strlen($name) > 255 || preg_match('//u', $name) !== 1 || preg_match('/[\x00-\x1F\x7F]/', $name) === 1) {
                $errors[$user]['name'] = 'accounts.error.name';
            }
            $serviceId = trim(is_string($row['service_id'] ?? null) ? $row['service_id'] : '');
            if ($serviceId === '') {
                $serviceId = 'cpanel:' . $user;
            } elseif (preg_match(self::SERVICE_ID, $serviceId) !== 1) {
                $errors[$user]['service_id'] = 'accounts.error.service_id';
            }
            $prepared[] = ['account' => $account, 'email' => $email, 'name' => $name, 'service_id' => $serviceId];
        }
        if ($errors !== []) {
            return ['created' => [], 'errors' => $errors, 'general' => 'accounts.error.review_rows'];
        }

        $created = [];
        $now = $this->clock->now();
        foreach ($prepared as $item) {
            $account = $item['account'];
            $requestId = Uuid::v4();
            $payload = [
                'installation_id' => $batch['installation_id'],
                'external_client_id' => 'cpanel:' . $account['user'],
                'request_id' => $requestId,
            ];
            if ($linked) {
                $owner = strtolower(trim((string) ($account['owner'] ?? '')));
                $payload['source'] = [
                    'kind' => 'cpanel',
                    'server_hostname' => $hostname,
                    'username' => (string) $account['user'],
                    'owner' => preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/D', $owner) === 1 ? $owner : 'root',
                    'domain' => strtolower((string) $account['domain']),
                    'initiated_by' => in_array($batch['source_initiated_by'] ?? null, ['root', 'account', 'reseller'], true) ? $batch['source_initiated_by'] : 'root',
                ];
            } else {
                $payload['owner'] = ['email' => $item['email'], 'name' => $item['name']];
            }
            $payload += [
                'native_product_id' => $batch['native_product_id'],
                'partner_plan_id' => $batch['plan_id'],
                'location_id' => $batch['location_id'],
                'source_panel' => 'cpanel',
                'commercial' => $batch['commercial'],
            ];
            if ($batch['domain_mode'] === 'account' && JobActions::validDomain($account['domain'])) {
                $payload['primary_domain'] = $account['domain'];
            }
            $origin = (string) ($batch['origin'] ?? 'whm');
            $job = JobState::create($requestId, $account['user'], $account['domain'], $item['service_id'], $payload, $now,
                $origin, (string) ($batch['initiated_by'] ?? 'root'));
            $job['estimated_bytes'] = $account['disk_used_bytes'];
            $this->store->createJob($job);
            $created[] = $requestId;
            $this->log->info('job.queued', ['job' => $requestId, 'user' => $account['user'], 'mode' => $batch['commercial']['mode'] ?? '', 'origin' => $job['origin']]);
        }
        return ['created' => $created, 'errors' => [], 'general' => null];
    }

    /** Host name (letters, digits, dots and hyphens, at most 253 characters, with a dot). */
    public static function validHost(string $host): bool
    {
        return strlen($host) <= 253
            && preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $host) === 1;
    }

    /**
     * Accounts that already have a migration in progress. A complete
     * migration (only the optional domain pointing left) does not count.
     *
     * @param list<array<string,mixed>> $jobs
     * @return array<string,string> user => job id
     */
    public static function activeUsers(array $jobs): array
    {
        $active = [];
        foreach ($jobs as $job) {
            if (!JobState::isTerminal($job) && !JobState::isComplete($job)) {
                $active[(string) $job['user']] = (string) $job['id'];
            }
        }
        return $active;
    }
}
