<?php

declare(strict_types=1);

namespace BladeCP\CpanelMigrator\System;

use BladeCP\CpanelMigrator\Support\Redactor;

/**
 * The Favorites of the WHM home page, for the root user.
 *
 * WHM keeps them in the root NVData under `favorites`: a list of
 * "group$key" identifiers (Whostmgr::Customization::Utils::get_favorites).
 * A plugin is "plugins$<uniquekey>", where the uniquekey is the AppConfig
 * displayname through Cpanel::StringFunc::Group::group_words (see slug()).
 * Identifiers WHM does not know are skipped by the home page, so a default
 * that does not exist on a server does no harm.
 *
 * Without a saved list WHM shows its own defaults (DEFAULTS), so pinning
 * starts from them: the root user keeps what the home page showed, with the
 * plugin first. The list is read and written with whmapi1
 * personalization_get and personalization_set (JSON input on stdin, the only
 * input those calls take), never with nvset.
 *
 * The displayname follows the server language (install.sh), so the
 * uniquekey does too: the id is computed from the installed AppConfig file,
 * and every name the plugin ever had (DISPLAY_NAMES) counts as ours when
 * pinning, unpinning and migrating an older favorite.
 */
final class WhmFavorites
{
    public const NAME = 'favorites';
    public const GROUP = 'plugins';
    public const APPCONFIG = BLADECP_MIGRATOR_ROOT . '/appconfig/bladecp_migrator.conf';
    /** displayname without a localized install (the English one, as in the shipped AppConfig). */
    public const DEFAULT_DISPLAY_NAME = 'BladeCP: Migrate and Transfer Accounts';
    /** Every displayname of the plugin: 0.3.4 and before, then en, pt-BR and es (install.sh). */
    public const DISPLAY_NAMES = [
        'BladeCP cPanel Migrator',
        'BladeCP: Migrate and Transfer Accounts',
        'BladeCP: Migrar e Transferir Contas',
        'BladeCP: Migrar y Transferir Cuentas',
    ];
    /**
     * Favorites WHM shows when the root user never saved any (cPanel 138,
     * built into the compiled home page). Keys from themes/x/dynamicui.conf,
     * in the order of the home page, whose grid fills row by row:
     * List Accounts | Process Manager, Create a New Account | DNS Zone
     * Manager, Terminal | Mail Queue Manager.
     */
    public const DEFAULTS = [
        'account_information$list_accounts',
        'system_health$process_manager',
        'account_functions$create_a_new_account',
        'dns_functions$whm_zone_manager',
        'server_configuration$terminal',
        'email$mail_queue_manager',
    ];
    /** What 0.3.2 wrote as the defaults (wrong order); pinning again repairs it. */
    private const DEFAULTS_0_3_2 = [
        'account_information$list_accounts',
        'account_functions$create_a_new_account',
        'server_configuration$terminal',
        'dns_functions$whm_zone_manager',
        'email$mail_queue_manager',
        'system_health$process_manager',
    ];
    private const MAX_ITEMS = 60;

    private string $displayName;

    public function __construct(
        private readonly CommandRunner $runner = new ProcRunner(),
        private readonly string $whmapi1 = WhmSystem::WHMAPI1,
        ?string $displayName = null
    ) {
        $this->displayName = $displayName ?? self::installedDisplayName();
    }

    /** displayname of the installed AppConfig file (localized by install.sh). */
    public static function installedDisplayName(string $file = self::APPCONFIG): string
    {
        $content = is_file($file) ? (string) @file_get_contents($file) : '';
        if (preg_match('/^displayname=(.+)$/m', $content, $match) === 1 && trim($match[1]) !== '') {
            return trim($match[1]);
        }
        return self::DEFAULT_DISPLAY_NAME;
    }

    /** Cpanel::StringFunc::Group::group_words, the uniquekey WHM gives an AppConfig plugin. */
    public static function slug(string $text): string
    {
        $text = (string) preg_replace('/[^a-z0-9_\s]/', '', strtolower($text));
        return (string) preg_replace('/[-\s]+/', '_', trim($text));
    }

    /** Identifier of this plugin in the Favorites. */
    public function id(): string
    {
        return self::GROUP . '$' . self::slug($this->displayName);
    }

    /**
     * Identifiers of this plugin under its other names (older versions, other
     * server languages). WHM no longer knows them.
     *
     * @return list<string>
     */
    public function formerIds(): array
    {
        $ids = [];
        foreach (self::DISPLAY_NAMES as $name) {
            $id = self::GROUP . '$' . self::slug($name);
            if ($id !== $this->id() && !in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }
        return $ids;
    }

    /**
     * Saved Favorites, or null when the root user never saved a list (WHM
     * then shows DEFAULTS).
     *
     * @return list<string>|null
     */
    public function current(): ?array
    {
        $decoded = $this->call('personalization_get', ['names' => [self::NAME]]);
        $entry = $decoded['data']['personalization'][self::NAME] ?? null;
        // success 0 only means the datastore file does not exist yet: no list.
        if (!is_array($entry) || !is_array($entry['value'] ?? null)) {
            return null;
        }
        $ids = [];
        foreach ($entry['value'] as $id) {
            if (is_string($id) && $id !== '') {
                $ids[] = $id;
            }
        }
        return $ids;
    }

    public function isPinned(): bool
    {
        return in_array($this->id(), $this->current() ?? [], true);
    }

    /** Puts the plugin first, keeping every other favorite in its order. */
    public function pin(): void
    {
        $this->save(self::pinned($this->current(), $this->id(), $this->formerIds()));
    }

    /** Takes only the plugin (under any of its names) out of the Favorites. */
    public function unpin(): void
    {
        $current = $this->current();
        $ours = array_merge([$this->id()], $this->formerIds());
        if ($current === null || array_intersect($ours, $current) === []) {
            return;
        }
        $this->save(self::unpinned($current, $this->id(), $this->formerIds()));
    }

    /**
     * After the displayname changed (new version or server language), the
     * favorite saved under a former name points to nothing: it becomes the
     * current id, in the same place. Nothing is written when the list has no
     * former id. Returns true when the list was rewritten.
     */
    public function migrate(): bool
    {
        $current = $this->current();
        if ($current === null) {
            return false;
        }
        $migrated = self::migrated($current, $this->id(), $this->formerIds());
        if ($migrated === $current) {
            return false;
        }
        $this->save($migrated);
        return true;
    }

    /**
     * @param list<string> $current
     * @param list<string> $former
     * @return list<string>
     */
    public static function migrated(array $current, string $id, array $former): array
    {
        $out = [];
        foreach ($current as $item) {
            $item = in_array($item, $former, true) ? $id : $item;
            if (!in_array($item, $out, true)) {
                $out[] = $item;
            }
        }
        return $out;
    }

    /**
     * @param list<string>|null $current null when there is no saved list
     * @param list<string> $former the plugin under its other names
     * @return list<string>
     */
    public static function pinned(?array $current, string $id, array $former = []): array
    {
        $ours = array_merge([$id], $former);
        $rest = array_values(array_filter($current ?? self::DEFAULTS, static fn (string $item): bool => !in_array($item, $ours, true)));
        if ($rest === self::DEFAULTS_0_3_2) {
            // Exactly the defaults 0.3.2 saved in the wrong order: back to the order of the home page.
            $rest = self::DEFAULTS;
        }
        return array_slice(array_merge([$id], $rest), 0, self::MAX_ITEMS);
    }

    /**
     * @param list<string> $current
     * @param list<string> $former the plugin under its other names
     * @return list<string>
     */
    public static function unpinned(array $current, string $id, array $former = []): array
    {
        $ours = array_merge([$id], $former);
        return array_values(array_filter($current, static fn (string $item): bool => !in_array($item, $ours, true)));
    }

    /** @param list<string> $ids */
    private function save(array $ids): void
    {
        $decoded = $this->call('personalization_set', ['personalization' => [self::NAME => $ids]]);
        // personalization_set always answers result 1: the outcome is per name.
        if ((int) ($decoded['data']['personalization'][self::NAME]['success'] ?? 0) !== 1) {
            throw new SystemError(SystemError::WHMAPI_FAILED, 'whmapi1 personalization_set did not save the favorites.',
                Redactor::string((string) ($decoded['data']['personalization'][self::NAME]['reason'] ?? '')));
        }
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    private function call(string $function, array $input): array
    {
        $result = $this->runner->run([$this->whmapi1, '--input=json', '--output=json', $function], 60, null, 1024 * 1024,
            (string) json_encode($input, JSON_UNESCAPED_SLASHES));
        if (!$result->ok() || $result->truncated) {
            throw new SystemError(SystemError::WHMAPI_FAILED, 'whmapi1 ' . $function . ' failed.', Redactor::string(substr($result->stderr, -500)));
        }
        $decoded = json_decode($result->stdout, true);
        if (!is_array($decoded) || (int) ($decoded['metadata']['result'] ?? 0) !== 1) {
            throw new SystemError(SystemError::WHMAPI_FAILED, 'whmapi1 ' . $function . ' returned an error.',
                Redactor::string(is_array($decoded) ? (string) ($decoded['metadata']['reason'] ?? '') : ''));
        }
        return $decoded;
    }
}
