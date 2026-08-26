# BT Accounts

Contract-account portal for Boomer T's. Named business accounts (Cintas/Sasha is the first)
sign in, place orders against agreed pricing, and the shop works them from an order queue.
Order numbers are `CIN-####`.

- Current version: **0.7.0**. Constant `BTA_VERSION`, function prefix `bta_`.
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
(no PHP binary in the container, brace-audit by hand), build `bt-accounts-X.Y.Z.zip` plus
plain `bt-accounts.zip` at the repo root, update `manifest.json` with the version, the
**versioned** raw `download_url` and a changelog entry, commit and push to `main`. Dillon
then does **BT Accounts → Status & Updates → Check for updates now**, then
**Plugins → Update Now**.

`uploads.github.com` is blocked from the container, which is why releases use versioned raw
zips rather than GitHub Release assets. The updater reads `manifest.json` through
`api.github.com` with `Accept: application/vnd.github.raw`, so a push is live instantly.

`includes/bt-admin.php` is byte-identical across bt-portal, bt-catalog, bt-quote and
bt-accounts. Don't fork it; re-copy into all four in the same release round if it changes.

## Structure

`bt-accounts.php` main · `includes/schema.php` tables · `includes/accounts.php` the account
model · `includes/auth.php` sign-in, sessions, capability checks · `includes/orders.php`
the order model · `includes/admin.php` and `admin-orders.php` shop-side management ·
`includes/portal.php` the customer-facing portal shell · `portal-orders.php` order entry
and list · `portal-quote.php` the account quoter · `portal-print.php` printable work order ·
`includes/notify.php` order notification emails · `includes/pricing.php` ·
`includes/admin-diagnostics.php` · `assets/` order-form.js, quote.js, portal.css, print.css

This plugin has its **own auth system**, separate from BT Portal's `bt_portal_user` roles.
Portal roles are for shop staff; this is for outside contract customers. Don't merge them.

Print is the default decoration in the account quoter.

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
