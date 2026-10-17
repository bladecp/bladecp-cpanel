# Troubleshooting

## The background worker has not run in the last few minutes

Confirm that `/etc/cron.d/bladecp-migrator` exists and that the cron service is
running, then run as root:

```sh
/usr/local/cpanel/3rdparty/bin/php /usr/local/bladecp-migrator/bin/worker.php status
```

## Connection failed

Check that the API key is complete and active, belongs to a partner account and
has migration permissions, and that the server reaches `api.bladecp.com` over
HTTPS. Leave the custom API URL in **Advanced** empty.

## No plans in Settings

Your partner account needs a plan that accepts migrations. Enable one, then
click **Reload plans and locations**.

## A migration stopped

Open **View error** on the migration. It shows the message, a short code, the
step and the details of the last error.

| Error | What to do |
| --- | --- |
| Not enough free disk space | Free space in the work directory or choose another one. The plugin retries automatically. |
| The account backup failed | The details carry the `pkgacct` output. Fix the cause on the server, then click **Retry**. |
| The work directory is not safe | Every directory in the path must be owned by root and not writable by group or others. |
| Upload interrupted | Nothing to do: the next attempt resumes from the first missing part. |

Recoverable failures go back to the queue after 1, 5, 15, 30 and 60 minutes,
up to 6 attempts.

## The cPanel app, side menu item or banner does not show

- The account needs the `site_migration` feature. It is on in every feature
  list unless you turned it off in the Feature Manager.
- The cPanel app, the side menu item and the banner need the Jupiter theme.
- The banner does not show to an account that closed it, already migrated or
  has a migration going on, and it is off when **Show the banner in the cPanel
  of your customers** is off.
- When another product already uses the cPanel home page include
  (`cpanel_jupiter_tools_header_after.html.tt`), the plugin does not replace
  it and Settings says so.

## The WHM favorites could not be read

The WHM home page row in **Settings** reads the favorites through the WHM API
of the server. Try again in a moment; the rest of the plugin keeps working.

## Logs

Logs are JSON lines in `/var/cpanel/bladecp-migrator/logs/migrator.log`, root
only. They never contain the API key, signed upload links or e-mail addresses.

## Get help

When contacting support, include the plugin version, the cPanel & WHM version,
the operating system and the error code shown by the plugin. Never send the
API key. Report security issues privately as described in
[SECURITY.md](../SECURITY.md).
