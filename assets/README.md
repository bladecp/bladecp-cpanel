# Public assets

## Brand mark

`logo-light.svg` is the official BladeCP wordmark, intended for light
backgrounds. `logo-dark.svg` is the matching dark-background variant: it
differs only in the root `fill` colour (`#ffffff`); its vector paths and view
box are the same.

Both files contain only a root `<svg>` element and path elements. They contain
no scripts, external references, embedded images, event-handler attributes or
metadata.

The plugin draws the same wordmark and the BladeCP "B" inline in its WHM and
cPanel screens. The WHM icon is `plugin/assets/bladecp_migrator.png` and the
cPanel app icon is `plugin/cpanel/plugin/site_migration.svg`.

## README artwork

Each public README opens with a hero and shows an architecture diagram, in its
own language. Every image has a wide version and a compact one, which the
README selects with `<picture>` and `<source media="(max-width: 600px)">`:

| File | Use | Size |
| --- | --- | --- |
| `hero.<language>.svg` | README header on desktop | 1280 × 480 |
| `hero-compact.<language>.svg` | README header on a phone | 720 × 694 |
| `architecture.<language>.svg` | Architecture section on desktop | 1520 × 1300 |
| `architecture-compact.<language>.svg` | Architecture section on a phone | 600 wide, stacked |

`<language>` is `en`, `pt-BR` or `es`, matching `README.md`,
`README.pt-BR.md` and `README.es.md`. That makes twelve files.

The artwork follows the BladeCP Horizon signature: a dark sky, indigo and
violet glows, a dark planet with a straight base and a thin rim of light with
a bright point at its centre. The palette is indigo `#4f46e5` and `#6366f1`,
violet `#8b5cf6`, text `#f5f5ff` and secondary text `#a5a6c9`. The wordmark
uses the two paths of `logo-dark.svg`.

Rules for every artwork file:

- No external fonts: text uses the system font stack, and every label fits its
  box in San Francisco, Helvetica, Arial and Verdana.
- No scripts, `foreignObject`, embedded images, event handlers or external
  references. GitHub shows the files through `<img>`, and
  `scripts/check-public-tree.sh` rejects any of them.
- Motion is CSS only: the hero has one light gliding along the rim, which
  stops under `prefers-reduced-motion: reduce`. The diagrams are static.
- Content is public grade: `api.bladecp.com` only, BladeCP brands only, no
  people, customers, server names or identifiers.
- The artwork carries no version number, so a release does not require new
  images.
- Every block in the architecture diagrams matches a real part of `plugin/`.
  Update the diagrams in the three languages together when that changes.

## Screenshots

The public READMEs show 24 localized screenshots: eight per language, under
`screenshots/en/`, `screenshots/pt-BR/` and `screenshots/es/`.

| File | Screen |
| --- | --- |
| `whm-migrations.png` | WHM, **Migrations**: accounts at different stages, progress, preview link and actions |
| `whm-accounts.png` | WHM, **Accounts**, with three accounts selected |
| `whm-review.png` | WHM, review of the batch: destination, commercial rule and accounts |
| `whm-settings.png` | WHM, **Settings**: connection and migration defaults |
| `whm-settings-highlights.png` | WHM, **Settings**: your customers, shortcuts and highlights |
| `cpanel-app-start.png` | cPanel app, before the migration |
| `cpanel-app-track.png` | cPanel app, with the preview ready to approve |
| `cpanel-banner.png` | Banner at the top of the cPanel home page |

Rules for every screenshot:

- The screen is rendered by the plugin code itself (the WHM screens, the cPanel
  app and the banner template), in the language of the folder. Text is never
  edited in an image.
- Data is neutral demonstration data only: `example.com` domains and e-mail
  addresses, a partner called "Example Hosting" and no real server, address,
  person or customer.
- PNG, 1360 pixels wide (the banner is drawn at 1.2x, 1426 pixels), optimized
  losslessly and with no metadata chunk: only `IHDR`, `IDAT` and `IEND`.
- `scripts/check-public-tree.sh` requires exactly these eight files in each
  language folder and requires the README of that language to show each one.
- The footer of the WHM screens shows the plugin version. Capture the three
  languages again when the screens change.
