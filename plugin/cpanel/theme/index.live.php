<?php

declare(strict_types=1);

/*
 * cPanel app page (Jupiter theme, frontend/jupiter/site_migration/).
 *
 * cpsrvd runs this LiveAPI page as the logged-in account. It holds no
 * secret: the app asks the root side through UAPI and an AdminBin, which
 * acts only on this account. install.sh writes the real code path below.
 */

$home = '@@PLUGIN_HOME@@';
if (!is_file($home . '/src/bootstrap.php')) {
    header('Content-Type: text/plain; charset=utf-8', true, 503);
    echo "This page is not available right now.\n";
    exit(0);
}
require $home . '/src/bootstrap.php';

\BladeCP\CpanelMigrator\Client\ClientApp::run();
