<?php

declare(strict_types=1);

namespace BladeCP\CpanelMigrator\Client;

use BladeCP\CpanelMigrator\Support\I18n;
use BladeCP\CpanelMigrator\Web\Csrf;
use BladeCP\CpanelMigrator\Web\Ui;
use BladeCP\CpanelMigrator\Web\View;

/**
 * Screens of the cPanel app ("Migrate to BladeCP"), in the approved A2 look
 * of the WHM plugin: the brand band with the Horizon, the BladeCP wordmark
 * and the tabs on its straight base, the light sheet with the aurora, cards
 * with icons and the six-segment progress bar driven only by the real state.
 * The hosting partner shows as a discreet reference ("Partner: <name>"), with
 * the name saved in WHM or the partner brand from the API.
 *
 * Every dynamic value goes through Ui::e(); the only script is the
 * nonce-tagged block of the WHM plugin plus the copy buttons of the DNS
 * records.
 */
final class ClientView
{
    public const FRAME_ID = View::FRAME_ID;
    /** State => [tone, icon]. */
    public const STATE_STYLE = [
        'queued' => ['neutral', 'hourglass'],
        'retrying' => ['warning', 'rotate'],
        'working' => ['info', 'loader'],
        'cancelling' => ['warning', 'loader'],
        'review' => ['warning', 'search'],
        'approval' => ['warning', 'eye'],
        'claim' => ['warning', 'user'],
        'provider' => ['warning', 'clock'],
        'complete' => ['success', 'check-circle'],
        'error' => ['danger', 'triangle'],
        'failed' => ['danger', 'x-circle'],
        'cancelled' => ['neutral', 'ban'],
    ];
    /** Icon of each of the seven stages (help page). */
    private const STAGE_ICONS = ['backup' => 'layers', 'upload' => 'upload', 'analysis' => 'search', 'import' => 'server', 'preview' => 'eye', 'done' => 'check-circle'];
    private const FONTS = [400 => 'Inter-Regular', 500 => 'Inter-Medium', 600 => 'Inter-SemiBold', 700 => 'Inter-Bold'];

    public function __construct(
        private readonly I18n $i18n,
        private readonly string $nonce,
        /** @var array<string,string> query that keeps the mode: frame=1, standalone=1 or nothing */
        private readonly array $mode,
        /** Name of the hosting partner, shown as a reference; null hides it. */
        private readonly ?string $brand,
        private readonly ?string $supportUrl
    ) {
    }

    public function t(string $key, array $params = []): string
    {
        return $this->i18n->t($key, $params);
    }

    // ---------------------------------------------------------------- documents

    /**
     * @param array<string,mixed> $status answer of the status function
     * @param array{ok:bool,key:string}|null $notice result of the last action
     */
    public function page(string $page, array $status, ?array $notice): string
    {
        $job = is_array($status['job'] ?? null) ? $status['job'] : null;
        $domain = is_array($status['account'] ?? null) ? (string) ($status['account']['domain'] ?? '') : '';
        $attention = $job !== null && in_array($job['state'] ?? '', JobView::ATTENTION, true);
        $content = match ($page) {
            'history' => $this->history($status),
            'help' => $this->help(),
            default => $this->migration($status),
        };
        $noticeHtml = '';
        if ($notice !== null) {
            $tone = $notice['ok'] ? 'success' : 'danger';
            $noticeHtml = '<div class="m-notice m-flash m-t-' . $tone . '" role="' . ($notice['ok'] ? 'status' : 'alert') . '" data-flash>'
                . Ui::icon($notice['ok'] ? 'check-circle' : 'triangle') . '<p>' . Ui::e($this->t($notice['key'])) . '</p></div>';
        }
        return $this->document($this->header($page, $domain, $attention, true) . '<main class="m-sheet">' . $noticeHtml . $content . '</main>'
            . $this->footer() . '<template data-busy-icon>' . Ui::icon('loader', 'm-spin') . '</template>' . $this->scriptTag(), $page);
    }

    /** Unavailable, too many refreshes or expired page, in the same band and sheet. */
    public function message(string $kind, string $domain = ''): string
    {
        [$icon, $action] = match ($kind) {
            'limited' => ['hourglass', 'reload'],
            'expired' => ['refresh', 'reload'],
            default => ['clock', 'support'],
        };
        $kind = in_array($kind, ['limited', 'expired'], true) ? $kind : 'unavailable';
        $button = $action === 'reload'
            ? '<a class="m-btn m-primary" href="' . Ui::e($this->url(['page' => 'migration'])) . '">' . Ui::icon('refresh') . Ui::e($this->t('client.refresh')) . '</a>'
            : $this->supportLink('m-btn m-primary');
        $card = '<section class="m-card m-empty m-client-empty"><div class="m-empty-art" aria-hidden="true"><span class="m-empty-node m-empty-key">' . Ui::icon($icon) . '</span></div>'
            . '<h1>' . Ui::e($this->t('client.' . $kind . '.title')) . '</h1><p>' . Ui::e($this->t('client.' . $kind . '.text')) . '</p>' . $button . '</section>';
        return $this->document($this->header('migration', $domain, false, false) . '<main class="m-sheet">' . $card . '</main>' . $this->footer() . $this->scriptTag(), 'migration');
    }

    /**
     * Outer cPanel page: the Jupiter header and footer around the iframe
     * with the app. It carries no account data; its script only sizes the
     * iframe and mirrors the tab, like the WHM plugin.
     *
     * If the panel ever refuses to frame the page (its own frame policy),
     * the frame never reports back and the page opens the app alone, still
     * under the strict policy.
     *
     * @param array{0:string,1:string} $chrome
     */
    public static function shell(array $chrome, string $frameUrl, string $title, string $standaloneUrl = '?standalone=1'): string
    {
        $fallback = "(function(){var frame=document.getElementById('" . self::FRAME_ID . "');if(!frame){return;}var heard=false;"
            . "window.addEventListener('message',function(e){if(e.origin===window.location.origin&&e.source===frame.contentWindow){heard=true;}});"
            . 'setTimeout(function(){if(!heard){window.location.replace(' . json_encode($standaloneUrl, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ');}},10000);})();';
        return $chrome[0] . '<iframe id="' . self::FRAME_ID . '" src="' . Ui::e($frameUrl) . '" title="' . Ui::e($title) . '"'
            . ' style="display:block;width:100%;height:760px;border:0"></iframe>'
            . '<script>' . ClientApp::shellScript() . $fallback . '</script>' . $chrome[1];
    }

    private function document(string $inner, string $page): string
    {
        $body = isset($this->mode['frame']) ? 'body{margin:0}'
            : 'body{margin:0;padding:18px 24px 40px;background:#e9edf2}@media (max-width:900px){body{padding:14px 16px 32px}}';
        $fonts = '';
        foreach (self::FONTS as $weight => $file) {
            $fonts .= '@font-face{font-family:"BCPM Inter";src:url("fonts/' . $file . '.woff2?v=' . rawurlencode(BLADECP_MIGRATOR_VERSION) . '") format("woff2");'
                . 'font-weight:' . $weight . ';font-display:swap}';
        }
        return '<!doctype html><html lang="' . Ui::e($this->i18n->htmlLang()) . '"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . Ui::e($this->t('client.title')) . '</title><link rel="icon" type="image/svg+xml" href="' . Ui::e(Ui::favicon()) . '">'
            . '<style>' . $body . $fonts . Ui::stylesheet() . '</style></head><body>'
            . '<div class="bcpm-app bcpm-client" data-page="' . Ui::e($page) . '">' . $inner . '</div></body></html>';
    }

    private function scriptTag(): string
    {
        return '<script nonce="' . Ui::e($this->nonce) . '">' . View::script() . '</script>';
    }

    // ---------------------------------------------------------------- band and footer

    private function header(string $active, string $domain, bool $attention, bool $tabs): string
    {
        $nav = '';
        foreach (['migration' => ['client.nav.migration', 'transfer'], 'history' => ['client.nav.history', 'history'], 'help' => ['client.nav.help', 'help-circle']] as $page => [$label, $icon]) {
            $badge = $page === 'migration' && $attention
                ? '<span class="m-tab-n" title="' . Ui::e($this->t('client.tab_attention')) . '">1</span>' : '';
            $nav .= '<a href="' . Ui::e($this->url(['page' => $page])) . '"' . ($page === $active ? ' aria-current="page"' : '') . '>'
                . Ui::icon($icon) . '<span>' . Ui::e($this->t($label)) . '</span>' . $badge . '</a>';
        }
        // The partner is a reference next to the account domain.
        $chips = ($this->brand !== null
                ? '<span class="m-conn m-chip m-partner"><span class="m-partner-l">' . Ui::e($this->t('client.partner')) . '</span>'
                    . '<span class="m-chip-domain">' . Ui::e($this->brand) . '</span></span>' : '')
            . ($domain !== ''
                ? '<span class="m-conn m-chip"><span class="m-conn-dot" aria-hidden="true"></span><span class="m-sr">' . Ui::e($this->t('client.chip_label')) . ': </span>'
                    . '<span class="m-chip-domain">' . Ui::e($domain) . '</span></span>' : '');
        $chip = $chips !== '' ? '<div class="m-band-meta">' . $chips . '</div>' : '';
        return '<header class="m-band"><div class="m-hz" aria-hidden="true"><span class="m-hz-atmo"></span><span class="m-hz-planet"></span>'
            . '<span class="m-hz-rimwrap"><span class="m-hz-rim"></span></span><span class="m-hz-sun"></span></div>'
            . '<div class="m-band-row"><a class="m-brand" href="' . Ui::e($this->url(['page' => 'migration'])) . '">'
            . Ui::logo() . '<span class="m-brand-sep" aria-hidden="true"></span><span class="m-brand-name">' . Ui::e($this->t('client.title')) . '</span></a>' . $chip . '</div>'
            . '<p class="m-tagline">' . Ui::e($this->t('client.tagline')) . '</p>'
            . ($tabs ? '<nav class="m-tabs" aria-label="' . Ui::e($this->t('client.nav.label')) . '">' . $nav . '</nav>' : '<div class="m-tabs" aria-hidden="true"></div>')
            . '</header>';
    }

    private function footer(): string
    {
        return '<footer class="m-foot">' . Ui::mark() . '<span>' . Ui::e($this->t('client.footer')) . '</span>'
            . $this->supportLink('m-link m-foot-link') . '</footer>';
    }

    // ---------------------------------------------------------------- migration tab

    /** @param array<string,mixed> $status */
    private function migration(array $status): string
    {
        $job = is_array($status['job'] ?? null) ? $status['job'] : null;
        $csrf = is_string($status['csrf'] ?? null) && preg_match('/^[a-f0-9]{64}$/D', $status['csrf']) === 1 ? $status['csrf'] : null;
        if ($job === null || in_array($job['state'] ?? '', ['failed', 'cancelled'], true)) {
            return $this->start($status, $job, $csrf);
        }
        if (($job['state'] ?? '') === 'complete') {
            return $this->complete($job);
        }
        return $this->track($job, $csrf);
    }

    /**
     * Start screen: what happens, where the site goes and the start form.
     *
     * @param array<string,mixed> $status
     * @param array<string,mixed>|null $previous last migration, when it failed or was cancelled
     */
    private function start(array $status, ?array $previous, ?string $csrf): string
    {
        $account = is_array($status['account'] ?? null) ? $status['account'] : [];
        $domain = (string) ($account['domain'] ?? '');
        $title = $this->t('client.start.title', ['domain' => $domain]);
        $html = '';
        if ($previous !== null) {
            $failed = ($previous['state'] ?? '') === 'failed';
            $html .= $this->notice($failed ? 'warning' : 'neutral', Ui::e($this->t($failed ? 'client.start.again_failed' : 'client.start.again_cancelled')), $failed ? 'alert-circle' : 'info', 'status');
        }
        $canStart = $csrf !== null && !empty($status['can_start']);
        $destination = is_array($status['destination'] ?? null) ? $status['destination'] : [];
        $facts = '';
        if ($this->brand !== null) {
            $facts .= $this->fact('users', $this->t('client.partner'), $this->brand);
        }
        if (is_string($destination['plan'] ?? null)) {
            $facts .= $this->fact('layers', $this->t('client.start.plan'), $destination['plan']);
        }
        if (is_string($destination['location'] ?? null)) {
            $facts .= $this->fact('map-pin', $this->t('client.start.location'), $destination['location']);
        }
        $mode = in_array($destination['mode'] ?? null, ['trial', 'payment_before', 'payment_after'], true) ? $destination['mode'] : 'trial';
        $facts .= $this->fact(['trial' => 'gift', 'payment_before' => 'card', 'payment_after' => 'receipt'][$mode], $this->t('client.start.terms'),
            $this->t('client.start.terms.' . $mode, ['days' => (int) ($destination['days'] ?? 7)]));
        $form = '<form method="post" action="' . Ui::e($this->url(['page' => 'migration'])) . '" class="m-hero-side"'
            . ' data-confirm="' . Ui::e($this->t('client.start.confirm', ['domain' => $domain])) . '">'
            . ($canStart ? $this->csrfField((string) $csrf) : '') . '<input type="hidden" name="action" value="start">'
            . '<h2 class="m-hero-side-title">' . Ui::e($this->t('client.start.form_title')) . '</h2>'
            . '<p class="m-help">' . Ui::e($this->t('client.start.form_hint')) . '</p>'
            . '<ul class="m-dest">' . $facts . '</ul>'
            . '<button class="m-btn m-primary m-lg m-block" type="submit"' . $this->busy('client.start.busy') . ($canStart ? '' : ' disabled') . '>'
            . Ui::icon('rocket') . Ui::e($this->t('client.start.button')) . '</button>'
            . ($csrf === null ? '<p class="m-note">' . Ui::icon('info') . '<span>' . Ui::e($this->t('client.session_readonly')) . '</span></p>' : '')
            . '</form>';
        $main = '<div class="m-hero-main"><span class="m-kicker">' . Ui::icon('sparkles') . '<span>' . Ui::e($this->t('client.start.kicker')) . '</span></span>'
            . '<h1 class="m-hero-title">' . Ui::e($title) . '</h1><p class="m-hero-text">' . Ui::e($this->t('client.start.text')) . '</p>'
            . '<div class="m-route"><span class="m-route-node"><span class="m-route-ic" aria-hidden="true">' . Ui::icon('server') . '</span><span>'
            . '<span class="m-route-l">' . Ui::e($this->t('client.route.from')) . '</span><strong>' . Ui::e($domain) . '</strong></span></span>'
            . '<span class="m-route-line" aria-hidden="true"></span><span class="m-route-node is-dest"><span class="m-route-ic" aria-hidden="true">' . Ui::mark('m-route-mark') . '</span><span>'
            . '<span class="m-route-l">' . Ui::e($this->t('client.route.to')) . '</span><strong>BladeCP</strong></span></span></div>'
            . $this->path(0, null, 'info', false, $this->t('client.start.path')) . '</div>';
        $features = '';
        foreach (['online' => 'shield', 'preview' => 'eye', 'control' => 'globe'] as $key => $icon) {
            $features .= '<article class="m-card m-feat"><span class="m-tile">' . Ui::icon($icon) . '</span><div><h2>' . Ui::e($this->t('client.feat.' . $key)) . '</h2>'
                . '<p>' . Ui::e($this->t('client.feat.' . $key . '_text')) . '</p></div></article>';
        }
        return $html . '<section class="m-card m-hero">' . $main . $form . '</section><div class="m-feats">' . $features . '</div>';
    }

    /**
     * Migration in progress: status, the seven segments with their names
     * and the card of what the owner can do now.
     *
     * @param array<string,mixed> $job
     */
    private function track(array $job, ?string $csrf): string
    {
        $state = (string) $job['state'];
        [$tone, $icon] = self::STATE_STYLE[$state] ?? ['info', 'loader'];
        $domain = (string) ($job['domain'] ?? '');
        $stage = is_int($job['stage'] ?? null) ? $job['stage'] : 0;
        $percent = is_int($job['percent'] ?? null) ? $job['percent'] : null;
        $activity = match (true) {
            $state === 'error' && $stage > 0 => $this->t('client.track.stopped_at', ['stage' => $this->stageName($stage)]),
            is_string($job['activity'] ?? null) && in_array($job['activity'], JobView::ACTIVITIES, true) => $this->t('client.activity.' . $job['activity']),
            default => $this->t('client.state.' . $state),
        };
        $step = '';
        if ($stage > 0) {
            $step = $this->t('progress.step', ['step' => $stage, 'total' => count(View::STAGES)]) . ($percent !== null ? ' · ' . $percent . '%' : '');
        }
        $next = in_array($state, ['queued', 'working', 'retrying'], true) && $stage > 0 && $stage < count(View::STAGES)
            ? '<p class="m-track-next">' . Ui::icon('arrow-right') . '<span>' . Ui::e($this->t('client.track.next', ['stage' => $this->stageName($stage + 1)])) . '</span></p>' : '';
        $head = '<div class="m-head"><div><h1 class="m-h1">' . Ui::e($this->t('client.track.title', ['domain' => $domain])) . '</h1>'
            . '<p class="m-sub">' . Ui::e($this->t('client.track.started', ['date' => $this->date((int) ($job['created_at'] ?? 0))])) . '</p></div>'
            . '<div class="m-head-actions"><a class="m-btn" href="' . Ui::e($this->url(['page' => 'migration'])) . '">' . Ui::icon('refresh') . Ui::e($this->t('client.refresh')) . '</a></div></div>';
        $card = '<section class="m-card m-track" data-autorefresh="30" aria-label="' . Ui::e($this->t('client.track.title', ['domain' => $domain])) . '">'
            . '<div class="m-track-top"><span class="m-pill m-t-' . $tone . '">' . Ui::icon($icon, 'm-pill-ic' . ($icon === 'loader' ? ' m-spin' : '')) . '<span>'
            . Ui::e($this->t('client.state.' . $state)) . '</span></span>' . ($step !== '' ? '<span class="m-track-step">' . Ui::e($step) . '</span>' : '') . '</div>'
            . '<p class="m-track-now">' . Ui::e($activity) . '</p>'
            . $this->path($stage, $percent, $tone, false) . $next . '</section>';
        $cancel = '';
        if (in_array('cancel', is_array($job['actions'] ?? null) ? $job['actions'] : [], true)) {
            $cancel = '<div class="m-quiet"><p>' . Ui::e($this->t('client.cancel.hint')) . '</p>'
                . $this->actionForm($job, 'cancel', $csrf, '<button class="m-btn m-sm m-danger" type="submit"' . $this->busy('client.cancel.busy') . ($csrf === null ? ' disabled' : '') . '>'
                    . Ui::icon('x-circle') . Ui::e($this->t('client.cancel.button')) . '</button>', $this->t('client.cancel.confirm', ['domain' => $domain])) . '</div>';
        }
        // "It keeps going on its own" only while that is true.
        $calm = in_array($state, ['queued', 'working', 'retrying', 'cancelling', 'provider'], true)
            ? '<p class="m-hint">' . Ui::icon('info') . Ui::e($this->t('client.track.calm')) . '</p>' : '';
        return $head . $card . $this->actionCard($job, $csrf) . $calm . $cancel;
    }

    /**
     * Card of what can be done now, by state.
     *
     * @param array<string,mixed> $job
     */
    private function actionCard(array $job, ?string $csrf): string
    {
        $state = (string) $job['state'];
        $actions = is_array($job['actions'] ?? null) ? $job['actions'] : [];
        $domain = (string) ($job['domain'] ?? '');
        $readonly = $csrf === null ? '<p class="m-note">' . Ui::icon('info') . '<span>' . Ui::e($this->t('client.session_readonly')) . '</span></p>' : '';
        $error = is_array($job['error'] ?? null) ? $job['error'] : null;
        $code = $error !== null && is_string($error['code'] ?? null)
            ? '<p class="m-code">' . Ui::e($this->t('client.error.code', ['code' => $error['code']])) . '</p>' : '';
        switch ($state) {
            case 'review':
                if (!in_array('review', $actions, true)) {
                    return '';
                }
                $field = '<div class="m-inline m-domain-row"><label class="m-sr" for="domain">' . Ui::e($this->t('client.review.label')) . '</label>'
                    . '<input class="m-input" id="domain" name="domain" maxlength="63" required spellcheck="false" autocomplete="off" value="'
                    . Ui::e((string) ($job['suggested_domain'] ?? $domain)) . '"' . ($csrf === null ? ' disabled' : '') . '>'
                    . '<button class="m-btn m-primary" type="submit"' . $this->busy('client.review.busy') . ($csrf === null ? ' disabled' : '') . '>' . Ui::icon('check')
                    . Ui::e($this->t('client.review.button')) . '</button></div>';
                return $this->act('warning', 'search', $this->t('client.review.title'), $this->t('client.review.text'), $this->actionForm($job, 'review', $csrf, $field) . $readonly);
            case 'approval':
                $buttons = '<div class="m-form-actions">' . $this->previewLink($job, 'm-btn')
                    . (in_array('approve', $actions, true) ? $this->actionForm($job, 'approve', $csrf, '<button class="m-btn m-primary" type="submit"'
                        . $this->busy('client.approval.busy') . ($csrf === null ? ' disabled' : '') . '>' . Ui::icon('check') . Ui::e($this->t('client.approval.button')) . '</button>',
                        $this->t('client.approval.confirm', ['domain' => $domain])) : '') . '</div>';
                return $this->act('warning', 'eye', $this->t('client.approval.title'), $this->t('client.approval.text'), $buttons . $readonly);
            case 'claim':
                // The account holder confirms the account in the provider client area (new tab).
                // The URL is asked for only on the click and never sits in this page.
                $brand = $this->brand;
                $button = '<form method="post" action="' . Ui::e($this->url(['page' => 'migration'])) . '" class="m-inline" target="_blank" rel="noopener noreferrer">'
                    . ($csrf !== null ? $this->csrfField($csrf) : '') . '<input type="hidden" name="action" value="claim">'
                    . '<input type="hidden" name="job" value="' . Ui::e($job['id'] ?? '') . '">'
                    . '<button class="m-btn m-primary" type="submit"' . ($csrf === null || !in_array('claim', $actions, true) ? ' disabled' : '') . '>' . Ui::icon('external')
                    . Ui::e($this->t('client.claim.button')) . '</button></form>';
                return $this->act('warning', 'user',
                    $brand !== null ? $this->t('client.claim.title', ['brand' => $brand]) : $this->t('client.claim.title_neutral'),
                    $brand !== null ? $this->t('client.claim.text', ['brand' => $brand]) : $this->t('client.claim.text_neutral'),
                    '<div class="m-form-actions">' . $button . '</div><p class="m-note">' . Ui::icon('info') . '<span>' . Ui::e($this->t('client.claim.after')) . '</span></p>' . $readonly);
            case 'provider':
                $body = trim($this->previewLink($job, 'm-btn') . $this->supportLink('m-btn'));
                return $this->act('warning', 'clock', $this->t('client.provider.title'), $this->t('client.provider.text'), $body === '' ? '' : '<div class="m-form-actions">' . $body . '</div>');
            case 'error':
                $key = is_string($error['key'] ?? null) && in_array($error['key'], JobView::ERRORS, true) ? $error['key'] : 'generic';
                return $this->act('danger', 'triangle', $this->t('client.error.title'), $this->t('client.error.' . $key), $code
                    . ($this->supportUrl !== null ? '<div class="m-form-actions">' . $this->supportLink('m-btn') . '</div>' : ''));
            case 'retrying':
                return $this->act('warning', 'rotate', $this->t('client.state.retrying'), $this->t('client.error.retrying', ['minutes' => (int) ($job['retry_minutes'] ?? 1)]), $code);
            default:
                if (!empty($job['preview'])) {
                    return $this->act('info', 'eye', $this->t('client.preview.title'), $this->t('client.preview.text'), '<div class="m-form-actions">' . $this->previewLink($job, 'm-btn') . '</div>');
                }
                return '';
        }
    }

    /**
     * Complete migration: the success card with the six segments done and,
     * while the domain does not point to the new server yet, the optional
     * pointing card. The site already runs on the new hosting either way.
     *
     * @param array<string,mixed> $job
     */
    private function complete(array $job): string
    {
        $domain = (string) ($job['domain'] ?? '');
        $pointing = is_array($job['pointing'] ?? null) ? $job['pointing'] : [];
        $connected = ($pointing['status'] ?? '') === 'connected';
        $open = $connected && $domain !== ''
            ? '<a class="m-btn m-primary m-lg" href="' . Ui::e('https://' . $domain . '/') . '" target="_blank" rel="noopener noreferrer">'
                . Ui::icon('external') . Ui::e($this->t('client.complete.open', ['domain' => $domain])) . '</a>'
            : $this->previewLink($job, 'm-btn m-primary m-lg');
        $pointed = $connected && $domain !== ''
            ? '<p class="m-pointed m-pointed-lg">' . Ui::icon('globe') . '<span>' . Ui::e($this->t('client.point.pointed', ['domain' => $domain])) . '</span></p>' : '';
        $card = '<section class="m-card m-live"><div class="m-live-art" aria-hidden="true"><span class="m-live-ring">' . Ui::icon('check') . '</span></div>'
            . '<h1 class="m-live-title">' . Ui::e($this->t('client.complete.title')) . '</h1>'
            . '<p class="m-live-text">' . Ui::e($this->t($connected ? 'client.complete.text_connected' : 'client.complete.text', ['domain' => $domain])) . '</p>'
            . $this->path(count(View::STAGES), 100, 'success', true) . $pointed
            . ($open !== '' ? '<div class="m-live-actions">' . $open . '</div>' : '')
            . '<p class="m-when">' . Ui::e($this->t('client.complete.finished', ['date' => $this->date((int) ($job['updated_at'] ?? 0))])) . '</p></section>';
        return $card . ($connected ? '' : $this->pointingCard($job, $domain, $pointing));
    }

    /**
     * "Point the domain whenever you want": optional, never a step. The
     * BladeCP nameservers (copy each or both) and, as an alternative, the A
     * (or AAAA, or CNAME) records to the new server, like in WHM, with the
     * Registro.br tip for .br domains.
     *
     * @param array<string,mixed> $job
     * @param array<string,mixed> $pointing
     */
    private function pointingCard(array $job, string $domain, array $pointing): string
    {
        $stalled = ($pointing['status'] ?? '') === 'stalled';
        $records = is_array($pointing['records'] ?? null) ? $pointing['records'] : [];
        $servers = '';
        foreach (View::NAMESERVERS as $index => $server) {
            $servers .= '<div><dt>NS' . ($index + 1) . '</dt><dd><code>' . Ui::e($server) . '</code>' . $this->copyButton($server, true) . '</dd></div>';
        }
        $html = '<div class="m-point-ns-head"><span>' . Ui::e($this->t('client.point.nameservers')) . '</span>'
            . $this->copyButton(implode("\n", View::NAMESERVERS), false, 'client.point.copy_both') . '</div><dl class="m-ns">' . $servers . '</dl>';
        if (str_ends_with($domain, '.br')) {
            $action = $this->t('client.point.br_action');
            $html .= '<p class="m-note m-point-br">' . Ui::icon('info') . '<span>' . str_replace(Ui::e($action), '<strong>' . Ui::e($action) . '</strong>', Ui::e($this->t('client.point.br_hint', ['action' => $action])))
                . ' <a class="m-link" href="https://registro.br" target="_blank" rel="noopener noreferrer">' . Ui::e($this->t('client.point.br_link')) . Ui::icon('external') . '</a></span></p>';
        }
        if ($records === []) {
            $html .= '<p class="m-note">' . Ui::icon('info') . '<span>' . Ui::e($this->t('client.point.no_target')) . '</span></p>';
        } else {
            $col = fn (string $key): string => Ui::e($this->t('client.point.col.' . $key));
            $rows = '';
            foreach ($records as $record) {
                $value = (string) ($record['value'] ?? '');
                $rows .= '<tr><td data-label="' . $col('type') . '"><span class="m-rtype">' . Ui::e($record['type'] ?? '') . '</span></td>'
                    . '<td data-label="' . $col('name') . '"><code class="m-mono">' . Ui::e($record['name'] ?? '') . '</code><span class="m-dom">' . Ui::e($record['host'] ?? '') . '</span></td>'
                    . '<td data-label="' . $col('value') . '"><div class="m-copyrow"><code class="m-mono m-rvalue">' . Ui::e($value) . '</code>'
                    . '<button type="button" class="m-btn m-sm m-copy" data-copy="' . Ui::e($value) . '" data-copied="' . Ui::e($this->t('client.point.copied')) . '">'
                    . Ui::icon('copy') . '<span>' . Ui::e($this->t('client.point.copy')) . '</span></button></div></td></tr>';
            }
            $html .= '<p class="m-point-lead-client m-point-alt-client">' . Ui::e($this->t('client.point.lead')) . '</p>'
                . '<div class="m-records"><table class="m-table m-rtable"><thead><tr><th scope="col">' . $col('type') . '</th><th scope="col">' . $col('name') . '</th>'
                . '<th scope="col">' . $col('value') . '</th></tr></thead><tbody>' . $rows . '</tbody></table></div>';
        }
        $html .= '<p class="m-note">' . Ui::icon('info') . '<span>' . Ui::e($this->t($stalled ? 'client.point.hint_stalled' : 'client.point.hint')) . '</span></p>';
        if (!$stalled && is_int($pointing['deadline'] ?? null)) {
            $html .= '<p class="m-note">' . Ui::icon('clock') . '<span>' . Ui::e($this->t('client.point.check_until', ['date' => $this->date($pointing['deadline'])])) . '</span></p>';
        }
        if ($stalled) {
            $html .= '<p class="m-point-note">' . Ui::icon('info') . '<span>' . Ui::e($this->t('client.point.stalled_note')) . '</span></p>';
        }
        return '<section class="m-card m-sec m-point-card"><header class="m-sec-head"><span class="m-tile">' . Ui::icon('globe') . '</span><div><h2>'
            . Ui::e($this->t('client.point.title')) . '</h2><p>' . Ui::e($this->t('client.point.optional')) . '</p></div></header>'
            . '<div class="m-sec-body">' . $html . '</div></section>';
    }

    // ---------------------------------------------------------------- history and help

    /** @param array<string,mixed> $status */
    private function history(array $status): string
    {
        $html = $this->head($this->t('client.history.title'), $this->t('client.history.hint'));
        $rows = [];
        if (is_array($status['job'] ?? null)) {
            $rows[] = $status['job'];
        }
        foreach (is_array($status['history'] ?? null) ? $status['history'] : [] as $item) {
            if (is_array($item)) {
                $rows[] = $item;
            }
        }
        if ($rows === []) {
            return $html . '<section class="m-card m-empty"><div class="m-empty-art" aria-hidden="true"><span class="m-empty-node m-empty-key">' . Ui::icon('history') . '</span></div>'
                . '<h2>' . Ui::e($this->t('client.history.empty_title')) . '</h2><p>' . Ui::e($this->t('client.history.empty')) . '</p></section>';
        }
        $col = fn (string $key): string => Ui::e($this->t('client.history.col.' . $key));
        $body = '';
        foreach ($rows as $row) {
            $state = in_array($row['state'] ?? '', JobView::STATES, true) ? (string) $row['state'] : 'working';
            [$tone, $icon] = self::STATE_STYLE[$state];
            $body .= '<tr><td class="m-cell-user"><strong>' . Ui::e($row['domain'] ?? '') . '</strong></td>'
                . '<td data-label="' . $col('date') . '">' . Ui::e($this->date((int) ($row['created_at'] ?? 0))) . '</td>'
                . '<td data-label="' . $col('result') . '"><span class="m-pill m-t-' . $tone . '">' . Ui::icon($icon, 'm-pill-ic' . ($icon === 'loader' ? ' m-spin' : ''))
                . '<span>' . Ui::e($this->t('client.state.' . $state)) . '</span></span></td>'
                . '<td data-label="' . $col('updated') . '">' . Ui::e($this->date((int) ($row['updated_at'] ?? 0))) . '</td></tr>';
        }
        return $html . '<section class="m-card m-flush"><div class="m-table-wrap"><table class="m-table m-history"><thead><tr><th scope="col">' . $col('domain') . '</th>'
            . '<th scope="col">' . $col('date') . '</th><th scope="col">' . $col('result') . '</th><th scope="col">' . $col('updated') . '</th></tr></thead><tbody>'
            . $body . '</tbody></table></div></section>';
    }

    private function help(): string
    {
        $html = $this->head($this->t('client.help.title'), $this->t('client.help.hint'));
        $stages = '';
        foreach (View::STAGES as $index => $stage) {
            $stages .= '<article class="m-card m-stage"><div class="m-stage-top"><span class="m-tile">' . Ui::icon(self::STAGE_ICONS[$stage]) . '</span>'
                . '<span class="m-stage-n">' . ($index + 1) . '</span></div><h2>' . Ui::e($this->t('client.stage.' . $stage)) . '</h2>'
                . '<p>' . Ui::e($this->t('client.stage.' . $stage . '_text')) . '</p></article>';
        }
        $faq = '';
        foreach (['client.help.q1' => 'client.help.a1', 'client.help.q2' => 'client.help.a2', 'client.help.q3' => 'client.help.a3'] as $question => $answer) {
            $faq .= '<div><dt>' . Ui::e($this->t($question)) . '</dt><dd>' . Ui::e($this->t($answer)) . '</dd></div>';
        }
        $support = $this->supportUrl !== null ? '<div class="m-form-actions">' . $this->supportLink('m-btn m-primary') . '</div>' : '';
        return $html . '<div class="m-stages">' . $stages . '</div>'
            . '<div class="m-help-grid">' . $this->section('help-circle', $this->t('client.help.faq'), $this->t('client.help.faq_hint'), '<dl class="m-qa">' . $faq . '</dl>')
            . $this->section('life-buoy', $this->t('client.help.support_title'), $this->t($this->supportUrl !== null ? 'client.help.support_text' : 'client.help.no_support'), $support)
            . '</div>';
    }

    // ---------------------------------------------------------------- building blocks

    /**
     * The seven segments with the name of each stage under it. Stage 0 is
     * the start screen (nothing done yet).
     */
    private function path(int $stage, ?int $percent, string $tone, bool $live, string $label = ''): string
    {
        $total = count(View::STAGES);
        $segments = '';
        $steps = '';
        for ($i = 1; $i <= $total; $i++) {
            $class = $live || ($stage > 0 && $i < $stage) ? 'is-done' : ($i === $stage ? 'is-now' : '');
            $segments .= $class !== '' ? '<i class="' . $class . '"></i>' : '<i></i>';
            $steps .= '<li' . ($class !== '' ? ' class="' . $class . '"' : '') . ($i === $stage && !$live ? ' aria-current="step"' : '') . '>'
                . Ui::e($this->stageName($i)) . '</li>';
        }
        $aria = ' aria-hidden="true"';
        if ($stage > 0) {
            $params = ['step' => $stage, 'total' => $total, 'stage' => $this->stageName($stage), 'percent' => (int) $percent];
            $aria = ' role="progressbar" aria-valuemin="1" aria-valuemax="' . $total . '" aria-valuenow="' . $stage . '" aria-valuetext="'
                . Ui::e($this->t($percent === null ? 'progress.value' : 'progress.value_percent', $params)) . '" aria-label="' . Ui::e($this->t('client.title')) . '"';
        }
        return '<div class="m-path m-t-' . $tone . ($live ? ' is-live' : '') . '">' . ($label !== '' ? '<p class="m-path-label">' . Ui::e($label) . '</p>' : '')
            . '<span class="m-segs m-segs-lg"' . $aria . '>' . $segments . '</span><ol class="m-path-steps">' . $steps . '</ol></div>';
    }

    private function stageName(int $stage): string
    {
        $key = View::STAGES[max(1, min(count(View::STAGES), $stage)) - 1];
        return $this->t('client.stage.' . $key);
    }

    /** Owner action card: icon tile in the tone of the state, title, text and the controls. */
    private function act(string $tone, string $icon, string $title, string $text, string $body): string
    {
        return '<section class="m-card m-sec m-act m-t-' . $tone . '"><header class="m-sec-head"><span class="m-tile">' . Ui::icon($icon) . '</span><div><h2>'
            . Ui::e($title) . '</h2><p>' . Ui::e($text) . '</p></div></header>' . ($body !== '' ? '<div class="m-sec-body">' . $body . '</div>' : '') . '</section>';
    }

    private function section(string $icon, string $title, string $sub, string $body): string
    {
        return '<section class="m-card m-sec"><header class="m-sec-head"><span class="m-tile">' . Ui::icon($icon) . '</span><div><h2>' . Ui::e($title) . '</h2>'
            . ($sub !== '' ? '<p>' . Ui::e($sub) . '</p>' : '') . '</div></header>' . ($body !== '' ? '<div class="m-sec-body">' . $body . '</div>' : '') . '</section>';
    }

    private function head(string $title, string $sub): string
    {
        return '<div class="m-head"><div><h1 class="m-h1">' . Ui::e($title) . '</h1>' . ($sub !== '' ? '<p class="m-sub">' . Ui::e($sub) . '</p>' : '') . '</div></div>';
    }

    /** @param string $html already escaped */
    private function notice(string $tone, string $html, string $icon, string $role): string
    {
        return '<div class="m-notice m-t-' . $tone . '" role="' . $role . '">' . Ui::icon($icon) . '<p>' . $html . '</p></div>';
    }

    private function fact(string $icon, string $label, string $value): string
    {
        return '<li><span class="m-dest-ic">' . Ui::icon($icon) . '</span><span class="m-dest-l">' . Ui::e($label) . '</span><strong>' . Ui::e($value) . '</strong></li>';
    }

    /**
     * Form for one owner operation on the job.
     *
     * @param array<string,mixed> $job
     */
    private function actionForm(array $job, string $op, ?string $csrf, string $controls, ?string $confirm = null): string
    {
        return '<form method="post" action="' . Ui::e($this->url(['page' => 'migration'])) . '" class="m-inline"'
            . ($confirm !== null ? ' data-confirm="' . Ui::e($confirm) . '"' : '') . '>'
            . ($csrf !== null ? $this->csrfField($csrf) : '') . '<input type="hidden" name="action" value="act">'
            . '<input type="hidden" name="job" value="' . Ui::e($job['id'] ?? '') . '"><input type="hidden" name="op" value="' . Ui::e($op) . '">'
            . $controls . '</form>';
    }

    private function csrfField(string $token): string
    {
        return '<input type="hidden" name="' . Csrf::FIELD . '" value="' . Ui::e($token) . '">';
    }

    /** "Open preview" through the app (the preview address never sits in the page). */
    private function previewLink(array $job, string $class): string
    {
        if (empty($job['preview'])) {
            return '';
        }
        return '<a class="' . $class . '" href="' . Ui::e($this->url(['go' => 'preview'])) . '" target="_blank" rel="noopener noreferrer">' . Ui::icon('external')
            . Ui::e($this->t('client.preview.open')) . '</a>';
    }

    private function supportLink(string $class): string
    {
        if ($this->supportUrl === null) {
            return '';
        }
        return '<a class="' . $class . '" href="' . Ui::e($this->supportUrl) . '" target="_blank" rel="noopener noreferrer">' . Ui::icon('life-buoy')
            . '<span>' . Ui::e($this->t('client.support')) . '</span></a>';
    }

    /** Copy button for the page script (data-copy); icon only with a hidden label, or with its label. */
    private function copyButton(string $value, bool $iconOnly, string $labelKey = 'client.point.copy'): string
    {
        return '<button type="button" class="m-btn m-sm m-copy' . ($iconOnly ? ' m-copy-ic' : '') . '" data-copy="' . Ui::e($value) . '"'
            . ' data-copied="' . Ui::e($this->t('client.point.copied')) . '"' . ($iconOnly ? ' title="' . Ui::e($this->t('client.point.copy')) . '"' : '') . '>'
            . Ui::icon('copy') . '<span' . ($iconOnly ? ' class="m-sr"' : '') . '>' . Ui::e($this->t($labelKey)) . '</span></button>';
    }

    private function busy(string $key): string
    {
        return ' data-busy-label="' . Ui::e($this->t($key)) . '"';
    }

    private function date(int $timestamp): string
    {
        return $timestamp > 0 ? gmdate($this->t('client.date_format'), $timestamp) . ' UTC' : '-';
    }

    /** App URL relative to the current page; every link and form keeps the mode (inside the cPanel frame, or alone). */
    private function url(array $query): string
    {
        return '?' . http_build_query($this->mode + $query);
    }
}
