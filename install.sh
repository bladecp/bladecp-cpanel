#!/usr/bin/env bash
#
# BladeCP cPanel Migrator installer.
#
# Run as root from the extracted release directory:
#   ./install.sh
#
# Safe to run again: it upgrades the plugin in place and keeps the settings,
# the installation ID and the migration queue.

set -euo pipefail
umask 022

APP_NAME="bladecp_migrator"
SRC_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# Test-only prefix used by the private test suite. Never set it on a server.
ROOT="${BLADECP_MIGRATOR_TEST_ROOT:-}"

HOME_DIR="$ROOT/usr/local/bladecp-migrator"
CGI_DIR="$ROOT/usr/local/cpanel/whostmgr/docroot/cgi/bladecp_migrator"
ICON_DIR="$ROOT/usr/local/cpanel/whostmgr/docroot/addon_plugins"
DATA_DIR="$ROOT/var/cpanel/bladecp-migrator"
CRON_FILE="$ROOT/etc/cron.d/bladecp-migrator"
REGISTER="$ROOT/usr/local/cpanel/bin/register_appconfig"
# cPanel app for every account (Jupiter theme, UAPI module and AdminBin).
THEME_DIR="$ROOT/usr/local/cpanel/base/frontend/jupiter"
APP_DIR="$THEME_DIR/site_migration"
APP_MARKER=".bladecp-migrator"
UAPI_FILE="$ROOT/usr/local/cpanel/Cpanel/API/BladeCPMigrator.pm"
ADMINBIN_DIR="$ROOT/usr/local/cpanel/bin/admin/BladeCP"
INSTALL_PLUGIN="$ROOT/usr/local/cpanel/scripts/install_plugin"
# Item of the Jupiter side menu: cPanel merges every /var/cpanel/plugins/*/menu/LeftMenu.yaml.
MENU_HOME="$ROOT/var/cpanel/plugins/bladecp_migrator"
CPANEL_CONFIG="$ROOT/var/cpanel/cpanel.config"
PHP_BIN="/usr/local/cpanel/3rdparty/bin/php"
if [ -n "$ROOT" ]; then
    PHP_BIN="${BLADECP_MIGRATOR_PHP:-php}"
fi

say() { printf '%s\n' "$*"; }
die() { printf 'Error: %s\n' "$*" >&2; exit 1; }

# --- checks -------------------------------------------------------------------

if [ -z "$ROOT" ] && [ "$(id -u)" -ne 0 ]; then
    die "run install.sh as root."
fi
if [ -z "$ROOT" ] && [ ! -x /usr/local/cpanel/cpanel ]; then
    die "cPanel & WHM was not found on this server."
fi
[ -x "$REGISTER" ] || die "register_appconfig was not found at $REGISTER."
[ -f "$SRC_DIR/plugin/src/bootstrap.php" ] || die "run install.sh from the extracted release directory."
command -v "$PHP_BIN" >/dev/null 2>&1 || [ -x "$PHP_BIN" ] || die "PHP was not found at $PHP_BIN."
"$PHP_BIN" -r 'exit(PHP_VERSION_ID >= 80100 ? 0 : 1);' || die "PHP 8.1 or newer is required ($PHP_BIN)."
"$PHP_BIN" -r 'exit(extension_loaded("curl") && extension_loaded("json") ? 0 : 1);' \
    || die "the PHP curl and json extensions are required ($PHP_BIN)."

# Never take over files that another product put in the same places.
if [ -e "$APP_DIR" ] && [ ! -f "$APP_DIR/$APP_MARKER" ]; then
    die "$APP_DIR already exists and was not created by this plugin."
fi
if [ -e "$UAPI_FILE" ] && ! grep -q '^package Cpanel::API::BladeCPMigrator;' "$UAPI_FILE"; then
    die "$UAPI_FILE already exists and was not created by this plugin."
fi
if [ -e "$MENU_HOME" ] && [ ! -f "$MENU_HOME/$APP_MARKER" ]; then
    die "$MENU_HOME already exists and was not created by this plugin."
fi

VERSION="$(tr -d '[:space:]' < "$SRC_DIR/plugin/VERSION")"
say "Installing BladeCP cPanel Migrator $VERSION"

owner_root() {
    if [ -z "$ROOT" ]; then
        chown -R root:root "$@"
    fi
}

# --- plugin code (outside the WHM document root) ------------------------------

STAGE="$HOME_DIR.new"
rm -rf "$STAGE"
mkdir -p "$STAGE"
cp -R "$SRC_DIR/plugin/src" "$SRC_DIR/plugin/bin" "$SRC_DIR/plugin/lang" "$SRC_DIR/plugin/appconfig" \
    "$SRC_DIR/plugin/assets" "$SRC_DIR/plugin/cgi" "$SRC_DIR/plugin/cpanel" "$SRC_DIR/plugin/VERSION" "$STAGE/"
cp "$SRC_DIR/uninstall.sh" "$STAGE/uninstall.sh"
# Registration data of the cPanel app (install_plugin and uninstall_plugin read it).
# The menu label follows the server language: cPanel does not translate plugin labels.
# The WHM name and description of the plugin (Plugins list, Favorites, WHM search) too.
LABEL="Migrate to BladeCP"
DISPLAYNAME="BladeCP: Migrate and Transfer Accounts"
DESCRIPTION="Migrate and transfer cPanel accounts from this server to BladeCP (transfer with a preview and billing through your WHMCS)."
case "$(sed -n 's/^server_locale=//p' "$CPANEL_CONFIG" 2>/dev/null | head -n 1 | tr '[:upper:]' '[:lower:]')" in
    pt*)
        LABEL="Migrar para BladeCP"
        DISPLAYNAME="BladeCP: Migrar e Transferir Contas"
        DESCRIPTION="Migre e transfira contas cPanel deste servidor para a BladeCP (transferência com prévia e cobrança pelo seu WHMCS)."
        ;;
    es*)
        LABEL="Migrar a BladeCP"
        DISPLAYNAME="BladeCP: Migrar y Transferir Cuentas"
        DESCRIPTION="Migra y transfiere cuentas cPanel de este servidor a BladeCP (transferencia con vista previa y cobro por tu WHMCS)."
        ;;
esac
APPCONFIG_FILE="$STAGE/appconfig/$APP_NAME.conf"
sed -e "s#^displayname=.*#displayname=$DISPLAYNAME#" -e "s#^description=.*#description=$DESCRIPTION#" "$APPCONFIG_FILE" > "$APPCONFIG_FILE.tmp"
mv "$APPCONFIG_FILE.tmp" "$APPCONFIG_FILE"
mkdir -p "$STAGE/cpanel-plugin"
sed "s#@@LABEL@@#$LABEL#" "$SRC_DIR/plugin/cpanel/plugin/install.json" > "$STAGE/cpanel-plugin/install.json"
cp "$SRC_DIR/plugin/cpanel/plugin/site_migration.svg" "$STAGE/cpanel-plugin/site_migration.svg"
# A menu/ directory here makes install_plugin and uninstall_plugin rebuild the side menu.
mkdir -p "$STAGE/cpanel-plugin/menu"
cp "$SRC_DIR/plugin/cpanel/plugin/menu/LeftMenu.yaml" "$STAGE/cpanel-plugin/menu/LeftMenu.yaml"
find "$STAGE" -type d -exec chmod 0755 {} +
find "$STAGE" -type f -exec chmod 0644 {} +
chmod 0755 "$STAGE/uninstall.sh"
owner_root "$STAGE"
if [ -d "$HOME_DIR" ]; then
    rm -rf "$HOME_DIR.old"
    mv "$HOME_DIR" "$HOME_DIR.old"
fi
mv "$STAGE" "$HOME_DIR"
rm -rf "$HOME_DIR.old"

# --- WHM entry point and icon -------------------------------------------------

mkdir -p "$CGI_DIR" "$ICON_DIR"
chmod 0755 "$CGI_DIR"
sed "s#@@PLUGIN_HOME@@#$HOME_DIR#" "$SRC_DIR/plugin/cgi/index.cgi" > "$CGI_DIR/index.cgi.tmp"
chmod 0755 "$CGI_DIR/index.cgi.tmp"
owner_root "$CGI_DIR/index.cgi.tmp"
mv "$CGI_DIR/index.cgi.tmp" "$CGI_DIR/index.cgi"
cp "$SRC_DIR/plugin/assets/bladecp_migrator.png" "$ICON_DIR/bladecp_migrator.png"
chmod 0644 "$ICON_DIR/bladecp_migrator.png"
owner_root "$ICON_DIR/bladecp_migrator.png"

# --- data directory, installation ID and form secret (kept on upgrade) --------

"$PHP_BIN" "$HOME_DIR/bin/worker.php" init --data-dir="$DATA_DIR" >/dev/null
chmod 0700 "$DATA_DIR"
owner_root "$DATA_DIR"

# --- cron worker --------------------------------------------------------------

mkdir -p "$(dirname "$CRON_FILE")"
{
    printf '# BladeCP cPanel Migrator queue worker. Managed by install.sh and removed by uninstall.sh.\n'
    printf '* * * * * root %s %s/bin/worker.php run >/dev/null 2>&1\n' "$PHP_BIN" "$HOME_DIR"
} > "$CRON_FILE.tmp"
chmod 0644 "$CRON_FILE.tmp"
owner_root "$CRON_FILE.tmp"
mv "$CRON_FILE.tmp" "$CRON_FILE"

# --- WHM registration (AppConfig) ---------------------------------------------

"$REGISTER" "$HOME_DIR/appconfig/$APP_NAME.conf" >/dev/null

# A favorite saved under a former name of the plugin (older version, other
# server language) points to nothing now: it becomes the current one, in the
# same place. Nothing is pinned here.
FAVORITES_ARGS=(favorites "--data-dir=$DATA_DIR")
if [ -n "$ROOT" ]; then
    FAVORITES_ARGS+=("--system-root=$ROOT")
fi
"$PHP_BIN" "$HOME_DIR/bin/worker.php" "${FAVORITES_ARGS[@]}" >/dev/null 2>&1 || true

# --- cPanel app for every account ------------------------------------------------
# The page runs as the account and holds no secret; the UAPI module forwards to
# the root AdminBin, which acts only on the account cPanel authenticated.

APP_READY=0
if [ -d "$THEME_DIR" ]; then
    APP_STAGE="$APP_DIR.new"
    rm -rf "$APP_STAGE"
    mkdir -p "$APP_STAGE/fonts"
    sed "s#@@PLUGIN_HOME@@#$HOME_DIR#" "$SRC_DIR/plugin/cpanel/theme/index.live.php" > "$APP_STAGE/index.live.php"
    cp "$SRC_DIR"/plugin/assets/fonts/*.woff2 "$APP_STAGE/fonts/"
    printf 'Created by BladeCP cPanel Migrator. Removed by uninstall.sh.\n' > "$APP_STAGE/$APP_MARKER"
    find "$APP_STAGE" -type d -exec chmod 0755 {} +
    find "$APP_STAGE" -type f -exec chmod 0644 {} +
    owner_root "$APP_STAGE"
    rm -rf "$APP_DIR"
    mv "$APP_STAGE" "$APP_DIR"

    mkdir -p "$(dirname "$UAPI_FILE")"
    cp "$SRC_DIR/plugin/cpanel/uapi/BladeCPMigrator.pm" "$UAPI_FILE.tmp"
    chmod 0644 "$UAPI_FILE.tmp"
    owner_root "$UAPI_FILE.tmp"
    mv "$UAPI_FILE.tmp" "$UAPI_FILE"

    mkdir -p "$ADMINBIN_DIR"
    chmod 0755 "$ADMINBIN_DIR"
    owner_root "$ADMINBIN_DIR"
    sed -e "s#@@PLUGIN_HOME@@#$HOME_DIR#" -e "s#@@PHP_BIN@@#$PHP_BIN#" "$SRC_DIR/plugin/cpanel/adminbin/MigratorClient" > "$ADMINBIN_DIR/MigratorClient.tmp"
    chmod 0700 "$ADMINBIN_DIR/MigratorClient.tmp"
    owner_root "$ADMINBIN_DIR/MigratorClient.tmp"
    mv "$ADMINBIN_DIR/MigratorClient.tmp" "$ADMINBIN_DIR/MigratorClient"
    cp "$SRC_DIR/plugin/cpanel/adminbin/MigratorClient.conf" "$ADMINBIN_DIR/MigratorClient.conf.tmp"
    chmod 0644 "$ADMINBIN_DIR/MigratorClient.conf.tmp"
    owner_root "$ADMINBIN_DIR/MigratorClient.conf.tmp"
    mv "$ADMINBIN_DIR/MigratorClient.conf.tmp" "$ADMINBIN_DIR/MigratorClient.conf"

    # Side menu item ("Migrate to BladeCP" with the B), read when install_plugin rebuilds the menu.
    mkdir -p "$MENU_HOME/menu"
    printf 'Created by BladeCP cPanel Migrator. Removed by uninstall.sh.\n' > "$MENU_HOME/$APP_MARKER"
    cp "$HOME_DIR/cpanel-plugin/menu/LeftMenu.yaml" "$MENU_HOME/menu/LeftMenu.yaml.tmp"
    chmod 0755 "$MENU_HOME" "$MENU_HOME/menu"
    chmod 0644 "$MENU_HOME/$APP_MARKER" "$MENU_HOME/menu/LeftMenu.yaml.tmp"
    owner_root "$MENU_HOME"
    mv "$MENU_HOME/menu/LeftMenu.yaml.tmp" "$MENU_HOME/menu/LeftMenu.yaml"

    if [ -x "$INSTALL_PLUGIN" ]; then
        if "$INSTALL_PLUGIN" "$HOME_DIR/cpanel-plugin" --theme=jupiter >/dev/null 2>&1; then
            APP_READY=1
            # install_plugin never rewrites an existing Feature Manager entry, so a
            # reinstall would keep an old label. Rewrite only our own entry, never a link.
            FEATURE_FILE="$ROOT/usr/local/cpanel/whostmgr/addonfeatures/site_migration"
            if [ ! -L "$FEATURE_FILE" ] && { [ ! -e "$FEATURE_FILE" ] || grep -q '^site_migration:' "$FEATURE_FILE"; }; then
                mkdir -p "$(dirname "$FEATURE_FILE")"
                printf 'site_migration:%s\n' "$LABEL" > "$FEATURE_FILE.tmp"
                chmod 0644 "$FEATURE_FILE.tmp"
                owner_root "$FEATURE_FILE.tmp"
                mv "$FEATURE_FILE.tmp" "$FEATURE_FILE"
            fi
        else
            say "Warning: the cPanel app could not be registered. Run install.sh again."
        fi
    else
        say "Warning: install_plugin was not found, so the cPanel app was not registered."
    fi
else
    say "Warning: the Jupiter theme was not found, so the cPanel app was not installed."
fi

# --- banner at the top of the cPanel home page (Content Includes) --------------
# On by default (Settings), only with the app registered, and never over an
# include of another product.

BANNER_ARGS=(banner "--data-dir=$DATA_DIR")
if [ -n "$ROOT" ]; then
    BANNER_ARGS+=("--system-root=$ROOT")
fi
if [ "$APP_READY" -ne 1 ]; then
    BANNER_ARGS+=(--remove)
fi
BANNER="$("$PHP_BIN" "$HOME_DIR/bin/worker.php" "${BANNER_ARGS[@]}" 2>/dev/null || echo banner=failed)"
case "$BANNER" in
    banner=foreign) say "Warning: another product already uses the cPanel home page include, so the banner was not installed." ;;
    banner=failed) say "Warning: the banner of the cPanel home page could not be installed. Run install.sh again." ;;
esac

say "BladeCP cPanel Migrator $VERSION is installed."
say "Open WHM, go to Plugins and choose \"$DISPLAYNAME\" to connect your partner API key."
say "To pin it first in the Favorites of the WHM home page, open the plugin and choose \"Pin to the top\" (also in Settings)."
if [ "$APP_READY" -eq 1 ]; then
    say "Every cPanel account now has the \"$LABEL\" app in the Domains group and in the side menu."
fi
