# Configuration

Open WHM, go to **Plugins**, open the plugin and choose **Settings**. Only the
WHM `root` user can open the plugin.

## BladeCP connection

Paste your partner API key and click **Save and test connection**. The plugin
checks the BladeCP API and shows the partner account it is connected to.

The key is stored in `/var/cpanel/bladecp-migrator/config.json` with mode
0600. It is never shown again, never written to logs and sent only in the
`Authorization` header. The key needs migration permissions:
`migrations:read` and `migrations:write`, or `provisions:read` and
`provisions:write`.

The **Advanced** section has a custom API URL. Leave it empty: the plugin uses
`https://api.bladecp.com/api/v1`. Change it only when BladeCP support asks you
to. A new URL needs the partner API key typed again in the same form, so the
saved key never goes to an address it was not entered for.

## Migration defaults

| Setting | Meaning |
| --- | --- |
| Plan and location | Destination of new migrations. Only plans that accept migrations are listed. |
| Commercial rule | **Free period** (14 days by default), **Pay before migrating** or **Pay after migrating** (with the days to pay). You can change it for each batch. |
| Product ID in your billing system | A number that correlates the migration with your billing product, such as the WHMCS product ID. Keep 1 if you do not use one. |
| Main domain | Use the main domain of the account (fewer steps) or confirm it after BladeCP analyzes the backup. |
| Simultaneous migrations | 1 by default, up to 4. |
| Work directory | Where backups are written while they are uploaded (default `/home/bladecp-migrator`). Every directory in its path must be owned by root and not writable by group or others, and the directory must be empty or created by the plugin. |
| Language | Automatic (follows WHM), English, Portuguese (Brazil) or Spanish. |

The cPanel app uses the same defaults for the migrations your customers start.

### Commercial rules

| Rule | Behavior |
| --- | --- |
| Free period | The migration runs to the preview and can be approved at once. The customer has the chosen number of free days. |
| Pay before migrating | The backup is uploaded, but the migration starts only after the WHMCS invoice is paid. |
| Pay after migrating | The migration runs to the preview. Approval requires the WHMCS invoice to be paid, due within the chosen days. |

Your billing WHMCS, with the BladeCP addon, finds the customer by the cPanel
account on this server, creates the service and links it. The plugin never
asks for an owner e-mail or a billing service ID.

## For your customers

- **Partner name shown to customers**: the cPanel app shows it as a reference,
  for example "Partner: <name>". Empty uses the name of your BladeCP brand.
- **Support link**: optional, `https://` or `mailto:`.

## Shortcuts and highlights

- **WHM home page**: **Pin to the top** puts the plugin first in the Favorites
  of your WHM home page and keeps your other favorites in their order. The
  Migrations page offers it once, with **Not now**.
- **Show the banner in the cPanel of your customers**: on by default. The
  banner appears at the top of the cPanel home page of every account that has
  the app and has not migrated yet, and each customer can close it for good.
  Turning the switch off removes the banner from every account.

## Command line

```sh
/usr/local/cpanel/3rdparty/bin/php /usr/local/bladecp-migrator/bin/worker.php status
```

Lists every migration with its status, step, phase and progress. `run`
executes the worker once, the same command cron runs every minute.
