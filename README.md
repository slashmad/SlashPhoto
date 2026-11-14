# SlashPhoto

Open-source photography galleries, client proofing and photo sales for
WordPress. No subscriptions, no paid add-ons, no locked features.

> **Status: early development (0.1.0-dev).** Galleries and panorama support
> work; proofing and the shop are not built yet. Not ready for production.

## Features

Working now:

- **Galleries and sub-galleries** as a hierarchical post type, rendered inside
  any theme's own layout (block and classic themes).
- **Panoramas and 360° images.** 360° images are detected from the GPano XMP
  metadata that cameras and stitchers such as PTGui write, including partial
  spheres. They keep up to 8192 px instead of WordPress' usual 2560 px, and
  can be marked manually in the media library. Wide flat panoramas span the
  full gallery row.
- **Theme-first styling** that adapts to the theme's colors, fonts and width.
  See [docs/theme-compatibility.md](docs/theme-compatibility.md).

Planned, in order:

1. Lightbox and interactive 360° viewer
2. Client access: private galleries, client accounts, watermarked previews
3. Proofing: favourites and selections without payment
4. Shop: price lists for digital downloads and prints, cart, Stripe Checkout,
   orders, receipts and secure expiring download links
5. Import from Sunshine Photo Cart
6. Licences, print-lab integrations, AVIF/WebP, EXIF/IPTC search

## Requirements

- WordPress 6.7 or later
- PHP 8.1 or later

## Development

Requires Node.js and Docker. PHP and Composer run inside the containers, so
they do not need to be installed locally.

```sh
npm install
npm run env:start      # WordPress at http://localhost:8888 (admin / password)
npm run setup          # composer install inside the container
npm run test:php       # PHPUnit integration tests
npm run lint:php       # WordPress Coding Standards + PHP compatibility
npm run seed           # demo galleries; uses your own JPEGs from .dev-media/ if present
npm run i18n:pot       # regenerate languages/slashphoto.pot
npm run env:stop
```

Put your own test photos, for example 360° panoramas, in `.dev-media/`. The
folder is ignored by Git.

### Layout

```text
slashphoto.php          plugin header and autoloader
src/                    PSR-4 classes (SlashPhoto\)
  Plugin.php            wiring
  Gallery/              post type and queries
  Frontend/             theme compatibility, templates, assets
  Media/                panorama and 360° detection
templates/              overridable partials (copy to yourtheme/slashphoto/)
assets/                 CSS and, later, JavaScript
tests/Integration/      PHPUnit tests against the WordPress test suite
docs/                   developer documentation
```

## Support the project

SlashPhoto is free and will stay free, with no paid add-ons. If it saves you
a subscription, consider supporting development through
[GitHub Sponsors](https://github.com/sponsors/slashmad),
[Ko-fi](https://ko-fi.com/slashmad) or
[Liberapay](https://liberapay.com/slashmad).

## Licence

GPL-2.0-or-later. See [LICENSE](LICENSE).
