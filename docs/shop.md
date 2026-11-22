# Shop: downloads and prints

SlashPhoto sells two kinds of products for each photo:

- **Downloads** of the original you uploaded (full resolution, the file as
  it came from your camera or editor), or a smaller copy of it, for example
  2048 pixels on the longest side for web and social media.
- **Prints** in the sizes you offer. You make and send them yourself; print
  labs are not connected yet.

There are no platform fees, no paid add-ons and no WooCommerce dependency.

## Setting up

1. **SlashPhoto → Shop settings.** Create the cart page with one click (it
   is an ordinary page containing `[slashphoto_checkout]`), choose currency,
   VAT, shipping for prints, the countries you ship to and how many days
   downloads stay available.
2. **SlashPhoto → Price lists → Add price list.** Each row is a product:
   type (download or print), the name customers see ("Print 20 × 30 cm")
   and the price. A download can set **Longest side (px)**; leave it empty
   to deliver the original. A new list suggests a full-size download, a
   2048 px web download and three print sizes; rows without a price are not
   saved.
3. **In the gallery editor**, open the "Shop" panel and choose the price
   list. Sub-galleries use the same list unless they choose another or "Not
   for sale". A default price list in the shop settings covers top-level
   galleries without their own choice.
4. **Payment.** Manual payment (invoice, bank transfer, Swish, cash) is on
   by default: write your payment details in the settings. For card
   payments, connect Stripe (below).

Under **Prints and shipping**, open **Choose countries** to select delivery
destinations by name. The search narrows the checklist while keeping your
selections. Clear the checklist to disable print orders.

## Paper and framing options

Create a print product for each size. Under **Paper and framing options**,
enter one available combination per line as `Paper | Frame | Full price`.
Leave the frame field empty to offer an unframed print. Use the shop's
currency and the same VAT settings as for other prices. The first listed
combination is selected when a customer opens the Buy panel. The customer
can choose a different combination before adding the photo to the cart.
The price list's regular **Price** field applies to a print without options.

A Swedish shop can use Crimson's separation between small prints, posters
and framed prints as a starting point. List the papers and frames you can
source for each size. This example is a 30 × 40 cm poster in SEK with
illustrative retail prices. [Crimson's print and framing range](https://crimson.se/bestall-bilder)

```text
Matt fotopapper | | 229
Blankt fotopapper | | 249
Matt fotopapper | Svart träram | 399
Fine art-papper | Ekram | 549
```

WhiteWall and theprintspace are international references for paper choices
and framing by size. The same price list works with your own sizes and local
fulfilment. [WhiteWall photo prints](https://www.whitewall.com/us/photo-prints) · [theprintspace framing options](https://www.theprintspace.co.uk/help/knowledge-base/how-to-set-up-and-sell-art-prints-with-frames/)

### Print templates

**Shop settings → Print templates** controls which starting points are shown
in the price-list editor. Enable or disable Crimson, WhiteWall and
theprintspace independently.

On a print product, open **Paper and framing options**, choose a template and
click **Add combinations**. New combinations are appended with blank prices.
Existing combinations keep their current prices. Keep only the combinations
you can produce at the size you sell, then enter their prices and save the list.
Disabling a template hides it from the editor. The combinations you saved stay
available, and you can always add your own paper and framing options.

The cart keeps separate lines for different options of the same photo.
The selected paper, framing and price are saved with the order for fulfilment
and receipts. Customers will need to choose again if you add options to a
plain print they have in their cart, or remove or rename the selected option.
For a package with a fixed paper or frame, create a separate print named
with that specification and choose it under **each with**. The package
price covers that configuration.

## Packages

A package is a number of photos the customer chooses, each with the same
download or print, for one price: "10 downloads", "5 prints 13 × 18 cm".
Add a row of type "Package" to a price list, set the number of photos and
choose the product each photo gets (save new downloads and prints first).
With the number set to 0 the package covers every photo in the gallery
("the whole gallery as downloads").

Customers add photos to a package from the lightbox ("Add to package");
the cart shows "3 of 10 photos chosen", the photos as thumbnails that can
be removed one by one, and the package price. A package can be ordered
before it is full. After the order each photo is its own line (price 0)
under the package, so downloads and prints work as usual and the order
screen lists every file. A package of prints is shipped.

## Discount codes

**SlashPhoto → Discount codes → Add discount code.** The title is the code
(stored in capitals; customers can type it in any case). Rules:

- percent or a fixed amount, on everything, downloads only or prints only
  (a package counts as what it contains);
- free shipping, with or without an amount off;
- minimum order value, first and last day;
- total number of uses and uses per customer (by email address).

Publish the code to switch it on, save it as a draft to switch it off. The
list shows how often each code was used; uses are counted from orders, so
cancelled orders give the use back. Customers type the code in the cart;
the discount is shown before VAT and shipping are worked out. With Stripe,
the discount is sent as a one-time coupon on the Checkout page.

## VAT by country

By default one VAT rate applies to every order. When you sell to other
countries at their own rates, for example under the EU One-Stop Shop
(OSS), list them under **Shop settings → Prices → VAT by country**, one per
line: `DE 19`, `FI 25.5`, `NO 0`. Countries not listed use the general
rate.

- Orders with prints are charged the rate of the delivery country.
- Orders with only downloads ask for the country the customer lives in;
  the checkout shows the matching VAT as soon as it is chosen, and the
  order cannot be placed without it.
- With prices that include VAT, customers pay the same price everywhere and
  only the VAT part changes; with prices before VAT, the country's rate is
  added on top.
- The order stores the country and the rate (shown on the order screen and
  the receipt), so later changes to the rates never change past orders.
- **Orders → VAT report** downloads a CSV of the orders paid in a period
  (the previous quarter is filled in): order number, payment date, status,
  VAT country, rate, currency, net, VAT, total and whether it contained
  prints, downloads or packages. It has no names or addresses. Sum it per
  country for the OSS return. Refunded orders are listed with their status
  so you can correct them.

SlashPhoto does not decide which rules apply to you (thresholds, reverse
charge for businesses, exports outside the EU); check with your tax
authority or accountant and enter the rates accordingly.

## Gift cards

Switch them on under **Shop settings → Gift cards**, enter the amounts
(for example `500, 1000, 2000`) and how many months they are valid (0 for
no end date), and put `[slashphoto_gift_cards]` on a page. Customers add a
card to the cart there and pay for it like any order.

- A gift card is a voucher, not a sale: it has no VAT, discount codes do
  not apply to it and it needs no shipping. VAT is charged on what it is
  later spent on. The VAT report lists gift cards sold and the part of an
  order a gift card paid in separate columns.
- When the order is paid, each card gets a code like `7K2M-QX9D-4RTB-WN3C`.
  The buyer sees the codes on the order page and in the payment email; the
  shop owner's email does not include them. Codes are derived from the
  order with the site's salt and are never stored: the database only holds
  a hash to look them up, so a copy of the database does not reveal usable
  codes.
- At checkout the customer enters the code under **Gift card code**. It
  pays as much of the order as its balance allows; the rest is paid with
  the chosen payment method, or nothing when the card covers everything.
  The payment providers receive the remaining amount (Stripe and PayPal
  see the card as a discount).
- Gift cards cannot pay for gift cards. The balance is checked again under
  a database lock when the order is placed, so one card cannot be spent
  twice at the same time.
- If an order that used a card is cancelled, fails, is refunded or is
  deleted as abandoned, its part goes back to the card. Refunding or
  cancelling the order that bought cards voids them.
- **SlashPhoto → Gift cards** lists every card with its balance and the
  orders that used it, voids or reactivates cards, and creates a card by
  hand (for prizes or cards you sell in person). A card made by hand shows
  its code once, to you, and never again.

## Receipts and invoices

Every order has a printable page: an **invoice** while a manual payment is
outstanding (with your payment details and the order number as reference)
and a **receipt** once paid, showing your business name and details from
the shop settings, the customer, every line (photos of packages listed
below them), discount, shipping, the VAT included or added and the
payment method. Customers open it from their order page or the payment
email and print it or save it as PDF in their browser; you open it from
the order screen. Add your address, company registration and VAT number
under Shop settings → Receipts and invoices.

## What customers see

- In the lightbox, a cart button opens the products for the photo, with a
  quantity for prints. A bar under the gallery shows the number of items
  and links to the cart.
- The cart page lists the photos with thumbnails, lets customers change
  quantities and shows subtotal, shipping, VAT and total. The checkout form
  asks for email and name, and a delivery address only when the cart has
  prints. Guests can order; logged-in clients can also order from galleries
  limited to their account.
- After ordering, customers land on their order page and get an email with
  a private link to it. Once the order is paid, the page has a download
  button for each download.

## Orders

**SlashPhoto → Orders** lists orders with status filters and search. The
menu shows how many orders wait for payment or fulfilment. An order shows
the photos with their file names (to find them in Lightroom), quantities,
the delivery address, the customer's message, payment details and a private
note. Each line has a link to download the original for printing.

| Status | Meaning | Customer email |
| --- | --- | --- |
| Awaiting payment | Sent to Stripe, not paid yet | – |
| Awaiting manual payment | Ordered with manual payment | Order received, with your payment details |
| Paid, being prepared | Paid; downloads are open | Payment received, with the download link |
| Completed | Delivered | Order complete |
| Cancelled, Refunded | | A short notice |
| Payment failed | A delayed payment method failed | – |

You get an email for new manual orders and for paid orders. Marking an
order paid opens its downloads for the number of days set in the settings.

**Export paid prints CSV** on the Orders overview exports paid orders awaiting
fulfilment. Use **Export prints CSV** in the list for a different status or
search. There is one row per print line, including prints in packages, with
quantity, original file label (without extension), the saved description and
the product, paper and frame where recorded. Older lines retain their saved
description. The CSV includes order and image IDs and excludes contact details,
addresses and download links.

## Stripe

Customers pay on Stripe's hosted Checkout page. Card details never reach
your site, no Stripe script is loaded on your pages and SlashPhoto adds no
fee. Methods such as Swish and Klarna appear when you switch them on in the
Stripe Dashboard.

1. Stripe Dashboard → Developers → API keys: copy the secret key (or create
   a restricted key with write access to Checkout Sessions) and paste it in
   the shop settings. The key is checked with Stripe and stored encrypted.
2. Recommended: add a webhook endpoint with the address shown in the
   settings and the events `checkout.session.completed`,
   `checkout.session.async_payment_succeeded`,
   `checkout.session.async_payment_failed` and `checkout.session.expired`,
   and paste its signing secret (`whsec_…`). Without a webhook, orders are
   marked paid when the customer returns to your site after paying; delayed
   methods then need a webhook.
3. Test with a `sk_test_…` key first; the settings say when test mode is on.

Refunds are made in the Stripe Dashboard; then set the order to "Refunded".

## PayPal

Customers approve the payment on paypal.com (PayPal balance, cards and
Pay Later where PayPal offers them) and come back to their order page,
where the payment is captured. No PayPal script is loaded on your pages.

1. developer.paypal.com → Apps & Credentials: create a REST app and copy
   its client ID and secret into Shop settings → PayPal. Keep "Sandbox"
   ticked to test; untick it with live credentials.
2. Recommended: add a webhook in the app with the address shown in the
   settings and the events "Checkout order approved", "Payment capture
   completed", "denied" and "refunded", and paste its webhook ID. Events
   are verified with PayPal's API before anything changes, and a payment
   is captured even if the customer closes the window after approving.

The order is sent with its lines, shipping, VAT (when added on top) and
discount, so PayPal shows the same totals.

## Mollie

Customers pay on Mollie's hosted checkout with the methods switched on in
the Mollie Dashboard (cards, iDEAL, Bancontact, Klarna, PayPal and more).
Paste a `test_…` or `live_…` API key in Shop settings → Mollie. Mollie's
webhook only sends a payment ID; SlashPhoto reads the payment back from
Mollie with your key, so a forged call changes nothing. On sites Mollie
cannot reach (localhost) no webhook is sent and payments are confirmed
when the customer returns.

## Security

- Prices, names, shipping and VAT are always calculated on the server from
  image and product IDs. A price sent by the browser is never used; if the
  cart changed since the customer saw it, the order is refused with the new
  prices.
- Every image in a cart must be in a gallery the customer can see at that
  moment (password, client list, secret link, end date, terms). Others are
  reported as unavailable, exactly like images that do not exist.
- The order link carries a key that is an HMAC of a random token with the
  site's salt. The key is not stored, so the database alone does not open
  orders or downloads. Order pages are not cached, not indexed and send no
  referrer.
- Downloads are streamed through PHP from the private originals folder,
  only for paid orders, only during the download period and only with the
  right key. Admin links to originals need a nonce and the right to edit
  the gallery.
- A smaller download is made from the original the first time it is
  downloaded and kept next to the original, in the same protected random
  folder, until the image is deleted. It keeps the original's format for
  JPEG, PNG, WebP and AVIF (other formats become JPEG), keeps the colour
  profile and photo metadata where the server's image library can, and has
  no watermark. Originals that are already small enough are delivered
  unchanged. Formats WordPress cannot open (RAW, PSD) need a connected
  SnapOtter server. The size is saved on the order line, so changing the
  price list later does not change what a customer bought.
- Stripe webhooks are accepted only with a valid signature no older than
  five minutes, and only for the Checkout Session stored with the order.
- The checkout has a hidden field that bots fill in and people do not.
  There is no rate limiting by IP address, because SlashPhoto stores none.

## Privacy

- The cart is kept in the visitor's own browser (local storage) until they
  order.
- An order stores what the customer typed in (name, email, phone if given,
  delivery address for prints, message) and what they bought. Orders that
  are never paid are deleted after a week.
- Orders are included in WordPress' personal data export. Erasure deletes
  unpaid orders and keeps paid ones, which are bookkeeping records.
- Stripe receives the customer's email address and the order lines;
  PayPal and Mollie receive the order lines and amounts.

## For developers

- Orders live in `{prefix}slashphoto_orders` and
  `{prefix}slashphoto_order_items`, amounts in minor units (öre, cents).
  The tables are never dropped, not even on uninstall.
- Add payment methods with the `slashphoto_gateways` filter (implement
  `SlashPhoto\Shop\Gateways\Gateway`).
- `slashphoto_order_status` fires on every status change
  (`$order_id, $new, $old`).
- `slashphoto_send_original` fires before a file is streamed; a handler can
  send `X-Sendfile` or `X-Accel-Redirect` and exit.
- `slashphoto_currencies` filters the currencies in the settings and
  `slashphoto_orders_capability` the capability for the Orders screen
  (default `manage_options`).
- Templates `checkout.php`, `order.php` and `receipt.php` can be copied to
  `yourtheme/slashphoto/`.

## Not yet

Print-lab integrations are planned.
