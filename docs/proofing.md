# Proofing

Clients choose the images they want and send the selection to the
photographer. No payment is involved; the shop is a separate feature.

## Switching it on

In the gallery workspace, open **Settings → Access** and set **Client
selections** to **On**. Optionally set a maximum number of images.

A selection belongs to the nearest gallery up the tree where proofing is
**On**. Sub-galleries left on "Same as parent gallery" add to the same
selection, so a wedding with chapters gives one selection, not one per
chapter. A sub-gallery can be set to **Off**, or to **On** to start a
separate selection.

## For clients

- A heart on each image (and in the lightbox) adds it to the selection.
  With a mouse, hearts of unselected images appear on hover; on touch
  screens they are always visible.
- Logged-in clients can add a note to a selected image from the lightbox
  and send the selection with an optional message. After sending, the
  selection is locked until the photographer reopens it.
- **Show only selected** filters the grid; the lightbox then steps through
  the selected images only.
- Visitors who are not logged in keep favourites in their own browser and
  see a link to log in. After logging in, those favourites are added to
  their selection once.

Who may choose follows the gallery's access rules exactly: passwords,
client lists, secret links, end dates and terms all apply, also to each
sub-gallery's images.

## For the photographer

- When a selection is sent, the gallery's author gets a plain-text email
  (filter `slashphoto_selection_recipients`; return an empty array to send
  none). The client's address is set as Reply-To.
- The client gets a copy at their account's email address: the chosen file
  names in their order, their notes and message, and a link back to the
  gallery (left out for secret-link galleries, whose plain address does not
  open them). Replies go to the gallery's author. Switch it off under
  **Settings → Proofing**, or per gallery with the filter
  `slashphoto_selection_client_email` (`bool $send, WP_Post $selection,
  WP_Post $gallery`).
- The **Selections** column in the gallery list shows how many clients have
  sent a selection and how many are still choosing, linked to the overview
  filtered by that gallery.
- **SlashPhoto → Client selections** shows selections across galleries you
  can edit. Filter by gallery or by whether the client has submitted their
  selection, then open a selection for review.
- The review shows the client's message, selected photos and image notes.
  **File names for Lightroom** gives you the original file labels without
  extensions and upload suffixes. **Download CSV** includes those labels,
  image IDs and per-photo notes in the order the client chose them.
- **Let the client change it** reopens a sent selection; **Delete selection**
  removes it.

## Data and privacy

- Stored: per client and gallery, the chosen image IDs with notes, the
  message, and the time it was sent. Nothing about browsing, devices or
  networks.
- Storage: a private post type (`slashphoto_selection`) with no front end,
  REST route or admin screen of its own. Selections are deleted with the
  client's account and with the gallery, and are included in WordPress'
  personal data export and erase tools.
- Anonymous favourites: `localStorage` key `slashphoto-favourites-<gallery
  ID>` in the visitor's browser, never sent to the site.

## REST API

All routes are under `/wp-json/slashphoto/v1/selections/<gallery ID>` and
need a logged-in user with a REST nonce. Any gallery or image the user may
not see answers `404 slashphoto_not_found`, like one that does not exist.

| Method | Route | Body | Result |
| --- | --- | --- | --- |
| GET | `` | | the user's selection |
| POST | `/items` | `id`, `selected`, optional `note` | updated selection; `409 slashphoto_selection_full` over the maximum |
| POST | `/merge` | `ids` | adds favourites chosen before logging in; skips images not allowed or over the maximum |
| POST | `/submit` | optional `message` | sends the selection; `409 slashphoto_selection_submitted` once sent |

Hooks: `slashphoto_selection_submitted` (selection, gallery) and
`slashphoto_selection_changed` (operation, selection).
