# Changelog

All notable changes to BladeCP cPanel Migrator are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [Semantic Versioning](https://semver.org/). Versions before 1.0.0 are pre-releases.

0.3.7 is the first public release. The earlier versions below were private pre-releases and are listed for reference.

## [0.3.8] - 2026-10-25

### Fixed

- **Confirm my account** in the cPanel app is now a link that opens the client area of your provider in a new tab. It was a form, and browsers that apply the form-action policy of the app to the redirect of a form, such as Chrome, blocked it. When the confirmation cannot start, the new tab comes back to the app with the reason.
- Two requests for the same account at the same time, from WHM and the cPanel app or a double click, can no longer queue the account twice.
- An account listed twice in the same WHM batch is reported as a duplicate instead of as an account that was not found.

### Security

- Migrations created from 0.3.8 on keep the creation time of their cPanel account, as WHM reports it. An account removed and created again with the same name does not see or act on those migrations, and the worker does not back it up for them.
- Changing the BladeCP API URL in WHM needs the partner API key typed again in the same form, so the saved key is never sent to an address it was not entered for.
- An existing work directory is used only when it is empty or already marked by the plugin, and its permissions change only after it passes the safety checks. The plugin no longer changes the mode of a directory that belongs to something else, and `uninstall.sh --purge` keeps the mark on a work directory that still holds other files, so a new installation can use it.

## [0.3.7] - 2026-10-17

First public release.

### Added

- Public repository under the MIT License, with installation, configuration, compatibility and troubleshooting guides, a security policy, contribution guidelines and public checks that run in continuous integration.
- Installation from the GitHub release: a deterministic `bladecp-cpanel-migrator-0.3.7.tar.gz`, verified with the published `SHA256SUMS`.

### Changed

- The default free trial is now 14 days instead of 7. Servers that already saved their migration defaults keep their value; partners change it under **Migration defaults** in WHM.

## [0.3.6]

### Changed

- cPanel home page banner: taller, with the full BladeCP horizon of the plugin header (sky, glow, rim of light, dark planet and a straight base) and its motion, the light running along the rim and the breathing glow. Reduced motion keeps it still.
- The banner shows the white BladeCP wordmark next to the title and the text, and **Migrate now** and the close button sit centered on that line. On narrow screens it stacks, with the close button in the corner.

## [0.3.5]

### Changed

- WHM: the plugin is now **BladeCP: Migrate and Transfer Accounts** in the Plugins list and in the Favorites (by the server language: **BladeCP: Migrar e Transferir Contas**, **BladeCP: Migrar y Transferir Cuentas**), with a description and search words, so searching WHM for "transfer" or "migrate" finds it.
- A WHM favorite saved under the former name of the plugin moves to the new name on install, in the same place. Pin and unpin know every name of the plugin.

## [0.3.4]

### Fixed

- cPanel side menu: the **Migrate to BladeCP** icon is now the BladeCP "B" in one color, the theme color of the native menu icons, so it reads on the dark menu and follows the theme. The app icon in the Domains group and the WHM icon keep the gradient.

## [0.3.3]

### Fixed

- Pinning without a saved list keeps the default WHM favorites in the order the home page shows them (List Accounts, Process Manager, Create a New Account, DNS Zone Manager, Terminal, Mail Queue Manager), with BladeCP first. A list pinned with 0.3.2 gets this order back when you remove BladeCP from the top and pin it again.

### Changed

- The WHM plugin icon, in the Plugins list and in the Favorites, is now the BladeCP "B" with its gradient, the same as the cPanel app.

## [0.3.2]

### Added

- WHM home page: **Pin to the top** puts the plugin first in the Favorites of the root WHM home page. Without a saved list it starts from the favorites WHM shows by default, and an existing list keeps every other favorite in its order. The Migrations page offers it once in a small card (**Pin to the top** or **Not now**), and **Settings > Shortcuts and highlights** shows whether it is pinned and pins or removes it. The installer pins nothing.
- cPanel side menu: **Migrate to BladeCP**, with the BladeCP "B", in the side menu of every account that has the app.
- cPanel home page banner: "Bring your site to BladeCP" with **Migrate now**, at the top of the cPanel home page. Each customer can close it for good, and it does not show to accounts that already migrated or have a migration going on, nor to accounts without the app. **Settings > Show the banner in the cPanel of your customers** turns it off (on by default). The plugin never replaces a home page include of another product, and uninstalling removes only its own.

## [0.3.1]

### Changed

- The cPanel app uses the BladeCP brand: **Migrate to BladeCP** in the cPanel menu (by the server language), the BladeCP "B" as its icon and page icon, and the BladeCP wordmark in the app. Your name shows as the partner, next to the account domain.
- The optional domain pointing in the cPanel app also offers the BladeCP nameservers, with copy buttons, and the Registro.br tip for .br domains.
- WHM: "Name shown to your customers" is now "Partner name shown to customers".

### Fixed

- The cPanel menu icon no longer shows blank: cPanel builds its icon sprite from simple shapes only, so the BladeCP "B" now comes as one image that keeps its gradient.
- A reinstall updates the app name in the cPanel Feature Manager entry too.

## [0.3.0]

### Added

- Billing link with the partner WHMCS: new migrations, from WHM or from the cPanel app, send the source account (this server host name, the account, its cPanel owner, the main domain and who started it) and no owner. Your billing WHMCS finds the customer, creates the service and links it.
- The worker waits for that link after the upload, like it waits for a payment, and never binds a service itself.
- WHM shows "Waiting for the provider WHMCS", "Waiting for the customer to confirm the account" and, once linked, "Service #<id> in WHMCS", with a hint to review it in the WHMCS after 15 minutes.
- cPanel app: when the WHMCS does not know the account, a "Confirm your account" card opens your WHMCS client area in a new tab and the app continues on its own once linked.

### Changed

- The batch review no longer asks for owner e-mail, owner name or billing service ID, and the cPanel app no longer needs a contact e-mail.
- **Mark as paid** only remains for migrations created before the billing link; the WHMCS invoice confirms the payment of the new ones.

## [0.2.0]

### Added

- cPanel app for every account (**Migrate my site**, in the Domains group of the Jupiter theme), installed with the plugin and removed with it. The customer starts the migration of their own account with the defaults saved in WHM, follows the same stages, opens the preview, confirms the domain, approves, cancels before approving and sees errors in plain language.
- White label in the cPanel app: the name of your brand (from the API or from the new **For your customers** settings) in place of a logo, an optional support link and no mention of BladeCP.
- The app works through a cPanel API module and a root AdminBin that act only on the account cPanel authenticated, with a form token bound to the account and the session, limits per account and an audit log.
- WHM shows who started each migration (the administrator or the account) and keeps every action on all of them.
- Optional domain pointing: WHM shows the BladeCP nameservers, with copy buttons, the A record as an alternative, the Registro.br tip for .br domains and the end of the automatic check. The cPanel app shows only the A record to the new server.

### Changed

- Pointing the domain is never a step: the progress bar has six stages (Backup, Upload, Analysis, Import, Preview and Completed), and a migration is complete after the approval, with 100%. It no longer counts as waiting for you.

## [0.1.0]

### Added

- WHM plugin registered through AppConfig, available only to the root user.
- The plugin opens inside the WHM interface, with the WHM header, side menu and footer around it, in a frame that keeps its own strict content security policy.
- New interface: summary cards that filter the migrations, a progress bar based on the real migration state, tables that become cards on narrow screens, and the Inter font bundled under the SIL Open Font License.
- Connection settings with the partner API key stored root-only, a connection test and an advanced custom API URL.
- Migration defaults: plan, location, commercial rule (free period, pay before or pay after migrating), billing product ID, main domain handling, simultaneous migrations, work directory and language.
- Account list with filter, multiple selection and a single **Migrate selected** action, followed by a review of owner e-mail, owner name and billing service ID.
- Queue worker started by cron every minute, with worker slots, disk space check, full backup with `pkgacct`, streamed multipart upload, automatic resume, automatic retries with increasing delays and removal of the local backup after upload.
- Migrations table with status, progress, preview link and the actions confirm domain, approve, mark as paid, retry, cancel and view error.
- Interface in English, Portuguese (Brazil) and Spanish, following the WHM language.
- `install.sh` and `uninstall.sh` (with `--purge`), both safe to run again.
- Structured JSON logs without credentials, signed links or e-mail addresses.
