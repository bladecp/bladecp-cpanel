<?php

declare(strict_types=1);

/**
 * Public, dependency-free regression checks for the distributed plugin.
 *
 * Run from the repository root with: php tests/run.php
 *
 * The installer and uninstaller are checked by tests/install/run.sh (fake
 * filesystem root) and the Perl pieces of the cPanel app by
 * tests/perl/run.sh. Nothing here needs a cPanel server or the network.
 */

$root = dirname(__DIR__);
require $root . '/plugin/src/bootstrap.php';

use BladeCP\CpanelMigrator\Client\CpanelBanner;
use BladeCP\CpanelMigrator\Queue\JobState;
use BladeCP\CpanelMigrator\Queue\UploadPlan;
use BladeCP\CpanelMigrator\Settings\Settings;
use BladeCP\CpanelMigrator\Settings\SettingsValidator;
use BladeCP\CpanelMigrator\Support\I18n;
use BladeCP\CpanelMigrator\Support\Redactor;
use BladeCP\CpanelMigrator\System\ProcRunner;
use BladeCP\CpanelMigrator\System\WhmFavorites;
use BladeCP\CpanelMigrator\System\WhmSystem;
use BladeCP\CpanelMigrator\Web\Csrf;
use BladeCP\CpanelMigrator\Web\Ui;

$checks = 0;
$failures = [];

/** @param callable():void $test */
function check(string $name, callable $test): void
{
    global $checks, $failures;
    $checks++;
    try {
        $test();
        echo "ok {$checks} - {$name}\n";
    } catch (Throwable $error) {
        $failures[] = "{$name}: {$error->getMessage()}";
        echo "not ok {$checks} - {$name}\n";
    }
}

function expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @return array<string,string> */
function catalogue(string $root, string $locale): array
{
    $entries = require $root . '/plugin/lang/' . $locale . '.php';
    expect(is_array($entries), "catalogue {$locale} is not an array");
    return $entries;
}

// ---------------------------------------------------------------- version and documents

check('version is semantic and matches the changelog and the README downloads', static function () use ($root): void {
    $version = trim((string) file_get_contents($root . '/plugin/VERSION'));
    expect(preg_match('/^\d+\.\d+\.\d+$/', $version) === 1, 'plugin/VERSION is not semantic');
    expect(BLADECP_MIGRATOR_VERSION === $version, 'bootstrap version differs from plugin/VERSION');
    $changelog = (string) file_get_contents($root . '/CHANGELOG.md');
    expect(preg_match('/^## \[' . preg_quote($version, '/') . '\] - \d{4}-\d{2}-\d{2}$/m', $changelog) === 1, 'CHANGELOG has no dated entry for the version');
    foreach (['README.md', 'README.pt-BR.md', 'README.es.md'] as $readme) {
        $text = (string) file_get_contents($root . '/' . $readme);
        expect(str_contains($text, 'https://github.com/bladecp/bladecp-cpanel/releases/download/v' . $version . '/bladecp-cpanel-migrator-' . $version . '.tar.gz'),
            "{$readme} does not download the current release");
        expect(str_contains($text, '(LICENSE)'), "{$readme} does not link the license");
    }
});

check('the license is MIT', static function () use ($root): void {
    expect(str_starts_with((string) file_get_contents($root . '/LICENSE'), 'MIT License'), 'LICENSE is not the MIT License');
});

// ---------------------------------------------------------------- translations

check('the three catalogues have the same keys and placeholders', static function () use ($root): void {
    $en = catalogue($root, 'en');
    foreach (['pt-BR', 'es'] as $locale) {
        $other = catalogue($root, $locale);
        expect(array_diff_key($en, $other) === [] && array_diff_key($other, $en) === [], "{$locale} keys differ from English");
        foreach ($en as $key => $text) {
            preg_match_all('/\{[a-z]+\}/', $text, $a);
            preg_match_all('/\{[a-z]+\}/', $other[$key], $b);
            sort($a[0]);
            sort($b[0]);
            expect($a[0] === $b[0], "{$locale} placeholders differ for {$key}");
        }
    }
});

check('user-facing texts have no em dash', static function () use ($root): void {
    foreach (['en', 'pt-BR', 'es'] as $locale) {
        expect(!str_contains(implode("\n", catalogue($root, $locale)), "\u{2014}"), "{$locale} has an em dash");
    }
});

check('every literal message key used in the code exists', static function () use ($root): void {
    $en = catalogue($root, 'en');
    $missing = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/plugin/src', FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        preg_match_all("/'((?:app|nav|header|migrations|progress|status|step|phase|wait|action|error|accounts|review|settings|language|client)\.[a-zA-Z0-9_.-]+)'/",
            (string) file_get_contents((string) $file), $match);
        foreach ($match[1] as $key) {
            if (!str_ends_with($key, '.') && !isset($en[$key])) {
                $missing[] = $key;
            }
        }
    }
    expect($missing === [], 'missing keys: ' . implode(', ', array_unique($missing)));
});

check('locale codes are normalized and English is the fallback', static function (): void {
    expect(I18n::normalize('pt_br') === 'pt-BR' && I18n::normalize('es_419') === 'es' && I18n::normalize('en_US') === 'en', 'normalization');
    expect(I18n::normalize('de') === null, 'unknown locale');
    expect((new I18n('es'))->t('no.such.key') === 'no.such.key', 'missing key falls back to the key');
});

// ---------------------------------------------------------------- settings and validation

check('defaults: production API, free period of 14 days, banner on', static function (): void {
    $defaults = Settings::fromArray([]);
    expect($defaults->apiBaseUrl() === 'https://api.bladecp.com/api/v1' && !$defaults->usesCustomApiUrl(), 'production API');
    expect($defaults->commercialMode() === 'trial' && $defaults->trialDays() === 14, 'free period');
    expect($defaults->concurrency() === 1 && $defaults->cpanelBanner() && !$defaults->whmPinDismissed(), 'concurrency and highlights');
});

check('support links accept https and mailto only', static function (): void {
    expect(SettingsValidator::supportUrl('https://support.example.com/help') === ['https://support.example.com/help', null], 'https');
    expect(SettingsValidator::supportUrl('mailto:help@example.com') === ['mailto:help@example.com', null], 'mailto');
    expect(SettingsValidator::supportUrl('http://support.example.com')[1] !== null, 'http refused');
    expect(SettingsValidator::supportUrl('javascript:alert(1)')[1] !== null, 'script refused');
});

// ---------------------------------------------------------------- security helpers

check('the form token is bound to the WHM session and user', static function (): void {
    $secret = str_repeat('7', 64);
    $token = (new Csrf($secret, '/cpsess1234567890', 'root'))->token();
    expect((new Csrf($secret, '/cpsess1234567890', 'root'))->valid($token), 'same session');
    expect(!(new Csrf($secret, '/cpsess0000000001', 'root'))->valid($token), 'other session');
    expect(!(new Csrf($secret, '/cpsess1234567890', 'reseller'))->valid($token), 'other user');
    expect(!(new Csrf('short', '/cpsess1234567890', 'root'))->valid((new Csrf('short', '/cpsess1234567890', 'root'))->token()), 'short secret');
});

check('origin checks refuse another host', static function (): void {
    expect(Csrf::sameOrigin('https://server.example.com:2087', null, 'server.example.com:2087'), 'same origin');
    expect(!Csrf::sameOrigin('https://other.example.com', null, 'server.example.com:2087'), 'other host');
});

check('API keys, signed links and e-mail addresses are redacted', static function (): void {
    $key = 'bcp_' . 'live_' . str_repeat('Ab3', 8);
    $clean = Redactor::string('Authorization: Bearer ' . $key . ' https://storage.example.com/u?X-Amz-Signature=abc owner@example.com');
    expect(!str_contains($clean, $key) && !str_contains($clean, 'abc') && !str_contains($clean, 'owner@example.com'), 'redaction');
    $context = Redactor::value(['api_key' => 'plain', 'user' => 'alice', 'nested' => ['url' => 'https://x.example.com']]);
    expect($context['api_key'] === Redactor::MASK && $context['nested']['url'] === Redactor::MASK && $context['user'] === 'alice', 'context');
});

// ---------------------------------------------------------------- WHM adapter

check('listaccts output is parsed defensively', static function (): void {
    $json = json_encode(['metadata' => ['result' => 1], 'data' => ['acct' => [
        ['user' => 'zed', 'domain' => 'Zed.Example.com', 'email' => '*unknown*', 'suspended' => 0, 'diskused' => '512M', 'owner' => 'root', 'plan' => 'p', 'partition' => 'home'],
        ['user' => '../root', 'domain' => 'x.example.com'],
    ]]]);
    $accounts = WhmSystem::parseListaccts((string) $json);
    expect(count($accounts) === 1 && $accounts[0]['user'] === 'zed' && $accounts[0]['domain'] === 'zed.example.com', 'valid users only');
    expect($accounts[0]['email'] === null && $accounts[0]['disk_used_bytes'] === 512 * 1024 * 1024, 'e-mail and size');
    expect(WhmSystem::validUser('alice') && !WhmSystem::validUser('a;b') && !WhmSystem::validUser('Root'), 'user names');
});

// ---------------------------------------------------------------- queue

check('retries wait 1, 5, 15, 30 and 60 minutes', static function (): void {
    expect(array_map([JobState::class, 'backoff'], [1, 2, 3, 4, 5, 9]) === [60, 300, 900, 1800, 3600, 3600], 'backoff');
});

check('a migration waiting only for the optional DNS is complete', static function (): void {
    expect(JobState::isComplete(['status' => JobState::WAITING, 'wait_reason' => 'dns']), 'dns wait is complete');
    expect(JobState::isComplete(['status' => JobState::DONE]), 'done');
    expect(!JobState::isComplete(['status' => JobState::WAITING, 'wait_reason' => 'approval']), 'approval is not complete');
});

check('an interrupted upload resumes from the parts the server already has', static function (): void {
    $plan = UploadPlan::reconcile(null, ['uploadId' => 'u1', 'partSize' => 10, 'uploadedParts' => [['partNumber' => 1, 'etag' => '"a"']],
        'parts' => [['partNumber' => 2, 'url' => 'https://storage.example.com/p2']]], 25, 'g1');
    expect($plan['state']['total_parts'] === 3, 'total parts');
    expect(UploadPlan::missing($plan['state']) === [2, 3], 'missing parts');
    expect(UploadPlan::range($plan['state'], 3) === [20, 5], 'last part range');
    $other = UploadPlan::reconcile($plan['state'], ['uploadId' => 'u1', 'partSize' => 10], 25, 'g2');
    expect(UploadPlan::missing($other['state']) === [1, 2, 3], 'a new backup generation never reuses parts');
});

// ---------------------------------------------------------------- WHM Favorites

check('the plugin id in the WHM Favorites follows the displayname', static function (): void {
    expect(WhmFavorites::slug('BladeCP: Migrate and Transfer Accounts') === 'bladecp_migrate_and_transfer_accounts', 'slug');
    expect((new WhmFavorites(new ProcRunner(), '/x/whmapi1', 'BladeCP: Migrar e Transferir Contas'))->id() === 'plugins$bladecp_migrar_e_transferir_contas', 'id');
});

check('pinning puts the plugin first, keeps the others and drops former ids', static function (): void {
    $id = 'plugins$bladecp_migrate_and_transfer_accounts';
    expect(WhmFavorites::pinned(null, $id) === array_merge([$id], WhmFavorites::DEFAULTS), 'no list starts from the WHM defaults');
    expect(WhmFavorites::pinned(['a$b', $id, 'c$d'], $id) === [$id, 'a$b', 'c$d'], 'existing list');
    expect(WhmFavorites::pinned(['plugins$bladecp_cpanel_migrator', 'a$b'], $id, ['plugins$bladecp_cpanel_migrator']) === [$id, 'a$b'], 'former id');
    expect(WhmFavorites::unpinned(['a$b', $id], $id) === ['a$b'], 'unpin');
    expect(WhmFavorites::migrated(['a$b', 'plugins$bladecp_cpanel_migrator', 'c$d'], $id, ['plugins$bladecp_cpanel_migrator']) === ['a$b', $id, 'c$d'], 'migration in place');
});

// ---------------------------------------------------------------- cPanel surfaces

check('the cPanel home page banner calls no API, carries no secret and keeps its CSS scoped', static function (): void {
    $tt = CpanelBanner::template();
    expect(str_starts_with($tt, CpanelBanner::SIGNATURE . "\n"), 'signature');
    expect(str_contains($tt, "CPANEL.feature('site_migration')") && !str_contains($tt, 'execute(') && !str_contains($tt, 'Api2'), 'no API call');
    expect(!preg_match('/api[_-]?key|csrf/i', $tt), 'no secret');
    $css = CpanelBanner::css();
    $css = (string) preg_replace('/@keyframes[^{]*\{(?:[^{}]*\{[^{}]*\})*[^{}]*\}/', '', $css);
    preg_match_all('/([^{}]+)\{[^{}]*\}/', (string) preg_replace('/@(?:media|container)[^{]+\{/', '', $css), $rules);
    foreach ($rules[1] as $list) {
        foreach (explode(',', $list) as $selector) {
            expect(str_starts_with(trim($selector), '.bcpm-cpb-wrap'), 'unscoped selector: ' . trim($selector));
        }
    }
    expect(preg_match('/@keyframes (?!bcpm-cpb-)/', CpanelBanner::css()) !== 1, 'keyframes have the plugin prefix');
});

check('the side menu item is the app, with a monochrome 24x24 icon', static function () use ($root): void {
    $yaml = (string) file_get_contents($root . '/plugin/cpanel/plugin/menu/LeftMenu.yaml');
    $install = json_decode((string) file_get_contents($root . '/plugin/cpanel/plugin/install.json'), true);
    expect(str_starts_with($yaml, "- key: site_migration\n  order: 5\n  icon: '") && $install[0]['id'] === 'site_migration', 'key and order');
    expect(str_contains($yaml, 'viewBox="0 0 24 24" width="24" height="24"') && str_contains($yaml, 'fill="var(--cp-tertiary-contrast)"'), 'icon');
    expect(str_contains($yaml, Ui::MARK_PATH) && !str_contains($yaml, 'linearGradient'), 'the BladeCP B in one color');
});

check('the WHM AppConfig is safe for the Plugins list', static function () use ($root): void {
    $conf = (string) file_get_contents($root . '/plugin/appconfig/bladecp_migrator.conf');
    foreach (['displayname', 'description', 'searchtext'] as $key) {
        expect(preg_match('/^' . $key . '=([^\n]+)$/m', $conf, $match) === 1, "missing {$key}");
        expect(preg_match('/[\'"<>&#]/', $match[1]) !== 1, "{$key} has a character the Plugins list does not escape");
    }
    expect(preg_match('/^section=/m', $conf) !== 1 && str_contains($conf, "\nuser=root\n") && str_contains($conf, "\nacls=all\n"), 'stays in Plugins, root only');
});

check('the installer and uninstaller never take over files of other products', static function () use ($root): void {
    $install = (string) file_get_contents($root . '/install.sh');
    $uninstall = (string) file_get_contents($root . '/uninstall.sh');
    expect(substr_count($install, 'was not created by this plugin.') >= 3, 'installer refuses foreign paths');
    expect(str_contains($uninstall, "BANNER_SIGNATURE='" . CpanelBanner::SIGNATURE . "'"), 'uninstaller removes only its own banner');
    expect(!str_contains($install, 'personalization_set'), 'installer pins nothing');
});

echo "\n";
if ($failures === []) {
    echo "PASS: {$checks} public checks\n";
    exit(0);
}
echo 'FAIL: ' . count($failures) . " of {$checks} public checks\n";
foreach ($failures as $failure) {
    echo "  - {$failure}\n";
}
exit(1);
