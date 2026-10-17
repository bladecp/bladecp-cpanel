<?php

declare(strict_types=1);

namespace BladeCP\CpanelMigrator\Client;

use BladeCP\CpanelMigrator\Support\I18n;
use BladeCP\CpanelMigrator\Web\Csrf;
use BladeCP\CpanelMigrator\Web\Origin;
use BladeCP\CpanelMigrator\Web\Request;
use BladeCP\CpanelMigrator\Web\Response;
use BladeCP\CpanelMigrator\Web\View;

/**
 * Controller of the cPanel app page (site_migration/index.live.php), run by
 * cpsrvd as the logged-in account. It holds no secret and reads no plugin
 * data: everything comes from the root side through the gateway.
 *
 * Like the WHM plugin, a GET without frame=1 answers with the Jupiter
 * header and footer around a same-origin iframe, and the app itself always
 * renders in a complete document under the strict nonce policy. When the
 * chrome is not available the page is that document alone.
 *
 * Form token: the root side signs the account and a session key. The key
 * comes from what cPanel gives this request, never from a request field:
 * the security token (cp_security_token, or the /cpsess path cpsrvd
 * accepted), else the cpsession cookie cPanel set at login. Without any of
 * them the page is read-only.
 */
final class ClientApp
{
    public const PAGES = ['migration', 'history', 'help'];
    private const SHELL_PARAMS = ['page', 'notice', 'ok'];
    private const APP_PATH = '#^(?:/cpsess\d{10})?/frontend/[A-Za-z0-9_-]{1,40}/site_migration/(?:index\.live\.php)?$#D';
    private const NOTICE = '/^client\.notice\.[a-z_]{1,40}$/D';

    private string $nonce;
    private ?I18n $i18n = null;
    private ?string $liveLocale = null;
    private bool $liveLocaleRead = false;

    public function __construct(
        private readonly ?Gateway $gateway,
        private readonly Request $request
    ) {
        $this->nonce = bin2hex(random_bytes(16));
    }

    /** Entry point of index.live.php. */
    public static function run(): void
    {
        $request = Request::fromGlobals();
        try {
            $response = (new self(LiveApiGateway::connect(), $request))->handle();
        } catch (\Throwable) {
            $response = new Response(503, "This page is not available right now.\n", ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'no-store']);
        }
        $response->emit();
    }

    public function handle(): Response
    {
        try {
            if ($this->gateway === null) {
                return $this->message(null, 'unavailable', 503);
            }
            if (!$this->request->isPost() && !$this->framed() && !$this->standalone() && $this->request->query('go') !== 'preview') {
                $shell = $this->shell();
                if ($shell !== null) {
                    return $shell;
                }
            }
            if ($this->request->isPost()) {
                return $this->post();
            }
            if ($this->request->query('go') === 'preview') {
                return $this->preview();
            }
            return $this->get(null);
        } catch (\Throwable) {
            return $this->message(null, 'unavailable', 500);
        }
    }

    /**
     * Session key for the form token, from what cPanel delivered to this
     * request: the security token, else the login cookie. '' when none.
     */
    public function sessionKey(): string
    {
        $token = $this->request->sessionToken();
        if ($token === '') {
            $path = (string) parse_url((string) $this->request->header('REQUEST_URI'), PHP_URL_PATH);
            if (preg_match('#^(/cpsess\d{10})/#', $path, $match) === 1) {
                $token = $match[1];
            }
        }
        if ($token !== '') {
            return hash('sha256', 'cpsess|' . $token);
        }
        $cookie = $this->request->cookies['cpsession'] ?? '';
        if (is_string($cookie) && $cookie !== '' && strlen($cookie) <= 1024 && preg_match('/^[\x21-\x7E]+$/D', $cookie) === 1) {
            return hash('sha256', 'cookie|' . $cookie);
        }
        return '';
    }

    // ---------------------------------------------------------------- requests

    /** Jupiter chrome around the app frame, or null to render the app directly. */
    private function shell(): ?Response
    {
        $i18n = $this->i18n();
        $title = $i18n->t('client.title');
        $chrome = $this->gateway?->chrome($title);
        if ($chrome === null) {
            return null;
        }
        $query = ['frame' => '1'];
        foreach (self::SHELL_PARAMS as $key) {
            $value = $this->request->query($key);
            if ($value !== null && preg_match('/^[a-z0-9_.]{1,80}$/D', $value) === 1) {
                $query[$key] = $value;
            }
        }
        $standalone = ['standalone' => '1'] + array_diff_key($query, ['frame' => true]);
        return Response::whmShell(ClientView::shell($chrome, '?' . http_build_query($query), $title, '?' . http_build_query($standalone)));
    }

    /** @param array{ok:bool,key:string}|null $notice */
    private function get(?array $notice, int $code = 200): Response
    {
        $status = $this->status();
        if ($status === null) {
            return $this->message(null, 'unavailable');
        }
        if (!empty($status['limited'])) {
            return $this->message($status, 'limited', 429);
        }
        if (empty($status['available'])) {
            return $this->message($status, 'unavailable');
        }
        $page = $this->request->query('page');
        $page = in_array($page, self::PAGES, true) ? $page : 'migration';
        $notice ??= $this->noticeFromQuery();
        return $this->page($this->view($status)->page($page, $status, $notice), $code);
    }

    private function post(): Response
    {
        if (!Csrf::sameOrigin($this->request->header('HTTP_ORIGIN'), $this->request->header('HTTP_REFERER'), $this->request->host(), $this->request->header('SERVER_PORT'))) {
            return $this->message(null, 'expired', 403);
        }
        $action = $this->request->inputString('action');
        if (!in_array($action, ['start', 'act', 'claim'], true)) {
            return $this->redirect('client.notice.not_available', false);
        }
        // No owner e-mail is ever sent: the provider billing WHMCS links the owner.
        $args = ['session' => $this->sessionKey(), 'csrf' => $this->request->inputString(Csrf::FIELD)];
        if ($action === 'claim') {
            $args['job'] = $this->request->inputString('job');
        } elseif ($action === 'act') {
            $args['job'] = $this->request->inputString('job');
            $args['op'] = $this->request->inputString('op');
            $args['domain'] = strtolower(trim($this->request->inputString('domain')));
        }
        foreach ($args as $name => $value) {
            $args[$name] = substr($value, 0, 254);
        }
        try {
            $result = $this->gateway?->call($action, $args) ?? [];
        } catch (GatewayError) {
            return $this->redirect('client.notice.unavailable', false);
        }
        // Confirm the account: this tab goes straight to the client area. The URL
        // holds a token, so it only travels in this Location header.
        if ($action === 'claim' && !empty($result['ok']) && is_string($result['url'] ?? null)) {
            $parts = parse_url($result['url']);
            if (is_array($parts) && strtolower((string) ($parts['scheme'] ?? '')) === 'https' && !empty($parts['host'])
                && preg_match('/[\s"<>\\\\]/', $result['url']) !== 1) {
                return Response::away($result['url']);
            }
            $result = ['ok' => false, 'notice' => 'client.notice.failed'];
        }
        $key = is_string($result['notice'] ?? null) && preg_match(self::NOTICE, $result['notice']) === 1 && $this->i18n()->has($result['notice'])
            ? $result['notice'] : 'client.notice.failed';
        // Booleans may come back from the Perl layer as 1 or 0.
        return $this->redirect($key, !empty($result['ok']));
    }

    /** "Open preview": straight to the preview, so its address never sits in the page. */
    private function preview(): Response
    {
        $status = $this->status();
        $url = is_array($status['job'] ?? null) ? ($status['job']['preview_url'] ?? null) : null;
        $parts = is_string($url) ? parse_url($url) : false;
        if (is_array($parts) && in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true) && !empty($parts['host'])
            && preg_match('/[\s"<>\\\\]/', (string) $url) !== 1) {
            return Response::away((string) $url);
        }
        return $this->get(['ok' => false, 'key' => 'client.notice.not_available']);
    }

    // ---------------------------------------------------------------- helpers

    /** @return array<string,mixed>|null */
    private function status(): ?array
    {
        try {
            $status = $this->gateway?->call('status', ['session' => $this->sessionKey()]);
        } catch (GatewayError) {
            return null;
        }
        if (!is_array($status)) {
            return null;
        }
        // The account language from cPanel, else the one the root side read for the account.
        $this->i18n ??= new I18n($this->liveLocale() ?? I18n::normalize(is_string($status['locale'] ?? null) ? $status['locale'] : null) ?? I18n::DEFAULT_LOCALE);
        return $status;
    }

    private function i18n(): I18n
    {
        return $this->i18n ??= new I18n($this->liveLocale() ?? I18n::DEFAULT_LOCALE);
    }

    private function liveLocale(): ?string
    {
        if (!$this->liveLocaleRead) {
            $this->liveLocaleRead = true;
            $this->liveLocale = I18n::normalize($this->gateway?->locale());
        }
        return $this->liveLocale;
    }

    /** @param array<string,mixed>|null $status */
    private function view(?array $status): ClientView
    {
        $brand = is_array($status['brand'] ?? null) ? $status['brand'] : [];
        return new ClientView(
            $this->i18n(),
            $this->nonce,
            $this->mode(),
            is_string($brand['name'] ?? null) ? $brand['name'] : null,
            is_string($brand['support_url'] ?? null) ? $brand['support_url'] : null
        );
    }

    /** @param array<string,mixed>|null $status */
    private function message(?array $status, string $kind, int $code = 200): Response
    {
        return $this->page($this->view($status)->message($kind, is_array($status['account'] ?? null) ? (string) ($status['account']['domain'] ?? '') : ''), $code);
    }

    private function page(string $body, int $code = 200): Response
    {
        return Response::html($body, $this->nonce, $code);
    }

    /**
     * 303 back to the app (post/redirect/get). Without a usable origin or
     * path the result renders at once instead.
     */
    private function redirect(string $key, bool $ok): Response
    {
        $path = (string) parse_url((string) $this->request->header('REQUEST_URI'), PHP_URL_PATH);
        $origin = Origin::of($this->request);
        if ($origin === '' || preg_match(self::APP_PATH, $path) !== 1) {
            return $this->get(['ok' => $ok, 'key' => $key]);
        }
        $query = $this->mode() + ['page' => 'migration', 'notice' => $key, 'ok' => $ok ? '1' : '0'];
        return Response::redirect($origin . $path . '?' . http_build_query($query));
    }

    /** @return array{ok:bool,key:string}|null */
    private function noticeFromQuery(): ?array
    {
        $key = $this->request->query('notice');
        if ($key === null || preg_match(self::NOTICE, $key) !== 1 || !$this->i18n()->has($key)) {
            return null;
        }
        return ['ok' => $this->request->query('ok') === '1', 'key' => $key];
    }

    private function framed(): bool
    {
        return $this->request->query('frame') === '1';
    }

    /** The app without the Jupiter chrome, when the frame could not load (see ClientView::shell). */
    private function standalone(): bool
    {
        return !$this->framed() && $this->request->query('standalone') === '1';
    }

    /** @return array<string,string> query that keeps the current mode */
    private function mode(): array
    {
        return $this->framed() ? ['frame' => '1'] : ($this->standalone() ? ['standalone' => '1'] : []);
    }

    /** Tabs the outer page may mirror in its address. */
    public static function shellScript(): string
    {
        return View::shellScript(self::PAGES);
    }
}
