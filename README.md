# SlashPhoto

Open-source photography galleries, client proofing and photo sales for
WordPress. No subscriptions, no paid add-ons, no locked features.

> **Early development (`0.1.0-dev`).** Use a test WordPress site to try
> SlashPhoto. The online payment integrations have been tested with simulated
> provider APIs.

## Quick start

1. On a test site with WordPress 6.7+ and PHP 8.3+, copy the folder containing
   `slashphoto.php` to `wp-content/plugins/slashphoto/` and activate it under
   **Plugins**. With a beta ZIP, use **Plugins → Add New → Upload Plugin**.
   The browser libraries are bundled, so the plugin needs no build step.
2. Go to **SlashPhoto → Add new gallery** and add images using **Upload photos**
   in the gallery editor. Set the gallery's access rules before publishing.
3. Open the published gallery or place it on another page with the
   **SlashPhoto gallery** block. To enable client selections or sales, see
   the [proofing guide](docs/proofing.md) or [shop setup](docs/shop.md).

If your test site uses nginx, configure the deny rule for originals and temporary
uploads. See [image storage and server configuration](docs/security-and-privacy.md#image-files).

## Features

- **Galleries and sub-galleries** that use the active block or classic theme's
  layout, colours and typography. Use **Photos → Reorder photos** to move
  an image to any position in a gallery. The gallery block applies the same access
  rules on other pages. See [theme compatibility](docs/theme-compatibility.md).
- **Panoramas and 360° images.** 360° images are detected from the GPano XMP
  metadata that cameras and stitchers such as PTGui write, including partial
  spheres. They keep up to 8192 px instead of WordPress' usual 2560 px, and
  can be marked manually in the media library. Wide flat panoramas span the
  full gallery row.
- **Lightbox and 360° viewer.** A native `<dialog>` lightbox with keyboard,
  swipe and focus handling. 360° images open in an interactive viewer
  ([Photo Sphere Viewer](https://photo-sphere-viewer.js.org/) on
  [three.js](https://threejs.org/), both MIT, bundled) that is downloaded
  only when a visitor opens one. Partial panoramas cannot be panned past
  their edges, and panoramas that were cropped again after stitching are
  mapped correctly.
- **Client access.** Use WordPress passwords, secret share links, selected
  client accounts or any signed-in account. Add expiry dates and terms that
  visitors must accept. Sub-galleries follow their ancestors' access rules.
  Send a gallery link from the **Invite** tab in a published gallery.
  Clients can sign in with a one-time emailed link instead of a password.
  **Make new copies** applies changed watermark and display settings to
  photos uploaded earlier and moves older uploads to private paths.
  See [gallery access and privacy](docs/security-and-privacy.md).
- **Full-size originals.** Upload one full-resolution file per image through
  the gallery editor's **Upload photos** panel. Chunked uploads can handle
  originals larger than PHP's per-upload limit. SlashPhoto stores the original
  privately and creates a separate display copy, as JPEG, WebP or AVIF
  (Settings → Display copies). The gallery's Photos tab finds photos by
  title, caption, file name, IPTC keywords, camera, lens or capture date.
  Originals can also be copied into an import folder on the server (SFTP,
  FTP, a synced folder): each sub-folder becomes or fills a gallery.
- **Optional SnapOtter processing.** Connect your own
  [SnapOtter](https://github.com/snapotter-hq/SnapOtter) server (address and
  login, or an API key) to import RAW, HEIC, TIFF and PSD files and to make
  display copies of very large images off your web host.
  See [docs/snapotter.md](docs/snapotter.md).
- **Watermarked previews.** Apply a watermark to display copies while keeping
  the original files intact for delivery.
- **Proofing.** Clients mark favourites (in the grid or the lightbox), add a
  note per image and send their selection with a message. One selection
  covers a gallery and its sub-galleries, with an optional maximum. The
  photographer gets an email and sees every selection with thumbnails, notes
  and the file names to paste into Lightroom, and can reopen or delete it.
  The client gets an emailed copy of what they sent.
  Visitors without an account keep favourites in their own browser only.
  See [docs/proofing.md](docs/proofing.md).
- **Photo sales.** Sell downloads (the original or a smaller web-size copy)
  and prints in your own sizes with paper and framing options, per-gallery
  price lists, packages and discount codes. Customers add photos from the
  lightbox and check out as guests. Accept manual payments (invoice, bank
  transfer or Swish) or connect Stripe Checkout, PayPal or Mollie. SlashPhoto
  charges no platform fee. Manage orders, export a print production CSV, send
  status emails and offer printable receipts and invoices. Private download
  links work for paid orders during the configured download period. Optional
  VAT rates by country (for EU OSS) come with a quarterly VAT report, gift
  cards can be sold and redeemed at checkout, and downloads can carry a
  licence that customers accept. See
  [shop setup](docs/shop.md).
- **Import from Sunshine Photo Cart.** Copies galleries with their access
  settings, images, price levels and orders, in repeatable steps, without
  changing Sunshine's data. See [docs/import-sunshine.md](docs/import-sunshine.md).
- **Visitor controls and statistics.** Request an email address before showing
  photos, record terms acceptances and count daily views anonymously. The
  visitor log supports retention settings, CSV export and WordPress's personal
  data export and erasure tools. SlashPhoto keeps view totals without storing
  IP addresses or browser details. See [visitor settings](docs/visitors.md).

## Roadmap

- Tax per country, gift cards, licences and print-lab integrations.
- AVIF/WebP and EXIF/IPTC search.

## Requirements

- WordPress 6.7 or later
- PHP 8.3 or later (CI covers 8.3–8.5)

## Development

Requires Node.js and Docker. PHP and Composer run inside the containers, so
they do not need to be installed locally.

```sh
npm ci
npm run env:start      # WordPress at http://localhost:8888 (admin / password)
npm run setup          # composer install inside the container
npm run test:php       # PHPUnit integration tests
npm run lint:php       # WordPress Coding Standards + PHP compatibility
npm run seed           # replaces demo galleries in the local test site
npm run test:e2e       # Playwright browser tests (needs the seeded site)
npm run vendor         # copy three.js / Photo Sphere Viewer from node_modules to assets/vendor
npm run i18n:pot       # regenerate languages/slashphoto.pot
npm run i18n:build     # POT, then update and compile the bundled translations (sv_SE)
npm run package:beta   # requires zip; build an installable archive in dist/
npm run env:stop
```

Put your own JPEG test photos, including 360° panoramas, in `.dev-media/` for
`npm run seed`. The folder is ignored by Git.

Browser tests use Playwright's Chromium (`npx playwright install chromium`),
or a system Chromium: `PLAYWRIGHT_CHROMIUM=/usr/bin/chromium-browser npm run test:e2e`.

Third-party browser libraries are pinned in `package.json` and copied into
`assets/vendor/` by `npm run vendor`, which also rewrites their imports to
SlashPhoto-prefixed module IDs so they cannot collide with other plugins.
The copies are committed, so the plugin needs no build step.

### Layout

```text
slashphoto.php          plugin header and autoloader
src/                    PSR-4 classes (SlashPhoto\)
  Plugin.php            wiring
  Gallery/              post type, queries, rendering and uploads
  Access/               access rules, visibility guards and visitor gates
  Admin/                gallery editor panels and administration screens
  Blocks/               gallery block
  Frontend/             theme compatibility, templates, assets
  Media/                display copies, originals, watermarks and panoramas
  Proofing/             client favourites and selections
  Shop/                 price lists, orders, payment gateways and downloads
  Import/               Sunshine Photo Cart migration
  SnapOtter/            optional image processing service
  Visitors/             visitor log and view counts
blocks/                 block.json, render.php and editor script
templates/              overridable partials (copy to yourtheme/slashphoto/)
assets/                 CSS, JavaScript modules, bundled vendor libraries
tests/Integration/      PHPUnit tests against the WordPress test suite
tests/e2e/              Playwright browser tests
docs/                   developer documentation
```

## Support

SlashPhoto is free and will stay free. If it saves you a subscription, you can
support its development.

[![GitHub Sponsors](https://img.shields.io/badge/GitHub-Sponsors-EA4AAA?logo=githubsponsors&logoColor=white&style=flat-square)](https://github.com/sponsors/slashmad)
[![Ko-fi](https://img.shields.io/badge/Ko--fi-buy%20me%20a%20beer-FF5E5B?logo=ko-fi&logoColor=white&style=flat-square)](https://ko-fi.com/slashmad)
[![Liberapay](https://img.shields.io/badge/Liberapay-donate-F6C915?logo=liberapay&logoColor=black&style=flat-square)](https://liberapay.com/slashmad/)

## Licence

GPL-2.0-or-later. See [LICENSE](LICENSE).
