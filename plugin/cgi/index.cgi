#!/usr/local/cpanel/3rdparty/bin/php -q
<?php

declare(strict_types=1);

/*
 * BladeCP cPanel Migrator: WHM plugin entry point.
 *
 * Installed at /usr/local/cpanel/whostmgr/docroot/cgi/bladecp_migrator/index.cgi
 * and registered through AppConfig. The plugin code lives outside the WHM
 * document root; install.sh writes the real path below.
 */

$home = '@@PLUGIN_HOME@@';
if (!is_file($home . '/src/bootstrap.php')) {
    echo "Status: 500 Internal Server Error\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nBladeCP cPanel Migrator is not installed correctly. Run install.sh again.\n";
    exit(0);
}
require $home . '/src/bootstrap.php';

umask(0077);

try {
    $runtime = \BladeCP\CpanelMigrator\Runtime::production();
    $app = new \BladeCP\CpanelMigrator\Web\App(
        $runtime,
        \BladeCP\CpanelMigrator\Web\Request::fromGlobals(),
        \BladeCP\CpanelMigrator\Runtime::effectiveUid() === 0
    );
    $app->handle()->emit();
} catch (\Throwable $error) {
    try {
        // Outer WHM page, then the message in its frame; plain text only if even that fails.
        \BladeCP\CpanelMigrator\Web\App::startupFailure(\BladeCP\CpanelMigrator\Web\Request::fromGlobals())->emit();
    } catch (\Throwable $fallback) {
        echo "Status: 500 Internal Server Error\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nBladeCP cPanel Migrator could not start. Check that the data directory exists and is owned by root.\n";
    }
}
