<?php

declare(strict_types=1);

namespace BladeCP\CpanelMigrator;

use BladeCP\CpanelMigrator\Api\Client;
use BladeCP\CpanelMigrator\Client\BannerMarks;
use BladeCP\CpanelMigrator\Client\CpanelBanner;
use BladeCP\CpanelMigrator\Api\CurlTransport;
use BladeCP\CpanelMigrator\Api\Transport;
use BladeCP\CpanelMigrator\Queue\Enqueuer;
use BladeCP\CpanelMigrator\Queue\JobActions;
use BladeCP\CpanelMigrator\Queue\Uploader;
use BladeCP\CpanelMigrator\Queue\Worker;
use BladeCP\CpanelMigrator\Settings\Catalog;
use BladeCP\CpanelMigrator\Settings\Settings;
use BladeCP\CpanelMigrator\Store\FileStore;
use BladeCP\CpanelMigrator\Support\Clock;
use BladeCP\CpanelMigrator\Support\FileLogger;
use BladeCP\CpanelMigrator\Support\Logger;
use BladeCP\CpanelMigrator\Support\SystemClock;
use BladeCP\CpanelMigrator\Support\Uuid;
use BladeCP\CpanelMigrator\System\CommandRunner;
use BladeCP\CpanelMigrator\System\ProcRunner;
use BladeCP\CpanelMigrator\System\SystemAdapter;
use BladeCP\CpanelMigrator\System\WhmFavorites;
use BladeCP\CpanelMigrator\System\WhmSystem;
use BladeCP\CpanelMigrator\System\WorkDir;

/**
 * Wires the adapters together. Production uses cURL, WHM and the root-only
 * data directory; tests pass fakes for the transport, the system and the
 * clock.
 */
final class Runtime
{
    public const DATA_DIR = '/var/cpanel/bladecp-migrator';

    private Settings $settings;
    private ?Client $client = null;

    public function __construct(
        public readonly FileStore $store,
        public readonly Transport $transport,
        public readonly SystemAdapter $system,
        public readonly Clock $clock,
        public readonly Logger $log,
        private readonly int $workDirUid = 0,
        private readonly string $trustedBase = '',
        public readonly CommandRunner $runner = new ProcRunner(),
        private readonly string $systemRoot = ''
    ) {
        $this->settings = Settings::fromArray($store->loadConfig());
    }

    /** @param string $systemRoot prefix of the cPanel paths, only for the private install test */
    public static function production(string $dataDir = self::DATA_DIR, string $systemRoot = ''): self
    {
        $store = new FileStore($dataDir);
        $store->ensureLayout();
        $clock = new SystemClock();
        return new self($store, new CurlTransport(), new WhmSystem(), $clock, new FileLogger($store->logPath(), $clock), 0, '', new ProcRunner(), $systemRoot);
    }

    /**
     * Generates the installation id and the CSRF secret when missing and keeps
     * every existing value. Safe to run on every install or upgrade.
     *
     * @return array<string,mixed>
     */
    public static function initialize(FileStore $store): array
    {
        $store->ensureLayout();
        return $store->updateConfig(static function (array $config): array {
            if (!Uuid::isValid($config['installation_id'] ?? null)) {
                $config['installation_id'] = Uuid::v4();
            }
            if (!is_string($config['csrf_secret'] ?? null) || strlen($config['csrf_secret']) < 32) {
                $config['csrf_secret'] = bin2hex(random_bytes(32));
            }
            return $config + Settings::DEFAULTS;
        });
    }

    /** Effective user id of this process, or null when it cannot be determined. */
    public static function effectiveUid(): ?int
    {
        if (function_exists('posix_geteuid')) {
            return posix_geteuid();
        }
        $status = @file_get_contents('/proc/self/status');
        if (is_string($status) && preg_match('/^Uid:\s+\d+\s+(\d+)/m', $status, $match) === 1) {
            return (int) $match[1];
        }
        return null;
    }

    public function settings(): Settings
    {
        return $this->settings;
    }

    /**
     * Merges a validated patch into the stored settings.
     *
     * @param array<string,mixed> $patch
     */
    public function saveSettings(array $patch): void
    {
        $config = $this->store->updateConfig(static fn (array $config): array => array_merge($config, $patch));
        $this->settings = Settings::fromArray($config);
        $this->client = null;
    }

    public function api(): Client
    {
        return $this->client ??= new Client($this->transport, $this->settings->apiBaseUrl(), $this->settings->apiKey());
    }

    public function catalog(): Catalog
    {
        // The cache key changes with the endpoint and the key, never storing either.
        $key = substr(hash('sha256', $this->settings->apiBaseUrl() . '|' . $this->settings->apiKey()), 0, 16);
        return new Catalog($this->api(), $this->store, $this->clock, $key);
    }

    public function workDir(): WorkDir
    {
        return new WorkDir($this->settings->workDir(), $this->workDirUid, $this->trustedBase);
    }

    public function actions(): JobActions
    {
        return new JobActions($this->store, $this->api(), $this->workDir(), $this->clock, $this->log);
    }

    public function enqueuer(): Enqueuer
    {
        return new Enqueuer($this->store, $this->system, $this->clock, $this->log);
    }

    /** Favorites of the WHM home page (whmapi1 personalization_get/set). */
    public function favorites(): WhmFavorites
    {
        return new WhmFavorites($this->runner, $this->systemRoot . WhmSystem::WHMAPI1);
    }

    /** Banner at the top of the cPanel home page (Content Includes). */
    public function banner(): CpanelBanner
    {
        return new CpanelBanner($this->store->dataDir(), $this->systemRoot . CpanelBanner::INCLUDES_DIR);
    }

    /** Account marks that hide the banner once a migration exists. */
    public function bannerMarks(): BannerMarks
    {
        return new BannerMarks($this->store, $this->runner, $this->clock, $this->log, $this->systemRoot . BannerMarks::UAPI);
    }

    public function worker(?callable $sleep = null, ?int $pid = null): Worker
    {
        return new Worker(
            $this->store,
            $this->settings,
            $this->api(),
            new Uploader($this->api(), $this->transport, $this->log, $sleep),
            $this->system,
            $this->workDir(),
            $this->actions(),
            $this->log,
            $this->clock,
            $pid
        );
    }
}
