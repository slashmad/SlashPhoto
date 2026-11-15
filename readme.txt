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

This is an early development version. Galleries, panoramas, client access and proofing work; the shop is in development.

* Galleries and sub-galleries that use your theme's own layout, colors and fonts, in both block themes and classic themes.
* 360° panoramas are detected automatically from camera and stitcher metadata, and are kept at high resolution.
* Wide panoramas are shown across the full gallery width.
* Client galleries: password, secret share links, selected client accounts, end dates and terms to accept, inherited by sub-galleries. Protected galleries and their images do not leak through the REST API, attachment pages, sitemaps, search or caches, and images get unguessable addresses.
* Upload full-size originals of any size from the gallery editor (sent in pieces, so the server's upload limit does not apply). Every image gets a display copy; originals are stored privately.
* Optional: connect your own SnapOtter server to import RAW, HEIC, TIFF and PSD files and to process very large images.
* Watermarked previews; originals are kept where preview addresses cannot lead to them.
* Proofing: clients mark favourites, add notes and send their selection; you get an email and a list with file names for Lightroom. Visitors without an account keep favourites in their own browser only.
* No visitor logging, no analytics, no third-party requests.
* A gallery block to place any gallery on any page.
* A lightbox for photos, and an interactive 360° viewer that is only downloaded when a visitor opens a 360° image.
* Templates can be overridden from your theme.

= Third-party libraries =

SlashPhoto bundles these libraries; nothing is loaded from external servers.

* three.js 0.185.1, MIT License, https://threejs.org/
* Photo Sphere Viewer 5.15.1 (core and visible range plugin), MIT License, https://photo-sphere-viewer.js.org/

= External services =

SlashPhoto contacts no external service by default. Optionally, a site administrator can connect a SnapOtter server (https://github.com/snapotter-hq/SnapOtter) that they run themselves. SlashPhoto then sends gallery uploads (the image files) to the address the administrator entered, to make display copies, and downloads the results. Nothing about site visitors is sent. SnapOtter is free software (AGPL-3.0); see its documentation for its own terms and its optional usage statistics.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/` or install it from the Plugins screen.
2. Activate SlashPhoto.
3. Go to SlashPhoto > Add new gallery, write an introduction and add images.

== Frequently Asked Questions ==

= Does it work with my theme? =

SlashPhoto renders galleries inside your theme's normal post layout, so it works with block themes and classic themes. Themes can adjust or replace the markup; see the developer documentation.

= What does SlashPhoto store about visitors? =

Nothing. It does not log IP addresses or visits and makes no requests to other sites. Accepting a gallery's terms sets one cookie in the visitor's own browser; nothing is stored on the server.

= Does uninstalling delete my galleries? =

No. Galleries, images and orders are kept when the plugin is removed.

== Changelog ==

= 0.1.0-dev =
* Gallery post type with sub-galleries.
* Theme compatibility layer and overridable templates.
* Panorama and 360° detection.
* Lightbox and 360° viewer.
* Client access (password, clients, end date, terms) with leak protection.
* Gallery block.
* Secret share links, watermarks, protected originals.
* Proofing: favourites, notes, selections sent to the photographer.
* Chunked uploads of full-size originals, display copies for every gallery image, deny rules for originals folders.
* Optional SnapOtter integration.
