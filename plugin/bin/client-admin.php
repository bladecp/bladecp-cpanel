<?php

declare(strict_types=1);

/*
 * BladeCP cPanel Migrator: root side of the cPanel app.
 *
 *   client-admin.php status|start|act|claim --user=<account>   JSON request on stdin, JSON answer on stdout
 *
 * Run only by the AdminBin (/usr/local/cpanel/bin/admin/BladeCP/MigratorClient),
 * which cPanel starts as root with the UID of the logged-in account and
 * which passes that account here. The request fields come from the cPanel
 * session; the account never does.
 *
 * Options --data-dir=PATH and --users-dir=PATH select other directories
 * (used by tests); with them the root check is skipped, like worker.php.
 */

require dirname(__DIR__) . '/src/bootstrap.php';

use BladeCP\CpanelMigrator\Client\Backend;
use BladeCP\CpanelMigrator\Runtime;
use BladeCP\CpanelMigrator\Support\LocaleResolver;
use BladeCP\CpanelMigrator\System\WhmSystem;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

umask(0077);

$answer = static function (array $data): never {
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR), "\n";
    exit(0);
};
$unavailable = ['ok' => false, 'available' => false, 'notice' => 'client.notice.unavailable'];

$function = '';
$user = '';
$dataDir = Runtime::DATA_DIR;
$usersDir = '/var/cpanel/users';
$custom = false;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--user=')) {
        $user = substr($arg, 7);
    } elseif (str_starts_with($arg, '--data-dir=')) {
        $dataDir = rtrim(substr($arg, 11), '/');
        $custom = true;
    } elseif (str_starts_with($arg, '--users-dir=')) {
        $usersDir = rtrim(substr($arg, 12), '/');
        $custom = true;
    } elseif ($arg !== '' && $arg[0] !== '-') {
        $function = $arg;
    }
}

if (!in_array($function, Backend::FUNCTIONS, true) || !WhmSystem::validUser($user)) {
    $answer($unavailable);
}
if (!$custom && Runtime::effectiveUid() !== 0) {
    fwrite(STDERR, "BladeCP cPanel Migrator: run this command as root.\n");
    exit(1);
}
if ($dataDir === '' || $dataDir[0] !== '/' || $usersDir === '' || $usersDir[0] !== '/') {
    $answer($unavailable);
}
// Only real cPanel accounts (the users file exists and is not a link).
$userFile = $usersDir . '/' . $user;
if (!is_file($userFile) || is_link($userFile)) {
    $answer($unavailable);
}

$raw = (string) stream_get_contents(STDIN, 16385);
if (strlen($raw) > 16384) {
    $answer($unavailable);
}
$args = $raw === '' ? [] : json_decode($raw, true);
if (!is_array($args)) {
    $answer($unavailable);
}

try {
    $runtime = Runtime::production($dataDir);
} catch (\Throwable) {
    $answer($unavailable);
}
$answer((new Backend($runtime, $user, new LocaleResolver($usersDir)))->handle($function, $args));
