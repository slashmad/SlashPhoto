# Visitors: logged-in mode, email request, terms log, statistics

SlashPhoto never stores IP addresses, user agents or anything about a
visitor's device. Since 0.1 (D-021) it can store what visitors choose to
tell the photographer, plus anonymous view counts. Everything here is
switched on per gallery or in **SlashPhoto → Settings → Visitors**.

## Access for any logged-in account

Access panel → "Anyone logged in to this site". Every account on the site
can see the gallery (Sunshine's "Registered and logged in"); visitors who
are not logged in see a log-in prompt. Sub-galleries inherit it. Use
"Only selected clients" to limit it to particular accounts.

## Email request

Access panel → "Ask for an email address". Before the photos are shown,
visitors type their email address and tick a consent box that says how
long it is kept. Sub-galleries share the request unless they switch it
off. The browser remembers the answer for 30 days in a signed cookie
(`slashphoto_email_<gallery ID>`). Logged-in visitors are not asked; their
account is noted once per gallery instead. The request only adds a step:
it never opens a gallery the visitor could not otherwise see.

## Terms log

When a gallery has terms, every acceptance is logged with the date, a
fingerprint of the exact wording (it changes when you change the text)
and the visitor's account or email address. Visitors who are not logged
in and have not given an address at an email request are asked for one on
the terms form (switch this off in the settings). The browser still
remembers the acceptance in the `slashphoto_terms_<gallery ID>` cookie.

## The visitor log

**SlashPhoto → Visitors** lists email addresses and terms acceptances
with date, gallery and account, filterable by type, gallery and address,
and downloadable as CSV (values that spreadsheets would run as formulas
are neutralised). Entries are deleted after the retention period (365
days by default, 0 keeps them). WordPress' Export/Erase Personal Data
tools cover them, by email address or account.

## Statistics

With "Count how often galleries and photos are viewed" on (default), the
browser tells the site when a gallery page is opened and when a photo is
shown in the lightbox, once per page view. The site adds one to that
day's total for the gallery or photo. The table holds only day, gallery,
photo and count: no cookie, no IP address, no user agent, no session.
People who can edit the gallery and obvious robots are not counted.
**SlashPhoto → Statistics** shows views per day, per gallery and the most
viewed photos for 7, 30, 90 or 365 days.

## Privacy policy

The suggested text under Settings → Privacy describes exactly what is
enabled: the statistics sentence appears only when counting is on, the
retention period is filled in, and the payment providers that are set up
are named.
