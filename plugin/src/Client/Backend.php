<?php

declare(strict_types=1);

namespace BladeCP\CpanelMigrator\Client;

use BladeCP\CpanelMigrator\Api\ApiError;
use BladeCP\CpanelMigrator\Queue\JobActions;
use BladeCP\CpanelMigrator\Queue\JobState;
use BladeCP\CpanelMigrator\Runtime;
use BladeCP\CpanelMigrator\Settings\Settings;
use BladeCP\CpanelMigrator\Support\LocaleResolver;
use BladeCP\CpanelMigrator\Support\Uuid;
use BladeCP\CpanelMigrator\System\SystemError;
use BladeCP\CpanelMigrator\System\WhmSystem;

/**
 * Root side of the cPanel app: the four functions of the AdminBin.
 *
 * The account is the one cPanel authenticated (the AdminBin turns the
 * caller UID into the account name); it is never a request field. A job of
 * another account answers exactly like a missing job. Every write needs the
 * form token, which is an HMAC of the account and the cPanel session with
 * the root-only secret. Limits per account and a ceiling for the whole
 * server protect the queue; every write is logged.
 *
 *   status {session}                      state of the account migration
 *   start  {session, csrf}                queue the account with the WHM defaults
 *   act    {session, csrf, job, op, domain?}   review, approve or cancel
 *   claim  {session, csrf, job}           client-area URL to confirm the account
 *
 * There is no owner e-mail: the request carries the account as `source` and
 * the partner billing WHMCS links the owner and the service (or the account
 * holder confirms the account in the WHMCS client area). The claim URL holds
 * a token: it is returned once, to the page, and never logged.
 */
final class Backend
{
    public const FUNCTIONS = ['status', 'start', 'act', 'claim'];
    public const MAX_PENDING_FROM_CPANEL = 25;
    public const HISTORY = 10;
    /** rule => [limit, window in seconds] */
    public const LIMITS = [
        'status' => ['status' => [60, 60]],
        'start' => ['start' => [5, 3600], 'startday' => [12, 86400]],
        'act' => ['act' => [20, 600]],
        'claim' => ['claim' => [10, 600]],
    ];
    private const ACCOUNT_CACHE_SECONDS = 300;
    private const FIELDS = ['session' => 64, 'csrf' => 64, 'job' => 36, 'op' => 16, 'domain' => 253, 'ip' => 45, 'team_user' => 64];

    public function __construct(
        private readonly Runtime $runtime,
        private readonly string $user,
        private readonly LocaleResolver $locales = new LocaleResolver()
    ) {
    }

    /**
     * @param array<string,mixed> $args request fields from the cPanel session
     * @return array<string,mixed>
     */
    public function handle(string $function, array $args): array
    {
        if (!WhmSystem::validUser($this->user) || !in_array($function, self::FUNCTIONS, true)) {
            return ['ok' => false, 'available' => false, 'notice' => 'client.notice.unavailable'];
        }
        $args = self::clean($args);
        try {
            return match ($function) {
                'status' => $this->status($args),
                'start' => $this->start($args),
                'claim' => $this->claim($args),
                default => $this->act($args),
            };
        } catch (\Throwable $error) {
            $this->runtime->log->error('cpapp.unexpected', ['user' => $this->user, 'function' => $function, 'error' => $error]);
            return ['ok' => false, 'available' => false, 'notice' => 'client.notice.unavailable'];
        }
    }

    /**
     * Form token of this account and session, or null without a session.
     */
    public function csrf(string $session): ?string
    {
        $secret = $this->runtime->settings()->csrfSecret();
        if (strlen($secret) < 32 || preg_match('/^[a-f0-9]{64}$/D', $session) !== 1) {
            return null;
        }
        return hash_hmac('sha256', 'bladecp-cpanel-migrator|client|' . $this->user . '|' . $session, $secret);
    }

    // ---------------------------------------------------------------- functions

    /** @param array<string,string> $args */
    private function status(array $args): array
    {
        if (!$this->limiter()->allow($this->user, self::LIMITS['status'])) {
            return ['ok' => false, 'limited' => true, 'notice' => 'client.notice.slow_down'];
        }
        $settings = $this->runtime->settings();
        $base = [
            'ok' => true,
            'brand' => ['name' => Branding::name($settings, $this->runtime->store), 'support_url' => Branding::supportUrl($settings)],
            'locale' => $this->locales->resolve('auto', [], $this->user),
        ];
        $account = $this->account(false);
        if (!$this->available($settings) || $account === null || $account['suspended']) {
            return $base + ['available' => false];
        }
        $now = $this->runtime->clock->now();
        [$current, $history] = $this->jobs($account);
        $view = $current === null ? null : JobView::from($current, $now);
        $csrf = $this->csrf($args['session'] ?? '');
        return $base + [
            'available' => true,
            'account' => ['domain' => $account['domain']],
            'destination' => $this->destination($settings),
            'job' => $view,
            'history' => array_map(static fn (array $job): array => JobView::summary($job), $history),
            'csrf' => $csrf,
            'can_start' => $csrf !== null && ($view === null || in_array($view['state'], ['failed', 'cancelled'], true)),
        ];
    }

    /** @param array<string,string> $args */
    private function start(array $args): array
    {
        if (!$this->limiter()->allow($this->user, self::LIMITS['start'])) {
            $this->audit('cpapp.rate_limited', $args, ['function' => 'start']);
            return self::notice(false, 'client.notice.slow_down');
        }
        if (!$this->validCsrf($args)) {
            $this->audit('cpapp.csrf_rejected', $args, ['function' => 'start']);
            return self::notice(false, 'client.notice.expired');
        }
        $settings = $this->runtime->settings();
        if (!$this->available($settings)) {
            return self::notice(false, 'client.notice.unavailable');
        }
        $pending = 0;
        foreach ($this->runtime->store->jobs() as $job) {
            if (JobState::origin($job) === 'cpanel' && ($job['status'] ?? '') === JobState::PENDING) {
                $pending++;
            }
        }
        if ($pending >= self::MAX_PENDING_FROM_CPANEL) {
            $this->audit('cpapp.queue_full', $args, ['pending' => $pending]);
            return self::notice(false, 'client.notice.busy');
        }
        $account = $this->account(true);
        if ($account === null || $account['suspended']) {
            return self::notice(false, 'client.notice.unavailable');
        }
        [$current] = $this->jobs($account);
        if ($current !== null && !JobState::isTerminal($current)) {
            return self::notice(false, 'client.notice.already');
        }
        $mode = $settings->commercialMode();
        try {
            $result = $this->runtime->enqueuer()->enqueue([
                'installation_id' => $settings->installationId(),
                'plan_id' => (int) $settings->planId(),
                'location_id' => (int) $settings->locationId(),
                'native_product_id' => $settings->nativeProductId(),
                'commercial' => Settings::commercialPolicy($mode, $settings->trialDays(), $settings->paymentDays()),
                'domain_mode' => $settings->domainMode(),
                'origin' => 'cpanel',
                'initiated_by' => $this->user,
                'source_initiated_by' => 'account',
                'billing_link' => true,
            ], [['user' => $this->user]]);
        } catch (SystemError) {
            return self::notice(false, 'client.notice.failed');
        }
        if ($result['created'] === []) {
            $errors = $result['errors'][$this->user] ?? [];
            if (($errors['user'] ?? null) !== 'accounts.error.already_queued') {
                return self::notice(false, 'client.notice.failed');
            }
            // The job in the way may be of an earlier account with this name: nothing is said about it.
            [$current] = $this->jobs($account);
            return self::notice(false, $current !== null && !JobState::isTerminal($current) ? 'client.notice.already' : 'client.notice.unavailable');
        }
        $this->audit('cpapp.start', $args, ['job' => $result['created'][0]]);
        return self::notice(true, 'client.notice.started');
    }

    /** @param array<string,string> $args */
    private function act(array $args): array
    {
        $op = $args['op'] ?? '';
        if (!$this->limiter()->allow($this->user, self::LIMITS['act'])) {
            $this->audit('cpapp.rate_limited', $args, ['function' => 'act', 'op' => $op]);
            return self::notice(false, 'client.notice.slow_down');
        }
        if (!$this->validCsrf($args)) {
            $this->audit('cpapp.csrf_rejected', $args, ['function' => 'act', 'op' => $op]);
            return self::notice(false, 'client.notice.expired');
        }
        $id = $args['job'] ?? '';
        $job = Uuid::isValid($id) ? $this->runtime->store->job($id) : null;
        // Acting needs the account as WHM sees it now, never a cached copy.
        $account = $this->account(true);
        if ($account === null) {
            $this->audit('cpapp.action', $args, ['op' => $op, 'result' => 'unavailable']);
            return self::notice(false, 'client.notice.unavailable');
        }
        // A job of another account is answered exactly like a missing one.
        if ($job === null || !$this->owns($job, $account)) {
            $this->audit('cpapp.action', $args, ['op' => $op, 'result' => 'not_found']);
            return self::notice(false, 'client.notice.not_found');
        }
        if (!in_array($op, JobView::OPS, true) || !in_array($op, JobView::from($job, $this->runtime->clock->now())['actions'], true)) {
            $this->audit('cpapp.action', $args, ['op' => $op, 'job' => $id, 'result' => 'not_available']);
            return self::notice(false, 'client.notice.not_available');
        }
        $actions = $this->runtime->actions();
        $result = match ($op) {
            'review' => $actions->review($id, $args['domain'] ?? ''),
            'approve' => $actions->approve($id),
            default => $actions->cancel($id, JobActions::ACCOUNT_CANCEL_REASON),
        };
        $this->audit('cpapp.action', $args, ['op' => $op, 'job' => $id, 'result' => $result->messageKey]);
        return self::notice($result->ok, self::clientNotice($result->messageKey));
    }

    /**
     * Client-area URL where the account holder confirms the account, only
     * for a job of this account in claim_required. The URL is answered to the
     * page, which sends the browser there; it is never stored or logged.
     *
     * @param array<string,string> $args
     */
    private function claim(array $args): array
    {
        if (!$this->limiter()->allow($this->user, self::LIMITS['claim'])) {
            $this->audit('cpapp.rate_limited', $args, ['function' => 'claim']);
            return self::notice(false, 'client.notice.slow_down');
        }
        if (!$this->validCsrf($args)) {
            $this->audit('cpapp.csrf_rejected', $args, ['function' => 'claim']);
            return self::notice(false, 'client.notice.expired');
        }
        $id = $args['job'] ?? '';
        $job = Uuid::isValid($id) ? $this->runtime->store->job($id) : null;
        $account = $this->account(true);
        if ($account === null) {
            $this->audit('cpapp.claim', $args, ['result' => 'unavailable']);
            return self::notice(false, 'client.notice.unavailable');
        }
        if ($job === null || !$this->owns($job, $account)) {
            $this->audit('cpapp.claim', $args, ['result' => 'not_found']);
            return self::notice(false, 'client.notice.not_found');
        }
        if (!in_array('claim', JobView::from($job, $this->runtime->clock->now())['actions'], true)) {
            $this->audit('cpapp.claim', $args, ['job' => $id, 'result' => 'not_available']);
            return self::notice(false, 'client.notice.not_available');
        }
        try {
            $answer = $this->runtime->api()->claimUrl($id, JobState::scope($job));
        } catch (ApiError $error) {
            $this->audit('cpapp.claim', $args, ['job' => $id, 'result' => $error->apiCode ?? $error->kind]);
            return self::notice(false, $error->httpStatus === 409 ? 'client.notice.not_available' : 'client.notice.failed');
        }
        $url = is_string($answer['url'] ?? null) ? $answer['url'] : '';
        $parts = parse_url($url);
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || empty($parts['host']) || strlen($url) > 2048
            || preg_match('/[\s"<>\\\\]/', $url) === 1) {
            $this->audit('cpapp.claim', $args, ['job' => $id, 'result' => 'invalid_url']);
            return self::notice(false, 'client.notice.failed');
        }
        $this->audit('cpapp.claim', $args, ['job' => $id, 'result' => 'url_issued']);
        return ['ok' => true, 'notice' => 'client.notice.claim_opened', 'url' => $url];
    }

    // ---------------------------------------------------------------- helpers

    private function available(Settings $settings): bool
    {
        return $settings->readyToMigrate() && Uuid::isValid($settings->installationId()) && strlen($settings->csrfSecret()) >= 32;
    }

    /**
     * Current migration of the account (the active one, else the latest)
     * and the earlier ones, newest first.
     *
     * @param array<string,mixed>|null $account
     * @return array{0:?array<string,mixed>,1:list<array<string,mixed>>}
     */
    private function jobs(?array $account): array
    {
        $mine = array_values(array_filter($this->runtime->store->jobs(), fn (array $job): bool => $this->owns($job, $account)));
        $mine = array_reverse($mine);
        $current = null;
        foreach ($mine as $job) {
            if (!JobState::isTerminal($job)) {
                $current = $job;
                break;
            }
        }
        $current ??= $mine[0] ?? null;
        $history = array_values(array_filter($mine, static fn (array $job): bool => $current === null || $job['id'] !== $current['id']));
        return [$current, array_slice($history, 0, self::HISTORY)];
    }

    /**
     * A job of this account: its name and, when both are known, its creation
     * time, so an account created again with the same name never sees or acts
     * on the jobs of the one before.
     *
     * @param array<string,mixed> $job
     * @param array<string,mixed>|null $account
     */
    private function owns(array $job, ?array $account): bool
    {
        return ($job['user'] ?? null) === $this->user && JobState::sameAccount($job, is_int($account['started'] ?? null) ? $account['started'] : null);
    }

    /**
     * Main domain, contact e-mail, suspension and creation time of the
     * account, from WHM (cached for a few minutes when only displaying). The
     * cache also keeps the inode and change time of the account file, so an
     * account removed and created again never reads the entry of the one
     * before.
     *
     * @return array{domain:string,email:?string,suspended:bool,started:?int}|null
     */
    private function account(bool $fresh): ?array
    {
        $cacheName = 'client-acct-' . $this->user;
        $now = $this->runtime->clock->now();
        $signature = $this->accountSignature();
        if (!$fresh) {
            $cached = $this->runtime->store->cacheGet($cacheName, self::ACCOUNT_CACHE_SECONDS, $now);
            if (is_array($cached) && is_string($cached['domain'] ?? null) && ($cached['file'] ?? '') === $signature) {
                return ['domain' => $cached['domain'], 'email' => is_string($cached['email'] ?? null) ? $cached['email'] : null, 'suspended' => !empty($cached['suspended']),
                    'started' => is_int($cached['started'] ?? null) ? $cached['started'] : null];
            }
        }
        try {
            $account = $this->runtime->system->account($this->user);
        } catch (SystemError) {
            return null;
        }
        if ($account === null) {
            return null;
        }
        $value = ['domain' => (string) $account['domain'], 'email' => is_string($account['email'] ?? null) ? $account['email'] : null, 'suspended' => !empty($account['suspended']),
            'started' => is_int($account['started'] ?? null) ? $account['started'] : null];
        $this->runtime->store->cachePut($cacheName, $value + ['file' => $signature], $now);
        return $value;
    }

    /** Inode and change time of the account file (/var/cpanel/users/<user>), '' when it cannot be read. */
    private function accountSignature(): string
    {
        $file = $this->locales->accountFile($this->user);
        clearstatcache(true, $file);
        $stat = @stat($file);
        return is_array($stat) ? $stat['ino'] . ':' . $stat['ctime'] : '';
    }

    /**
     * Plan, location and commercial rule chosen by the provider, from the
     * cached catalogue (the account side never calls the API).
     *
     * @return array{plan:?string,location:?string,mode:string,days:int}
     */
    private function destination(Settings $settings): array
    {
        $catalog = $this->runtime->store->cacheGet('catalog', 0, $this->runtime->clock->now());
        $plan = null;
        $location = null;
        if (is_array($catalog)) {
            foreach (is_array($catalog['plans'] ?? null) ? $catalog['plans'] : [] as $item) {
                if (is_array($item) && (int) ($item['id'] ?? 0) === $settings->planId()) {
                    $plan = Branding::clean($item['name'] ?? null);
                }
            }
            $locations = is_array($catalog['locations'] ?? null) ? $catalog['locations'] : [];
            foreach (is_array($locations[(string) $settings->planId()] ?? null) ? $locations[(string) $settings->planId()] : [] as $item) {
                if (is_array($item) && (int) ($item['id'] ?? 0) === $settings->locationId()) {
                    $location = Branding::clean(trim((string) ($item['city'] ?? '') . ', ' . (string) ($item['country'] ?? ''), ', '));
                }
            }
        }
        $mode = $settings->commercialMode();
        return ['plan' => $plan, 'location' => $location, 'mode' => $mode, 'days' => $mode === 'trial' ? $settings->trialDays() : $settings->paymentDays()];
    }

    /** @param array<string,string> $args */
    private function validCsrf(array $args): bool
    {
        $expected = $this->csrf($args['session'] ?? '');
        $given = $args['csrf'] ?? '';
        return $expected !== null && preg_match('/^[a-f0-9]{64}$/D', $given) === 1 && hash_equals($expected, $given);
    }

    private function limiter(): RateLimiter
    {
        return new RateLimiter($this->runtime->store, $this->runtime->clock);
    }

    /**
     * @param array<string,string> $args
     * @param array<string,mixed> $context
     */
    private function audit(string $event, array $args, array $context): void
    {
        $ip = $args['ip'] ?? '';
        $team = $args['team_user'] ?? '';
        $this->runtime->log->info($event, ['user' => $this->user]
            + ($team !== '' && preg_match('/^[A-Za-z0-9._@+-]{1,64}$/D', $team) === 1 ? ['team_user' => $team] : [])
            + (filter_var($ip, FILTER_VALIDATE_IP) !== false ? ['ip' => $ip] : [])
            + $context);
    }

    private static function clientNotice(string $actionKey): string
    {
        return match ($actionKey) {
            'action.domain_confirmed' => 'client.notice.domain_confirmed',
            'action.approved' => 'client.notice.approved',
            'action.cancelled' => 'client.notice.cancelled',
            'action.cancel_requested' => 'client.notice.cancelling',
            'action.invalid_domain' => 'client.notice.invalid_domain',
            'action.not_available', 'error.code.migration_cancel_not_allowed', 'error.code.migration_approval_not_allowed',
            'error.code.migration_discovery_review_unavailable' => 'client.notice.not_available',
            'action.not_found' => 'client.notice.not_found',
            'error.code.migration_domain_unavailable', 'error.code.migration_discovery_choice_conflict' => 'client.notice.domain_taken',
            default => 'client.notice.failed',
        };
    }

    /** @return array{ok:bool,notice:string} */
    private static function notice(bool $ok, string $key): array
    {
        return ['ok' => $ok, 'notice' => $key];
    }

    /**
     * Request fields: only known names, only strings, with length limits.
     *
     * @param array<string,mixed> $args
     * @return array<string,string>
     */
    private static function clean(array $args): array
    {
        $clean = [];
        foreach (self::FIELDS as $name => $max) {
            $value = $args[$name] ?? null;
            if (is_string($value) && strlen($value) <= $max && preg_match('/[\x00-\x1F\x7F]/', $value) !== 1) {
                $clean[$name] = $value;
            }
        }
        return $clean;
    }
}
