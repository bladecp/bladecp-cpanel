<?php

declare(strict_types=1);

/*
 * BladeCP cPanel Migrator bootstrap.
 *
 * Native PSR-4 autoloader for the BladeCP\CpanelMigrator namespace. The plugin
 * has no Composer dependency: every class lives under plugin/src.
 */

if (!defined('BLADECP_MIGRATOR_ROOT')) {
    define('BLADECP_MIGRATOR_ROOT', dirname(__DIR__));
}

if (!defined('BLADECP_MIGRATOR_VERSION')) {
    $versionFile = BLADECP_MIGRATOR_ROOT . '/VERSION';
    $version = is_file($versionFile) ? trim((string) file_get_contents($versionFile)) : '';
    define('BLADECP_MIGRATOR_VERSION', preg_match('/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.]+)?$/', $version) === 1 ? $version : '0.0.0');
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'BladeCP\\CpanelMigrator\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    if (preg_match('/^[A-Za-z0-9_\\\\]+$/', $relative) !== 1) {
        return;
    }
    $path = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require $path;
    }
});
