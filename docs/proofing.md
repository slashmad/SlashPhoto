# Proofing

Clients choose the images they want and send the selection to the
photographer. No payment is involved; the shop is a separate feature.

## Switching it on

In the gallery editor, open **Client selections** in the sidebar (or the
**Access** box in the classic editor) and choose **On**. Optionally set a
maximum number of images.

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
- The **Client selections** box on the gallery's edit screen (in the block
  editor: the Meta Boxes pane at the bottom) lists every client's selection
  with thumbnails, notes, the message and the file names (without the random
  suffix SlashPhoto adds to uploads) to paste into a catalogue filter such as
  Lightroom's.
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
