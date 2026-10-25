# Installation

## Before you begin

You need root access to a cPanel & WHM server that meets the
[compatibility requirements](compatibility.md), a BladeCP partner account and a
partner API key with migration permissions.

Download the release archive and its `SHA256SUMS` file from the
[GitHub releases](https://github.com/bladecp/bladecp-cpanel/releases). Each
release also publishes `bladecp-cpanel-migrator-<version>.tar.gz.sha256` with
the same checksum.

## Verify and install

Run as root on the server, replacing `0.3.8` with the version you install:

```sh
curl -fLO https://github.com/bladecp/bladecp-cpanel/releases/download/v0.3.8/bladecp-cpanel-migrator-0.3.8.tar.gz
curl -fLO https://github.com/bladecp/bladecp-cpanel/releases/download/v0.3.8/SHA256SUMS
sha256sum -c SHA256SUMS
tar -xzf bladecp-cpanel-migrator-0.3.8.tar.gz
cd bladecp-cpanel-migrator-0.3.8
./install.sh
```

`sha256sum -c SHA256SUMS` must report `OK` for the archive. Do not run the
installer from an archive that fails this check.

The installer checks that it runs as root on cPanel & WHM, that the PHP shipped
with cPanel is version 8.1 or newer with curl and json, and that no other
product owns the paths it needs. It never takes over a directory, cPanel API
module or side menu entry that it did not create.

## What the installer creates

| Path | Content |
| --- | --- |
| `/usr/local/bladecp-migrator/` | Plugin code and `uninstall.sh` |
| `/usr/local/cpanel/whostmgr/docroot/cgi/bladecp_migrator/index.cgi` | WHM entry point |
| `/usr/local/cpanel/whostmgr/docroot/addon_plugins/bladecp_migrator.png` | WHM icon |
| `/var/cpanel/bladecp-migrator/` | Settings, queue and logs (root only) |
| `/etc/cron.d/bladecp-migrator` | Worker, every minute |
| WHM AppConfig registration `bladecp_migrator` | Entry in the WHM Plugins list |
| `/usr/local/cpanel/base/frontend/jupiter/site_migration/` | cPanel app page (Jupiter theme) |
| `/usr/local/cpanel/Cpanel/API/BladeCPMigrator.pm` | cPanel app API module (UAPI) |
| `/usr/local/cpanel/bin/admin/BladeCP/MigratorClient` | Privileged part of the cPanel app (AdminBin, root only) |
| cPanel plugin `site_migration` (through `install_plugin`) | App entry in the **Domains** group of every account |
| `/var/cpanel/plugins/bladecp_migrator/menu/LeftMenu.yaml` | App item in the cPanel side menu |
| `/var/cpanel/customizations/content_includes/cpanel_jupiter_tools_header_after.html.tt` | Banner at the top of the cPanel home page, only when no other product uses this file |

The names shown in WHM and in cPanel follow the server language: English,
Portuguese (Brazil) or Spanish. The installer pins nothing to the WHM home
page; the plugin offers it once on its Migrations page.

When it finishes, open WHM, go to **Plugins** and choose
**BladeCP: Migrate and Transfer Accounts**. Continue with
[configuration](configuration.md).

## Update

Download and verify the new release, extract it and run its `install.sh`
again. The settings, the installation ID, the API key and the migration queue
are kept. A WHM favorite saved under a former name of the plugin moves to the
current one, in the same place.

## Uninstall

```sh
/usr/local/bladecp-migrator/uninstall.sh
```

This removes the plugin files, the cron job, the WHM registration, the cPanel
app with its side menu item and banner, and the temporary backups. Settings and
migration history stay in `/var/cpanel/bladecp-migrator`. To remove them too,
including the API key:

```sh
/usr/local/bladecp-migrator/uninstall.sh --purge
```

The uninstaller removes only what the plugin created: a home page include of
another product is never touched. It does not cancel migrations that are
already running at BladeCP, and it never changes a cPanel account.
