<?php

declare(strict_types=1);

namespace BladeCP\CpanelMigrator\Web;

use BladeCP\CpanelMigrator\Queue\JobState;
use BladeCP\CpanelMigrator\Queue\UploadPlan;
use BladeCP\CpanelMigrator\Client\CpanelBanner;
use BladeCP\CpanelMigrator\Settings\Settings;
use BladeCP\CpanelMigrator\Support\I18n;

/**
 * Server-rendered screens. Every dynamic value goes through e(); the only
 * plugin script is the nonce-tagged block at the end of the layout.
 *
 * Each screen is a complete document under the strict nonce policy. With the
 * WHM library the browser first gets whmShell(): the WHM header and footer
 * (see WhmChrome) around an iframe that loads the same CGI with frame=1, and
 * the screens render inside that frame ($framed: every link and form keeps
 * frame=1 and a script reports the height to the WHM page). Without the
 * library the screens are a standalone page.
 *
 * The markup follows the approved design (direction A2): a light sheet,
 * cards with icons, summary cards that filter the list, a seven-segment
 * progress bar and tables that become cards on narrow screens. The styles
 * live in assets/app.css, inlined in the page, every rule scoped to
 * .bcpm-app. The Inter font is served by the CGI itself (App::asset), so
 * font-src falls back to default-src 'self'.
 */
final class View
{
    public const FRAME_ID = 'bcpm-frame';

    /**
     * Stages of the progress bar, in order (1 to 6). Pointing the domain is
     * never a stage: after the approval the migration is complete.
     */
    public const STAGES = ['backup', 'upload', 'analysis', 'import', 'preview', 'done'];

    /** Nameservers of the platform for the optional domain pointing (shown in WHM only). */
    public const NAMESERVERS = ['kant.ns.bladecp.com', 'hegel.ns.bladecp.com'];

    /** Local step => stage, while the plugin still generates and sends the backup. */
    public const STEP_STAGE = ['backup' => 1, 'request' => 2, 'service' => 2, 'upload' => 2, 'complete' => 2, 'confirm' => 2];

    /** Customer API migration phase => stage, once BladeCP drives the migration. */
    public const PHASE_STAGE = [
        'draft' => 2, 'uploading' => 2,
        'queued' => 3, 'parsing' => 3, 'discovery_review' => 3,
        'provisioning' => 4, 'importing' => 4, 'validating' => 4,
        'awaiting_approval' => 5, 'syncing' => 5,
        'cutover_pending' => 6, 'cutover_stalled' => 6, 'live' => 6,
    ];

    /** Summary cards, in display order: filter => [tone, icon]. */
    public const FILTERS = [
        'done' => ['success', 'check-circle'],
        'active' => ['info', 'activity'],
        'waiting' => ['warning', 'eye'],
        'error' => ['danger', 'triangle'],
    ];

    /** Font weights => asset name served by App::asset(). */
    public const FONTS = [400 => 'inter-400', 500 => 'inter-500', 600 => 'inter-600', 700 => 'inter-700'];


    private int $marks = 0;

    public function __construct(
        private readonly I18n $i18n,
        private readonly string $baseUrl,
        private readonly string $csrfToken,
        private readonly string $nonce,
        private readonly bool $framed = false
    ) {
    }

    public function t(string $key, array $params = []): string
    {
        return $this->i18n->t($key, $params);
    }

    public static function e(mixed $value): string
    {
        return htmlspecialchars(is_scalar($value) ? (string) $value : '', ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    /** Plugin URL; inside the WHM frame every link and form target stays in the frame. */
    public function url(array $query = []): string
    {
        if ($this->framed) {
            $query = ['frame' => '1'] + $query;
        }
        return $this->baseUrl . ($query === [] ? '' : '?' . http_build_query($query));
    }

    /**
     * @param array{ok:bool,key:string,params?:array<string,scalar>}|null $notice
     * @param array{attention?:int,connection?:string,email?:string} $context header data
     */
    public function layout(string $active, string $content, ?array $notice = null, array $context = []): string
    {
        $noticeHtml = '';
        if ($notice !== null) {
            // Result of the last action (after a redirect): the script keeps it in view and drops it from the URL.
            $tone = $notice['ok'] ? 'success' : 'danger';
            $noticeHtml = '<div class="m-notice m-flash m-t-' . $tone . '" role="' . ($notice['ok'] ? 'status' : 'alert') . '" data-flash>'
                . $this->icon($notice['ok'] ? 'check-circle' : 'triangle') . '<p>' . self::e($this->t($notice['key'], $notice['params'] ?? [])) . '</p></div>';
        }
        return $this->document($this->header($active, $context) . '<main class="m-sheet">' . $noticeHtml . $content . '</main>'
            . $this->footer() . '<template data-busy-icon>' . $this->icon('loader', 'm-spin') . '</template>' . $this->scriptTag(), $active);
    }

    public function message(string $titleKey, string $textKey): string
    {
        $card = '<section class="m-card m-empty"><div class="m-empty-art" aria-hidden="true"><span class="m-empty-node m-empty-alert">'
            . $this->icon('triangle') . '</span></div><h1>' . self::e($this->t($titleKey)) . '</h1><p>' . self::e($this->t($textKey)) . '</p></section>';
        return $this->document('<main class="m-sheet">' . $card . '</main>' . $this->footer() . ($this->framed ? $this->scriptTag() : ''));
    }

    /**
     * Outer WHM page: the WHM header and footer around the iframe that loads
     * the plugin document. It carries no plugin content, form or account
     * data; its only script sizes the iframe from the frame's messages (same
     * origin, the frame window, known format) and mirrors the current tab in
     * the address bar.
     *
     * @param array{0:string,1:string} $chrome WHM header and footer
     */
    public static function whmShell(array $chrome, string $frameUrl): string
    {
        return $chrome[0] . '<iframe id="' . self::FRAME_ID . '" src="' . self::e($frameUrl) . '" title="' . self::e(WhmChrome::TITLE) . '"'
            . ' style="display:block;width:100%;height:720px;border:0"></iframe>'
            . '<script>' . self::shellScript() . '</script>' . $chrome[1];
    }

    private function scriptTag(): string
    {
        return '<script nonce="' . self::e($this->nonce) . '">' . self::script() . '</script>';
    }

    /** Complete plugin document. Inside the WHM frame it has no background of its own and names the current tab. */
    private function document(string $inner, string $page = ''): string
    {
        $body = $this->framed ? 'body{margin:0}'
            : 'body{margin:0;padding:18px 24px 40px;background:#e9edf2}@media (max-width:900px){body{padding:14px 16px 32px}}';
        return '<!doctype html><html lang="' . self::e($this->i18n->htmlLang()) . '"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . self::e($this->t('app.name')) . '</title><style>' . $body . $this->fontFaces() . self::css() . '</style></head><body>'
            . '<div class="bcpm-app"' . ($this->framed ? ' data-page="' . self::e($page) . '"' : '') . '>' . $inner . '</div></body></html>';
    }

    /** Same-origin Inter font, served by the CGI (no font-src: default-src 'self' applies). */
    private function fontFaces(): string
    {
        $css = '';
        foreach (self::FONTS as $weight => $asset) {
            $css .= '@font-face{font-family:"BCPM Inter";src:url("' . $this->baseUrl . '?' . http_build_query(['asset' => $asset, 'v' => BLADECP_MIGRATOR_VERSION])
                . '") format("woff2");font-weight:' . $weight . ';font-display:swap}';
        }
        return $css;
    }

    // ------------------------------------------------------------------ building blocks

    /**
     * Brand band: logo, connection state, tagline and the plugin tabs.
     *
     * @param array{attention?:int,connection?:string,email?:string} $context
     */
    private function header(string $active, array $context): string
    {
        $tabs = '';
        foreach (['migrations' => ['nav.migrations', 'transfer'], 'accounts' => ['nav.accounts', 'users'], 'settings' => ['nav.settings', 'settings']] as $page => [$label, $icon]) {
            $badge = '';
            $attention = (int) ($context['attention'] ?? 0);
            if ($page === 'migrations' && $attention > 0) {
                $badge = '<span class="m-tab-n" title="' . self::e($this->t('migrations.needs_action', ['count' => $attention])) . '">' . $attention . '</span>';
            }
            $tabs .= '<a href="' . self::e($this->url(['page' => $page])) . '"' . ($page === $active ? ' aria-current="page"' : '') . '>'
                . $this->icon($icon) . '<span>' . self::e($this->t($label)) . '</span>' . $badge . '</a>';
        }
        $state = (string) ($context['connection'] ?? 'none');
        $conn = '<a class="m-conn" href="' . self::e($this->url(['page' => 'settings'])) . '"><span class="m-conn-dot' . ($state === 'ok' ? '' : ' is-off') . '" aria-hidden="true"></span>'
            . '<span>' . self::e($this->t($state === 'ok' ? 'settings.connection.ok' : ($state === 'failed' ? 'settings.connection.failed' : 'header.not_connected'))) . '</span>'
            . ($state === 'ok' && ($context['email'] ?? '') !== '' ? '<span class="m-conn-mail">' . self::e($context['email']) . '</span>' : '') . '</a>';
        return '<header class="m-band"><div class="m-hz" aria-hidden="true"><span class="m-hz-atmo"></span><span class="m-hz-planet"></span>'
            . '<span class="m-hz-rimwrap"><span class="m-hz-rim"></span></span><span class="m-hz-sun"></span></div>'
            . '<div class="m-band-row"><a class="m-brand" href="' . self::e($this->url(['page' => 'migrations'])) . '">'
            . '<svg class="m-logo" viewBox="0 0 354.64 63.53" fill="currentColor" role="img" aria-label="BladeCP">' . Ui::LOGO_PATHS . '</svg>'
            . '<span class="m-brand-sep" aria-hidden="true"></span><span class="m-brand-name">cPanel Migrator</span></a>' . $conn . '</div>'
            . '<p class="m-tagline">' . self::e($this->t('app.tagline')) . '</p>'
            . '<nav class="m-tabs" aria-label="' . self::e($this->t('nav.label')) . '">' . $tabs . '</nav></header>';
    }

    private function footer(): string
    {
        return '<footer class="m-foot">' . $this->mark() . '<span>' . self::e($this->t('app.footer', ['version' => BLADECP_MIGRATOR_VERSION])) . '</span></footer>';
    }

    private function icon(string $name, string $class = ''): string
    {
        return '<svg' . ($class !== '' ? ' class="' . $class . '"' : '') . ' viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"'
            . ' stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . (Ui::ICONS[$name] ?? '') . '</svg>';
    }

    /** BladeCP mark; each copy gets its own gradient id. */
    private function mark(): string
    {
        $id = 'bcpm-grad-' . ++$this->marks;
        return '<svg class="m-mark" viewBox="0 0 118.16 85.75" aria-hidden="true" focusable="false"><defs><linearGradient id="' . $id . '" x1="47.26" y1="44.94"'
            . ' x2="145.43" y2="46.35" gradientTransform="translate(0 88) scale(1 -1)" gradientUnits="userSpaceOnUse"><stop offset="0" stop-color="#8b5cf6"/>'
            . '<stop offset=".7" stop-color="#636ff6"/><stop offset="1" stop-color="#3b82f6"/></linearGradient></defs><path fill="url(#' . $id . ')" d="'
            . Ui::MARK_PATH . '"/></svg>';
    }

    /** Text the script shows on a submit button while its request runs. */
    private function busy(string $key): string
    {
        return ' data-busy-label="' . self::e($this->t($key)) . '"';
    }

    /** @param string $html already escaped */
    private function notice(string $tone, string $html, string $icon, string $role): string
    {
        return '<div class="m-notice m-t-' . $tone . '" role="' . $role . '">' . $this->icon($icon) . '<p>' . $html . '</p></div>';
    }

    /** Page title with its description, optional actions and an optional link back. */
    private function head(string $title, string $sub, string $actions = '', string $back = ''): string
    {
        return '<div class="m-head"><div>' . $back . '<h1 class="m-h1">' . self::e($title) . '</h1>'
            . ($sub !== '' ? '<p class="m-sub">' . self::e($sub) . '</p>' : '') . '</div>'
            . ($actions !== '' ? '<div class="m-head-actions">' . $actions . '</div>' : '') . '</div>';
    }

    /** Section card with an icon tile, a title and an optional description. */
    private function section(string $icon, string $title, string $sub, string $body, string $class = ''): string
    {
        return '<section class="m-card m-sec' . $class . '"><header class="m-sec-head"><span class="m-tile">' . $this->icon($icon) . '</span><div><h2>'
            . self::e($title) . '</h2>' . ($sub !== '' ? '<p>' . self::e($sub) . '</p>' : '') . '</div></header><div class="m-sec-body">' . $body . '</div></section>';
    }

    /** Escapes a text and wraps one literal part of it in markup (a path in <code>, a name in <strong>). */
    private static function emphasize(string $text, string $literal, string $tag): string
    {
        $escaped = self::e($literal);
        return str_replace($escaped, '<' . $tag . '>' . $escaped . '</' . $tag . '>', self::e($text));
    }

    // ------------------------------------------------------------------ migrations

    /**
     * Summary bucket of a job: done, active (the plugin or BladeCP is working),
     * waiting (a person must approve, pay or confirm), error, or null for a
     * cancelled migration. A migration waiting only for the optional domain
     * pointing is done.
     *
     * @param array<string,mixed> $job
     */
    public static function category(array $job): ?string
    {
        $status = (string) ($job['status'] ?? '');
        if (!empty($job['cancel_requested']) && !JobState::isTerminal($job)) {
            return 'active';
        }
        if (JobState::isComplete($job)) {
            return 'done';
        }
        return match ($status) {
            JobState::CANCELLED => null,
            JobState::ERROR, JobState::FAILED => 'error',
            JobState::WAITING => in_array($job['wait_reason'] ?? null, ['payment', 'review', 'approval'], true) ? 'waiting' : 'active',
            default => 'active',
        };
    }

    /**
     * @param list<array<string,mixed>> $jobs
     * @return array<string,int> filter => number of jobs
     */
    public static function summary(array $jobs): array
    {
        $counts = array_fill_keys(array_keys(self::FILTERS), 0);
        foreach ($jobs as $job) {
            $category = self::category($job);
            if ($category !== null) {
                $counts[$category]++;
            }
        }
        return $counts;
    }

    /**
     * Stage of the six-segment bar and its measured percentage, from the
     * real job state: the local step while the plugin works, then the
     * Customer API phase. The only percentages are the upload while it runs
     * (0 to 99) and 100 once the migration is complete (cutover_pending,
     * cutover_stalled and live); every wait shows "step k of 6". Null for
     * cancelled and failed migrations.
     *
     * @param array<string,mixed> $job
     * @return array{0:int,1:?int}|null stage (1 to 6) and percentage
     */
    public static function stageOf(array $job): ?array
    {
        $status = (string) ($job['status'] ?? '');
        if ($status === JobState::CANCELLED || $status === JobState::FAILED) {
            return null;
        }
        if (JobState::isComplete($job)) {
            return [count(self::STAGES), 100];
        }
        $step = (string) ($job['step'] ?? 'backup');
        if (isset(self::STEP_STAGE[$step])) {
            // A percentage only while the backup is really being sent (0 to 99%).
            // Every wait (queue, retry, provider, account confirmation, payment)
            // shows "step k of 6": 100% belongs to Completed only.
            $progress = $job['progress'] ?? null;
            $percent = $step === 'upload' && $status === JobState::RUNNING && empty($job['cancel_requested'])
                && is_int($progress) && $progress >= 0 && $progress < 100 ? $progress : null;
            return [self::STEP_STAGE[$step], $percent];
        }
        $phase = (string) ($job['remote']['phase'] ?? '');
        $stage = self::PHASE_STAGE[$phase] ?? 3;
        return [$stage, $stage === count(self::STAGES) ? 100 : null];
    }

    /**
     * @param list<array<string,mixed>> $jobs newest first
     */
    public function migrations(array $jobs, bool $ready, ?int $workerLastRun, int $now, ?string $filter = null, bool $pinCard = false): string
    {
        $filter = $filter !== null && isset(self::FILTERS[$filter]) ? $filter : null;
        $all = ['page' => 'migrations'];
        $actions = '<a class="m-btn" href="' . self::e($this->url($all + ($filter !== null ? ['filter' => $filter] : []))) . '">' . $this->icon('refresh')
            . self::e($this->t('migrations.refresh')) . '</a>';
        if ($ready) {
            $actions .= '<a class="m-btn m-primary" href="' . self::e($this->url(['page' => 'accounts'])) . '">' . $this->icon('users')
                . self::e($this->t('migrations.go_accounts')) . '</a>';
        }
        $html = $this->head($this->t('migrations.title'), $this->t('migrations.hint'), $actions);
        if ($workerLastRun === null || $now - $workerLastRun > 300) {
            $html .= $this->notice('warning', self::emphasize($this->t('migrations.worker_idle'), '/etc/cron.d/bladecp-migrator', 'code'), 'triangle', 'status');
        }
        if ($pinCard) {
            $html .= $this->pinCard();
        }
        if ($jobs === []) {
            return $html . $this->emptyMigrations($ready);
        }
        $counts = self::summary($jobs);
        $stats = '';
        foreach (self::FILTERS as $key => [$tone, $icon]) {
            $current = $filter === $key;
            $stats .= '<a class="m-stat m-t-' . $tone . '" href="' . self::e($this->url($all + ($current ? [] : ['filter' => $key]))) . '"'
                . ($current ? ' aria-current="true"' : '') . '><span class="m-stat-ic">' . $this->icon($icon) . '</span>'
                . '<span class="m-stat-n">' . $counts[$key] . '</span><span class="m-stat-l">' . self::e($this->t('migrations.stat.' . $key)) . '</span>'
                . '<span class="m-stat-s">' . self::e($this->t('migrations.stat.' . $key . '_hint')) . '</span></a>';
        }
        $html .= '<div class="m-stats">' . $stats . '</div>';
        $visible = $jobs;
        if ($filter !== null) {
            $visible = array_values(array_filter($jobs, static fn (array $job): bool => self::category($job) === $filter));
            $label = $this->t('migrations.stat.' . $filter);
            $html .= '<p class="m-filter"><span>' . self::emphasize($this->t('migrations.filter.showing', ['filter' => $label]), $label, 'strong') . '</span>'
                . '<a class="m-link" href="' . self::e($this->url($all)) . '">' . self::e($this->t('migrations.filter.clear')) . '</a></p>';
        }
        if ($visible === []) {
            return $html . '<section class="m-card m-filter-empty"><p>' . self::e($this->t('migrations.filter.empty')) . '</p></section>';
        }
        $rows = '';
        foreach ($visible as $job) {
            $rows .= $this->jobRow($job, $now);
        }
        $head = '';
        foreach (['account', 'status', 'progress', 'preview', 'actions'] as $column) {
            $head .= '<th scope="col">' . self::e($this->t('migrations.col.' . $column)) . '</th>';
        }
        return $html . '<section class="m-card m-flush" aria-label="' . self::e($this->t('migrations.title')) . '" data-autorefresh="30">'
            . '<div class="m-table-wrap"><table class="m-table m-jobs"><thead><tr>' . $head . '</tr></thead><tbody>' . $rows . '</tbody></table></div></section>';
    }

    /** Offer to put the plugin first in the WHM home page Favorites ("Not now" hides it for good). */
    private function pinCard(): string
    {
        return '<section class="m-card m-pin" aria-labelledby="bcpm-pin-title"><span class="m-tile">' . $this->icon('pin') . '</span>'
            . '<div class="m-pin-copy"><h2 id="bcpm-pin-title">' . self::e($this->t('migrations.pin.title')) . '</h2><p>' . self::e($this->t('migrations.pin.text')) . '</p></div>'
            . '<div class="m-pin-actions">' . $this->pinForm('pin', 'migrations', 'migrations.pin.pin', 'migrations.pin.busy', 'm-primary', 'pin')
            . $this->pinForm('dismiss', 'migrations', 'migrations.pin.later', 'migrations.pin.busy_later', '', null) . '</div></section>';
    }

    private function pinForm(string $op, string $return, string $labelKey, string $busyKey, string $tone, ?string $icon): string
    {
        return '<form method="post" action="' . self::e($this->url()) . '" class="m-inline">' . $this->csrfField()
            . '<input type="hidden" name="action" value="whm_pin"><input type="hidden" name="op" value="' . self::e($op) . '">'
            . '<input type="hidden" name="return" value="' . self::e($return) . '">'
            . '<button class="m-btn m-sm' . ($tone !== '' ? ' ' . $tone : '') . '" type="submit"' . $this->busy($busyKey) . '>'
            . ($icon !== null ? $this->icon($icon) : '') . self::e($this->t($labelKey)) . '</button></form>';
    }

    private function emptyMigrations(bool $ready): string
    {
        if ($ready) {
            return '<section class="m-card m-empty"><div class="m-empty-art" aria-hidden="true"><span class="m-empty-node">' . $this->icon('server') . '</span>'
                . '<span class="m-empty-line"></span><span class="m-empty-node m-empty-brand">' . $this->mark() . '</span></div>'
                . '<h2>' . self::e($this->t('migrations.empty_title')) . '</h2><p>' . self::e($this->t('migrations.empty')) . '</p>'
                . '<a class="m-btn m-primary" href="' . self::e($this->url(['page' => 'accounts'])) . '">' . $this->icon('users')
                . self::e($this->t('migrations.go_accounts')) . '</a></section>';
        }
        return '<section class="m-card m-empty"><div class="m-empty-art" aria-hidden="true"><span class="m-empty-node m-empty-key">' . $this->icon('key') . '</span></div>'
            . '<h2>' . self::e($this->t('migrations.empty_setup_title')) . '</h2><p>' . self::e($this->t('migrations.empty_setup')) . '</p>'
            . '<a class="m-btn m-primary" href="' . self::e($this->url(['page' => 'settings'])) . '">' . $this->icon('settings')
            . self::e($this->t('migrations.go_settings')) . '</a></section>';
    }

    /** @param array<string,mixed> $job */
    private function jobRow(array $job, int $now): string
    {
        [$label, $tone, $detail, $icon] = $this->statusOf($job, $now);
        $remote = is_array($job['remote'] ?? null) ? $job['remote'] : [];
        $category = self::category($job);
        $needs = $category === 'waiting' || $category === 'error' ? ' m-needs m-t-' . ($category === 'error' ? 'danger' : 'warning') : '';
        $user = (string) $job['user'];
        $domain = (string) ($remote['primary_domain'] ?? $job['domain'] ?? '');
        $preview = '<span class="m-dash" aria-label="' . self::e($this->t('migrations.no_preview')) . '">-</span>';
        if (is_string($remote['staging_url'] ?? null)) {
            $preview = '<a class="m-link" href="' . self::e($remote['staging_url']) . '" target="_blank" rel="noopener noreferrer">' . $this->icon('external')
                . '<span>' . self::e($this->t('migrations.open_preview')) . '</span></a>';
        }
        return '<tr class="m-row' . $needs . '"><td class="m-cell-acct"><div class="m-acct"><span class="m-avatar" aria-hidden="true">' . self::e(strtoupper(substr($user, 0, 1))) . '</span>'
            . '<div><strong>' . self::e($user) . '</strong><span class="m-dom">' . self::e($domain) . '</span>'
            . '<span class="m-when">' . self::e($this->t('migrations.started', ['date' => gmdate('Y-m-d H:i', (int) $job['created_at']) . ' UTC'])) . '</span>'
            . $this->origin($job) . $this->whmcsService($job) . '</div></div></td>'
            . '<td data-label="' . self::e($this->t('migrations.col.status')) . '"><span class="m-pill m-t-' . $tone . '">'
            . $this->icon($icon, 'm-pill-ic' . ($icon === 'loader' ? ' m-spin' : '')) . '<span>' . self::e($label) . '</span></span>'
            . ($detail !== '' ? '<p class="m-detail">' . self::e($detail) . '</p>' : '') . '</td>'
            . '<td data-label="' . self::e($this->t('migrations.col.progress')) . '">' . $this->progress($job, $tone) . '</td>'
            . '<td data-label="' . self::e($this->t('migrations.col.preview')) . '">' . $preview . '</td>'
            . '<td data-label="' . self::e($this->t('migrations.col.actions')) . '">' . $this->jobActions($job) . '</td></tr>';
    }

    /** The service the partner WHMCS linked to the migration, once linked. */
    private function whmcsService(array $job): string
    {
        $billing = $job['remote']['billing_link'] ?? null;
        if (!is_array($billing) || ($billing['status'] ?? null) !== 'linked' || !is_string($billing['whmcs_service_id'] ?? null)) {
            return '';
        }
        return '<span class="m-origin m-whmcs">' . $this->icon('receipt') . '<span>' . self::e($this->t('migrations.whmcs_service', ['id' => $billing['whmcs_service_id']])) . '</span></span>';
    }

    /** Who started the migration: the administrator here, or the account in its cPanel. */
    private function origin(array $job): string
    {
        $account = JobState::origin($job) === 'cpanel';
        return '<span class="m-origin' . ($account ? ' is-account' : '') . '">' . $this->icon($account ? 'user' : 'server')
            . '<span>' . self::e($this->t($account ? 'migrations.origin.cpanel' : 'migrations.origin.whm')) . '</span></span>';
    }

    /** @param array<string,mixed> $job */
    private function progress(array $job, string $tone): string
    {
        $stage = self::stageOf($job);
        if ($stage === null) {
            return '<span class="m-dash" aria-label="' . self::e($this->t('progress.none')) . '">-</span>';
        }
        [$index, $percent] = $stage;
        $total = count(self::STAGES);
        $name = $this->t('progress.stage.' . self::STAGES[$index - 1]);
        $live = JobState::isComplete($job);
        $params = ['step' => $index, 'total' => $total, 'stage' => $name, 'percent' => (int) $percent];
        $segments = '';
        for ($i = 1; $i <= $total; $i++) {
            $segments .= $live || $i < $index ? '<i class="is-done"></i>' : ($i === $index ? '<i class="is-now"></i>' : '<i></i>');
        }
        return '<div class="m-sprog m-t-' . $tone . ($live ? ' is-live' : '') . '"><div class="m-sprog-top"><span class="m-sprog-name">' . self::e($name) . '</span>'
            . '<span class="m-pct">' . self::e($percent === null ? $this->t('progress.step', $params) : $percent . '%') . '</span></div>'
            . '<span class="m-segs" role="progressbar" aria-valuemin="1" aria-valuemax="' . $total . '" aria-valuenow="' . $index . '"'
            . ' aria-valuetext="' . self::e($this->t($percent === null ? 'progress.value' : 'progress.value_percent', $params)) . '"'
            . ' aria-label="' . self::e($this->t('progress.label', ['account' => (string) $job['user']])) . '">' . $segments . '</span></div>';
    }

    /**
     * @param array<string,mixed> $job
     * @return array{0:string,1:string,2:string,3:string} label, tone, detail, icon
     */
    public function statusOf(array $job, int $now): array
    {
        $status = (string) ($job['status'] ?? '');
        $phase = (string) ($job['remote']['phase'] ?? '');
        if (!empty($job['cancel_requested']) && !JobState::isTerminal($job)) {
            return [$this->t('status.cancelling'), 'warning', '', 'loader'];
        }
        return match ($status) {
            JobState::PENDING => is_array($job['error'] ?? null)
                ? [$this->t('status.retrying'), 'warning', $this->t('status.retry_at', ['minutes' => max(1, (int) ceil(((int) $job['next_attempt_at'] - $now) / 60))]), 'rotate']
                : [$this->t('status.queued'), 'neutral', '', 'hourglass'],
            JobState::RUNNING => [$this->t('step.' . $job['step']), 'info', $job['step'] === 'upload' && is_array($job['upload'] ?? null)
                ? $this->t('status.upload_detail', ['done' => count(UploadPlan::completed($job['upload'])), 'total' => (int) $job['upload']['total_parts']])
                : '', 'loader'],
            JobState::WAITING => match ($job['wait_reason'] ?? 'processing') {
                // Billing link: the partner WHMCS links the owner and the service, or the holder confirms the account.
                'billing' => JobState::billingStatus($job) === 'claim_required'
                    ? [$this->t('wait.billing_claim'), 'warning', $this->t('wait.billing_claim_detail'), 'user']
                    : [$this->t('wait.billing'), 'info', $now - (int) ($job['billing_wait_since'] ?? $now) > 900 ? $this->t('wait.billing_review') : '', 'clock'],
                'payment' => [$this->t('wait.payment'), 'warning', $this->t(JobState::usesBillingLink($job) ? 'wait.payment_whmcs_detail' : 'wait.payment_detail'), 'card'],
                'review' => [$this->t('wait.review'), 'warning', $this->t('wait.review_detail'), 'search'],
                'approval' => [$this->t('wait.approval'), 'warning', $this->t('wait.approval_detail'), 'eye'],
                // Complete: pointing the domain is optional (see pointing()).
                'dns' => [$this->t('status.done'), 'success', '', 'check-circle'],
                default => [$this->t('phase.' . ($phase === '' ? 'queued' : $phase)), 'info', '', 'loader'],
            },
            JobState::ERROR => [$this->t('status.error'), 'danger', $this->t((string) ($job['error']['key'] ?? 'error.local.unexpected')), 'triangle'],
            JobState::FAILED => [$this->t('status.failed'), 'danger', '', 'x-circle'],
            JobState::DONE => [$this->t('status.done'), 'success', '', 'check-circle'],
            JobState::CANCELLED => [$this->t('status.cancelled'), 'neutral', '', 'ban'],
            default => [$status, 'neutral', '', 'info'],
        };
    }

    /** @param array<string,mixed> $job */
    private function jobActions(array $job): string
    {
        $html = '';
        foreach (JobState::actions($job) as $action) {
            $html .= match ($action) {
                'review' => '<form method="post" action="' . self::e($this->url()) . '" class="m-inline m-domain">' . $this->hidden('job_action', $job['id'], 'review')
                    . '<label class="m-sr" for="domain-' . self::e($job['id']) . '">' . self::e($this->t('action.review_label')) . '</label>'
                    . '<input class="m-input m-input-sm" id="domain-' . self::e($job['id']) . '" name="domain" maxlength="63" required value="'
                    . self::e($job['remote']['source_domain'] ?? $job['domain']) . '">'
                    . '<button class="m-btn m-sm m-primary" type="submit"' . $this->busy('action.busy.review') . '>' . $this->icon('check') . self::e($this->t('action.review')) . '</button></form>',
                'approve' => $this->actionButton($job, 'approve', 'action.approve', 'm-primary', 'check', 'action.approve_confirm'),
                'mark_paid' => $this->actionButton($job, 'mark_paid', 'action.mark_paid', 'm-accent', 'banknote', 'action.mark_paid_confirm'),
                'retry' => $this->actionButton($job, 'retry', 'action.retry', 'm-accent', 'rotate'),
                'cancel' => $this->actionButton($job, 'cancel', 'action.cancel', 'm-danger', null, 'action.cancel_confirm'),
                default => '',
            };
        }
        if (JobState::isComplete($job)) {
            $html .= $this->pointing($job);
        }
        if (is_array($job['error'] ?? null)) {
            $error = $job['error'];
            // An interrupted job already shows the error message as its status detail.
            $message = ($job['status'] ?? '') === JobState::ERROR ? '' : '<p>' . self::e($this->t((string) ($error['key'] ?? 'error.local.unexpected'))) . '</p>';
            $html .= '<details class="m-err"><summary>' . $this->icon('alert-circle') . '<span>' . self::e($this->t('action.view_error')) . '</span>'
                . $this->icon('chevron', 'm-chev') . '</summary><div class="m-err-body">' . $message
                . '<dl><dt>' . self::e($this->t('error.code_label')) . '</dt><dd><code>' . self::e($error['code'] ?? '') . '</code></dd>'
                . '<dt>' . self::e($this->t('error.step_label')) . '</dt><dd>' . self::e($this->t('step.' . ($error['step'] ?? 'backup'))) . '</dd>'
                . '<dt>' . self::e($this->t('error.time_label')) . '</dt><dd>' . self::e(gmdate('Y-m-d H:i', (int) ($error['at'] ?? 0)) . ' UTC') . '</dd></dl>'
                . (($error['detail'] ?? '') !== '' ? '<pre>' . self::e($error['detail']) . '</pre>' : '')
                . '</div></details>';
        }
        return $html === '' ? '<span class="m-dash" aria-label="' . self::e($this->t('migrations.no_actions')) . '">-</span>' : '<div class="m-actions">' . $html . '</div>';
    }

    /**
     * Optional domain pointing of a complete migration: the platform
     * nameservers (copy each or both) and, as an alternative, the A record
     * of the cutover target, the Registro.br tip for .br domains and the
     * note when the automatic check ended. Once live, one line says so.
     *
     * @param array<string,mixed> $job
     */
    private function pointing(array $job): string
    {
        $remote = is_array($job['remote'] ?? null) ? $job['remote'] : [];
        $phase = (string) ($remote['phase'] ?? '');
        $domain = strtolower((string) ($remote['primary_domain'] ?? $job['domain'] ?? ''));
        $domain = preg_match('/^[a-z0-9.-]{1,253}$/D', $domain) === 1 ? $domain : strtolower((string) ($job['domain'] ?? ''));
        if ($phase === 'live') {
            return $domain === '' ? '' : '<p class="m-pointed">' . $this->icon('globe') . '<span>'
                . self::emphasize($this->t('migrations.point.pointed', ['domain' => $domain]), $domain, 'strong') . '</span></p>';
        }
        $stalled = $phase === 'cutover_stalled';
        $servers = '';
        foreach (self::NAMESERVERS as $index => $server) {
            $servers .= '<div><dt>NS' . ($index + 1) . '</dt><dd><code>' . self::e($server) . '</code>' . $this->copyButton($server, true) . '</dd></div>';
        }
        $body = '<p class="m-point-lead"><strong>' . self::e($this->t('migrations.point.title')) . '</strong><span>' . self::e($this->t('migrations.point.optional')) . '</span></p>'
            . '<div class="m-point-ns-head"><span>' . self::e($this->t('migrations.point.nameservers')) . '</span>'
            . $this->copyButton(implode("\n", self::NAMESERVERS), false, 'migrations.point.copy_both') . '</div>'
            . '<dl class="m-ns">' . $servers . '</dl>'
            . '<p class="m-help">' . self::e($this->t($stalled ? 'migrations.point.hint_stalled' : 'migrations.point.hint')) . '</p>';
        if (str_ends_with($domain, '.br')) {
            $action = $this->t('migrations.point.br_action');
            $body .= '<p class="m-help m-point-br"><span>' . self::emphasize($this->t('migrations.point.br_hint', ['action' => $action]), $action, 'strong') . '</span> '
                . '<a class="m-link" href="https://registro.br" target="_blank" rel="noopener noreferrer">' . self::e($this->t('migrations.point.br_link')) . $this->icon('external') . '</a></p>';
        }
        $target = is_string($remote['cutover_target'] ?? null) ? $remote['cutover_target'] : null;
        if ($target !== null && $domain !== '') {
            $type = filter_var($target, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false ? 'A'
                : (filter_var($target, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? 'AAAA' : 'CNAME');
            $records = '';
            foreach ([$domain, 'www.' . $domain] as $host) {
                $records .= '<li><span class="m-rtype">' . self::e($type) . '</span><code>' . self::e($host) . '</code></li>';
            }
            $body .= '<p class="m-point-alt">' . self::e($this->t('migrations.point.alternative', ['type' => $type])) . '</p>'
                . '<ul class="m-arec">' . $records . '</ul><div class="m-ns"><div><dt>' . self::e($this->t('migrations.point.value')) . '</dt><dd><code>' . self::e($target) . '</code>'
                . $this->copyButton($target, true) . '</dd></div></div>';
        }
        $deadline = $remote['cutover_deadline_at'] ?? null;
        if (!$stalled && is_int($deadline) && $deadline > 0) {
            $body .= '<p class="m-help">' . self::e($this->t('migrations.point.check_until', ['date' => gmdate('Y-m-d H:i', $deadline) . ' UTC'])) . '</p>';
        }
        if ($stalled) {
            $body .= '<p class="m-point-note">' . $this->icon('info') . '<span>' . self::e($this->t('migrations.point.stalled_note')) . '</span></p>';
        }
        return '<details class="m-point"><summary>' . $this->icon('globe') . '<span>' . self::e($this->t('migrations.point.summary')) . '</span>'
            . $this->icon('chevron', 'm-chev') . '</summary><div class="m-point-body">' . $body . '</div></details>';
    }

    /** Copy button (the page script handles data-copy); icon only, with a hidden label, or with its label. */
    private function copyButton(string $value, bool $iconOnly, string $labelKey = 'migrations.point.copy'): string
    {
        return '<button type="button" class="m-btn m-sm m-copy' . ($iconOnly ? ' m-copy-ic' : '') . '" data-copy="' . self::e($value) . '"'
            . ' data-copied="' . self::e($this->t('migrations.point.copied')) . '"' . ($iconOnly ? ' title="' . self::e($this->t('migrations.point.copy')) . '"' : '') . '>'
            . $this->icon('copy') . '<span' . ($iconOnly ? ' class="m-sr"' : '') . '>' . self::e($this->t($labelKey)) . '</span></button>';
    }

    private function actionButton(array $job, string $op, string $labelKey, string $tone, ?string $icon, ?string $confirmKey = null): string
    {
        $confirm = $confirmKey === null ? '' : ' data-confirm="' . self::e($this->t($confirmKey, ['account' => $job['user']])) . '"';
        return '<form method="post" action="' . self::e($this->url()) . '" class="m-inline"' . $confirm . '>'
            . $this->hidden('job_action', $job['id'], $op)
            . '<button class="m-btn m-sm ' . $tone . '" type="submit"' . $this->busy('action.busy.' . $op) . '>' . ($icon !== null ? $this->icon($icon) : '') . self::e($this->t($labelKey)) . '</button></form>';
    }

    private function hidden(string $action, string $jobId, string $op): string
    {
        return $this->csrfField() . '<input type="hidden" name="action" value="' . self::e($action) . '">'
            . '<input type="hidden" name="job" value="' . self::e($jobId) . '"><input type="hidden" name="op" value="' . self::e($op) . '">';
    }

    public function csrfField(): string
    {
        return '<input type="hidden" name="' . Csrf::FIELD . '" value="' . self::e($this->csrfToken) . '">';
    }

    // ------------------------------------------------------------------ accounts

    /**
     * @param list<array<string,mixed>> $accounts
     * @param array<string,string> $active user => job id
     */
    public function accounts(array $accounts, array $active, bool $ready, ?string $errorKey): string
    {
        $html = $this->head($this->t('accounts.title'), $this->t('accounts.hint'));
        if (!$ready) {
            $html .= $this->notice('warning', self::e($this->t('accounts.not_ready')) . ' <a href="' . self::e($this->url(['page' => 'settings'])) . '">'
                . self::e($this->t('migrations.go_settings')) . '</a>', 'key', 'status');
        }
        if ($errorKey !== null) {
            return $html . $this->notice('danger', self::e($this->t($errorKey)), 'alert-circle', 'alert');
        }
        if ($accounts === []) {
            return $html . '<section class="m-card m-empty"><div class="m-empty-art" aria-hidden="true"><span class="m-empty-node">' . $this->icon('server') . '</span></div>'
                . '<p>' . self::e($this->t('accounts.empty')) . '</p></section>';
        }
        $col = fn (string $key): string => self::e($this->t('accounts.col.' . $key));
        $rows = '';
        foreach ($accounts as $account) {
            $user = (string) $account['user'];
            $busy = isset($active[$user]);
            $email = $account['email'] ?? null;
            $status = [];
            if ($busy) {
                $status[] = '<span class="m-pill m-t-info">' . $this->icon('loader', 'm-pill-ic m-spin') . '<span>' . self::e($this->t('accounts.in_progress')) . '</span></span>';
            }
            if (!empty($account['suspended'])) {
                $status[] = '<span class="m-pill m-t-neutral">' . $this->icon('ban', 'm-pill-ic') . '<span>' . self::e($this->t('accounts.suspended')) . '</span></span>';
            }
            $search = strtolower($user . ' ' . $account['domain'] . ' ' . ($email ?? ''));
            $rows .= '<tr' . ($busy ? ' class="is-busy"' : '') . ' data-search="' . self::e($search) . '"><td class="m-check-cell"><input type="checkbox" class="m-check" name="accounts[]" value="'
                . self::e($user) . '"' . ($busy || !$ready ? ' disabled' : '') . ' aria-label="' . self::e($this->t('accounts.select', ['account' => $user])) . '"></td>'
                . '<td class="m-cell-user" data-label="' . $col('user') . '"><strong>' . self::e($user) . '</strong></td>'
                . '<td class="m-wrap" data-label="' . $col('domain') . '">' . self::e($account['domain']) . '</td>'
                . '<td class="m-wrap" data-label="' . $col('email') . '">' . ($email === null ? '<span class="m-faint">' . self::e($this->t('accounts.no_email')) . '</span>' : self::e($email)) . '</td>'
                . '<td class="m-num" data-label="' . $col('disk') . '">' . self::e(self::bytes($account['disk_used_bytes'] ?? null)) . '</td>'
                . '<td data-label="' . $col('status') . '">' . ($status === [] ? '<span class="m-dash" aria-label="' . self::e($this->t('accounts.available')) . '">-</span>' : implode(' ', $status)) . '</td></tr>';
        }
        return $html . '<form method="post" action="' . self::e($this->url()) . '" data-accounts>' . $this->csrfField()
            . '<input type="hidden" name="action" value="review_selection"><section class="m-card m-flush"><div class="m-toolbar">'
            . '<div class="m-search">' . $this->icon('search') . '<label class="m-sr" for="filter">' . self::e($this->t('accounts.filter')) . '</label>'
            . '<input id="filter" type="search" class="m-input" placeholder="' . self::e($this->t('accounts.filter')) . '" data-filter></div>'
            . '<span class="m-selected"><span data-selected-count data-template="' . self::e($this->t('accounts.selected', ['count' => '{count}'])) . '">'
            . self::e($this->t('accounts.selected', ['count' => 0])) . '</span><span class="m-faint">' . self::e($this->t('accounts.limit')) . '</span></span>'
            . '<button class="m-btn m-primary" type="submit" data-migrate' . $this->busy('accounts.busy') . ($ready ? '' : ' disabled') . '>' . $this->icon('transfer')
            . self::e($this->t('accounts.migrate_selected')) . '</button></div>'
            . '<div class="m-table-wrap"><table class="m-table m-accounts"><thead><tr>'
            . '<th scope="col" class="m-check-cell"><input type="checkbox" class="m-check" data-select-all aria-label="' . self::e($this->t('accounts.select_all')) . '"' . ($ready ? '' : ' disabled') . '></th>'
            . '<th scope="col">' . $col('user') . '</th><th scope="col">' . $col('domain') . '</th><th scope="col">' . $col('email') . '</th>'
            . '<th scope="col" class="m-num">' . $col('disk') . '</th><th scope="col">' . $col('status') . '</th>'
            . '</tr></thead><tbody>' . $rows . '</tbody></table></div></section></form>'
            . '<p class="m-hint">' . $this->icon('server') . self::e($this->t('accounts.count', ['count' => count($accounts)])) . '</p>';
    }

    // ------------------------------------------------------------------ review

    /**
     * @param list<array<string,mixed>> $rows user, domain, email, name, service_id
     * @param array<string,mixed> $batch form values
     * @param array{plans:list<array<string,mixed>>,locations:array<int,list<array<string,mixed>>>}|null $catalog
     * @param array<string,array<string,string>> $rowErrors
     * @param array<string,string> $batchErrors
     */
    public function review(array $rows, array $batch, ?array $catalog, array $rowErrors, array $batchErrors, ?string $generalKey, string $domainMode): string
    {
        $back = '<a class="m-back" href="' . self::e($this->url(['page' => 'accounts'])) . '">' . $this->icon('arrow-left') . self::e($this->t('review.back')) . '</a>';
        $html = $this->head($this->t('review.title', ['count' => count($rows)]), $this->t('review.intro'), '', $back);
        if ($generalKey !== null) {
            $html .= $this->notice('danger', self::e($this->t($generalKey)), 'alert-circle', 'alert');
        }
        $destination = $this->destinationFields($catalog, $batch, $batchErrors)
            . $this->commercialFields($batch, $batchErrors)
            . $this->field('native_product_id', 'settings.native_product_id', '<input class="m-input" id="native_product_id" name="native_product_id" inputmode="numeric" required value="'
                . self::e($batch['native_product_id'] ?? '') . '">', $batchErrors, 'settings.native_product_id_help', ' m-narrow')
            . '<p class="m-note">' . $this->icon('info') . '<span>' . self::e($this->t($domainMode === 'account' ? 'review.domain_account' : 'review.domain_review')) . '</span></p>';
        $label = fn (string $key): string => self::e($this->t($key));
        $table = '<div class="m-table-wrap"><table class="m-table m-review"><thead><tr><th scope="col">' . $label('accounts.col.user') . '</th>'
            . '<th scope="col">' . $label('accounts.col.domain') . '</th></tr></thead><tbody>';
        foreach (array_values($rows) as $index => $row) {
            $user = (string) $row['user'];
            $errors = $rowErrors[$user] ?? [];
            $table .= '<tr' . ($errors !== [] ? ' class="has-error"' : '') . '><td class="m-cell-user" data-label="' . $label('accounts.col.user') . '">'
                . '<input type="hidden" name="rows[' . $index . '][user]" value="' . self::e($user) . '"><strong>' . self::e($user) . '</strong>'
                . $this->errorFor($errors, 'user') . '</td>'
                . '<td class="m-wrap" data-label="' . $label('accounts.col.domain') . '">' . self::e($row['domain'] ?? '') . '</td></tr>';
        }
        $table .= '</tbody></table></div>';
        return $html . '<form method="post" action="' . self::e($this->url()) . '">' . $this->csrfField() . '<input type="hidden" name="action" value="start_migration">'
            . $this->section('layers', $this->t('review.destination'), $this->t('review.destination_hint'), $destination)
            . $this->section('users', $this->t('review.accounts'), $this->t('review.accounts_hint'), $table, ' m-flush-body')
            . '<div class="m-submit"><button class="m-btn m-primary m-lg" type="submit"' . $this->busy('review.busy') . '>' . $this->icon('upload') . self::e($this->t('review.start', ['count' => count($rows)])) . '</button></div></form>';
    }

    // ------------------------------------------------------------------ settings

    /**
     * @param array{plans:list<array<string,mixed>>,locations:array<int,list<array<string,mixed>>>}|null $catalog
     * @param array<string,mixed>|null $connection last connection test
     * @param array<string,string> $errors
     * @param array<string,mixed> $input values to show (settings or rejected input)
     */
    public function settings(Settings $settings, ?array $catalog, ?string $catalogErrorKey, ?array $connection, array $errors, array $input, string $dataDir,
        ?string $apiBrandName = null, array $surfaces = []): string
    {
        $html = $this->head($this->t('settings.title'), $this->t('settings.intro'));

        // Connection
        $status = '<div class="m-conn-status"><p>' . self::e($this->t('settings.connection.not_tested')) . '</p></div>';
        $scopes = '';
        if (!$settings->hasApiKey()) {
            $status = '<div class="m-conn-status"><p>' . self::e($this->t('settings.connection.no_key')) . '</p></div>';
        } elseif (is_array($connection)) {
            $when = gmdate('Y-m-d H:i', (int) ($connection['at'] ?? 0)) . ' UTC';
            $status = !empty($connection['ok'])
                ? '<div class="m-conn-status"><span class="m-pill m-t-success">' . $this->icon('check-circle', 'm-pill-ic') . '<span>' . self::e($this->t('settings.connection.ok')) . '</span></span>'
                    . '<p>' . self::e($this->t('settings.connection.as', ['email' => (string) ($connection['email'] ?? ''), 'date' => $when])) . '</p></div>'
                : '<div class="m-conn-status"><span class="m-pill m-t-danger">' . $this->icon('x-circle', 'm-pill-ic') . '<span>' . self::e($this->t('settings.connection.failed')) . '</span></span>'
                    . '<p>' . self::e($this->t((string) ($connection['error_key'] ?? 'error.api.network'))) . ' <span class="m-faint">' . self::e($when) . '</span></p></div>';
            if (!empty($connection['ok']) && empty($connection['scopes_ok'])) {
                $scopes = $this->notice('warning', self::e($this->t('settings.connection.scopes')), 'key', 'status');
            }
        }
        $keyPlaceholder = $settings->hasApiKey() ? $this->t('settings.api_key_saved') : $this->t('settings.api_key_placeholder');
        $customUrl = (string) ($input['api_base_url'] ?? $settings->customApiUrl());
        $test = $settings->hasApiKey()
            ? '<button class="m-btn" type="submit" form="bcpm-test-connection"' . $this->busy('settings.busy.test') . '>' . $this->icon('activity') . self::e($this->t('settings.test')) . '</button>' : '';
        $connectionBody = $status . $scopes . '<form method="post" action="' . self::e($this->url()) . '">' . $this->csrfField()
            . '<input type="hidden" name="action" value="save_connection">'
            . $this->field('api_key', 'settings.api_key', '<div class="m-keywrap">' . $this->icon('key') . '<input class="m-input" id="api_key" name="api_key" type="password" autocomplete="off" spellcheck="false" placeholder="'
                . self::e($keyPlaceholder) . '"' . (isset($errors['api_key']) ? ' aria-invalid="true"' : '') . '></div>', $errors, 'settings.api_key_help')
            . '<details class="m-adv"' . ($customUrl !== '' || isset($errors['api_base_url']) ? ' open' : '') . '><summary>' . $this->icon('chevron', 'm-chev')
            . '<span>' . self::e($this->t('settings.advanced')) . '</span></summary><div class="m-adv-body">'
            . $this->field('api_base_url', 'settings.api_base_url', '<input class="m-input" id="api_base_url" name="api_base_url" type="url" spellcheck="false" placeholder="https://api.bladecp.com/api/v1" value="'
                . self::e($customUrl) . '"' . (isset($errors['api_base_url']) ? ' aria-invalid="true"' : '') . '>', $errors, 'settings.api_base_url_help')
            . '</div></details><div class="m-form-actions"><button class="m-btn m-primary" type="submit"' . $this->busy('settings.busy.save_and_test') . '>' . $this->icon('plug') . self::e($this->t('settings.save_and_test')) . '</button>'
            . $test . '</div></form>'
            . ($settings->hasApiKey() ? '<form id="bcpm-test-connection" method="post" action="' . self::e($this->url()) . '">' . $this->csrfField()
                . '<input type="hidden" name="action" value="test_connection"></form>' : '');
        $html .= '<div class="m-settings">' . $this->section('key', $this->t('settings.connection'), $this->t('settings.connection_hint'), $connectionBody);

        // Defaults
        if (!$settings->hasApiKey()) {
            return $html . $this->section('layers', $this->t('settings.defaults'), $this->t('settings.defaults_hint'),
                '<p class="m-help">' . self::e($this->t('settings.defaults_need_key')) . '</p>')
                . $this->clients($settings, $errors, $input, $apiBrandName) . $this->surfaces($surfaces) . $this->about($settings, $dataDir) . '</div>';
        }
        $values = $input + [
            'plan_id' => $settings->planId(), 'location_id' => $settings->locationId(),
            'commercial_mode' => $settings->commercialMode(), 'trial_days' => $settings->trialDays(),
            'payment_days' => $settings->paymentDays(), 'native_product_id' => $settings->nativeProductId(),
            'concurrency' => $settings->concurrency(), 'work_dir' => $settings->workDir(),
            'domain_mode' => $settings->domainMode(), 'ui_language' => $settings->uiLanguage(),
        ];
        $concurrency = '';
        for ($i = 1; $i <= Settings::MAX_CONCURRENCY; $i++) {
            $concurrency .= '<option value="' . $i . '"' . ((int) $values['concurrency'] === $i ? ' selected' : '') . '>' . $i . '</option>';
        }
        $languages = '';
        foreach (Settings::LANGUAGES as $language) {
            $languages .= '<option value="' . self::e($language) . '"' . ($values['ui_language'] === $language ? ' selected' : '') . '>'
                . self::e($this->t('language.' . $language)) . '</option>';
        }
        $domainModes = '';
        foreach (Settings::DOMAIN_MODES as $mode) {
            $domainModes .= $this->choice('domain_mode', $mode, $values['domain_mode'] === $mode, $mode === 'account' ? 'server' : 'search',
                'settings.domain_mode.' . $mode, 'settings.domain_mode.' . $mode . '_help', '');
        }
        $defaults = ($catalogErrorKey !== null ? $this->notice('warning', self::e($this->t($catalogErrorKey)), 'alert-circle', 'status') : '')
            . '<form method="post" action="' . self::e($this->url()) . '">' . $this->csrfField()
            . '<input type="hidden" name="action" value="save_defaults">'
            . '<div class="m-group"><h3>' . self::e($this->t('settings.group.destination')) . '</h3>' . $this->destinationFields($catalog, $values, $errors) . '</div>'
            . '<div class="m-group">' . $this->commercialFields($values, $errors) . '</div>'
            . '<div class="m-group">' . $this->field('native_product_id', 'settings.native_product_id', '<input class="m-input" id="native_product_id" name="native_product_id" inputmode="numeric" required value="'
                . self::e($values['native_product_id']) . '">', $errors, 'settings.native_product_id_help', ' m-narrow')
            . '<fieldset class="m-fieldset"><legend class="m-legend">' . self::e($this->t('settings.domain_mode')) . '</legend><div class="m-choices m-two">' . $domainModes . '</div>'
            . $this->errorFor($errors, 'domain_mode') . '</fieldset></div>'
            . '<div class="m-group"><h3>' . self::e($this->t('settings.group.server')) . '</h3><div class="m-grid-2">'
            . $this->field('concurrency', 'settings.concurrency', $this->select('<select id="concurrency" name="concurrency">' . $concurrency . '</select>'), $errors, 'settings.concurrency_help')
            . $this->field('ui_language', 'settings.ui_language', $this->select('<select id="ui_language" name="ui_language">' . $languages . '</select>'), $errors, null)
            . '</div>'
            . $this->field('work_dir', 'settings.work_dir', '<input class="m-input m-mono" id="work_dir" name="work_dir" type="text" required spellcheck="false" value="' . self::e($values['work_dir']) . '"'
                . (isset($errors['work_dir']) ? ' aria-invalid="true"' : '') . '>', $errors, 'settings.work_dir_help')
            . '</div><div class="m-form-actions"><button class="m-btn m-primary" type="submit"' . $this->busy('settings.busy.save') . '>' . $this->icon('check') . self::e($this->t('settings.save_defaults')) . '</button>'
            . '<button class="m-btn" type="submit" form="bcpm-refresh-catalog"' . $this->busy('settings.busy.refresh') . '>' . $this->icon('refresh') . self::e($this->t('settings.refresh_catalog')) . '</button></div></form>'
            . '<form id="bcpm-refresh-catalog" method="post" action="' . self::e($this->url()) . '">' . $this->csrfField()
            . '<input type="hidden" name="action" value="refresh_catalog"></form>';
        return $html . $this->section('layers', $this->t('settings.defaults'), $this->t('settings.defaults_hint'), $defaults)
            . $this->clients($settings, $errors, $input, $apiBrandName) . $this->surfaces($surfaces) . $this->about($settings, $dataDir) . '</div>';
    }

    /**
     * Where BladeCP shows up: the WHM home page Favorites and the banner at
     * the top of the cPanel home page of the accounts.
     *
     * @param array{pinned?:?bool,banner?:bool,include?:string,include_path?:string} $surfaces
     */
    private function surfaces(array $surfaces): string
    {
        if ($surfaces === []) {
            return '';
        }
        $pinned = $surfaces['pinned'] ?? null;
        $pinState = match ($pinned) {
            true => '<span class="m-pill m-t-success">' . $this->icon('check-circle', 'm-pill-ic') . '<span>' . self::e($this->t('settings.pin.on')) . '</span></span>',
            false => '<span class="m-pill m-t-neutral">' . $this->icon('star', 'm-pill-ic') . '<span>' . self::e($this->t('settings.pin.off')) . '</span></span>',
            default => '<span class="m-pill m-t-warning">' . $this->icon('alert-circle', 'm-pill-ic') . '<span>' . self::e($this->t('settings.pin.unknown')) . '</span></span>',
        };
        $pinAction = $pinned === true
            ? $this->pinForm('unpin', 'settings', 'settings.pin.unpin', 'settings.pin.busy_unpin', '', 'pin-off')
            : $this->pinForm('pin', 'settings', 'migrations.pin.pin', 'migrations.pin.busy', 'm-primary', 'pin');
        $whm = '<div class="m-opt"><span class="m-opt-ic">' . $this->icon('star') . '</span><div class="m-opt-body"><h3>' . self::e($this->t('settings.pin.label')) . '</h3>'
            . '<p class="m-help">' . self::e($this->t('settings.pin.help')) . '</p><div class="m-opt-state">' . $pinState . '</div></div>'
            . '<div class="m-opt-action">' . $pinAction . '</div></div>';

        $on = ($surfaces['banner'] ?? true) === true;
        $include = (string) ($surfaces['include'] ?? CpanelBanner::ABSENT);
        $note = '';
        if ($on && $include === CpanelBanner::FOREIGN) {
            $file = (string) ($surfaces['include_path'] ?? CpanelBanner::FILE);
            $note = $this->notice('warning', self::emphasize($this->t('settings.banner.foreign', ['file' => $file]), $file, 'code'), 'triangle', 'status');
        } elseif ($on && $include !== CpanelBanner::INSTALLED) {
            $note = $this->notice('warning', self::e($this->t('settings.banner.absent')), 'alert-circle', 'status')
                . '<form method="post" action="' . self::e($this->url()) . '" class="m-inline">' . $this->csrfField()
                . '<input type="hidden" name="action" value="cpanel_banner"><input type="hidden" name="banner" value="on">'
                . '<button class="m-btn m-sm" type="submit"' . $this->busy('settings.banner.busy') . '>' . $this->icon('refresh') . self::e($this->t('settings.banner.install')) . '</button></form>';
        }
        $switch = '<form method="post" action="' . self::e($this->url()) . '" class="m-inline">' . $this->csrfField()
            . '<input type="hidden" name="action" value="cpanel_banner"><input type="hidden" name="banner" value="' . ($on ? 'off' : 'on') . '">'
            . '<button class="m-switch" type="submit" role="switch" aria-checked="' . ($on ? 'true' : 'false') . '" aria-labelledby="bcpm-banner-label"' . $this->busy('settings.banner.busy') . '>'
            . '<span class="m-switch-track" aria-hidden="true"><span class="m-switch-thumb"></span></span>'
            . '<span class="m-switch-text">' . self::e($this->t($on ? 'settings.banner.state_on' : 'settings.banner.state_off')) . '</span></button></form>';
        $banner = '<div class="m-opt"><span class="m-opt-ic">' . $this->icon('megaphone') . '</span><div class="m-opt-body"><h3 id="bcpm-banner-label">' . self::e($this->t('settings.banner.label')) . '</h3>'
            . '<p class="m-help">' . self::e($this->t($on ? 'settings.banner.help_on' : 'settings.banner.help_off')) . '</p>' . $note . '</div>'
            . '<div class="m-opt-action">' . $switch . '</div></div>';
        return $this->section('sparkles', $this->t('settings.surfaces'), $this->t('settings.surfaces_hint'), '<div class="m-opts">' . $whm . $banner . '</div>');
    }

    /**
     * What the cPanel app shows to the account owners: the partner name, as
     * a reference, and the support link.
     *
     * @param array<string,string> $errors
     * @param array<string,mixed> $input
     */
    private function clients(Settings $settings, array $errors, array $input, ?string $apiBrandName): string
    {
        $name = (string) ($input['client_brand_name'] ?? $settings->clientBrandName());
        $support = (string) ($input['client_support_url'] ?? $settings->clientSupportUrl());
        $brandHelp = $apiBrandName !== null
            ? '<p class="m-help">' . self::emphasize($this->t('settings.clients.brand_help_api', ['name' => $apiBrandName]), $apiBrandName, 'strong') . '</p>'
            : '<p class="m-help">' . self::e($this->t('settings.clients.brand_help_none')) . '</p>';
        $body = '<p class="m-note">' . $this->icon('info') . '<span>' . self::e($this->t('settings.clients.where')) . '</span></p>'
            . '<form method="post" action="' . self::e($this->url()) . '" class="m-clients">' . $this->csrfField()
            . '<input type="hidden" name="action" value="save_clients"><div class="m-grid-2">'
            . '<div class="m-field"><label for="client_brand_name">' . self::e($this->t('settings.clients.brand')) . '</label>'
            . '<input class="m-input" id="client_brand_name" name="client_brand_name" maxlength="60" autocomplete="off" placeholder="' . self::e($apiBrandName ?? '') . '" value="'
            . self::e($name) . '"' . (isset($errors['client_brand_name']) ? ' aria-invalid="true"' : '') . '>' . $this->errorFor($errors, 'client_brand_name') . $brandHelp . '</div>'
            . $this->field('client_support_url', 'settings.clients.support', '<input class="m-input" id="client_support_url" name="client_support_url" maxlength="300" spellcheck="false"'
                . ' placeholder="https://" value="' . self::e($support) . '"' . (isset($errors['client_support_url']) ? ' aria-invalid="true"' : '') . '>', $errors, 'settings.clients.support_help')
            . '</div><div class="m-form-actions"><button class="m-btn m-primary" type="submit"' . $this->busy('settings.busy.clients') . '>' . $this->icon('check')
            . self::e($this->t('settings.clients.save')) . '</button></div></form>';
        return $this->section('users', $this->t('settings.clients'), $this->t('settings.clients_hint'), $body);
    }

    private function about(Settings $settings, string $dataDir): string
    {
        $fact = static fn (string $label, string $value): string => '<div><dt>' . self::e($label) . '</dt><dd>' . $value . '</dd></div>';
        return $this->section('info', $this->t('settings.about'), '', '<dl class="m-facts">'
            . $fact($this->t('settings.about.version'), self::e(BLADECP_MIGRATOR_VERSION))
            . $fact($this->t('settings.about.installation'), '<code>' . self::e($settings->installationId()) . '</code>')
            . $fact($this->t('settings.about.data_dir'), '<code>' . self::e($dataDir) . '</code>')
            . $fact($this->t('settings.about.log'), '<code>' . self::e($dataDir . '/logs/migrator.log') . '</code>')
            . '</dl>');
    }

    /**
     * @param array{plans:list<array<string,mixed>>,locations:array<int,list<array<string,mixed>>>}|null $catalog
     * @param array<string,mixed> $values
     * @param array<string,string> $errors
     */
    private function destinationFields(?array $catalog, array $values, array $errors): string
    {
        $plans = '<option value="">' . self::e($this->t('settings.choose')) . '</option>';
        $locations = '<option value="">' . self::e($this->t('settings.choose')) . '</option>';
        foreach ($catalog['plans'] ?? [] as $plan) {
            $id = (int) $plan['id'];
            $label = $plan['name'] . ($plan['product'] !== '' ? ' (' . $plan['product'] . ')' : '');
            $plans .= '<option value="' . $id . '"' . ((int) ($values['plan_id'] ?? 0) === $id ? ' selected' : '') . '>' . self::e($label) . '</option>';
            foreach ($catalog['locations'][$id] ?? [] as $location) {
                $locationId = (int) $location['id'];
                $text = trim($location['city'] . ', ' . $location['country'], ', ') . ($location['region'] !== '' ? ' (' . $location['region'] . ')' : '');
                $locations .= '<option value="' . $locationId . '" data-plan="' . $id . '"'
                    . ((int) ($values['location_id'] ?? 0) === $locationId && (int) ($values['plan_id'] ?? 0) === $id ? ' selected' : '') . '>'
                    . self::e($text) . '</option>';
            }
        }
        $empty = $catalog !== null && ($catalog['plans'] ?? []) === []
            ? $this->notice('warning', self::e($this->t('settings.no_plans')), 'alert-circle', 'status') : '';
        return $empty . '<div class="m-grid-2">'
            . $this->field('plan_id', 'settings.plan', $this->select('<select id="plan_id" name="plan_id" required data-plan-select' . (isset($errors['plan_id']) ? ' aria-invalid="true"' : '') . '>'
                . $plans . '</select>'), $errors, 'settings.plan_help')
            . $this->field('location_id', 'settings.location', $this->select('<select id="location_id" name="location_id" required data-location-select'
                . (isset($errors['location_id']) ? ' aria-invalid="true"' : '') . '>' . $locations . '</select>'), $errors, null)
            . '</div>';
    }

    /**
     * @param array<string,mixed> $values
     * @param array<string,string> $errors
     */
    private function commercialFields(array $values, array $errors): string
    {
        $mode = (string) ($values['commercial_mode'] ?? 'trial');
        $days = fn (string $name, string $labelKey, mixed $value, string $unitKey): string => '<span class="m-days"><input class="m-input m-input-sm" inputmode="numeric" name="' . $name
            . '" value="' . self::e($value) . '" aria-label="' . self::e($this->t($labelKey)) . '"' . (isset($errors[$name]) ? ' aria-invalid="true"' : '') . '>'
            . '<span>' . self::e($this->t($unitKey)) . '</span></span>';
        $options = [
            'trial' => ['gift', $days('trial_days', 'settings.trial_days', $values['trial_days'] ?? 14, 'settings.days')],
            'payment_before' => ['card', ''],
            'payment_after' => ['receipt', $days('payment_days', 'settings.payment_days', $values['payment_days'] ?? 7, 'settings.days_to_pay')],
        ];
        $choices = '';
        foreach ($options as $value => [$icon, $extra]) {
            $choices .= $this->choice('commercial_mode', $value, $mode === $value, $icon, 'settings.commercial.' . $value, 'settings.commercial.' . $value . '_help', $extra);
        }
        return '<fieldset class="m-fieldset"><legend class="m-legend">' . self::e($this->t('settings.commercial')) . '</legend><div class="m-choices">' . $choices . '</div>'
            . $this->errorFor($errors, 'commercial_mode') . $this->errorFor($errors, 'trial_days') . $this->errorFor($errors, 'payment_days') . '</fieldset>';
    }

    /** Radio choice drawn as a card with an icon. */
    private function choice(string $name, string $value, bool $checked, string $icon, string $titleKey, string $helpKey, string $extra): string
    {
        return '<label class="m-choice"><input type="radio" class="m-radio" name="' . $name . '" value="' . self::e($value) . '"' . ($checked ? ' checked' : '') . '>'
            . '<span class="m-choice-ic">' . $this->icon($icon) . '</span><span class="m-choice-body"><strong>' . self::e($this->t($titleKey)) . '</strong>'
            . '<span class="m-help">' . self::e($this->t($helpKey)) . '</span>' . $extra . '</span></label>';
    }

    private function select(string $select): string
    {
        return '<div class="m-select">' . $select . $this->icon('chevron') . '</div>';
    }

    /** @param array<string,string> $errors */
    private function field(string $name, string $labelKey, string $control, array $errors, ?string $helpKey, string $class = ''): string
    {
        return '<div class="m-field' . $class . '"><label for="' . self::e($name) . '">' . self::e($this->t($labelKey)) . '</label>' . $control
            . $this->errorFor($errors, $name)
            . ($helpKey !== null ? '<p class="m-help">' . self::e($this->t($helpKey)) . '</p>' : '') . '</div>';
    }

    /** @param array<string,string> $errors */
    private function errorFor(array $errors, string $name): string
    {
        return isset($errors[$name]) ? '<p class="m-ferr" role="alert">' . $this->icon('alert-circle') . '<span>' . self::e($this->t($errors[$name])) . '</span></p>' : '';
    }

    public static function bytes(?int $bytes): string
    {
        if ($bytes === null) {
            return '-';
        }
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $value = (float) $bytes;
        $unit = 0;
        while ($value >= 1024 && $unit < count($units) - 1) {
            $value /= 1024;
            $unit++;
        }
        return ($unit === 0 ? (string) (int) $value : number_format($value, 1, '.', '')) . ' ' . $units[$unit];
    }

    /** The A2 stylesheet, inlined in every page (style-src 'unsafe-inline' allows no external file). */
    private static function css(): string
    {
        return Ui::stylesheet();
    }

    /**
     * Every query starts at the .bcpm-app container; checked accounts get
     * the is-picked row style. A submitted form (after its confirmation, if
     * any) puts its button in the busy state (spinner and data-busy-label)
     * and ignores further submits; a page restored from the back-forward
     * cache gets its buttons back. Inside the WHM frame the document height
     * and the current tab go to the parent page only (postMessage with this
     * page's own origin as the target), so the iframe grows with the content
     * and there is no second scroll bar. After a redirect with a notice the
     * first message also asks the parent to bring the frame top into view
     * (reveal), the standalone page scrolls to the notice itself, and the
     * notice parameters leave the URL so a reload does not repeat them.
     * Buttons with data-copy put their value on the clipboard (with a
     * selection fallback) and show data-copied for a moment.
     */
    public static function script(): string
    {
        return <<<'JS'
(function(){
var app=document.querySelector('.bcpm-app')||document;
var reduce=function(){return !!(window.matchMedia&&window.matchMedia('(prefers-reduced-motion: reduce)').matches);};
var icon=app.querySelector('template[data-busy-icon]');
var setBusy=function(b){var idle=document.createDocumentFragment();while(b.firstChild){idle.appendChild(b.firstChild);}b.bcpmIdle=idle;
if(icon){b.appendChild(icon.content.cloneNode(true));}b.appendChild(document.createTextNode(b.getAttribute('data-busy-label')));b.setAttribute('aria-busy','true');setTimeout(function(){b.disabled=true;},0);};
app.addEventListener('submit',function(e){var f=e.target;if(f.hasAttribute('data-busy')){e.preventDefault();return;}
var question=f.getAttribute('data-confirm');if(question&&!window.confirm(question)){e.preventDefault();return;}
if(f.getAttribute('target')==='_blank'){return;}
f.setAttribute('data-busy','1');var b=e.submitter||f.querySelector('button[type=submit]')||(f.id?app.querySelector('button[form="'+f.id+'"]'):null);
if(b&&b.hasAttribute('data-busy-label')&&b.getAttribute('aria-busy')!=='true'){setBusy(b);}});
var list=app.querySelector('form[data-accounts]');
if(list){var boxes=function(){return Array.prototype.slice.call(list.querySelectorAll('tbody input[type=checkbox]:not([disabled])'));};
var counter=list.querySelector('[data-selected-count]');var button=list.querySelector('[data-migrate]');var all=list.querySelector('[data-select-all]');
var update=function(){var n=0;boxes().forEach(function(b){var row=b.closest('tr');if(b.checked){n++;}if(row){row.classList.toggle('is-picked',b.checked);}});if(counter){counter.textContent=counter.getAttribute('data-template').replace('{count}',n);}if(button&&!button.hasAttribute('data-locked')){button.disabled=n===0;}};
if(button&&button.disabled){button.setAttribute('data-locked','1');}
list.addEventListener('change',update);
if(all){all.addEventListener('change',function(){boxes().forEach(function(b){if(b.closest('tr').style.display!=='none'){b.checked=all.checked;}});update();});}
var filter=list.querySelector('[data-filter]');if(filter){filter.addEventListener('input',function(){var q=filter.value.toLowerCase().trim();list.querySelectorAll('tbody tr').forEach(function(r){r.style.display=!q||r.getAttribute('data-search').indexOf(q)!==-1?'':'none';});});}
update();}
window.addEventListener('pageshow',function(e){if(!e.persisted){return;}app.querySelectorAll('[aria-busy="true"]').forEach(function(b){b.textContent='';if(b.bcpmIdle){b.appendChild(b.bcpmIdle);b.bcpmIdle=null;}b.removeAttribute('aria-busy');b.disabled=false;});
app.querySelectorAll('form[data-busy]').forEach(function(f){f.removeAttribute('data-busy');});if(list&&update){update();}});
var flash=app.querySelector('[data-flash]');
if(flash&&window.history&&window.history.replaceState&&window.URLSearchParams){var q=new URLSearchParams(window.location.search);q.delete('notice');q.delete('ok');q.delete('n');
var rest=q.toString();window.history.replaceState(window.history.state,'',window.location.pathname+(rest?'?'+rest:''));}
var plan=app.querySelector('[data-plan-select]');var loc=app.querySelector('[data-location-select]');
if(plan&&loc){var sync=function(){var p=plan.value;var keep=false;Array.prototype.forEach.call(loc.options,function(o){if(!o.value){return;}var show=o.getAttribute('data-plan')===p;o.hidden=!show;o.disabled=!show;if(show&&o.selected){keep=true;}});
if(!keep){loc.value='';var first=Array.prototype.find.call(loc.options,function(o){return o.value&&!o.disabled;});if(first){loc.value=first.value;}}};plan.addEventListener('change',sync);sync();}
var auto=app.querySelector('[data-autorefresh]');
if(auto){var secs=parseInt(auto.getAttribute('data-autorefresh'),10)||30;setInterval(function(){var a=document.activeElement;var busy=a&&(a.tagName==='INPUT'||a.tagName==='SELECT'||a.tagName==='TEXTAREA');if(!busy&&!app.querySelector('details[open]')){window.location.reload();}},secs*1000);}
if(window.parent!==window&&app.getBoundingClientRect){var reveal=!!flash;var report=function(){var m={source:'bcpm-frame',height:Math.ceil(app.getBoundingClientRect().height),page:app.getAttribute('data-page')||''};
if(reveal){m.reveal=true;reveal=false;}window.parent.postMessage(m,window.location.origin);};
if(window.ResizeObserver){new ResizeObserver(report).observe(app);}window.addEventListener('load',report);report();}
else if(flash){var box=flash.getBoundingClientRect();if(box.top<0||box.bottom>window.innerHeight){flash.scrollIntoView({block:'center',behavior:reduce()?'auto':'smooth'});}}
app.addEventListener('click',function(e){var b=e.target&&e.target.closest?e.target.closest('[data-copy]'):null;if(!b||!app.contains(b)){return;}
var label=b.querySelector('span');var done=function(){if(!label){return;}var idle=label.textContent;label.textContent=b.getAttribute('data-copied');b.classList.add('is-copied');
setTimeout(function(){label.textContent=idle;b.classList.remove('is-copied');},1800);};
var select=function(){var code=b.parentNode?b.parentNode.querySelector('code'):null;if(!code||!window.getSelection){return;}var r=document.createRange();r.selectNodeContents(code);var s=window.getSelection();s.removeAllRanges();s.addRange(r);
try{if(document.execCommand('copy')){done();}}catch(x){}};
var value=b.getAttribute('data-copy')||'';
if(navigator.clipboard&&navigator.clipboard.writeText){navigator.clipboard.writeText(value).then(done,select);}else{select();}});
})();
JS;
    }

    /**
     * Script of the outer WHM page. It accepts only messages whose origin is
     * this page's origin, whose sender is the plugin iframe and whose data has
     * the frame's format, then sets the iframe height, puts the current tab in
     * the address bar and, when the frame shows a new notice (reveal) while
     * its top is above the visible area, scrolls the WHM page to the frame
     * top (smoothly, or at once with reduced motion). It reads and writes no
     * plugin data.
     *
     * @param list<string> $pages tabs the frame may report (names of [a-z]+)
     */
    public static function shellScript(array $pages = ['migrations', 'accounts', 'settings']): string
    {
        $list = implode(',', array_map(static fn (string $page): string => "'" . preg_replace('/[^a-z]/', '', $page) . "'", $pages));
        return str_replace('@@PAGES@@', $list, <<<'JS'
(function(){
var frame=document.getElementById('bcpm-frame');if(!frame){return;}
var pages=[@@PAGES@@];
window.addEventListener('message',function(e){
if(e.origin!==window.location.origin||e.source!==frame.contentWindow){return;}
var d=e.data;if(!d||typeof d!=='object'||d.source!=='bcpm-frame'){return;}
if(typeof d.height==='number'&&isFinite(d.height)&&d.height>0&&d.height<=100000){frame.style.height=Math.ceil(d.height)+'px';}
if(typeof d.page==='string'&&pages.indexOf(d.page)!==-1&&window.history&&window.history.replaceState){var target=window.location.pathname+'?page='+d.page;
if(window.location.pathname+window.location.search!==target){window.history.replaceState(window.history.state,'',target);}}
if(d.reveal===true&&frame.getBoundingClientRect().top<0){var still=!!(window.matchMedia&&window.matchMedia('(prefers-reduced-motion: reduce)').matches);
frame.scrollIntoView({block:'start',behavior:still?'auto':'smooth'});}
});
})();
JS);
    }
}
