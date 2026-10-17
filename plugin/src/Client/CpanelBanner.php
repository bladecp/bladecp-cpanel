<?php

declare(strict_types=1);

namespace BladeCP\CpanelMigrator\Client;

use BladeCP\CpanelMigrator\Support\I18n;
use BladeCP\CpanelMigrator\Web\Ui;

/**
 * Banner at the top of the cPanel home page (Jupiter "Tools", app_key
 * tools), through the official Content Includes:
 * Cpanel::Template::Plugin::Content_Includes renders
 * /var/cpanel/customizations/content_includes/cpanel_jupiter_tools_header_after.html.tt
 * after the page header, on that page only.
 *
 * The include is a cPanel template that runs as the account. It holds no
 * secret and calls no API: it shows only when the site_migration feature is
 * on for the account, the account did not close it (NVData
 * site_migration_banner, written by its close button through
 * Personalization::set) and the server did not mark the account as migrated
 * or migrating (NVData site_migration_state, see BannerMarks). Every text
 * comes from the plugin catalogues, escaped when the file is written.
 *
 * The plugin never replaces an include it did not write: its file starts
 * with SIGNATURE, and a marker in the data directory records the install.
 * A file without the signature belongs to someone else and stays as it is.
 */
final class CpanelBanner
{
    public const INCLUDES_DIR = '/var/cpanel/customizations/content_includes';
    public const FILE = 'cpanel_jupiter_tools_header_after.html.tt';
    public const SIGNATURE = '[%# BladeCP cPanel Migrator: cPanel home page banner. Written by the plugin, replaced on update and removed on uninstall. %]';
    public const MARKER = 'cpanel-banner.installed';
    /** Account NVData written by the close button. */
    public const DISMISS_KEY = 'site_migration_banner';
    public const DISMISSED = 'dismissed';
    /** Account NVData written by the server (BannerMarks). */
    public const STATE_KEY = 'site_migration_state';
    public const HIDDEN = 'hide';
    /** The cPanel app, relative to the Jupiter home page. */
    public const APP_URL = 'site_migration/index.live.php';

    public const INSTALLED = 'installed';
    public const ABSENT = 'absent';
    public const FOREIGN = 'foreign';
    public const FAILED = 'failed';
    public const OFF = 'off';

    public function __construct(
        private readonly string $dataDir,
        private readonly string $includesDir = self::INCLUDES_DIR
    ) {
    }

    public function path(): string
    {
        return rtrim($this->includesDir, '/') . '/' . self::FILE;
    }

    /** installed (our file), foreign (a file of someone else) or absent. */
    public function state(): string
    {
        $path = $this->path();
        if (is_link($path)) {
            return self::FOREIGN;
        }
        if (!file_exists($path)) {
            return self::ABSENT;
        }
        return self::ours($path) ? self::INSTALLED : self::FOREIGN;
    }

    /** Installs the banner when on, removes it when off; never touches a foreign file. */
    public function apply(bool $enabled): string
    {
        if (!$enabled) {
            return $this->remove() ? self::OFF : self::FAILED;
        }
        return $this->install();
    }

    /** @return string installed, foreign or failed */
    public function install(): string
    {
        $state = $this->state();
        if ($state === self::FOREIGN) {
            return self::FOREIGN;
        }
        $dir = rtrim($this->includesDir, '/');
        if (!self::ensureDir($dir)) {
            return self::FAILED;
        }
        $content = self::template();
        $tmp = @tempnam($dir, '.bcpm-');
        if ($tmp === false) {
            return self::FAILED;
        }
        if (@file_put_contents($tmp, $content) !== strlen($content) || !@chmod($tmp, 0644)) {
            @unlink($tmp);
            return self::FAILED;
        }
        // Checked again right before the rename: a file that appeared meanwhile is not ours to replace.
        if (is_link($this->path()) || (file_exists($this->path()) && !self::ours($this->path())) || !@rename($tmp, $this->path())) {
            @unlink($tmp);
            return file_exists($this->path()) && !self::ours($this->path()) ? self::FOREIGN : self::FAILED;
        }
        $marker = json_encode(['file' => $this->path(), 'sha256' => hash('sha256', $content), 'version' => BLADECP_MIGRATOR_VERSION], JSON_UNESCAPED_SLASHES);
        @file_put_contents($this->dataDir . '/' . self::MARKER, $marker . "\n");
        @chmod($this->dataDir . '/' . self::MARKER, 0600);
        return self::INSTALLED;
    }

    /** Removes our include and the marker. True when no file of ours is left. */
    public function remove(): bool
    {
        @unlink($this->dataDir . '/' . self::MARKER);
        if ($this->state() !== self::INSTALLED) {
            return true;
        }
        return @unlink($this->path()) || !file_exists($this->path());
    }

    /**
     * Creates the includes directory when missing, 0755 at every level it
     * creates: the account reads the include, and the worker runs with umask
     * 077. A symbolic link on the way is refused.
     */
    private static function ensureDir(string $dir): bool
    {
        if (is_link($dir)) {
            return false;
        }
        if (is_dir($dir)) {
            return true;
        }
        $parent = dirname($dir);
        if ($parent === $dir || !self::ensureDir($parent)) {
            return false;
        }
        if (!@mkdir($dir, 0755) && !is_dir($dir)) {
            return false;
        }
        return @chmod($dir, 0755) && !is_link($dir);
    }

    private static function ours(string $path): bool
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }
        $first = fgets($handle, 4096);
        fclose($handle);
        return is_string($first) && rtrim($first, "\r\n") === self::SIGNATURE;
    }

    // ------------------------------------------------------------------ template

    /**
     * The include: a cPanel template (Template Toolkit) in the three
     * languages of the plugin, picked by the account locale.
     */
    public static function template(string $langDir = BLADECP_MIGRATOR_ROOT . '/lang'): string
    {
        $texts = [];
        foreach (['pt' => 'pt-BR', 'es' => 'es', 'en' => 'en'] as $prefix => $locale) {
            $i18n = new I18n($locale, $langDir);
            $pairs = [];
            foreach (['title' => 'client.banner.title', 'text' => 'client.banner.text', 'cta' => 'client.banner.cta',
                'close' => 'client.banner.close', 'label' => 'client.title'] as $name => $key) {
                $pairs[] = $name . " => '" . self::ttString($i18n->t($key)) . "'";
            }
            $texts[$prefix] = '{ ' . implode(', ', $pairs) . ' }';
        }
        $tt = self::SIGNATURE . "\n"
            . "[%- USE NVData;\n"
            . "SET bcpm_show = CPANEL.feature('site_migration') ? 1 : 0;\n"
            . "IF bcpm_show;\n"
            . "    TRY;\n"
            . "        IF NVData.get('" . self::DISMISS_KEY . "') == '" . self::DISMISSED . "' || NVData.get('" . self::STATE_KEY . "') == '" . self::HIDDEN . "';\n"
            . "            bcpm_show = 0;\n"
            . "        END;\n"
            . "    CATCH;\n"
            . "        bcpm_show = 0;\n"
            . "    END;\n"
            . "END;\n"
            . "IF bcpm_show;\n"
            . "    SET bcpm_lang = CPANEL.locale_info.locale.lower.substr(0, 2);\n"
            . "    IF bcpm_lang == 'pt';\n"
            . "        SET bcpm_t = " . $texts['pt'] . ";\n"
            . "    ELSIF bcpm_lang == 'es';\n"
            . "        SET bcpm_t = " . $texts['es'] . ";\n"
            . "    ELSE;\n"
            . "        SET bcpm_t = " . $texts['en'] . ";\n"
            . "    END;\n"
            . "-%]\n"
            . '<style>' . self::css() . "</style>\n"
            . '<div class="bcpm-cpb-wrap"><div class="container-lg">'
            . '<section id="bcpm-cpb" class="bcpm-cpb" aria-label="[% bcpm_t.label %]">'
            . '<span class="bcpm-cpb-hz" aria-hidden="true"><span class="bcpm-cpb-atmo"></span><span class="bcpm-cpb-planet"></span>'
            . '<span class="bcpm-cpb-rimwrap"><span class="bcpm-cpb-rim"></span><span class="bcpm-cpb-sweep"><i></i></span><span class="bcpm-cpb-glint"></span></span><span class="bcpm-cpb-sun"></span></span>'
            . '<span class="bcpm-cpb-brand">' . Ui::logo('bcpm-cpb-logo') . '<span class="bcpm-cpb-sep" aria-hidden="true"></span></span>'
            . '<span class="bcpm-cpb-copy"><strong class="bcpm-cpb-title">[% bcpm_t.title %]</strong><span class="bcpm-cpb-text">[% bcpm_t.text %]</span></span>'
            . '<span class="bcpm-cpb-actions"><a class="bcpm-cpb-cta" href="' . self::APP_URL . '">[% bcpm_t.cta %]' . Ui::icon('arrow-right') . '</a>'
            . '<button type="button" class="bcpm-cpb-close" data-bcpm-close aria-label="[% bcpm_t.close %]" title="[% bcpm_t.close %]">' . Ui::icon('x') . '</button></span>'
            . "</section></div></div>\n"
            . '<script>' . self::script() . "</script>\n"
            . "[%- END -%]\n";
        if (str_contains(self::css() . self::script(), '[%') || str_contains(self::css() . self::script(), '%]')) {
            throw new \LogicException('The banner markup cannot carry template tags.');
        }
        return $tt;
    }

    /** A catalogue text for a single-quoted template string: HTML-escaped, then backslash and quote escaped. */
    private static function ttString(string $text): string
    {
        $html = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
        if (str_contains($html, '[%') || str_contains($html, '%]')) {
            throw new \LogicException('A banner text cannot carry template tags.');
        }
        return str_replace(['\\', "'"], ['\\\\', "\\'"], $html);
    }

    /**
     * The A2 brand band, small: dark sky with the indigo glow, the light
     * rising from the rim, the dark planet with its bright rim near the
     * bottom and a straight base. The wordmark, the title and the actions sit
     * above the horizon, centered on one line; below 760 px of banner width
     * (container query, so it follows the banner and not the window) they
     * stack and the close button goes to the corner.
     *
     * Motion as in the plugin header: the atmosphere breathes (9 s) and a
     * light runs along the rim, back and forth (14 s, ease-in-out). Here the
     * light is a small gradient moved with transform inside a ring masked to
     * the rim, so only transform and opacity animate (no layout, no repaint
     * of a gradient), and the keyframes have their own prefix. With reduced
     * motion both stay still, the light at the top of the rim.
     *
     * Every selector starts with .bcpm-cpb-wrap, so nothing reaches the
     * Jupiter page.
     */
    public static function css(): string
    {
        $fade = 'linear-gradient(90deg,transparent,rgb(0 0 0 / .45) 16%,#000 40%,#000 60%,rgb(0 0 0 / .45) 84%,transparent)';
        $ellipse = 'position:absolute;left:50%;width:300%;height:640px;border-radius:50%;transform:translateX(-50%)';
        return '.bcpm-cpb-wrap{container:bcpm-cpb / inline-size;padding:var(--cp-spacer-6,1.5rem) var(--cp-spacer-6,1.5rem) 0}'
            . '.bcpm-cpb-wrap .bcpm-cpb{--bcpm-rim:calc(100% - 30px);position:relative;isolation:isolate;overflow:hidden;box-sizing:border-box;display:flex;align-items:center;'
            . 'gap:16px 24px;min-height:120px;margin:0;padding:20px 22px 42px 28px;border:1px solid #11152a;border-radius:16px;background:#030712;color:#e6e8f2;'
            . 'font-size:14px;line-height:1.45;text-align:left;box-shadow:0 1px 2px rgb(16 24 40 / .06),0 22px 44px -28px rgb(3 7 18 / .75)}'
            . '.bcpm-cpb-wrap .bcpm-cpb-hz{position:absolute;z-index:-1;inset:0;overflow:hidden;pointer-events:none;'
            . 'background-image:radial-gradient(520px 240px at 0 0,rgb(79 70 229 / .38),transparent 72%),radial-gradient(420px 200px at 100% 0,rgb(139 92 246 / .2),transparent 70%);'
            . 'background-repeat:no-repeat}'
            . '.bcpm-cpb-wrap .bcpm-cpb-atmo{position:absolute;left:0;right:0;top:calc(var(--bcpm-rim) - 170px);height:340px;'
            . 'background:radial-gradient(40% 170px at 50% 170px,rgb(99 102 241 / .5),rgb(139 92 246 / .12) 45%,transparent 72%);animation:bcpm-cpb-atmo 9s ease-in-out infinite}'
            . '.bcpm-cpb-wrap .bcpm-cpb-planet{' . $ellipse . ';top:var(--bcpm-rim);background:linear-gradient(180deg,#05060f,#000 48px)}'
            . '.bcpm-cpb-wrap .bcpm-cpb-rimwrap{position:absolute;inset:0;-webkit-mask-image:' . $fade . ';mask-image:' . $fade . '}'
            . '.bcpm-cpb-wrap .bcpm-cpb-rim{' . $ellipse . ';top:var(--bcpm-rim);'
            . 'box-shadow:inset 0 1.5px 0 rgb(255 255 255 / .88),inset 0 18px 40px -22px rgb(99 102 241 / .6),0 -1px 12px rgb(99 102 241 / .85),0 -26px 84px -12px rgb(99 102 241 / .55)}'
            // The light along the rim: a 2px ring with the rim shape (the mask keeps only the ring) and, inside it, a highlight one banner wide that moves.
            . '.bcpm-cpb-wrap .bcpm-cpb-sweep{' . $ellipse . ';top:calc(var(--bcpm-rim) - 1px);width:calc(300% + 2px);height:642px;box-sizing:border-box;padding:2px;overflow:hidden;'
            . '-webkit-mask:linear-gradient(#fff 0 0) content-box,linear-gradient(#fff 0 0);-webkit-mask-composite:xor;'
            . 'mask:linear-gradient(#fff 0 0) content-box exclude,linear-gradient(#fff 0 0)}'
            . '.bcpm-cpb-wrap .bcpm-cpb-sweep i{position:absolute;top:-12px;left:33.333%;width:33.333%;height:30px;'
            . 'background:linear-gradient(90deg,transparent calc(50% - 72px),rgb(99 102 241 / .8) calc(50% - 22px),#fff 50%,rgb(99 102 241 / .8) calc(50% + 22px),transparent calc(50% + 72px));'
            . 'animation:bcpm-cpb-sweep 14s ease-in-out infinite alternate;will-change:transform}'
            // The halo around the light, one banner wide like the light, moved by the same keyframes.
            . '.bcpm-cpb-wrap .bcpm-cpb-glint{position:absolute;left:0;width:100%;top:calc(var(--bcpm-rim) - 32px);height:64px;mix-blend-mode:screen;'
            . 'background:radial-gradient(140px 32px at 50% 50%,rgb(199 210 254 / .7),rgb(99 102 241 / .38) 45%,transparent 75%);'
            . 'animation:bcpm-cpb-sweep 14s ease-in-out infinite alternate;will-change:transform}'
            . '.bcpm-cpb-wrap .bcpm-cpb-sun{position:absolute;top:var(--bcpm-rim);left:50%;width:460px;height:46px;pointer-events:none;'
            . 'background:radial-gradient(50% 50% at 50% 50%,rgb(255 255 255 / .75),rgb(99 102 241 / .45) 30%,transparent 70%);filter:blur(9px);mix-blend-mode:screen;transform:translate(-50%,-50%)}'
            . '.bcpm-cpb-wrap .bcpm-cpb-brand{display:flex;align-items:center;gap:22px;flex:none}'
            . '.bcpm-cpb-wrap .bcpm-cpb-logo{display:block;width:auto;height:21px;color:#fff}'
            . '.bcpm-cpb-wrap .bcpm-cpb-sep{display:block;width:1px;height:38px;background:rgb(255 255 255 / .18)}'
            . '.bcpm-cpb-wrap .bcpm-cpb-copy{display:flex;flex-direction:column;flex:1 1 auto;min-width:0}'
            . '.bcpm-cpb-wrap .bcpm-cpb-title{display:block;margin:0;color:#fff;font-size:17px;font-weight:600;line-height:1.3;letter-spacing:-.01em}'
            . '.bcpm-cpb-wrap .bcpm-cpb-text{display:block;margin-top:3px;color:#b9bfd3;font-size:13.5px;line-height:1.45}'
            . '.bcpm-cpb-wrap .bcpm-cpb-actions{display:flex;align-items:center;gap:12px;flex:none;margin-left:auto}'
            . '.bcpm-cpb-wrap .bcpm-cpb-cta{display:inline-flex;align-items:center;justify-content:center;gap:8px;min-height:40px;padding:9px 16px;border-radius:10px;'
            . 'background:#fff;color:#3730a3;font-size:14px;font-weight:600;line-height:1.2;white-space:nowrap;text-decoration:none;box-shadow:0 8px 20px -10px rgb(99 102 241 / .95)}'
            . '.bcpm-cpb-wrap .bcpm-cpb-cta:hover,.bcpm-cpb-wrap .bcpm-cpb-cta:focus{background:#eef0ff;color:#3730a3;text-decoration:none}'
            . '.bcpm-cpb-wrap .bcpm-cpb-cta svg{display:block;width:16px;height:16px}'
            . '.bcpm-cpb-wrap .bcpm-cpb-close{display:grid;place-items:center;flex:none;width:36px;height:36px;margin:0;padding:0;border:0;border-radius:9px;'
            . 'background:transparent;color:#9aa2b8;cursor:pointer;line-height:1}'
            . '.bcpm-cpb-wrap .bcpm-cpb-close:hover{background:rgb(255 255 255 / .08);color:#fff}'
            . '.bcpm-cpb-wrap .bcpm-cpb-close svg{display:block;width:18px;height:18px}'
            . '.bcpm-cpb-wrap .bcpm-cpb-cta:focus-visible,.bcpm-cpb-wrap .bcpm-cpb-close:focus-visible{outline:3px solid rgb(129 140 248 / .75);outline-offset:2px}'
            . '@keyframes bcpm-cpb-atmo{50%{opacity:.8}}'
            . '@keyframes bcpm-cpb-sweep{from{transform:translateX(-28%)}to{transform:translateX(28%)}}'
            . '@media (prefers-reduced-motion:reduce){.bcpm-cpb-wrap .bcpm-cpb-atmo,.bcpm-cpb-wrap .bcpm-cpb-sweep i,.bcpm-cpb-wrap .bcpm-cpb-glint{animation:none}}'
            . '@container bcpm-cpb (max-width:760px){'
            . '.bcpm-cpb-wrap .bcpm-cpb{flex-direction:column;align-items:flex-start;gap:12px;padding:20px 20px 46px}'
            . '.bcpm-cpb-wrap .bcpm-cpb-sep{display:none}'
            . '.bcpm-cpb-wrap .bcpm-cpb-copy{width:100%}'
            . '.bcpm-cpb-wrap .bcpm-cpb-title{font-size:16px}'
            . '.bcpm-cpb-wrap .bcpm-cpb-actions{width:100%;margin-left:0}'
            . '.bcpm-cpb-wrap .bcpm-cpb-cta{flex:1}'
            . '.bcpm-cpb-wrap .bcpm-cpb-close{position:absolute;top:12px;right:12px}}';
    }

    /**
     * Close button: the banner leaves the page at once and the account
     * NVData remembers it (Personalization::set, the way cPanel plugins save
     * a preference). A failed save only brings the banner back next time.
     */
    public static function script(): string
    {
        return "(function(){var b=document.getElementById('bcpm-cpb');var x=b?b.querySelector('[data-bcpm-close]'):null;if(!x){return;}"
            . "x.addEventListener('click',function(){var w=b.parentNode&&b.parentNode.parentNode?b.parentNode.parentNode:b;if(w.parentNode){w.parentNode.removeChild(w);}"
            . "var s=window.location.pathname.split('/')[1]||'';if(!/^cpsess[0-9]+$/.test(s)){return;}"
            . "var url='/'+s+'/execute/Personalization/set';var body=JSON.stringify({personalization:{" . self::DISMISS_KEY . ":'" . self::DISMISSED . "'}});"
            . "try{if(window.fetch){window.fetch(url,{method:'POST',credentials:'same-origin',keepalive:true,headers:{'Content-Type':'application/json'},body:body});return;}"
            . "var r=new XMLHttpRequest();r.open('POST',url,true);r.setRequestHeader('Content-Type','application/json');r.send(body);}catch(e){}});})();";
    }
}
