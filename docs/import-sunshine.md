# Import from Sunshine Photo Cart

SlashPhoto can copy a site built with Sunshine Photo Cart (tested with
3.8): galleries, images, prices and orders. Sunshine's own data is only
read, never changed, and Sunshine does not have to be active. You can
compare both side by side before switching Sunshine off.

## Running it

- **SlashPhoto → Import from Sunshine** appears when the site has Sunshine
  galleries or orders. It shows what was found and runs the import in
  short steps; keep the page open while images are copied.
- Or from the shell: `wp slashphoto import-sunshine`.

Running it again continues where it stopped and skips everything already
imported. "Look for new Sunshine content again" (or `--restart`) picks up
galleries, images and orders added in Sunshine since, and retries images
that could not be copied.

## What is carried over

| Sunshine | SlashPhoto |
| --- | --- |
| Price level | Price list "<name> (from Sunshine)" with every product priced at that level. The default level becomes the default price list. |
| Product | Download (type "download") or print, named "Category – Product". |
| Gallery | Gallery with title, text, slug, date, order, author and sub-galleries. |
| Password | WordPress password on the gallery (hints are listed for you, not imported). |
| Private (selected users) | Clients mode with the same accounts. |
| Access type "Direct URL" | Secret link. |
| Access type "Provide email" or "Registered and logged in" | Public, and listed under "To check": SlashPhoto does not collect visitor emails and has no "any logged-in visitor" gate. |
| Expiration date | End date. |
| Terms required | The Sunshine terms text on the gallery. |
| Price level, products disabled | The matching price list, or "Not for sale". |
| Images (in Sunshine's order) | Copied through the normal gallery import: display copy, original stored privately, title, caption, alt text, featured image. |
| Orders | Orders with customer, address, lines, amounts, discount, shipping, VAT and date. Paid orders stay paid; shipped becomes completed; unpaid orders are kept as "awaiting manual payment". |

Customers get no emails during the import. Their old Sunshine order links
do not carry over; imported orders get new private links (open the order
and send the customer its link if they need downloads again).

## Things to know

- Images are copied, not moved, so the import needs disk space for a
  second set of originals until you remove Sunshine and its files.
- Sunshine's download add-ons, packages, gift cards, favourites, comments
  and terms agreement logs are not imported. Terms agreements are a log of
  visitors, which SlashPhoto does not keep by design.
- VAT rates per order are not stored by Sunshine in a reusable form;
  imported orders keep their VAT amount.
