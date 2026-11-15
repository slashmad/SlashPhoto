# Theme compatibility

SlashPhoto has to look right in thousands of themes it has never seen: block
themes, classic themes, page builders and heavily customised child themes.
The rule is simple: **the theme owns the page, SlashPhoto owns the gallery.**

## How a gallery is rendered

1. A gallery is an ordinary post of type `slashphoto_gallery`. WordPress picks
   the template through the normal template hierarchy, so the theme's own
   header, footer, sidebars, title and content width are used, in block
   themes and classic themes alike.
2. SlashPhoto appends its markup to the post content (`the_content`, priority
   20), only for the main query of a single gallery, inside the loop, and not
   for password-protected galleries. The photographer's own text and blocks
   come first.
3. The gallery wrapper gets `alignwide` when the theme supports wide
   alignment (all block themes, and classic themes with
   `add_theme_support( 'align-wide' )`). The width therefore comes from the
   theme: `wideSize` in `theme.json` or the theme's `.alignwide` rules.

## Styles

`assets/css/frontend.css` is loaded only on gallery views, in two layers:

| Layer | Selectors | Purpose |
|---|---|---|
| Layout | `.slashphoto .slashphoto-grid` (0,2,0) | Grid and list resets that must beat generic theme rules such as `.entry-content ul` and `.entry-content li`. Without this, classic themes put bullets and indentation into the grid. |
| Appearance | `:where(...)` (0,0,0) | Colors, type, radius, hover. Any theme rule wins without `!important`. |

Colors and spacing come from the theme: `currentColor`, `inherit` and the
`theme.json` presets (`--wp--preset--*`, `--wp--style--block-gap`) with
fallbacks for classic themes. The grid uses `auto-fill` with a minimum column
width, so it adapts to the width of the content area rather than the
viewport, whether that is a sidebar layout, a boxed layout or full width.

Themes can tune the gallery with custom properties instead of overriding rules:

```css
.slashphoto {
	--slashphoto-gap: 4px;
	--slashphoto-column-min: 18rem;
	--slashphoto-aspect: 1;          /* square thumbnails */
	--slashphoto-radius: 6px;
	--slashphoto-badge-bg: var(--wp--preset--color--contrast);
}
```

## Lightbox

The lightbox is a native `<dialog>` in the browser's top layer, so it is not
affected by the theme's layout, but global theme rules for `button`, `img`
and `p` can still reach it. Its structural rules therefore use two-class
selectors, and its colors are custom properties:

```css
.slashphoto-lightbox {
	--slashphoto-lightbox-bg: #000;
	--slashphoto-lightbox-fg: #fff;
	--slashphoto-lightbox-muted: rgb(255 255 255 / 0.7);
	--slashphoto-lightbox-button-hover: rgb(255 255 255 / 0.14);
}
```

Without JavaScript, every image is still a plain link to its full-size file.

## Hooks for themes

| Hook | Use |
|---|---|
| `slashphoto_append_gallery_to_content` (filter, bool) | Return `false` when your own template renders the gallery. |
| `slashphoto_gallery_align` (filter, `wide`/`full`/`''`) | Change the gallery width. |
| `slashphoto_enqueue_frontend_styles` (filter, bool) | Return `false` to drop the stylesheet entirely. |
| `slashphoto_locate_template` (filter, path) | Load a partial from anywhere. |
| `slashphoto_image_kind` (filter) | Change how an image is classified (`photo`, `wide`, `360`). |
| `slashphoto_enable_lightbox` (filter, bool) | Return `false` to keep plain links, for example when the theme has its own lightbox. |
| `slashphoto_panorama_pano_data` (filter) | Adjust the 360° viewer geometry of an image. |
| `slashphoto_can_view_gallery` (filter, bool) | Add your own access restrictions (returning true bypasses SlashPhoto's). |

Notices and the terms form are partials too: `access-notice.php` and
`terms.php`. Keep the form field names in `terms.php`.

## Gallery block

`slashphoto/gallery` shows any gallery (or the current one) anywhere. When a
gallery's own content contains the block, the automatic output is skipped, so
the block controls the position. The block supports wide and full alignment.

Body classes on gallery views: `slashphoto-page` plus
`slashphoto-block-theme` or `slashphoto-classic-theme`.

## Overriding templates

Copy a file from the plugin's `templates/` folder to a `slashphoto/` folder in
your theme (child theme first, then parent theme):

```text
wp-content/themes/your-theme/slashphoto/gallery.php
```

Keep the `slashphoto-*` class names and `data-slashphoto-*` attributes so the
lightbox, 360° viewer and proofing scripts keep working.

For full control over the page, provide `single-slashphoto_gallery.php`
(classic) or `templates/single-slashphoto_gallery.html` (block theme) and
return `false` from `slashphoto_append_gallery_to_content`.

## Test matrix

The development environment (`npm run env:start`) installs these themes.
Check every front-end change in all of them, on desktop and phone widths.
`npm run test:e2e` does this automatically for the lightbox
(`tests/e2e/themes.spec.mjs`):

| Theme | Type | Notes |
|---|---|---|
| Twenty Twenty-Five | Block theme | Constrained layout, `theme.json` presets |
| Twenty Twenty-One | Classic | Strong `.entry-content` list styles |
| Astra | Classic, popular | Boxed content area, image shadows |

```sh
npx wp-env run cli wp theme activate astra
npm run seed
```
