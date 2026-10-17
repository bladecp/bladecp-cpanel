<?php

declare(strict_types=1);

namespace BladeCP\CpanelMigrator\Web;

use BladeCP\CpanelMigrator\Api\ApiError;
use BladeCP\CpanelMigrator\Client\Branding;
use BladeCP\CpanelMigrator\Client\CpanelBanner;
use BladeCP\CpanelMigrator\Queue\Enqueuer;
use BladeCP\CpanelMigrator\Queue\JobState;
use BladeCP\CpanelMigrator\Runtime;
use BladeCP\CpanelMigrator\Settings\Settings;
use BladeCP\CpanelMigrator\Settings\SettingsValidator;
use BladeCP\CpanelMigrator\Support\I18n;
use BladeCP\CpanelMigrator\Support\LocaleResolver;
use BladeCP\CpanelMigrator\Support\Uuid;
use BladeCP\CpanelMigrator\System\SystemError;
use BladeCP\CpanelMigrator\System\WhmSystem;

/**
 * WHM plugin controller. GET renders a screen; POST runs one action after the
 * CSRF and origin checks and answers with a redirect (post/redirect/get),
 * except for validation errors, which re-render the form.
 *
 * With the WHM library a GET without frame=1 answers with the outer WHM page
 * (shell()): the WHM chrome around an iframe that loads the same URL with
 * frame=1. Every screen, form, redirect and error then stays in that frame,
 * under the strict nonce policy.
 */
final class App
{
    public const SCRIPT_PATH = '/cgi/bladecp_migrator/index.cgi';
    /** Query values the outer WHM page passes on to the frame. */
    private const SHELL_PARAMS = ['page', 'notice', 'ok', 'n'];
    /** Static files the CGI serves itself (same origin, so font-src falls back to default-src 'self'). */
    public const ASSETS = [
        'inter-400' => 'assets/fonts/Inter-Regular.woff2',
        'inter-500' => 'assets/fonts/Inter-Medium.woff2',
        'inter-600' => 'assets/fonts/Inter-SemiBold.woff2',
        'inter-700' => 'assets/fonts/Inter-Bold.woff2',
    ];
    private const PAGES = ['migrations', 'accounts', 'settings'];
    private const JOB_OPS = ['cancel', 'retry', 'approve', 'review', 'mark_paid'];
    /** What the WHM home page card and the Settings row can do with the Favorites. */
    private const PIN_OPS = ['pin', 'unpin', 'dismiss'];
    /** Pinned state cache: the Migrations page reloads every 30 s and must not run whmapi1 each time. */
    private const PIN_CACHE = 'whm-pin';
    private const PIN_CACHE_SECONDS = 600;

    private I18n $i18n;
    private View $view;
    private Csrf $csrf;
    private string $nonce;

    public function __construct(
        private readonly Runtime $runtime,
        private readonly Request $request,
        private readonly bool $effectiveRoot,
        private readonly LocaleResolver $locales = new LocaleResolver(),
        private readonly WhmChrome $chrome = new WhmChrome()
    ) {
        $settings = $runtime->settings();
        $this->i18n = new I18n($this->locales->resolve($settings->uiLanguage(), $request->cookies, $request->remoteUser()));
        $this->csrf = new Csrf($settings->csrfSecret(), $request->sessionToken(), $request->remoteUser());
        $this->nonce = bin2hex(random_bytes(16));
        $this->view = new View($this->i18n, $request->sessionToken() . self::SCRIPT_PATH, $this->csrf->token(), $this->nonce, self::framed($request));
    }

    /**
     * Outer WHM page for a GET without frame=1 when the WHM library is
     * present, or null. It frames the same CGI with frame=1 and carries no
     * plugin data, so it needs no runtime and no authority check: the frame
     * applies every check before it shows anything.
     */
    public static function shell(Request $request, WhmChrome $chrome): ?Response
    {
        if ($request->isPost() || self::framed($request)) {
            return null;
        }
        $parts = $chrome->parts();
        if ($parts === null) {
            return null;
        }
        $query = ['frame' => '1'];
        foreach (self::SHELL_PARAMS as $key) {
            $value = $request->query($key);
            if ($value !== null && preg_match('/^[a-z0-9_.]{1,80}$/D', $value) === 1) {
                $query[$key] = $value;
            }
        }
        return Response::whmShell(View::whmShell($parts, $request->sessionToken() . self::SCRIPT_PATH . '?' . http_build_query($query)));
    }

    /**
     * Font file for a GET with ?asset=<name> from ASSETS, or null. The font is
     * public (SIL Open Font License, assets/fonts/LICENSE.txt), so it needs no
     * runtime and no authority check; it is cached for a year by the browser.
     */
    public static function asset(Request $request): ?Response
    {
        $name = $request->query('asset');
        if ($name === null || $request->isPost()) {
            return null;
        }
        $file = isset(self::ASSETS[$name]) ? BLADECP_MIGRATOR_ROOT . '/' . self::ASSETS[$name] : '';
        $body = $file !== '' && is_file($file) ? file_get_contents($file) : false;
        return is_string($body) ? Response::font($body) : Response::notFound();
    }

    /**
     * Answer of index.cgi when the runtime cannot start (data directory
     * missing or not owned by root): the outer WHM page, then the message in
     * the frame.
     */
    public static function startupFailure(Request $request, LocaleResolver $locales = new LocaleResolver(), WhmChrome $chrome = new WhmChrome()): Response
    {
        $shell = self::asset($request) ?? self::shell($request, $chrome);
        if ($shell !== null) {
            return $shell;
        }
        $nonce = bin2hex(random_bytes(16));
        $view = new View(new I18n($locales->resolve('auto', $request->cookies, $request->remoteUser())), '', '', $nonce, self::framed($request));
        return Response::html($view->message('error.not_installed_title', 'error.not_installed'), $nonce, 500);
    }

    private static function framed(Request $request): bool
    {
        return $request->query('frame') === '1';
    }

    public function handle(): Response
    {
        try {
            $shell = self::asset($this->request) ?? self::shell($this->request, $this->chrome);
            if ($shell !== null) {
                return $shell;
            }
            // Only the root WHM user manages migrations: they act on every account.
            if (!$this->effectiveRoot || $this->request->remoteUser() !== 'root') {
                return $this->page($this->view->message('error.forbidden_title', 'error.forbidden'), 403);
            }
            if (!Uuid::isValid($this->runtime->settings()->installationId()) || strlen($this->runtime->settings()->csrfSecret()) < 32) {
                return $this->page($this->view->message('error.not_installed_title', 'error.not_installed'), 500);
            }
            if ($this->request->isPost()) {
                if (!$this->csrf->valid($this->request->input(Csrf::FIELD))
                    || !Csrf::sameOrigin($this->request->header('HTTP_ORIGIN'), $this->request->header('HTTP_REFERER'), $this->request->host(), $this->request->header('SERVER_PORT'))) {
                    $this->runtime->log->warning('web.csrf_rejected', ['action' => $this->request->inputString('action')]);
                    return $this->page($this->view->message('error.csrf_title', 'error.csrf'), 403);
                }
                return $this->post($this->request->inputString('action'));
            }
            return $this->get();
        } catch (\Throwable $error) {
            $this->runtime->log->error('web.unexpected', ['error' => $error]);
            return $this->page($this->view->message('error.unexpected_title', 'error.unexpected'), 500);
        }
    }

    private function get(): Response
    {
        $settings = $this->runtime->settings();
        $page = $this->request->query('page');
        if (!in_array($page, self::PAGES, true)) {
            $page = $settings->readyToMigrate() ? 'migrations' : 'settings';
        }
        $notice = $this->noticeFromQuery();
        return match ($page) {
            'accounts' => $this->accountsPage($notice),
            'settings' => $this->settingsPage($notice),
            default => $this->migrationsPage($notice),
        };
    }

    private function post(string $action): Response
    {
        return match ($action) {
            'save_connection' => $this->saveConnection(),
            'test_connection' => $this->testConnection(),
            'save_defaults' => $this->saveDefaults(),
            'save_clients' => $this->saveClients(),
            'refresh_catalog' => $this->refreshCatalog(),
            'review_selection' => $this->reviewSelection(),
            'start_migration' => $this->startMigration(),
            'job_action' => $this->jobAction(),
            'whm_pin' => $this->whmPin(),
            'cpanel_banner' => $this->cpanelBanner(),
            default => $this->redirect('migrations', 'error.unknown_action', false),
        };
    }

    // ---------------------------------------------------------------- screens

    /** @param array{ok:bool,key:string,params?:array<string,scalar>}|null $notice */
    private function migrationsPage(?array $notice): Response
    {
        $now = $this->runtime->clock->now();
        $jobs = array_reverse($this->runtime->store->jobs());
        $worker = $this->runtime->store->cacheGet('worker', 0, $now);
        $lastRun = is_array($worker) && is_int($worker['at'] ?? null) ? $worker['at'] : null;
        $content = $this->view->migrations($jobs, $this->runtime->settings()->readyToMigrate(), $lastRun, $now, $this->request->query('filter'), $this->pinCardVisible());
        return $this->page($this->view->layout('migrations', $content, $notice, $this->headerContext()));
    }

    /** @param array{ok:bool,key:string,params?:array<string,scalar>}|null $notice */
    private function accountsPage(?array $notice): Response
    {
        $errorKey = null;
        $accounts = [];
        try {
            $accounts = $this->runtime->system->listAccounts();
        } catch (SystemError) {
            $errorKey = 'accounts.error.list';
        }
        $active = Enqueuer::activeUsers($this->runtime->store->jobs());
        $content = $this->view->accounts($accounts, $active, $this->runtime->settings()->readyToMigrate(), $errorKey);
        return $this->page($this->view->layout('accounts', $content, $notice, $this->headerContext()));
    }

    /**
     * @param array{ok:bool,key:string,params?:array<string,scalar>}|null $notice
     * @param array<string,string> $errors
     * @param array<string,mixed> $input
     */
    private function settingsPage(?array $notice, array $errors = [], array $input = [], int $status = 200): Response
    {
        $settings = $this->runtime->settings();
        $catalog = null;
        $catalogError = null;
        if ($settings->hasApiKey()) {
            $loader = $this->runtime->catalog();
            $catalog = $loader->load();
            if ($loader->lastError() !== null) {
                $catalogError = $loader->lastError()->messageKey();
            }
        }
        $connection = $this->runtime->store->cacheGet('connection', 0, $this->runtime->clock->now());
        $content = $this->view->settings(
            $settings,
            $catalog,
            $catalogError,
            is_array($connection) ? $connection : null,
            $errors,
            $input,
            $this->runtime->store->dataDir(),
            Branding::apiName($this->runtime->store),
            ['pinned' => $this->pinnedNow(), 'banner' => $settings->cpanelBanner(), 'include' => $this->runtime->banner()->state(),
                'include_path' => $this->runtime->banner()->path()]
        );
        return $this->page($this->view->layout('settings', $content, $notice, $this->headerContext()), $status);
    }

    /**
     * The card on the Migrations page: only while the plugin is not in the
     * WHM Favorites and the administrator did not answer "Not now". When the
     * Favorites cannot be read, no card.
     */
    private function pinCardVisible(): bool
    {
        if ($this->runtime->settings()->whmPinDismissed()) {
            return false;
        }
        $now = $this->runtime->clock->now();
        $cached = $this->runtime->store->cacheGet(self::PIN_CACHE, self::PIN_CACHE_SECONDS, $now);
        if (is_array($cached) && is_bool($cached['pinned'] ?? null)) {
            return !$cached['pinned'];
        }
        $pinned = $this->pinnedNow();
        return $pinned === false;
    }

    /** Pinned state read from WHM now (and cached), or null when WHM did not answer. */
    private function pinnedNow(): ?bool
    {
        try {
            $pinned = $this->runtime->favorites()->isPinned();
        } catch (SystemError $error) {
            $this->runtime->log->warning('whm.favorites_read_failed', ['code' => $error->errorCode]);
            return null;
        }
        $this->runtime->store->cachePut(self::PIN_CACHE, ['pinned' => $pinned], $this->runtime->clock->now());
        return $pinned;
    }

    // ---------------------------------------------------------------- actions

    private function saveConnection(): Response
    {
        [$patch, $errors] = SettingsValidator::connection($this->request->post);
        if ($errors !== []) {
            return $this->settingsPage(null, $errors, ['api_base_url' => $this->request->inputString('api_base_url')], 422);
        }
        $this->runtime->saveSettings($patch);
        $this->runtime->catalog()->forget();
        $ok = $this->runConnectionTest();
        return $this->redirect('settings', $ok ? 'settings.saved_connected' : 'settings.saved_not_connected', $ok);
    }

    private function testConnection(): Response
    {
        $ok = $this->runConnectionTest();
        return $this->redirect('settings', $ok ? 'settings.connection.ok' : 'settings.connection.failed', $ok);
    }

    /** Checks /customer/health and /customer/me and stores the outcome (no secret). */
    private function runConnectionTest(): bool
    {
        $now = $this->runtime->clock->now();
        $result = ['at' => $now, 'ok' => false];
        try {
            $api = $this->runtime->api();
            $health = $api->health();
            if (($health['ok'] ?? false) !== true) {
                throw ApiError::invalidResponse('/customer/health');
            }
            $me = $api->me();
            $scopes = is_array($me['scopes'] ?? null) ? $me['scopes'] : [];
            $result['ok'] = true;
            $result['email'] = is_string($me['customer_email'] ?? null) ? $me['customer_email'] : '';
            $result['environment'] = is_string($health['environment'] ?? null) ? $health['environment'] : '';
            $result['scopes_ok'] = $scopes === []
                || (array_intersect(['migrations:write', 'provisions:write'], $scopes) !== [] && array_intersect(['migrations:read', 'provisions:read'], $scopes) !== []);
            // The brand name the cPanel app shows comes with the same key.
            Branding::refresh($api, $this->runtime->store, $this->runtime->log, $now);
        } catch (ApiError $error) {
            $result['error_key'] = $error->messageKey();
            $this->runtime->log->warning('connection.failed', ['kind' => $error->kind, 'status' => $error->httpStatus]);
        }
        $this->runtime->store->cachePut('connection', $result, $now);
        return $result['ok'];
    }

    private function saveDefaults(): Response
    {
        $catalog = $this->runtime->catalog()->load();
        [$patch, $errors] = SettingsValidator::defaults($this->request->post, $catalog);
        if ($errors !== []) {
            return $this->settingsPage(null, $errors, $this->defaultsInput(), 422);
        }
        $this->runtime->saveSettings($patch);
        return $this->redirect('settings', 'settings.saved', true);
    }

    private function saveClients(): Response
    {
        [$patch, $errors] = SettingsValidator::clients($this->request->post);
        if ($errors !== []) {
            return $this->settingsPage(null, $errors, [
                'client_brand_name' => $this->request->inputString('client_brand_name'),
                'client_support_url' => $this->request->inputString('client_support_url'),
            ], 422);
        }
        $this->runtime->saveSettings($patch);
        return $this->redirect('settings', 'settings.clients.saved', true);
    }

    private function refreshCatalog(): Response
    {
        $loader = $this->runtime->catalog();
        $loader->load(true);
        return $loader->lastError() === null
            ? $this->redirect('settings', 'settings.catalog_refreshed', true)
            : $this->redirect('settings', $loader->lastError()->messageKey(), false);
    }

    private function reviewSelection(): Response
    {
        $settings = $this->runtime->settings();
        if (!$settings->readyToMigrate()) {
            return $this->redirect('settings', 'accounts.not_ready', false);
        }
        $selected = $this->request->input('accounts');
        $users = [];
        foreach (is_array($selected) ? $selected : [] as $user) {
            if (is_string($user) && WhmSystem::validUser($user)) {
                $users[$user] = true;
            }
        }
        if ($users === []) {
            return $this->redirect('accounts', 'accounts.error.none_selected', false);
        }
        if (count($users) > Enqueuer::MAX_BATCH) {
            return $this->redirect('accounts', 'accounts.error.too_many', false);
        }
        $accounts = [];
        try {
            foreach ($this->runtime->system->listAccounts() as $account) {
                $accounts[$account['user']] = $account;
            }
        } catch (SystemError) {
            return $this->redirect('accounts', 'accounts.error.list', false);
        }
        $rows = [];
        foreach (array_keys($users) as $user) {
            if (!isset($accounts[$user])) {
                continue;
            }
            $rows[] = ['user' => $user, 'domain' => $accounts[$user]['domain']];
        }
        $batch = [
            'plan_id' => $settings->planId(), 'location_id' => $settings->locationId(),
            'commercial_mode' => $settings->commercialMode(), 'trial_days' => $settings->trialDays(),
            'payment_days' => $settings->paymentDays(), 'native_product_id' => $settings->nativeProductId(),
        ];
        return $this->reviewPage($rows, $batch, [], [], null, 200);
    }

    private function startMigration(): Response
    {
        $settings = $this->runtime->settings();
        if (!$settings->readyToMigrate()) {
            return $this->redirect('settings', 'accounts.not_ready', false);
        }
        $rawRows = $this->request->input('rows');
        $rows = [];
        foreach (is_array($rawRows) ? array_slice($rawRows, 0, Enqueuer::MAX_BATCH + 1) : [] as $raw) {
            if (!is_array($raw)) {
                continue;
            }
            // The owner and the billing service come from the partner WHMCS: only the account.
            $rows[] = ['user' => is_string($raw['user'] ?? null) ? $raw['user'] : ''];
        }
        $batchInput = [
            'plan_id' => $this->request->inputString('plan_id'),
            'location_id' => $this->request->inputString('location_id'),
            'commercial_mode' => $this->request->inputString('commercial_mode'),
            'trial_days' => $this->request->inputString('trial_days'),
            'payment_days' => $this->request->inputString('payment_days'),
            'native_product_id' => $this->request->inputString('native_product_id'),
        ];
        $catalog = $this->runtime->catalog()->load();
        [$destination, $destinationErrors] = SettingsValidator::destination($batchInput, $catalog);
        [$commercial, $commercialErrors] = SettingsValidator::commercial($batchInput);
        $batchErrors = $destinationErrors + $commercialErrors;
        $native = $batchInput['native_product_id'];
        if (preg_match('/^\d{1,10}$/', $native) !== 1 || (int) $native < 1 || (int) $native > 2147483647) {
            $batchErrors['native_product_id'] = 'settings.error.native_product_id';
        }
        $domains = [];
        try {
            foreach ($this->runtime->system->listAccounts() as $account) {
                $domains[$account['user']] = $account['domain'];
            }
        } catch (SystemError) {
            return $this->redirect('accounts', 'accounts.error.list', false);
        }
        $displayRows = array_map(static fn (array $row): array => $row + ['domain' => $domains[$row['user']] ?? ''], $rows);
        if ($batchErrors !== []) {
            return $this->reviewPage($displayRows, $batchInput, [], $batchErrors, 'review.error.batch', 422);
        }
        $mode = (string) $commercial['commercial_mode'];
        try {
            $result = $this->enqueue($settings, $destination, $mode, $commercial, (int) $native, $rows);
        } catch (SystemError) {
            return $this->redirect('accounts', 'accounts.error.list', false);
        }
        if ($result['created'] === []) {
            return $this->reviewPage($displayRows, $batchInput, $result['errors'], [], $result['general'] ?? 'review.error.batch', 422);
        }
        return $this->redirect('migrations', 'migrations.queued', true, count($result['created']));
    }

    /**
     * @param array<string,mixed> $destination
     * @param array<string,mixed> $commercial
     * @param list<array<string,mixed>> $rows
     * @return array{created:list<string>,errors:array<string,array<string,string>>,general:?string}
     */
    private function enqueue(Settings $settings, array $destination, string $mode, array $commercial, int $native, array $rows): array
    {
        return $this->runtime->enqueuer()->enqueue([
            'installation_id' => $settings->installationId(),
            'plan_id' => (int) $destination['plan_id'],
            'location_id' => (int) $destination['location_id'],
            'native_product_id' => $native,
            'commercial' => Settings::commercialPolicy($mode, (int) ($commercial['trial_days'] ?? 14), (int) ($commercial['payment_days'] ?? 7)),
            'domain_mode' => $settings->domainMode(),
            'origin' => 'whm',
            'initiated_by' => 'root',
            'source_initiated_by' => 'root',
            'billing_link' => true,
        ], $rows);
    }

    private function jobAction(): Response
    {
        $id = $this->request->inputString('job');
        $op = $this->request->inputString('op');
        if (!Uuid::isValid($id) || !in_array($op, self::JOB_OPS, true)) {
            return $this->redirect('migrations', 'action.not_found', false);
        }
        $actions = $this->runtime->actions();
        $result = match ($op) {
            'cancel' => $actions->cancel($id),
            'retry' => $actions->retry($id),
            'approve' => $actions->approve($id),
            'review' => $actions->review($id, $this->request->inputString('domain')),
            'mark_paid' => $actions->markPaid($id),
        };
        return $this->redirect('migrations', $result->messageKey, $result->ok);
    }

    /** Pins the plugin first in the WHM Favorites, takes it out, or records "Not now". */
    private function whmPin(): Response
    {
        $op = $this->request->inputString('op');
        $back = $this->request->inputString('return') === 'settings' ? 'settings' : 'migrations';
        if (!in_array($op, self::PIN_OPS, true)) {
            return $this->redirect($back, 'error.unknown_action', false);
        }
        if ($op === 'dismiss') {
            $this->runtime->saveSettings(['whm_pin_dismissed' => true]);
            return $this->redirect($back, 'migrations.pin.dismissed', true);
        }
        $favorites = $this->runtime->favorites();
        try {
            if ($op === 'pin') {
                $favorites->pin();
            } else {
                $favorites->unpin();
            }
        } catch (SystemError $error) {
            $this->runtime->log->warning('whm.favorites_write_failed', ['op' => $op, 'code' => $error->errorCode]);
            $this->runtime->store->cacheForget(self::PIN_CACHE);
            return $this->redirect($back, 'migrations.pin.error', false);
        }
        $this->runtime->store->cachePut(self::PIN_CACHE, ['pinned' => $op === 'pin'], $this->runtime->clock->now());
        $this->runtime->log->info('whm.favorites_' . ($op === 'pin' ? 'pinned' : 'unpinned'), []);
        return $this->redirect($back, $op === 'pin' ? 'migrations.pin.done' : 'migrations.pin.removed', true);
    }

    /** Turns the cPanel home page banner on or off and installs or removes the include. */
    private function cpanelBanner(): Response
    {
        $enabled = $this->request->inputString('banner') === 'on';
        $this->runtime->saveSettings(['cpanel_banner' => $enabled]);
        $result = $this->runtime->banner()->apply($enabled);
        $this->runtime->log->info('cpanel.banner', ['enabled' => $enabled, 'result' => $result]);
        return match ($result) {
            CpanelBanner::INSTALLED => $this->redirect('settings', 'settings.banner.saved_on', true),
            CpanelBanner::OFF => $this->redirect('settings', 'settings.banner.saved_off', true),
            CpanelBanner::FOREIGN => $this->redirect('settings', 'settings.banner.error_foreign', false),
            default => $this->redirect('settings', 'settings.banner.error', false),
        };
    }

    // ---------------------------------------------------------------- helpers

    /**
     * @param list<array<string,mixed>> $rows
     * @param array<string,mixed> $batch
     * @param array<string,array<string,string>> $rowErrors
     * @param array<string,string> $batchErrors
     */
    private function reviewPage(array $rows, array $batch, array $rowErrors, array $batchErrors, ?string $general, int $status): Response
    {
        $catalog = $this->runtime->catalog()->load();
        $content = $this->view->review($rows, $batch, $catalog, $rowErrors, $batchErrors, $general, $this->runtime->settings()->domainMode());
        return $this->page($this->view->layout('accounts', $content, null, $this->headerContext()), $status);
    }

    /**
     * Brand band data: migrations that need the administrator (the badge on
     * the Migrations tab) and the last connection test.
     *
     * @return array{attention:int,connection:string,email:string}
     */
    private function headerContext(): array
    {
        $counts = View::summary($this->runtime->store->jobs());
        $connection = $this->runtime->settings()->hasApiKey()
            ? $this->runtime->store->cacheGet('connection', 0, $this->runtime->clock->now()) : null;
        return [
            'attention' => $counts['waiting'] + $counts['error'],
            'connection' => !is_array($connection) ? 'none' : (!empty($connection['ok']) ? 'ok' : 'failed'),
            'email' => is_array($connection) && is_string($connection['email'] ?? null) ? $connection['email'] : '',
        ];
    }

    private function page(string $body, int $status = 200): Response
    {
        return Response::html($body, $this->nonce, $status);
    }

    /** @return array<string,mixed> */
    private function defaultsInput(): array
    {
        $input = [];
        foreach (['plan_id', 'location_id', 'commercial_mode', 'trial_days', 'payment_days', 'native_product_id', 'concurrency', 'work_dir', 'domain_mode', 'ui_language'] as $field) {
            $input[$field] = $this->request->inputString($field);
        }
        return $input;
    }

    private function redirect(string $page, string $noticeKey, bool $ok, ?int $count = null): Response
    {
        // A POST from the WHM frame goes back to the frame.
        $query = (self::framed($this->request) ? ['frame' => '1'] : []) + ['page' => $page, 'notice' => $noticeKey, 'ok' => $ok ? '1' : '0'];
        if ($count !== null) {
            $query['n'] = (string) $count;
        }
        return Response::redirect(Origin::of($this->request) . $this->request->sessionToken() . self::SCRIPT_PATH . '?' . http_build_query($query));
    }

    /** @return array{ok:bool,key:string,params:array<string,scalar>}|null */
    private function noticeFromQuery(): ?array
    {
        $key = $this->request->query('notice');
        if ($key === null || preg_match('/^[a-z0-9_.]{1,80}$/', $key) !== 1 || !$this->i18n->has($key)) {
            return null;
        }
        $count = $this->request->query('n');
        return [
            'ok' => $this->request->query('ok') === '1',
            'key' => $key,
            'params' => ['count' => $count !== null && ctype_digit($count) ? (int) $count : 0],
        ];
    }
}
