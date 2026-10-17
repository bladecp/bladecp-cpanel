<?php

declare(strict_types=1);

/*
 * BladeCP cPanel Migrator command line.
 *
 *   worker.php run       queue worker, started by cron every minute
 *   worker.php status    list migrations (no secrets)
 *   worker.php init      create the data directory, installation id and form secret
 *   worker.php paths     print the data and work directories
 *   worker.php banner    install or remove the cPanel home page banner, as Settings say
 *                        (--remove: remove it, whatever Settings say)
 *   worker.php favorites the WHM favorite saved under a former plugin name becomes
 *                        the current one, in the same place (run by install.sh)
 *   worker.php version
 *
 * Option --data-dir=PATH selects another data directory (used by tests);
 * with it, --system-root=PATH prefixes the cPanel paths (install test).
 */

require dirname(__DIR__) . '/src/bootstrap.php';

use BladeCP\CpanelMigrator\Queue\JobState;
use BladeCP\CpanelMigrator\Runtime;
use BladeCP\CpanelMigrator\Settings\Settings;
use BladeCP\CpanelMigrator\Store\FileStore;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

umask(0077);

$args = array_slice($argv, 1);
$command = 'help';
$dataDir = Runtime::DATA_DIR;
$customDataDir = false;
$systemRoot = '';
$remove = in_array('--remove', $args, true);
foreach ($args as $arg) {
    if (str_starts_with($arg, '--data-dir=')) {
        $dataDir = rtrim(substr($arg, 11), '/');
        $customDataDir = true;
    } elseif (str_starts_with($arg, '--system-root=')) {
        $systemRoot = rtrim(substr($arg, 14), '/');
    } elseif ($arg !== '' && $arg[0] !== '-') {
        $command = $arg;
    }
}

if ($command === 'version') {
    echo BLADECP_MIGRATOR_VERSION, "\n";
    exit(0);
}
if ($command === 'help' || !in_array($command, ['run', 'status', 'init', 'paths', 'banner', 'favorites'], true)) {
    fwrite(STDERR, "Usage: worker.php run|status|init|paths|banner|favorites|version [--data-dir=PATH]\n");
    exit($command === 'help' ? 0 : 2);
}
if (!$customDataDir && Runtime::effectiveUid() !== 0) {
    fwrite(STDERR, "BladeCP cPanel Migrator: run this command as root.\n");
    exit(1);
}
if ($dataDir === '' || $dataDir[0] !== '/') {
    fwrite(STDERR, "BladeCP cPanel Migrator: --data-dir must be an absolute path.\n");
    exit(2);
}
if ($systemRoot !== '' && (!$customDataDir || $systemRoot[0] !== '/')) {
    fwrite(STDERR, "BladeCP cPanel Migrator: --system-root needs --data-dir and an absolute path.\n");
    exit(2);
}

try {
    if ($command === 'init') {
        $config = Runtime::initialize(new FileStore($dataDir));
        echo 'installation_id=', $config['installation_id'], "\n";
        exit(0);
    }
    if ($command === 'paths') {
        $settings = Settings::fromArray((new FileStore($dataDir))->loadConfig());
        echo 'data_dir=', $dataDir, "\n", 'work_dir=', $settings->workDir(), "\n";
        exit(0);
    }
    $runtime = Runtime::production($dataDir, $systemRoot);
    if ($command === 'banner') {
        // installed, off, foreign (a file of another product, kept) or failed.
        echo 'banner=', $runtime->banner()->apply(!$remove && $runtime->settings()->cpanelBanner()), "\n";
        exit(0);
    }
    if ($command === 'favorites') {
        // migrated, unchanged (nothing of ours under a former name) or unavailable (whmapi1 did not answer).
        try {
            echo 'favorites=', $runtime->favorites()->migrate() ? 'migrated' : 'unchanged', "\n";
        } catch (\BladeCP\CpanelMigrator\System\SystemError $error) {
            echo "favorites=unavailable\n";
        }
        exit(0);
    }
    if ($command === 'status') {
        $jobs = $runtime->store->jobs();
        if ($jobs === []) {
            echo "No migrations.\n";
            exit(0);
        }
        printf("%-16s %-10s %-10s %-20s %5s  %s\n", 'ACCOUNT', 'STATUS', 'STEP', 'PHASE', 'PROG', 'UPDATED (UTC)');
        foreach ($jobs as $job) {
            printf(
                "%-16s %-10s %-10s %-20s %4d%%  %s\n",
                $job['user'],
                $job['status'],
                $job['step'],
                (string) ($job['remote']['phase'] ?? '-'),
                (int) ($job['progress'] ?? 0),
                gmdate('Y-m-d H:i', (int) ($job['updated_at'] ?? 0))
            );
            if (is_array($job['error'] ?? null) && $job['status'] !== JobState::DONE) {
                printf("  last error: %s\n", $job['error']['code']);
            }
        }
        exit(0);
    }
    // run
    set_time_limit(0);
    ignore_user_abort(true);
    $summary = $runtime->worker()->runCron();
    // Accounts that migrated or are migrating stop seeing the cPanel banner (one process at a time).
    $marksLock = $runtime->store->tryLock('banner-marks');
    if ($marksLock !== null) {
        try {
            $runtime->bannerMarks()->sync();
        } catch (\Throwable $error) {
            $runtime->log->warning('banner.marks_failed', ['error' => $error]);
        } finally {
            $runtime->store->release($marksLock);
        }
    }
    if (!$summary['configured']) {
        exit(0);
    }
    if ($summary['polled'] > 0 || $summary['processed'] > 0) {
        $runtime->log->info('worker.run', $summary);
    }
    exit(0);
} catch (\Throwable $error) {
    fwrite(STDERR, "BladeCP cPanel Migrator: " . \BladeCP\CpanelMigrator\Support\Redactor::string($error->getMessage()) . "\n");
    exit(1);
}
