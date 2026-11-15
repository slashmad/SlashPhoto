# Shop: downloads and prints

SlashPhoto sells two kinds of products for each photo:

- **Downloads** of the original you uploaded (full resolution, the file as
  it came from your camera or editor).
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
   and the price. A new list suggests a download and three print sizes;
   rows without a price are not saved.
3. **In the gallery editor**, open the "Shop" panel and choose the price
   list. Sub-galleries use the same list unless they choose another or "Not
   for sale". A default price list in the shop settings covers top-level
   galleries without their own choice.
4. **Payment.** Manual payment (invoice, bank transfer, Swish, cash) is on
   by default: write your payment details in the settings. For card
   payments, connect Stripe (below).

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
- Stripe receives the customer's email address and the order lines.

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

Product options (paper, frames), tax per country, CSV export, print-lab
integrations, downloads in smaller sizes and gift cards are planned.
