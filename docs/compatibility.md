# Compatibility

## Requirements

| Component | Requirement |
| --- | --- |
| cPanel & WHM | A current release with root access. The cPanel app, its side menu item and the home page banner need the Jupiter theme. |
| Operating system | One supported by your cPanel & WHM release. |
| PHP | The PHP shipped with cPanel (`/usr/local/cpanel/3rdparty/bin/php`), version 8.1 or newer, with the curl and json extensions. `posix` is optional. |
| Perl | The Perl shipped with cPanel, used by the cPanel API module and the AdminBin. |
| Scheduler | The system cron reading `/etc/cron.d`. |
| System tools | `pkgacct` from cPanel, `setsid`, `nice` and `pkill`. |
| Network | Outbound HTTPS to `api.bladecp.com` and to the BladeCP backup storage. |
| Disk | Free space for the largest account you migrate at a time, plus 2 GB, in the work directory. |
| Billing | Your billing WHMCS with the BladeCP addon, which links the owner and the billing service of each migration. |
| BladeCP | A partner account, a partner API key with migration permissions and at least one plan that accepts migrations. |
| Browser | A current browser supported by cPanel & WHM. |
| Languages | English, Portuguese (Brazil) and Spanish, in WHM and in the cPanel app. |

The plugin has no Composer dependency and no build step. It does not change,
suspend or remove the source cPanel accounts: it reads them and archives them
with `pkgacct`.

## Validation snapshot

The `0.3.7` release was validated on cPanel & WHM 11.138 with AlmaLinux 9 and
the PHP 8.4 shipped with cPanel. These are tested environments, not additional
minimum requirements. Validate the plugin on a non-production server before
using it in production.

## Not supported in this version

- The Meridian cPanel theme: the WHM plugin works, but the cPanel app is
  installed for Jupiter only.
- CloudLinux CageFS specific setups have not been validated.
