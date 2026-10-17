#!/usr/bin/env bash
#
# BladeCP cPanel Migrator uninstaller.
#
#   ./uninstall.sh           removes the plugin, the cPanel app, the cron job,
#                            the WHM registration and temporary backups; keeps
#                            the settings and migration history
#   ./uninstall.sh --purge   also removes the settings, the API key, the
#                            migration history and the work directory
#
# Safe to run more than once. It never touches cPanel accounts.

set -euo pipefail

APP_NAME="bladecp_migrator"
ROOT="${BLADECP_MIGRATOR_TEST_ROOT:-}"

HOME_DIR="$ROOT/usr/local/bladecp-migrator"
CGI_DIR="$ROOT/usr/local/cpanel/whostmgr/docroot/cgi/bladecp_migrator"
ICON_FILE="$ROOT/usr/local/cpanel/whostmgr/docroot/addon_plugins/bladecp_migrator.png"
DATA_DIR="$ROOT/var/cpanel/bladecp-migrator"
CRON_FILE="$ROOT/etc/cron.d/bladecp-migrator"
UNREGISTER="$ROOT/usr/local/cpanel/bin/unregister_appconfig"
APP_DIR="$ROOT/usr/local/cpanel/base/frontend/jupiter/site_migration"
UAPI_FILE="$ROOT/usr/local/cpanel/Cpanel/API/BladeCPMigrator.pm"
ADMINBIN_DIR="$ROOT/usr/local/cpanel/bin/admin/BladeCP"
UNINSTALL_PLUGIN="$ROOT/usr/local/cpanel/scripts/uninstall_plugin"
FEATURE_FILE="$ROOT/usr/local/cpanel/whostmgr/addonfeatures/site_migration"
MENU_HOME="$ROOT/var/cpanel/plugins/bladecp_migrator"
MENU_CACHE="$ROOT/var/cpanel/menus/LeftMenu.yaml"
CPANEL_PERL="$ROOT/usr/local/cpanel/3rdparty/bin/perl"
BANNER_FILE="$ROOT/var/cpanel/customizations/content_includes/cpanel_jupiter_tools_header_after.html.tt"
BANNER_SIGNATURE='[%# BladeCP cPanel Migrator: cPanel home page banner. Written by the plugin, replaced on update and removed on uninstall. %]'
PHP_BIN="/usr/local/cpanel/3rdparty/bin/php"
if [ -n "$ROOT" ]; then
    PHP_BIN="${BLADECP_MIGRATOR_PHP:-php}"
fi
DEFAULT_WORK_DIR="$ROOT/home/bladecp-migrator"

PURGE=0
for arg in "$@"; do
    case "$arg" in
        --purge) PURGE=1 ;;
        -h|--help)
            printf 'Usage: uninstall.sh [--purge]\n'
            exit 0
            ;;
        *)
            printf 'Error: unknown option %s\n' "$arg" >&2
            exit 2
            ;;
    esac
done

say() { printf '%s\n' "$*"; }

if [ -z "$ROOT" ] && [ "$(id -u)" -ne 0 ]; then
    printf 'Error: run uninstall.sh as root.\n' >&2
    exit 1
fi

# Stop new worker runs first, then the running one.
rm -f "$CRON_FILE"
if [ -z "$ROOT" ] && command -v pkill >/dev/null 2>&1; then
    pkill -TERM -f "$HOME_DIR/bin/worker.php" 2>/dev/null || true
fi

# Work directory from the saved settings (default when unavailable).
WORK_DIR=""
if [ -f "$HOME_DIR/bin/worker.php" ] && [ -d "$DATA_DIR" ]; then
    WORK_DIR="$("$PHP_BIN" "$HOME_DIR/bin/worker.php" paths --data-dir="$DATA_DIR" 2>/dev/null | sed -n 's/^work_dir=//p' || true)"
fi
if [ -n "$ROOT" ] && [ -n "$WORK_DIR" ] && [ "${WORK_DIR#"$ROOT"}" = "$WORK_DIR" ]; then
    WORK_DIR="$ROOT$WORK_DIR"
fi
[ -n "$WORK_DIR" ] || WORK_DIR="$DEFAULT_WORK_DIR"

# WHM registration.
if [ -x "$UNREGISTER" ]; then
    "$UNREGISTER" "$APP_NAME" >/dev/null 2>&1 \
        || "$UNREGISTER" "$HOME_DIR/appconfig/$APP_NAME.conf" >/dev/null 2>&1 \
        || say "Warning: the WHM registration could not be removed automatically."
fi

# Banner of the cPanel home page: only the include this plugin wrote (its first line).
if [ -f "$BANNER_FILE" ] && [ ! -L "$BANNER_FILE" ] && [ "$(head -n 1 "$BANNER_FILE")" = "$BANNER_SIGNATURE" ]; then
    rm -f "$BANNER_FILE"
fi
rm -f "$DATA_DIR/cpanel-banner.installed"

# Side menu item: its file goes first, so the menu rebuilt by uninstall_plugin leaves it out.
if [ -d "$MENU_HOME" ] && [ ! -L "$MENU_HOME" ] && [ -f "$MENU_HOME/.bladecp-migrator" ]; then
    rm -rf "$MENU_HOME"
fi

# cPanel app: menu entry and feature first (the registration data lives in the
# plugin home), then the page, the UAPI module and the AdminBin.
if [ -x "$UNINSTALL_PLUGIN" ] && [ -f "$HOME_DIR/cpanel-plugin/install.json" ]; then
    "$UNINSTALL_PLUGIN" "$HOME_DIR/cpanel-plugin" --theme=jupiter >/dev/null 2>&1 \
        || say "Warning: the cPanel app could not be removed from the cPanel menu automatically."
fi
# The side menu cache still lists the item (uninstall_plugin did not run): rebuild it the way cPanel does.
if [ -z "$ROOT" ] && [ -f "$MENU_CACHE" ] && grep -q 'key: site_migration$' "$MENU_CACHE" && [ -x "$CPANEL_PERL" ]; then
    "$CPANEL_PERL" -I/usr/local/cpanel -MCpanel::Plugins::MenuBuilder -e 'Cpanel::Plugins::MenuBuilder::build_menu("LeftMenu")' >/dev/null 2>&1 \
        || say "Warning: the cPanel side menu could not be rebuilt. It hides the item once the app is gone."
fi
if [ -f "$FEATURE_FILE" ] && grep -q '^site_migration:' "$FEATURE_FILE"; then
    rm -f "$FEATURE_FILE"
fi
if [ -d "$APP_DIR" ] && [ ! -L "$APP_DIR" ] && [ -f "$APP_DIR/.bladecp-migrator" ]; then
    rm -rf "$APP_DIR"
fi
if [ -d "$APP_DIR.new" ] && [ -f "$APP_DIR.new/.bladecp-migrator" ]; then
    rm -rf "$APP_DIR.new"
fi
if [ -f "$UAPI_FILE" ] && grep -q '^package Cpanel::API::BladeCPMigrator;' "$UAPI_FILE"; then
    rm -f "$UAPI_FILE"
fi
rm -f "$UAPI_FILE.tmp" "$ADMINBIN_DIR/MigratorClient" "$ADMINBIN_DIR/MigratorClient.conf" "$ADMINBIN_DIR/MigratorClient.tmp" "$ADMINBIN_DIR/MigratorClient.conf.tmp"
rmdir "$ADMINBIN_DIR" 2>/dev/null || true

# Plugin files.
rm -rf "$CGI_DIR" "$HOME_DIR" "$HOME_DIR.new" "$HOME_DIR.old"
rm -f "$ICON_FILE"

# Temporary backups. Only directories created by the plugin (marker file,
# job directories named by UUID) are removed.
if [ -d "$WORK_DIR" ] && [ ! -L "$WORK_DIR" ] && [ -f "$WORK_DIR/.bladecp-migrator" ]; then
    for job in "$WORK_DIR"/*; do
        name="$(basename "$job")"
        if [ -d "$job" ] && [ ! -L "$job" ] && printf '%s' "$name" | grep -Eq '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$'; then
            rm -rf "$job"
        fi
    done
    if [ "$PURGE" -eq 1 ]; then
        rm -f "$WORK_DIR/.bladecp-migrator"
        rmdir "$WORK_DIR" 2>/dev/null || say "Warning: $WORK_DIR is not empty and was kept."
    fi
fi

if [ "$PURGE" -eq 1 ]; then
    rm -rf "$DATA_DIR"
    say "BladeCP cPanel Migrator was removed, including its settings and migration history."
else
    say "BladeCP cPanel Migrator was removed."
    if [ -d "$DATA_DIR" ]; then
        say "Settings and migration history were kept in $DATA_DIR. Run uninstall.sh --purge to remove them."
    fi
fi
