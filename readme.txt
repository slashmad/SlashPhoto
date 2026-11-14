=== SlashPhoto ===
Contributors: slashmad
Donate link: https://github.com/sponsors/slashmad
Tags: photography, gallery, proofing, panorama, 360
Requires at least: 6.7
Tested up to: 7.1
Requires PHP: 8.3
Stable tag: 0.1.0-dev
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Open-source photography galleries, client proofing and photo sales. No subscriptions, no locked features.

== Description ==

SlashPhoto is a free, open-source plugin for photographers who want to show and sell their work from their own WordPress site.

This is an early development version. Galleries and panorama support work; client proofing and the shop are in development.

* Galleries and sub-galleries that use your theme's own layout, colors and fonts, in both block themes and classic themes.
* 360° panoramas are detected automatically from camera and stitcher metadata, and are kept at high resolution.
* Wide panoramas are shown across the full gallery width.
* A lightbox for photos, and an interactive 360° viewer that is only downloaded when a visitor opens a 360° image.
* Templates can be overridden from your theme.

= Third-party libraries =

SlashPhoto bundles these libraries; nothing is loaded from external servers.

* three.js 0.185.1, MIT License, https://threejs.org/
* Photo Sphere Viewer 5.15.1 (core and visible range plugin), MIT License, https://photo-sphere-viewer.js.org/

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/` or install it from the Plugins screen.
2. Activate SlashPhoto.
3. Go to SlashPhoto > Add new gallery, write an introduction and add images.

== Frequently Asked Questions ==

= Does it work with my theme? =

SlashPhoto renders galleries inside your theme's normal post layout, so it works with block themes and classic themes. Themes can adjust or replace the markup; see the developer documentation.

= Does uninstalling delete my galleries? =

No. Galleries, images and orders are kept when the plugin is removed.

== Changelog ==

= 0.1.0-dev =
* Gallery post type with sub-galleries.
* Theme compatibility layer and overridable templates.
* Panorama and 360° detection.
* Lightbox and 360° viewer.
