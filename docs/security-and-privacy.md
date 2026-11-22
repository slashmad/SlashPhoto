# Security and privacy

SlashPhoto is built for client galleries, so a gallery that is not meant to
be public must not leak through any side door of WordPress. It is also built
to collect nothing about visitors.

## Access model

Open **SlashPhoto → All galleries** for a grouped overview of editable
galleries. Each group shows its total and the most recently edited galleries.
Open its list to see the full group, or use **List view** for search and bulk
actions across galleries. **Private / unpublished** includes drafts, scheduled
galleries and galleries with an unpublished ancestor. **Password protected**
includes inherited passwords. **Restricted** covers other access rules such as
secret links, client accounts, terms and email gates. **Public** shows published
galleries with open access. These filters organize the admin list.

Each gallery can combine these rules. Sub-galleries always follow the rules of
every gallery above them, so a sub-gallery is never more open than its parent.

| Rule | Where it is set | Effect |
|---|---|---|
| Password | Status → Visibility → Password protected (WordPress' own) | Visitors must enter the password. A sub-gallery without its own password uses the nearest parent's password. |
| Selected clients | Access panel → "Only selected clients" | Visitors must log in with one of the listed accounts. Each listed gallery up the tree must allow the account. |
| Secret link | Access panel → "Only people with the secret link" | Only visitors who opened the gallery's link (with a 128-bit key) can view it, without an account. "New link" locks out everyone who used the old one. |
| Available until | Access panel | After the end of that day (site time zone) only editors can see the gallery. |
| Terms | Access panel | Visitors must accept the text before images are shown. Terms never grant access on their own. |

Editors (anyone who can edit the gallery) always see everything. Client
accounts can use the "Photo client" role, which can only log in and read.

Password and selected-client galleries can be combined: the visitor must
then pass both.

## What is closed

Implemented in `src/Access/AccessGuard.php` and covered by
`tests/Integration/AccessLeakTest.php` and `tests/e2e/access.spec.mjs`:

- The gallery page: author text and images are replaced by the password form,
  a log-in prompt, an "expired" notice or the terms form.
- REST API: `/wp/v2/media` lists and single items (including `?parent=`),
  `/wp/v2/slashphoto_gallery` lists and single items (including `?include=`).
  The client list, end date and terms are only exposed to editors.
- Attachment pages return 404, without the canonical redirect that would
  reveal the image name.
- Listings, search, feeds and previous/next links do not show client-only or
  expired galleries. Password galleries stay listed by title, as WordPress
  does for password-protected posts.
- XML sitemaps leave out every protected gallery; protected pages get
  `noindex, nofollow`.
- Protected pages are sent with `Cache-Control: no-store` and
  `DONOTCACHEPAGE`, so caching plugins and proxies do not serve an unlocked
  page to someone else. Pages that embed a protected gallery with the block
  are treated the same way.
- oEmbed refuses hidden galleries and images of protected galleries.
- The gallery block renders nothing for client-only, expired or draft
  galleries the visitor may not see, and only a link for password galleries.

## Secret links

The link is `…/galleries/name/?key=<32 hex characters>`. Opening it sets a
cookie `slashphoto_link_<gallery ID>` (HMAC of the gallery ID and the current
key, httpOnly, SameSite=Lax, 30 days) and redirects at once to the address
without the key, so the key does not stay in the browser history or in
Referer headers. The key is only visible to editors (REST edit context) and
is never writable as plain post meta. Web server access logs may record the
first request including the key; that is outside WordPress.

## Gallery invitations

An editor can send a gallery link and an optional message from the **Invite**
tab of a published gallery. Account restrictions still apply and the
recipient's email must belong to an eligible account if one is required.
The invitation uses the existing secret link when needed. If it comes from a
parent gallery, only someone who can edit the parent may send it. Share
gallery passwords separately. SlashPhoto passes invitations to WordPress
mail without keeping an invitation log.

## Sign-in links

Clients can sign in with an emailed link instead of a password (switch:
**Settings → Visitors → Sign-in links**, on by default).

- Only accounts that cannot edit content get links (filter
  `slashphoto_signin_link_allowed`). Photographers and administrators always
  use their password and any two-factor plugin, so a stolen mailbox cannot
  open the dashboard. An account that gains editing rights can no longer use
  links it was sent earlier.
- A client-only gallery offers "Email me a sign-in link" to visitors who are
  not logged in. The page answers the same way whether or not the address
  has an account, sends only to accounts allowed in that gallery, and sends
  at most one link per account a minute. The request itself is not stored.
- Invitations to galleries that need an account include a link that works
  for seven days; requested links work for one hour.
- A link is `/?slashphoto_signin=<user ID>-<48 hex characters>` and works
  once. Only an HMAC of the token (with the site's auth salt), its expiry
  and the gallery to open are kept in the client's user meta, at most five
  at a time; they are removed when used or expired.
- Opening the link shows a confirmation page (no-cache, noindex, no
  referrer). Signing in needs its button, a POST, so mail scanners that open
  links in advance do not use them up. The session is a normal WordPress
  login that ends when the browser closes.

## Image files

Images uploaded to a gallery are stored as
`uploads/slashphoto/<96-bit random folder>/<name>-<48-bit random suffix>.jpg`,
with an empty `index.php` in each folder. The URLs cannot be guessed or
enumerated, unlike WordPress' default `uploads/2026/11/DSC6751.jpg`.

Originals are moved into a further random folder per image,
`…/o<96-bit random>/<name>-<suffix>.jpg`. WordPress normally keeps the
original next to its web copy (`photo-scaled.jpg` and `photo.jpg`), so the
full-resolution original could be fetched by removing "-scaled" from any
preview URL. WordPress still finds the original for editing and deletes it
with the attachment.

Every gallery image gets a separate display copy (longest side set under
SlashPhoto → Settings, 2560 px by default; 360° panoramas up to 8192 px),
so the original is never the file shown on the site, also for small images.
Originals folders contain a `.htaccess` that denies web access on Apache
and LiteSpeed. On nginx, add a rule like this to the server block:

```nginx
location ~ ^/wp-content/uploads/slashphoto/(.+/)?(o[a-f0-9]{24}|tmp)/ {
    deny all;
}
```

Without it the random folder names still protect the originals. Signed
download links for sold or delivered originals are planned with the shop.

## Uploads of full-size originals

The "Upload photos" panel in the gallery editor sends files in pieces
(chunks of at most 8 MB, smaller if the server's `post_max_size` is lower),
so the size of an original is not limited by PHP's upload settings. Parts
are stored in `uploads/slashphoto/tmp/` with deny rules and random names,
only the user who started an upload can continue it, and abandoned parts
are deleted after a day. The file type is checked by extension when the
upload starts and by content when it is imported.

Display copies and sizes of JPEG uploads can be written as WebP or AVIF
(Settings → Display copies → File type) when the server's image library
supports it; the choice is limited to galleries, and originals, PNG files
and downloads keep their own format. Photo metadata in the display copy
follows WordPress' usual stripping; the original keeps everything.

## Import folder

Settings → Import folder imports originals copied into
`wp-content/uploads/slashphoto-inbox/<gallery ID or slug>/` (or the folder
in the `SLASHPHOTO_INBOX_DIR` constant, which can be outside the web root).

- The folder gets the same deny rules as originals folders; on nginx add
  the deny rule for it as well, or use a folder outside the web root.
- Only photo file types are imported; other files are left in place. A
  file must be unchanged for a minute before it is imported.
- The importer works on a copy and removes the file only after the photo
  is in the gallery. Files that fail are moved to `_failed/<folder>/` and
  listed in the last-run report.
- A folder that does not match a gallery creates an unpublished draft, so
  new photos never become public without you. Existing galleries keep
  their access rules.
- Automatic import runs every five minutes with WP-Cron when switched on
  (off by default); "Import now" and `wp slashphoto inbox [--dry-run]` run
  it on demand. Only administrators can change it.

## SnapOtter

When a [SnapOtter](https://github.com/snapotter-hq/SnapOtter) server is
connected (see [snapotter.md](snapotter.md)), originals are sent to the
address the administrator entered, and nowhere else. SlashPhoto stores only
an API key, encrypted with a key derived from the site's salts; passwords
entered to connect are used once and discarded. SnapOtter itself sends
anonymous usage statistics to its developers by default, which can be
switched off in SnapOtter.

## Shop

See [shop.md](shop.md). In short: prices and availability are always
computed on the server, every image in a cart must be visible to the buyer
through the access rules above, order links carry an HMAC key that is not
stored, order pages are not cached or indexed and send no referrer,
downloads are streamed only for paid orders, and Stripe webhooks need a
valid, fresh signature. Receipts and invoices use the same order key and
headers as the order page. Discount codes are counted from orders, not
from visitors; there is no rate limit by IP address, so use codes that are
not easy to guess. Gift card codes have 80 random-looking bits, are derived
from the order with the site's salt and are stored only as an HMAC, and
their balance is spent under a database lock. Stripe Checkout is hosted by Stripe: card details
never reach the site and no Stripe script is loaded on its pages. Stripe
keys are stored encrypted like the SnapOtter key.

## Watermarks

With a watermark PNG set under SlashPhoto → Settings and watermarks switched
on for a gallery (or by default), every web copy of new uploads is stamped:
the "-scaled" display copy and all generated sizes. For these galleries
WordPress is made to create a separate display copy even for small images,
so the stamp never touches the original, which is moved out of reach as
above. 360° panoramas are not stamped. Images uploaded before watermarks
were switched on are not changed until the gallery's photos are processed
again (see below).

## Processing existing photos again

Settings for watermarks, display size and quality and SnapOtter apply when
a photo is uploaded. **Make new copies** on a gallery's Photos tab (or
`wp slashphoto reprocess <gallery ID>…` / `--all`) applies the current
settings to the photos already in that gallery (not its sub-galleries):

- The display copy and all sizes are made again from the original, so a
  watermark switched on later is stamped and a changed display size is
  used. The original itself is never stamped or re-encoded.
- The files move into the gallery's random folder with new random names,
  and the original into a new protected folder. Older uploads with ordinary
  WordPress paths, or with the original still next to the display copy,
  become private. Old addresses stop working, which also clears caches.
- The photo keeps its ID, title, caption, position, cover role, client
  selections and orders. Smaller download copies are made again when next
  bought.
- The old files are deleted only after the original in its new place has
  been compared byte for byte. If anything fails, the photo stays exactly
  as it was and the failure is listed.
- It needs the right to upload files and to edit the gallery. RAW, HEIC,
  TIFF and PSD originals need a connected SnapOtter server.

Known limits:

- Anyone who has been given an image URL can share it. Web images are served
  directly by the web server for speed. Originals sold as downloads are
  streamed through PHP, only for paid orders, during the download period and
  with the order's secret key (see below).
- Images uploaded before this feature (or uploaded outside a gallery and
  attached later) keep their old WordPress paths until the gallery's photos
  are processed again.
- Someone with access to the server's file system is outside this model.

## Privacy: what is stored about visitors

Changed by D-021 at the user's request (before, nothing was stored); see
[visitors.md](visitors.md).

- Never stored: IP addresses, user agents, device details, visit logs,
  sessions. Nothing is loaded from third parties (no CDNs, fonts,
  analytics).
- View counts: anonymous daily totals per gallery and photo (day,
  gallery, photo, count), only when switched on. Editors and obvious
  robots are not counted; the user agent is read for that check and never
  stored.
- Email request: the address a visitor types (with consent), the gallery
  and the date; a signed cookie `slashphoto_email_<gallery ID>` (log entry
  ID + HMAC) for 30 days. Logged-in visitors: their account, once per
  gallery.
- Terms: the cookie `slashphoto_terms_<gallery ID>` for 30 days (filter
  `slashphoto_terms_cookie_lifetime`; HMAC of the gallery ID and the exact
  wording, so changing the wording asks everyone again), and a log entry
  with the date, a fingerprint of the wording and the account or email
  address.
- The visitor log is deleted after the retention period (default 365
  days) and is covered by the export and erase tools.
- WordPress' own password cookie (`wp-postpass_…`) is used for passwords.
- Proofing: favourites of visitors who are not logged in stay in the
  browser's localStorage (`slashphoto-favourites-<gallery ID>`) and are never
  sent. Logged-in clients' selections store only what they choose (images,
  notes, a message, the time they sent it) in a private post owned by their
  account. They are deleted with the account or the gallery and covered by
  the export and erase tools. See [proofing.md](proofing.md).
- Shop: the cart stays in the visitor's localStorage (`slashphoto-cart`:
  image IDs, product IDs, quantities) until they order. An order stores what
  the customer typed in and what they bought; unpaid orders are deleted
  after a week. Export covers orders; erasure deletes unpaid orders and
  keeps paid ones as bookkeeping records. Stripe receives the customer's
  email address and the order lines when they choose to pay online.
- Client accounts are ordinary WordPress users; WordPress' personal data
  export and erase tools apply to them. Unused sign-in link tokens (hashes
  with an expiry) are kept in user meta until used or expired.
- SlashPhoto adds suggested text for the site's privacy policy under
  Settings → Privacy.
