# BT Accounts

Contract account portal for Boomer T's. Each account (Cintas first) gets a branded
sign-in at `/accounts`, its own pricing profile, order entry, and live order status
pulled from the shop's job cards.

## Status — Phase 1 of 4

| Phase | Scope | State |
|---|---|---|
| 1 | Plugin, schema, auth, admin screens, `/accounts` login + portal shell | **done** |
| 2 | Portal navigation and order list view | next |
| 3 | Account quoter | needs Cintas rates |
| 4 | Order entry + job card link | needs order form sign-off |

## Design notes

**Portal users are not WordPress users.** They live in `wp_bta_users`, are created
only from the BT Accounts admin screen, and can only ever reach `/accounts`. They
hold no WordPress capability and cannot see wp-admin. Passwords go through
`wp_hash_password()`; plaintext is never stored.

**Pricing does not fork BT Quote.** `btq_price()` ends with
`apply_filters('btq_pricing_tables', $t)`, so an account's rates are applied by
filtering that table during a `bta_price_for_account()` call. Outside that call the
filter is a no-op, so the public Quick Quote and BT Catalog are untouched. An empty
profile means standard rates.

**The portal is a standalone document**, not a theme template. No theme markup, no
other plugin's CSS, no shared DOM ids — BT Quote and BT Portal have already collided
once over `#btModalOverlay`. Everything here is prefixed `bta_` / `.bta-`.

## Requires

BT Quote must be active for pricing. Everything else works without it.
