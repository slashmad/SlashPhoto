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

This is an early development version. Galleries, panoramas, client access, proofing, the shop and the Sunshine importer work.

* Galleries and sub-galleries that use your theme's own layout, colors and fonts, in both block themes and classic themes.
* 360° panoramas are detected automatically from camera and stitcher metadata, and are kept at high resolution.
* Wide panoramas are shown across the full gallery width.
* Client galleries: password, secret share links, selected client accounts, end dates and terms to accept, inherited by sub-galleries. Clients can sign in with a one-time emailed link instead of a password. Protected galleries and their images do not leak through the REST API, attachment pages, sitemaps, search or caches, and images get unguessable addresses.
* Upload full-size originals of any size from the gallery editor (sent in pieces, so the server's upload limit does not apply). Every image gets a display copy; originals are stored privately.
* Optional: connect your own SnapOtter server to import RAW, HEIC, TIFF and PSD files and to process very large images.
* Watermarked previews; originals are kept where preview addresses cannot lead to them. Make new copies applies changed watermark and display settings to photos uploaded earlier.
* Proofing: clients mark favourites, add notes and send their selection; you get an email and a list with file names for Lightroom, and the client gets a copy. Visitors without an account keep favourites in their own browser only.
* Shop: sell downloads of your originals or smaller web-size copies, and prints in your own sizes, with price lists per gallery. Guest checkout, manual payment (invoice, transfer, Swish) or Stripe Checkout with no platform fee, order emails, and downloads that open only for paid orders. Optional VAT rates by country (EU OSS) with a VAT report, and gift cards.
* Packages (a number of photos or the whole gallery for one price), discount codes, and printable receipts and invoices.
* Paper and framing options on individual prints. Each size can offer its own combinations and prices, and the customer's choice is recorded on the order.
* Import from Sunshine Photo Cart: galleries, access settings, images, prices and orders, without changing Sunshine's data.
* Visitors: galleries for any logged-in account, an email request before the photos, a log of terms acceptances and anonymous view statistics. Never IP addresses or browser details, no third-party requests, with a retention period and export/erase support.
* A gallery block to place any gallery on any page.
* A lightbox for photos, and an interactive 360° viewer that is only downloaded when a visitor opens a 360° image.
* Templates can be overridden from your theme.

= Third-party libraries =

SlashPhoto bundles these libraries; nothing is loaded from external servers.

* three.js 0.185.1, MIT License, https://threejs.org/
* Photo Sphere Viewer 5.15.1 (core and visible range plugin), MIT License, https://photo-sphere-viewer.js.org/

= External services =

SlashPhoto contacts no external service by default.

Stripe (optional): if a site administrator enters Stripe API keys, customers who choose to pay online are sent to Stripe's hosted Checkout page (https://stripe.com). When an order is placed, SlashPhoto sends Stripe the customer's email address, the order number, the order lines with prices and any discount, and later asks Stripe whether that payment was completed; Stripe sends payment notifications back to the site. No Stripe script is loaded on the site's pages. Stripe terms: https://stripe.com/legal/ssa, privacy policy: https://stripe.com/privacy.

PayPal (optional): if a site administrator enters PayPal REST credentials, customers who choose PayPal are sent to paypal.com to approve the payment. SlashPhoto sends PayPal the order number, the order lines with prices, shipping, VAT and discount, and captures the payment; PayPal sends payment notifications back to the site, which are verified with PayPal's API. Terms: https://www.paypal.com/legalhub, privacy: https://www.paypal.com/privacy.

Mollie (optional): if a site administrator enters a Mollie API key, customers who choose Mollie are sent to Mollie's checkout. SlashPhoto sends Mollie the order number, amount and return address, and reads the payment status back from Mollie. Terms: https://www.mollie.com/terms, privacy: https://www.mollie.com/privacy.

SnapOtter (optional): a site administrator can connect a SnapOtter server (https://github.com/snapotter-hq/SnapOtter) that they run themselves. SlashPhoto then sends gallery uploads (the image files) to the address the administrator entered, to make display copies, and downloads the results. Nothing about site visitors is sent. SnapOtter is free software (AGPL-3.0); see its documentation for its own terms and its optional usage statistics.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/` or install it from the Plugins screen.
2. Activate SlashPhoto.
3. Go to SlashPhoto > Add new gallery, write an introduction and add images.

== Frequently Asked Questions ==

= Does it work with my theme? =

SlashPhoto renders galleries inside your theme's normal post layout, so it works with block themes and classic themes. Themes can adjust or replace the markup; see the developer documentation.

= What does SlashPhoto store about visitors? =

Never IP addresses, browser details or visit logs, and it makes no requests to other sites. It counts views as anonymous daily totals (can be switched off). When a gallery asks for an email address or has terms, the address or account and the date are logged and deleted after the retention period you set. The shopping cart stays in the visitor's browser; an order stores what the customer types in.

= Does uninstalling delete my galleries? =

No. Galleries, images and orders are kept when the plugin is removed.

== Changelog ==

= 0.1.0-dev =
* Swedish translation (sv_SE) included.
* Display copies as WebP or AVIF.
* Photo search by keywords, camera, lens and date in the gallery workspace.
* Import folder: photos copied to the server with SFTP or FTP are added to galleries automatically.
* Licences for downloads, accepted at checkout and kept with each order.
* Client sign-in links, reprocessing of existing photos, VAT by country with a VAT report, and gift cards.
* Price list field in the classic editor.
* Print options for paper and framing with a price per combination.
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
* Visitors: logged-in mode, email request, terms acceptance log, anonymous view statistics, visitor log with CSV export and retention.
* PayPal and Mollie payments.
* Shop: packages, discount codes, receipts and invoices.
* Import from Sunshine Photo Cart.
* Shop: price lists with downloads and prints, cart, guest checkout, manual payment and Stripe Checkout, orders with status emails, protected downloads of originals, privacy export and erasure of orders.
