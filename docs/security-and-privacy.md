# Security and privacy

SlashPhoto is built for client galleries, so a gallery that is not meant to
be public must not leak through any side door of WordPress. It is also built
to collect nothing about visitors.

## Access model

Each gallery can combine these rules. Sub-galleries always follow the rules of
every gallery above them, so a sub-gallery is never more open than its parent.

| Rule | Where it is set | Effect |
|---|---|---|
| Password | Status → Visibility → Password protected (WordPress' own) | Visitors must enter the password. A sub-gallery without its own password uses the nearest parent's password. |
| Selected clients | Access panel → "Only selected clients" | Visitors must log in with one of the listed accounts. Each listed gallery up the tree must allow the account. |
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

## Image files

Images uploaded to a gallery are stored as
`uploads/slashphoto/<96-bit random folder>/<name>-<48-bit random suffix>.jpg`,
with an empty `index.php` in each folder. The URLs cannot be guessed or
enumerated, unlike WordPress' default `uploads/2026/11/DSC6751.jpg`.

Known limits:

- Anyone who has been given an image URL can share it. Web images are served
  directly by the web server for speed. Originals sold as downloads will be
  delivered through signed, expiring links (planned, see the roadmap).
- Images uploaded before this feature (or uploaded outside a gallery and
  attached later) keep their old WordPress paths.
- Someone with access to the server's file system is outside this model.

## Privacy: no visitor logging

- No IP addresses, user agents, visit counts or timestamps about visitors are
  stored, and nothing is sent to third parties (no CDNs, fonts, analytics).
- Accepting terms sets one cookie, `slashphoto_terms_<gallery ID>`, for 30
  days (filter `slashphoto_terms_cookie_lifetime`). Its value is an HMAC of
  the gallery ID and the exact wording, signed with the site's auth salt. It
  contains nothing about the visitor, and nothing is stored on the server.
  Changing the wording asks everyone to accept again.
- WordPress' own password cookie (`wp-postpass_…`) is used for passwords.
- Client accounts are ordinary WordPress users; WordPress' personal data
  export and erase tools apply to them.
- SlashPhoto adds suggested text for the site's privacy policy under
  Settings → Privacy.
