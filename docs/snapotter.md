# SnapOtter image processing

[SnapOtter](https://github.com/snapotter-hq/SnapOtter) is a free, self-hosted
image toolkit (AGPL-3.0) that runs in Docker. SlashPhoto can use it to make
the display copies of gallery images. SlashPhoto talks to it over HTTP only;
no SnapOtter code is included in the plugin.

## Why

- **RAW, HEIC, TIFF and PSD**: upload camera RAW files (ARW, CR2, CR3, NEF,
  DNG, RAF, ORF, RW2, …) or edited TIFF/PSD files as originals. SnapOtter
  decodes them; WordPress cannot.
- **Large originals**: decoding a 60-megapixel image needs more memory than
  many web hosts allow. SnapOtter does the heavy step on your own hardware;
  WordPress only makes the small sizes from the display copy.

The display copy keeps the original's colour profile, EXIF and XMP, and is
turned upright according to the camera's orientation.

## Connecting

SlashPhoto → Settings → **SnapOtter connection**:

1. **Server address**: the address your web server uses to reach SnapOtter,
   for example `http://192.168.1.10:1349` on a home network or
   `https://snapotter.example.com` across the internet (use HTTPS then).
2. Either
   - **User name and password**: used once to create an API key named
     "SlashPhoto (your-site)"; the password is not stored. Disconnecting
     deletes that key from SnapOtter. Accounts with two-factor sign-in need
     the API key option.
   - **API key**: create one in SnapOtter under Settings → API Keys. You can
     restrict it to using tools and give it an expiry date.

**Test connection** checks that SnapOtter is healthy and accepts the key.

## What is sent where

Under **Image processing with SnapOtter** choose:

| Setting | RAW/HEIC/TIFF/PSD | JPEG/PNG/WebP/AVIF |
| --- | --- | --- |
| RAW, HEIC, TIFF, PSD and very large images (default) | SnapOtter | SnapOtter from the megapixel limit (default 40), WordPress below |
| All gallery uploads | SnapOtter | SnapOtter |
| Nothing | refused | WordPress |

If SnapOtter cannot be reached, WordPress makes the copy for web formats;
RAW and similar files are refused with a message. The attachment meta
`_slashphoto_display_copy` is `snapotter` when SnapOtter made the copy.

## Privacy

- Files go only to the address you entered.
- SnapOtter keeps processed files until its regular clean-up
  (`CLEANUP_INTERVAL_MINUTES`).
- SnapOtter sends anonymous usage statistics (tool names, counts; never
  files, file names or metadata) to its developers by default. Switch this
  off in SnapOtter under Settings → System Settings, or start it with
  `SNAPOTTER_ANALYTICS_DEFAULT=off`.

## Development

A local server for testing, on the wp-env network so WordPress reaches it as
`http://snapotter:1349` (user `admin`, password `admin`):

```sh
docker run -d --name slashphoto-snapotter \
  --network "$(docker network ls --format '{{.Name}}' | grep 'wp-env-slashphoto.*_default')" \
  --network-alias snapotter -p 127.0.0.1:1349:1349 \
  -e SNAPOTTER_ANALYTICS_DEFAULT=off -e SKIP_MUST_CHANGE_PASSWORD=true \
  snapotter/snapotter:2.3.0

npx wp-env run tests-cli --env-cwd=wp-content/plugins/slashphoto \
  env SLASHPHOTO_SNAPOTTER_URL=http://snapotter:1349 vendor/bin/phpunit --filter SnapOtter
SNAPOTTER_E2E_URL=http://snapotter:1349 npx playwright test tests/e2e/upload.spec.mjs
```

Tested with SnapOtter 2.3.0. Calls used: `POST /api/auth/login`,
`POST /api/v1/api-keys`, `GET /api/v1/health`, `GET /api/v1/pipeline/tools`,
`POST /api/v1/pipeline/execute` with one `optimize-for-web` step
(`stripMetadata: false`), and the returned download URL.
