# BT Accounts

Contract-account portal for Boomer T's. Named business accounts (Cintas/Sasha is the first)
sign in, place orders against agreed pricing, and the shop works them from an order queue.
Order numbers are `CIN-####`.

- Current version: **0.16.1**. Constant `BTA_VERSION`, function prefix `bta_`.
- Repo: `strummer95/bt-accounts`

## Environment

**Boomer T's Ink & Thread is a separate company from Duck and Rabbit Co.** Dillon's dad's
shop. **AWS Lightsail Bitnami WordPress + Elementor, NOT IONOS.** Never conflate with
PresStora.

**Dillon works only through the WordPress dashboard.** No SSH, no SFTP. Everything ships as
a plugin update.

Brand: navy `#27267e`, pink/magenta `#e535ab`, Oswald.

## Release process

Four places must match or WordPress loops forever trying to reinstall:

1. `Version:` in `bt-accounts/bt-accounts.php`
2. `BTA_VERSION` in the same file
3. `version` in `manifest.json`
4. The version inside the zip

Steps: edit under `bt-accounts/`, bump both version spots, `node --check` touched JS
and `php -l` touched PHP (the container has PHP 8.3 now), build `bt-accounts-X.Y.Z.zip` plus
plain `bt-accounts.zip` at the repo root, update `manifest.json` with the version, the
**versioned** raw `download_url` and a changelog entry, commit and push to `main`. Dillon
then does **BT Accounts → Check for updates** (the panel at the bottom of the BT Accounts
page), then **Plugins → Update Now**.

`uploads.github.com` is blocked from the container, which is why releases use versioned raw
zips rather than GitHub Release assets. The updater reads `manifest.json` through
`api.github.com` with `Accept: application/vnd.github.raw`, so a push is live instantly.

`includes/bt-admin.php` is byte-identical across bt-portal, bt-catalog, bt-quote,
bt-accounts and bt-dtf. Don't fork it; re-copy into all five in the same release round if
it changes.

**Where the update check lives is a fixed rule across the BT plugins:** the shared panel
is the last thing on the plugin's own top-level admin page. Never a separate Updates
submenu. BT Transfers was the one exception until 0.7.5 and it is not coming back.

## Structure

`bt-accounts.php` main · `includes/schema.php` tables · `includes/accounts.php` the account
model · `includes/auth.php` sign-in, sessions, capability checks · `includes/orders.php`
the order model · `includes/admin.php` and `admin-orders.php` shop-side management ·
`includes/portal.php` the customer-facing portal shell · `portal-orders.php` order entry
and list · `portal-quote.php` the account quoter · `portal-print.php` printable work order ·
`includes/notify.php` order notification emails · `includes/pricing.php` ·
`includes/staff-orders.php` shop staff order handling (see below) · `includes/printavo.php`
Printavo quotes (see below) ·
`includes/admin-diagnostics.php` · `assets/` order-form.js, quote.js, portal.css, print.css

This plugin has its **own auth system**, separate from BT Portal's `bt_portal_user` roles.
Portal roles are for shop staff; this is for outside contract customers. Don't merge them.

Print is the default decoration in the account quoter.

## Shop staff access (0.9.0)

Staff work account orders from BT Portal, not wp-admin. `includes/staff-orders.php` renders
the queue as `[bta_staff_orders]`, and BT Portal (0.51.0+) hosts it at **Other > Accounts**,
`/employees/accounts`. REST lives under `bt-accounts/v1/staff/*` with cookie auth + `wp_rest`
nonce.

- Capability `bta_handle_orders` (`BTA_STAFF_CAP`). Administrators get it through a
  `user_has_cap` filter, never stored. Everyone else gets it **on their own user record**
  from **BT Accounts > Shop staff**, never on a role, so one employee having it does not
  mean every Portal User does. Candidates listed there are the two BT Portal roles plus
  anyone already holding the cap.
- The print sheet is gated on the same cap. The wp-admin Orders screen is still
  `manage_options`.
- History entries are signed with `btp_actor_name()`, matching the board.
- Job cards are found by search over `wp_bt_jobs` (order #, customer, card id) because
  card ids are not visible anywhere on the board.
- **Create job card** (0.10.0) calls BT Portal's `window.btpNewJob(prefill, onCreated)`
  (0.52.0+), which opens the board's own New Job window prefilled and hands back the
  saved row; the screen then links it. The button hides when that hook is absent.
- Order addresses (0.11.0): `/employees/accounts/cin-1001` via BT Portal's `btpSetItem` /
  `btpCurrentItem` (0.53.0+). The address is the source of truth in `btaStaffLoad()`.
  `GET /staff/orders/number/{number}` resolves it, case-insensitive.

## Printavo (0.12.0)

**Per account, not every account.** An account sends only if it has a Printavo contact set
(`bta_pv_account_on()`); one without stays out of Printavo, no queueing, no failure emails.
Cintas → Sasha Velez (default for a Cintas-named account; ids cached in `bta_pv_acct_{id}`).
Each order from an account that sends is queued (WP-Cron) and created in Printavo as a **quote**
on that contact. The shop
reviews it and sends it for approval by hand; the plugin never approves, invoices or emails from
Printavo. Settings and Test connection are on the main BT Accounts page; the staff screen shows the
quote # with Try again / Send again. Failures email the shop and never touch the order.

- API v2 GraphQL at `www.printavo.com/api/v2`, headers `email` + `token`. Premium plan only.
  10 requests / 5 s, so calls are spaced 0.6 s.
- Printavo's docs site is blocked from the container, so field names are not hard-coded:
  `bta_pv_type()` introspects and `bta_pv_fit()` drops any field Printavo lacks. Alternate names
  (`contactId` beside `contact`, `zip` beside `zipCode`) are offered together on purpose. If
  introspection is off it falls back to the documented names (`bta_pv_blind_sig`). Everything is
  also in the production note as text. Verify against the first real quote and tighten then.
- Confirmed against live Printavo (0.12.1–0.12.2): tags must start with `#` (`#BTAccounts`); the
  contact lookup lands quotes on Sasha's customer. PO (`visualPoNumber`) and `customerDueAt` are
  set by `quoteUpdate` straight after create if `quoteCreate` won't take them. Line item Category:
  print → Digi Print, embroidery → Embroidery (matched by name, overridable in settings).
  Live Printavo has **no top-level categories query**; `bta_pv_find_category_path()` walks
  no-arg object fields up to 3 levels for a `*CategoryConnection` and caches the path in
  `bta_pv_cat_path` (Test connection clears it). Confirmed live in 0.12.4 (17 categories; Embroidery landed).
  Confirmed live: PO (`PO #`), customer and production due dates, ship-to, tag.
- Due dates (0.12.3, `orders.php`): in-hands must be a weekday at least 7 days after submission;
  weekend or after 5pm Friday counts from the next Monday (`bta_min_in_hands()`). Printavo
  `customerDueAt` = `bta_customer_due()` (in-hands held to that rule), `dueAt` (production) =
  `bta_production_due()`, the business day before. No holiday calendar.
- Line item description format is Dillon's, exactly: garment name, blank line, `LOCATION:` /
  art name per location (blank line between), blank line, `Blanks: supplier, PO x, arriving date`.
  Production note is ONE line (order # · employee-portal link); Dillon wants it out of the way.
  Ship method goes in Customer Notes (`Ship via: …`) above the order's own notes.
- Order lines now have `locations` (JSON list of placement / art_id / emb) plus `unit_price` and
  `price_note`. `placement` and `art_id` still hold a summary and the first logo for old readers.
  Line prices come from `bta_price_order_line()`: the Quote tab's engine on the account's rates.
  **Garment (0.13.0):** a line picked from BT Catalog (`catalog_id`) adds that style's customer price
  (`bta_catalog_price()` → `bt_cat_price_pair()`, the product page's "/ea retail") via the engine's
  `custom` garment, counted once per line. Typed-in styles stay decoration-only (`supplied`).
  Dillon confirmed: 2 × JST73 @ $36.95 + embroidered logo = **$64.85 each**. Legacy lines with no
  stored price are priced at Printavo send time (`bta_pv_unit_price()`).
- Order form fields are `item[i][...]` with explicit indexes; the old `item_x[]` arrays shifted
  sizes onto the wrong line when one was removed.

## Merch stores (0.13.2)

Second account kind: `accounts.kind` = `contract` (default, they send blanks) or `merch` (a band or
brand whose web store we print for). **Leonid & Friends** is the first: prefix `LAF`, login `kim`,
seeded once by `bta_seed_leonid()` (option `bta_seed_leonid_done`; only a bcrypt hash is in the repo).

- `includes/merch.php` model · `portal-merch.php` portal tabs · `admin-merch.php` wp-admin ·
  `assets/merch-form.js`. Tables v4: `bta_products`, `bta_art_library`, `bta_payments`.
- Product = one style with a colour list, sizes, `channels` (both / bulk / ondemand), bulk and
  on-demand price per piece, 2XL+ upcharge, and the library art it may carry (none = any).
  Seeded line-up (Dillon's): Gildan 5000 / 5V00L / 5400 in Black, Sport Grey, White and Valucap
  VC300A in Black, White, Khaki, Red on both; Port & Company LPC54V Black/White on-demand only.
  Prices left blank on purpose (shop sets them; blank shows "Ask" and the line comes in unpriced).
- Order form (0.13.3, Dillon's design): no pre-made list. **Add item** → pick garment by picture
  tile → pick design (skipped with 0–1 designs) → size grid, one row per colour. Fields
  `line[i][product]`, `line[i][art]`, `line[i][qty][colour][size]`; each colour with a qty becomes
  its own order line. Same builder for bulk and on demand. Pictures: `bta_product_image()` = the
  product's image URL, else BT Catalog's photo of the **black** colourway (Dillon's call) for the
  same `style_no`, read from the catalog row's `colors` JSON (`[name, hex, img, swatch…]` per colour,
  0.13.4). Each colour row in the size grid shows that colour's catalog photo.
- Art (0.14.0, schema 5): `art_library.placement` may list several (`Hat Front, Left Chest`);
  `bta_line_placement()` gives a hat (`bta_product_is_hat()`, by name) the hat one and a shirt the
  other. `art_library.variants` = colour versions (label / garment colours / file / preview / note);
  `bta_art_version()` picks by line colour, else the row's own file. Each version is copied to
  `order_art` separately, labelled `Bottle Cap (version 2)`.
- **Bottle Cap** (Leonid, "Make Me Smile" roundel) ships in `assets/art/` (PDF 8.5 MB + PNG);
  `bta_seed_bottle_cap()` copies it to `uploads/bt-accounts-art/leonid-bottle-cap.*` once. After a
  release where that has run live, the files can come out of `assets/art/` to shrink the zip.
  Cap versions per Dillon's Chipply table: 1 Black, 2 Red (blue/yellow), 3 Khaki + Brown
  (yellow/red). Versions 2 and 3 have no files yet; White cap gets the default (version 1 file).
- Mockups (0.15.0, schema 6), borrowed from PresStora (`pressly` repo, `includes/zones.php`):
  a print location is a box on the product photo, x/y/w/h in % of the image; art is
  contain-fitted and centred in it. Same location keys (`full_front`, `left_chest`, `right_chest`,
  `hat`, …); `bta_location_key()` maps labels ("Hat Front" → `hat`). PresStora's real boxes are
  drawn per style in its DB (nothing to copy), so `bta_default_zones()` holds starting boxes.
  0.16.1: PresStora's 25/20/50/55 drew art ~1.6x too big on catalog photos (Dillon); now sized from
  his Chipply mockup (full front ≈ ⅓ shirt width under the collar): tee full front 36.5/22/27/33,
  left chest 56/22/10/10, ladies full front 37.5/26/25/30, hat 36/29/28/22. And
  `products.zones` stores the shop's full set from the drag editor on the product form (a saved
  set replaces the defaults). Left chest = wearer's left = right side of the photo.
  "Put art X at location Y" = set the art's placement to Y; the mockup uses the product's Y box.
- More Leonid designs (0.15.1, schema 7, `bta_seed_leonid_designs()`): Deep in the Heart of Texas
  and Make Me Smile Tour 2026, Full Front, PNGs (from Dillon's webps) in `assets/art/`. Design
  choices are filtered by `bta_art_fits()`: a cap only gets art with a hat placement, a shirt only
  art with a non-hat one.
- 0.16.0 (schema 8): `art_library.garment_colors` limits a design to garment colours
  (`bta_art_on_color()`; Fall 2026 Tour Dates is white text → Black only). A form card can add a
  **second print** at a different location (`line[i][extra]`, e.g. dates on the back); it becomes a
  second entry in the line's `locations`, and `products.extra_price` is added per piece (blank =
  line priced by the shop). Seeded by `bta_seed_leonid_tour()`: Tour 2026 (Record) Full Front, Fall
  2026 Tour Dates Full Back; the earlier Tour 2026 renamed "(Van)". No back photos, so a back-only
  design shows as the art itself instead of a mockup.
- **Bulk** = stock they order (shows, tours). **On demand** = one web-store (Chipply) customer's order,
  shipped to that customer; `external_ref` holds the store order # and blocks double entry.
- Merch orders use the same orders table (`order_type` bulk / ondemand). Library art used on a
  line is copied into `order_art` so print sheet, emails, staff screen and Printavo read it
  unchanged. Prices come from the product server-side, never from the form.
- Money: `subtotal` (sum of `line_total`) + `shipping` + `adjustment` − `amount_paid`. The shop
  edits line prices, shipping and adjustment and records payments on the wp-admin order page.
- Card payments: Stripe Checkout through `wp_remote_request`, no SDK, secret key in
  `bta_stripe_secret`. On return the session is fetched from Stripe and recorded once (unique
  `stripe_session`). No webhook yet, so a payer who closes the tab before the return page loads
  isn't marked paid. Stripe shows it and the shop records it by hand.
- Not built yet: pulling store orders in automatically (Dillon: separate conversation), mockups.

## Sign-in diagnostics

0.6.1 and 0.7.0 exist because a failed sign-in gave no usable information. The plugin now
tells people why a sign-in failed and there is a diagnostics page. If you touch `auth.php`,
preserve that: a bare "login failed" was the actual bug being fixed, not a nicety.

## Pricing

`includes/pricing.php` covers this plugin's account pricing. Be aware of the open
`PRINT_TIERS` inversion documented in the **bt-quote** repo: two stitched curves make 204
shirts cost $171 more than 192, with the same seam at 96/108 and 288/300. If account
pricing derives from or mirrors those tiers, it inherits the problem.

That fix needs Dillon's real print costs and target margin. It is a business decision, not
a code cleanup. Don't adjust the numbers on your own initiative.

## Working notes

- Compact, always. Text sizing errs UP: table body 15px or larger, badges 13px or larger.
- Terse and results-first. Ship the deliverable, not narration.
- No bare `bt-` ids or classes. Shared ids across BT plugins have caused a real bug before:
  a duplicate `btModalOverlay` between BT Portal and BT Quote made clicking a job card open
  the wrong plugin's hidden overlay. Prefix everything `bta-`.
- Changelog entries in `manifest.json` are read by shop staff and by the account customer,
  not by developers. Match the existing plain-language voice.
