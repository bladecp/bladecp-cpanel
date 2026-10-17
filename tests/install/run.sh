#!/usr/bin/env bash
#
# Install and uninstall test with a fake filesystem root (no cPanel needed).
# Checks idempotency, permissions, the cron entry, the WHM registration
# calls, data kept on a plain uninstall and no residue after --purge.

set -euo pipefail

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
BASE="$(mktemp -d "${TMPDIR:-/tmp}/bladecp-migrator-install.XXXXXX")"
ROOT="$BASE/root"
trap 'rm -rf "$BASE"' EXIT

PASS=0
FAIL=0
check() {
    local name="$1"
    shift
    if "$@"; then
        PASS=$((PASS + 1))
    else
        FAIL=$((FAIL + 1))
        printf '  FAIL  %s\n' "$name"
    fi
}
mode_of() {
    if stat -c '%a' "$1" >/dev/null 2>&1; then stat -c '%a' "$1"; else stat -f '%Lp' "$1"; fi
}

# Stub WHM registration tools: they record calls and copy/remove the conf.
mkdir -p "$ROOT/usr/local/cpanel/bin" "$ROOT/var/cpanel/apps"
cat > "$ROOT/usr/local/cpanel/bin/register_appconfig" <<STUB
#!/usr/bin/env bash
echo "register \$1" >> "$BASE/calls.log"
cp "\$1" "$ROOT/var/cpanel/apps/\$(basename "\$1")"
STUB
cat > "$ROOT/usr/local/cpanel/bin/unregister_appconfig" <<STUB
#!/usr/bin/env bash
echo "unregister \$1" >> "$BASE/calls.log"
rm -f "$ROOT/var/cpanel/apps/\$1.conf"
STUB
chmod 0755 "$ROOT/usr/local/cpanel/bin/register_appconfig" "$ROOT/usr/local/cpanel/bin/unregister_appconfig"

# Stub cPanel plugin tools and theme: they record calls and play the Feature Manager.
JUPITER="$ROOT/usr/local/cpanel/base/frontend/jupiter"
FEATURES="$ROOT/usr/local/cpanel/whostmgr/addonfeatures"
mkdir -p "$ROOT/usr/local/cpanel/scripts" "$JUPITER/dynamicui" "$FEATURES" "$ROOT/usr/local/cpanel/Cpanel/API" "$ROOT/var/cpanel"
printf 'server_locale=pt_br\n' > "$ROOT/var/cpanel/cpanel.config"
# Jupiter side menu: the built-in list, another plugin item and the combined cache.
MENU_BUILTIN="$ROOT/usr/local/cpanel/Cpanel/Plugins/menus/LeftMenu.yaml"
MENU_CACHE="$ROOT/var/cpanel/menus/LeftMenu.yaml"
MENU_HOME="$ROOT/var/cpanel/plugins/bladecp_migrator"
mkdir -p "$(dirname "$MENU_BUILTIN")" "$(dirname "$MENU_CACHE")" "$ROOT/var/cpanel/plugins/sitejet/menu"
printf -- '- key: wp-toolkit\n  order: 20\n  icon: <svg/>\n' > "$MENU_BUILTIN"
printf -- '- key: cpanel-sitejet-plugin\n  order: 10\n  icon: <svg/>\n' > "$ROOT/var/cpanel/plugins/sitejet/menu/LeftMenu.yaml"
cp "$MENU_BUILTIN" "$MENU_CACHE"
# Content includes of the cPanel pages, with an include of another product.
INCLUDES="$ROOT/var/cpanel/customizations/content_includes"
BANNER_INCLUDE="$INCLUDES/cpanel_jupiter_tools_header_after.html.tt"
mkdir -p "$INCLUDES"
printf '<div>analytics</div>\n' > "$INCLUDES/cpanel_jupiter_cp_analytics.html.tt"
# Like Cpanel::Plugin::Install: a menu/ directory in the plugin rebuilds the menu
# from the built-in list and every /var/cpanel/plugins/*/menu/LeftMenu.yaml.
cat > "$BASE/rebuild_menu.sh" <<STUB
#!/usr/bin/env bash
[ -d "\$1/menu" ] || exit 0
cat "$MENU_BUILTIN" \$(find "$ROOT/var/cpanel/plugins" -name LeftMenu.yaml | sort) > "$MENU_CACHE"
echo "build_menu LeftMenu" >> "$BASE/calls.log"
STUB
chmod 0755 "$BASE/rebuild_menu.sh"
cat > "$ROOT/usr/local/cpanel/scripts/install_plugin" <<STUB
#!/usr/bin/env bash
echo "install_plugin \$*" >> "$BASE/calls.log"
[ -f "\$1/install.json" ] && [ -f "\$1/site_migration.svg" ] || exit 1
label="\$(sed -n 's/.*"name": "\(.*\)".*/\1/p' "\$1/install.json")"
# Like cPanel: an existing Feature Manager entry is never rewritten.
[ -e "$FEATURES/site_migration" ] || printf 'site_migration:%s\n' "\$label" > "$FEATURES/site_migration"
cp "\$1/install.json" "$JUPITER/dynamicui/dynamicui_site_migration.conf"
"$BASE/rebuild_menu.sh" "\$1"
STUB
cat > "$ROOT/usr/local/cpanel/scripts/uninstall_plugin" <<STUB
#!/usr/bin/env bash
echo "uninstall_plugin \$*" >> "$BASE/calls.log"
[ -f "\$1/install.json" ] || exit 1
rm -f "$FEATURES/site_migration" "$JUPITER/dynamicui/dynamicui_site_migration.conf"
"$BASE/rebuild_menu.sh" "\$1"
STUB
chmod 0755 "$ROOT/usr/local/cpanel/scripts/install_plugin" "$ROOT/usr/local/cpanel/scripts/uninstall_plugin"
# whmapi1 personalization_get/set for the root favorites (JSON on stdin), with the
# list in a file outside the fake root. The plugin runs it with a minimal
# environment, so the paths are written into the stub.
FAVORITES_STATE="$BASE/whm-favorites.json"
PHP_ABS="$(command -v "${PHP:-php}")"
cat > "$BASE/fake_whmapi1.php" <<'PHP'
<?php
[$self, $state, $log] = [$argv[0], $argv[1], $argv[2]];
$function = (string) end($argv);
file_put_contents($log, "whmapi1 {$function}\n", FILE_APPEND);
$input = json_decode((string) stream_get_contents(STDIN), true);
$saved = is_file($state) ? json_decode((string) file_get_contents($state), true) : null;
if ($function === 'personalization_set') {
    $saved = $input['personalization']['favorites'];
    file_put_contents($state, json_encode($saved));
}
echo json_encode(['data' => ['personalization' => ['favorites' => ['value' => $saved, 'success' => 1, 'reason' => 'OK']]],
    'metadata' => ['result' => 1, 'reason' => 'OK', 'command' => $function]]);
PHP
cat > "$ROOT/usr/local/cpanel/bin/whmapi1" <<STUB
#!/usr/bin/env bash
exec "$PHP_ABS" "$BASE/fake_whmapi1.php" "$FAVORITES_STATE" "$BASE/calls.log" "\$@"
STUB
chmod 0755 "$ROOT/usr/local/cpanel/bin/whmapi1"
find "$ROOT" -type f | sort > "$BASE/before.txt"

export BLADECP_MIGRATOR_TEST_ROOT="$ROOT"
export BLADECP_MIGRATOR_PHP="${PHP:-php}"

echo "== install"
"$REPO/install.sh" > "$BASE/install1.log"
cp "$BASE/calls.log" "$BASE/calls-install1.log"
# A favorite saved under the name of 0.3.4, between two other favorites.
printf '["account_information$list_accounts","plugins$bladecp_cpanel_migrator","plugins$wp_toolkit"]' > "$FAVORITES_STATE"
ID1="$(sed -n 's/.*"installation_id": "\(.*\)".*/\1/p' "$ROOT/var/cpanel/bladecp-migrator/config.json")"
# An entry left by an earlier version keeps its old label in install_plugin.
printf 'site_migration:Migrar meu site\n' > "$ROOT/usr/local/cpanel/whostmgr/addonfeatures/site_migration"
"$REPO/install.sh" > "$BASE/install2.log"
ID2="$(sed -n 's/.*"installation_id": "\(.*\)".*/\1/p' "$ROOT/var/cpanel/bladecp-migrator/config.json")"

check "plugin code installed" test -f "$ROOT/usr/local/bladecp-migrator/src/bootstrap.php"
check "uninstaller shipped with the plugin" test -x "$ROOT/usr/local/bladecp-migrator/uninstall.sh"
check "CGI installed and executable" test -x "$ROOT/usr/local/cpanel/whostmgr/docroot/cgi/bladecp_migrator/index.cgi"
check "CGI points to the plugin home" grep -q "'$ROOT/usr/local/bladecp-migrator'" "$ROOT/usr/local/cpanel/whostmgr/docroot/cgi/bladecp_migrator/index.cgi"
check "CGI placeholder replaced" bash -c "! grep -q '@@PLUGIN_HOME@@' '$ROOT/usr/local/cpanel/whostmgr/docroot/cgi/bladecp_migrator/index.cgi'"
check "icon installed" test -f "$ROOT/usr/local/cpanel/whostmgr/docroot/addon_plugins/bladecp_migrator.png"
check "AppConfig registered" test -f "$ROOT/var/cpanel/apps/bladecp_migrator.conf"
check "cron entry runs the worker every minute as root" grep -q "^\* \* \* \* \* root .*/usr/local/bladecp-migrator/bin/worker.php run" "$ROOT/etc/cron.d/bladecp-migrator"
check "cron file mode 644" test "$(mode_of "$ROOT/etc/cron.d/bladecp-migrator")" = "644"
check "data directory mode 700" test "$(mode_of "$ROOT/var/cpanel/bladecp-migrator")" = "700"
check "config mode 600" test "$(mode_of "$ROOT/var/cpanel/bladecp-migrator/config.json")" = "600"
check "installation id generated" test -n "$ID1"
check "reinstall keeps the installation id" test "$ID1" = "$ID2"
check "reinstall leaves no staging directories" bash -c "! ls -d '$ROOT/usr/local/bladecp-migrator.new' '$ROOT/usr/local/bladecp-migrator.old' 2>/dev/null"
APP="$JUPITER/site_migration"
HOME_PLUGIN="$ROOT/usr/local/bladecp-migrator"
check "cPanel app page installed in the Jupiter theme" test -f "$APP/index.live.php"
check "cPanel app page points to the plugin home" grep -q "'$HOME_PLUGIN'" "$APP/index.live.php"
check "cPanel app page placeholder replaced" bash -c "! grep -q '@@PLUGIN_HOME@@' '$APP/index.live.php'"
check "cPanel app page readable by the accounts" test "$(mode_of "$APP/index.live.php")" = "644" -a "$(mode_of "$APP")" = "755"
check "cPanel app fonts installed" test "$(find "$APP/fonts" -name '*.woff2' | wc -l | tr -d ' ')" = "4"
check "cPanel app marker written" test -f "$APP/.bladecp-migrator"
check "UAPI module installed with mode 644" test "$(mode_of "$ROOT/usr/local/cpanel/Cpanel/API/BladeCPMigrator.pm")" = "644"
check "AdminBin installed root-only (700)" test "$(mode_of "$ROOT/usr/local/cpanel/bin/admin/BladeCP/MigratorClient")" = "700"
check "AdminBin conf installed with mode 644" test "$(mode_of "$ROOT/usr/local/cpanel/bin/admin/BladeCP/MigratorClient.conf")" = "644"
check "AdminBin placeholders replaced" bash -c "! grep -q '@@' '$ROOT/usr/local/cpanel/bin/admin/BladeCP/MigratorClient' && grep -q '$HOME_PLUGIN/bin/client-admin.php' '$ROOT/usr/local/cpanel/bin/admin/BladeCP/MigratorClient'"
check "cPanel app registered for Jupiter through install_plugin" grep -q "^install_plugin $HOME_PLUGIN/cpanel-plugin --theme=jupiter$" "$BASE/calls.log"
check "Menu label follows the server language" grep -q '"name": "Migrar para BladeCP"' "$HOME_PLUGIN/cpanel-plugin/install.json"
check "Menu icon is the BladeCP B, 48x48 SVG like the Jupiter icons, with the image the sprite keeps" bash -c "grep -q 'viewBox=\"0 0 48 48\"' '$HOME_PLUGIN/cpanel-plugin/site_migration.svg' && grep -q 'href=\"data:image/png;base64,' '$HOME_PLUGIN/cpanel-plugin/site_migration.svg' && ! grep -q 'linearGradient' '$HOME_PLUGIN/cpanel-plugin/site_migration.svg'"
check "The app is a feature for every account (Feature Manager entry)" grep -qx 'site_migration:Migrar para BladeCP' "$FEATURES/site_migration"
check "A reinstall writes the current label over an old Feature Manager entry" test "$(cat "$FEATURES/site_migration")" = "site_migration:Migrar para BladeCP"
check "Feature Manager entry has mode 644 and no staging file" bash -c "test \"\$(stat -c '%a' '$FEATURES/site_migration' 2>/dev/null || stat -f '%Lp' '$FEATURES/site_migration')\" = 644 && test ! -e '$FEATURES/site_migration.tmp'"
check "Installer says every account has the app" grep -q 'Every cPanel account now has the "Migrar para BladeCP" app' "$BASE/install1.log"
check "cPanel app leaves no staging files" bash -c "! ls -d '$APP.new' '$ROOT/usr/local/cpanel/Cpanel/API/BladeCPMigrator.pm.tmp' '$ROOT/usr/local/cpanel/bin/admin/BladeCP/MigratorClient.tmp' 2>/dev/null"
check "Side menu item installed in /var/cpanel/plugins with its marker" bash -c "test -f '$MENU_HOME/menu/LeftMenu.yaml' && test -f '$MENU_HOME/.bladecp-migrator' && test \"\$(stat -c '%a' '$MENU_HOME/menu/LeftMenu.yaml' 2>/dev/null || stat -f '%Lp' '$MENU_HOME/menu/LeftMenu.yaml')\" = 644"
check "The plugin data for install_plugin has the menu (so cPanel rebuilds the side menu)" test -f "$HOME_PLUGIN/cpanel-plugin/menu/LeftMenu.yaml"
check "The rebuilt side menu has the item and keeps the others" bash -c "grep -qx -- '- key: site_migration' '$MENU_CACHE' && grep -q 'cpanel-sitejet-plugin' '$MENU_CACHE' && grep -q 'wp-toolkit' '$MENU_CACHE'"
check "Banner include installed with the plugin signature (644)" bash -c "head -n 1 '$BANNER_INCLUDE' | grep -q '^\[%# BladeCP cPanel Migrator: cPanel home page banner.' && test \"\$(stat -c '%a' '$BANNER_INCLUDE' 2>/dev/null || stat -f '%Lp' '$BANNER_INCLUDE')\" = 644"
check "Banner marker kept in the data directory" test -f "$ROOT/var/cpanel/bladecp-migrator/cpanel-banner.installed"
check "Includes of other products are kept" grep -qx '<div>analytics</div>' "$INCLUDES/cpanel_jupiter_cp_analytics.html.tt"
check "Installer explains how to pin the plugin and pins nothing" bash -c "grep -q 'choose \"Pin to the top\" (also in Settings)' '$BASE/install1.log' && ! grep -q 'personalization_set' '$BASE/calls-install1.log'"
check "Without saved favorites the installer only reads them" bash -c "grep -q 'whmapi1 personalization_get' '$BASE/calls-install1.log'"
check "A reinstall moves the favorite of the former name to the new one, in the same place" grep -qxF '["account_information$list_accounts","plugins$bladecp_migrar_e_transferir_contas","plugins$wp_toolkit"]' "$FAVORITES_STATE"
check "The AppConfig name, description and search words follow the server language (pt)" bash -c "grep -qx 'displayname=BladeCP: Migrar e Transferir Contas' '$ROOT/var/cpanel/apps/bladecp_migrator.conf' && grep -qx 'description=Migre e transfira contas cPanel deste servidor para a BladeCP (transferência com prévia e cobrança pelo seu WHMCS).' '$ROOT/var/cpanel/apps/bladecp_migrator.conf' && grep -q '^searchtext=.* transfer .*transferir' '$ROOT/var/cpanel/apps/bladecp_migrator.conf' && ! grep -q '^section=' '$ROOT/var/cpanel/apps/bladecp_migrator.conf' && cmp -s '$ROOT/var/cpanel/apps/bladecp_migrator.conf' '$HOME_PLUGIN/appconfig/bladecp_migrator.conf'"
check "Installer names the plugin as WHM lists it" grep -q 'choose "BladeCP: Migrar e Transferir Contas"' "$BASE/install1.log"
for locale_case in "en|BladeCP: Migrate and Transfer Accounts|Migrate and transfer cPanel accounts from this server to BladeCP (transfer with a preview and billing through your WHMCS).|bladecp_migrate_and_transfer_accounts" \
    "es_419|BladeCP: Migrar y Transferir Cuentas|Migra y transfiere cuentas cPanel de este servidor a BladeCP (transferencia con vista previa y cobro por tu WHMCS).|bladecp_migrar_y_transferir_cuentas"; do
    IFS='|' read -r locale_code locale_name locale_description locale_key <<< "$locale_case"
    printf 'server_locale=%s\n' "$locale_code" > "$ROOT/var/cpanel/cpanel.config"
    "$REPO/install.sh" > "$BASE/install-$locale_code.log"
    check "AppConfig name and description in $locale_code" bash -c "grep -qxF 'displayname=$locale_name' '$ROOT/var/cpanel/apps/bladecp_migrator.conf' && grep -qxF 'description=$locale_description' '$ROOT/var/cpanel/apps/bladecp_migrator.conf'"
    check "The favorite follows the new name ($locale_code), in the same place" grep -qxF "[\"account_information\$list_accounts\",\"plugins\$$locale_key\",\"plugins\$wp_toolkit\"]" "$FAVORITES_STATE"
done
printf 'server_locale=pt_br\n' > "$ROOT/var/cpanel/cpanel.config"
rm -f "$FAVORITES_STATE"
"$REPO/install.sh" > "$BASE/install-pt-again.log"
check "Installer says the app is in the side menu too" grep -q 'in the Domains group and in the side menu' "$BASE/install1.log"
CONFIG="$ROOT/var/cpanel/bladecp-migrator/config.json"
set_banner() { "$BLADECP_MIGRATOR_PHP" -r '$c = json_decode(file_get_contents($argv[1]), true); $c["cpanel_banner"] = $argv[2] === "on"; file_put_contents($argv[1], json_encode($c));' "$CONFIG" "$1"; }
set_banner off
"$REPO/install.sh" > "$BASE/install-banner-off.log"
check "Banner off in Settings: a reinstall removes the include" bash -c "test ! -e '$BANNER_INCLUDE' && test ! -e '$ROOT/var/cpanel/bladecp-migrator/cpanel-banner.installed'"
set_banner on
"$REPO/install.sh" > "$BASE/install-banner-on.log"
check "Banner on again: a reinstall puts it back" test -f "$BANNER_INCLUDE"
check "AdminBin command answers for an account" bash -c "mkdir -p '$BASE/users' && touch '$BASE/users/alice' && printf '{}' | '$BLADECP_MIGRATOR_PHP' '$HOME_PLUGIN/bin/client-admin.php' status --user=alice --data-dir='$ROOT/var/cpanel/bladecp-migrator' --users-dir='$BASE/users' | grep -q '\"ok\":true'"
check "worker status runs" bash -c "'$BLADECP_MIGRATOR_PHP' '$ROOT/usr/local/bladecp-migrator/bin/worker.php' status --data-dir='$ROOT/var/cpanel/bladecp-migrator' | grep -q 'No migrations'"
check "worker refuses the default data directory without root" bash -c "[ \"\$(id -u)\" = 0 ] || ! '$BLADECP_MIGRATOR_PHP' '$ROOT/usr/local/bladecp-migrator/bin/worker.php' run 2>/dev/null"
if [ "$(id -u)" != "0" ]; then
    check "CGI starts and fails closed without the data directory" bash -c "REQUEST_METHOD=GET '$BLADECP_MIGRATOR_PHP' '$ROOT/usr/local/cpanel/whostmgr/docroot/cgi/bladecp_migrator/index.cgi' | head -1 | grep -q '^Status: 500'"
fi

echo "== uninstall (keep data)"
mkdir -p "$ROOT/home/bladecp-migrator/0f8b1e4a-1c2d-4e5f-8a9b-0c1d2e3f4a5b" "$ROOT/home/bladecp-migrator/keep-me"
touch "$ROOT/home/bladecp-migrator/.bladecp-migrator" "$ROOT/home/bladecp-migrator/0f8b1e4a-1c2d-4e5f-8a9b-0c1d2e3f4a5b/cpmove-alice.tar.gz"
"$ROOT/usr/local/bladecp-migrator/uninstall.sh" > "$BASE/uninstall1.log"
check "plugin code removed" test ! -e "$ROOT/usr/local/bladecp-migrator"
check "CGI removed" test ! -e "$ROOT/usr/local/cpanel/whostmgr/docroot/cgi/bladecp_migrator"
check "icon removed" test ! -e "$ROOT/usr/local/cpanel/whostmgr/docroot/addon_plugins/bladecp_migrator.png"
check "cron removed" test ! -e "$ROOT/etc/cron.d/bladecp-migrator"
check "AppConfig unregistered" test ! -e "$ROOT/var/cpanel/apps/bladecp_migrator.conf"
check "cPanel app unregistered through uninstall_plugin" grep -q "^uninstall_plugin $HOME_PLUGIN/cpanel-plugin --theme=jupiter$" "$BASE/calls.log"
check "cPanel app feature removed" test ! -e "$FEATURES/site_migration"
check "cPanel app page removed" test ! -e "$APP"
check "UAPI module removed" test ! -e "$ROOT/usr/local/cpanel/Cpanel/API/BladeCPMigrator.pm"
check "AdminBin removed" test ! -e "$ROOT/usr/local/cpanel/bin/admin/BladeCP"
check "Banner include removed" bash -c "test ! -e '$BANNER_INCLUDE' && test ! -e '$ROOT/var/cpanel/bladecp-migrator/cpanel-banner.installed'"
check "Includes of other products kept on uninstall" grep -qx '<div>analytics</div>' "$INCLUDES/cpanel_jupiter_cp_analytics.html.tt"
check "Side menu item removed before the menu was rebuilt" bash -c "test ! -e '$MENU_HOME' && ! grep -q 'site_migration' '$MENU_CACHE' && grep -q 'cpanel-sitejet-plugin' '$MENU_CACHE'"
check "settings kept" test -f "$ROOT/var/cpanel/bladecp-migrator/config.json"
check "temporary backups removed" test ! -e "$ROOT/home/bladecp-migrator/0f8b1e4a-1c2d-4e5f-8a9b-0c1d2e3f4a5b"
check "unrelated directories in the work directory kept" test -d "$ROOT/home/bladecp-migrator/keep-me"
"$REPO/uninstall.sh" > "$BASE/uninstall2.log"
check "uninstall is idempotent" grep -q "was removed" "$BASE/uninstall2.log"

echo "== reinstall keeps the installation"
"$REPO/install.sh" > "$BASE/install3.log"
ID3="$(sed -n 's/.*"installation_id": "\(.*\)".*/\1/p' "$ROOT/var/cpanel/bladecp-migrator/config.json")"
check "installation id survives uninstall and reinstall" test "$ID1" = "$ID3"

echo "== uninstall --purge"
rmdir "$ROOT/home/bladecp-migrator/keep-me"
"$ROOT/usr/local/bladecp-migrator/uninstall.sh" --purge > "$BASE/uninstall3.log"
check "data directory removed" test ! -e "$ROOT/var/cpanel/bladecp-migrator"
check "work directory removed" test ! -e "$ROOT/home/bladecp-migrator"
find "$ROOT" -type f | sort > "$BASE/after.txt"
check "no residue besides the WHM stubs" diff -q "$BASE/before.txt" "$BASE/after.txt"
check "registration calls recorded" grep -q "^unregister bladecp_migrator" "$BASE/calls.log"

echo "== install refuses to take over another app"
mkdir -p "$APP"
printf 'other product\n' > "$APP/index.html"
check "install stops when site_migration belongs to someone else" bash -c "! '$REPO/install.sh' > '$BASE/install-foreign.log' 2>&1"
check "nothing was installed or changed" bash -c "test ! -e '$HOME_PLUGIN' && grep -qx 'other product' '$APP/index.html' && grep -q 'was not created by this plugin' '$BASE/install-foreign.log'"
rm -rf "$APP"
mkdir -p "$MENU_HOME"
check "install stops when the side menu directory belongs to someone else" bash -c "! '$REPO/install.sh' > '$BASE/install-foreign-menu.log' 2>&1 && grep -q 'was not created by this plugin' '$BASE/install-foreign-menu.log'"
rmdir "$MENU_HOME"

echo "== an include of another product is never replaced"
printf '<p>notice of the hosting company</p>\n' > "$BANNER_INCLUDE"
"$REPO/install.sh" > "$BASE/install-foreign-include.log"
check "install keeps the foreign include and warns" bash -c "grep -qx '<p>notice of the hosting company</p>' '$BANNER_INCLUDE' && grep -q 'another product already uses the cPanel home page include' '$BASE/install-foreign-include.log'"
"$ROOT/usr/local/bladecp-migrator/uninstall.sh" --purge > "$BASE/uninstall-foreign-include.log"
check "uninstall keeps the foreign include" grep -qx '<p>notice of the hosting company</p>' "$BANNER_INCLUDE"
rm -f "$BANNER_INCLUDE"

echo
if [ "$FAIL" -eq 0 ]; then
    echo "PASS: $PASS install checks"
    exit 0
fi
echo "FAIL: $FAIL failed, $PASS passed"
exit 1
