# Legacy System Reference Notes — admin.keroxshop.com & manage.keroxshop.com

**Status:** Reference only — informs UI/UX and workflow design, never data, scope, or supplier/reseller facts about this project.
**Compiled:** 2026-07-24, live read-only browser walkthrough. No sync/approve/delete/refresh-balance actions were taken. Two order-resolution modals (Resend Delivery, Create Voucher) were opened with explicit founder go-ahead, inspected, then cancelled without submitting. "Process Refund" was never opened.

## Read this file correctly

Everything below describes **KeroxShop's own production system** — its suppliers (MooGold, BarbarTopup), its live resellers (several, with real custom domains and order volume — one exceeds 12,000 orders), its order/customer counts. **None of this is a fact about `kedairuncitsoloz`.** This project's supplier decision stays Gamevion ([ADR-001](./adr.md#adr-001-d1-payment-gateway--xendit)) and reseller scope stays Phase 2 ([ADR-003](./adr.md)) unless the founder explicitly revisits either in its own right — nothing observed here overrides an ADR on its own. This distinction was corrected mid-session after an earlier draft of this audit wrongly framed legacy facts as decisions our own project needed to make.

## What was reviewed

- **admin.keroxshop.com** (business Admin Panel): Dashboard, Games, Price Sync, Supplier, Resellers, Users, Orders (including two order detail pages — one failed, one delivered), Reports, Withdrawals, Vouchers, Customer Analytics, Reviews, Backups, Themes, Settings, Developer.
- **manage.keroxshop.com** (Supplier Middleware): Dashboard, Product Manager, Request Logs, and the full sidebar structure (Price List, Games, Validators, Price Sync, Validate Player, Orders, Settings, Developer, per-supplier API testers).

## Structural finding

`prd.md` §6 reads as though it was written directly from this reference — nearly every screen matches close to 1:1: DASH-2/3, GAME-1..12, SYNC-1..6, SUPP-1..4, ORD-1..7, RPT-1..3, WTH-1..5, VCH-1..6, ANL-1..4, REV-1..5, BAK-1..5, THM-1..4, MID-1..13, MUI-1..11, DEV-1..2 all have a direct counterpart in the legacy UI. No PRD scope changes are recommended from this review — it's confirmation the spec is grounded in a real, working system, not a gap list.

## Patterns worth reusing directly

- **Order resolution modals** (Resend Delivery, Create Voucher) — see below, the richest single workflow observed in either app.
- **Dashboard system-health row** — per-supplier balance and connection status shown inline on the dashboard itself, not tucked behind a separate Supplier page.
- **Request Logs filter set** — supplier, status, endpoint, date range, and payload/response text search all on one screen. The strongest single screen observed on either app.
- **Order detail "Pricing Details" card** — Cost Price → Base Price → Admin Profit → Reseller Markup → Transaction Fee → Final Price, one line each. A direct UI expression of our own Order pricing fields (ORD-9).
- **Validated player display** — a delivered order shows the resolved in-game nickname next to a confirmation mark, proving MID-8 validation actually ran before delivery.

## Order resolution flow (order `27801`, failed, and `27789`, delivered — opened, not submitted)

- **Resend Delivery modal:** shows the original package next to the current matching package with a live price diff, a package selector constrained to a ±20% price range (guards against silently substituting a differently-priced package), an editable Character ID for typo correction, an optional note, and a collapsed "Raw Payloads" advanced section. The price-diff guard in particular is a real safeguard worth reusing closely for our own retry-delivery action.
- **Create Voucher modal:** pre-fills a suggested refund amount (customer paid − transaction fee, so the platform doesn't eat the gateway fee twice), a pre-filled reason, and locks the customer email/phone from the order so the voucher can't be misdirected. Strong reference for VCH-3.
- **"Process Refund" button also exists** — a third resolution option (a real cash refund) alongside Resend Delivery and Create Voucher. **Not opened, and not to be replicated:** it directly contradicts [ADR-004](./adr.md) (no cash refunds, ever). Its existence in the legacy system is exactly *why* ours deliberately has only two resolution paths, not three — worth remembering if anyone later asks "why does the reference system have three options and ours only two."

## Merge opportunities (confirms the existing PRD/ADR design is correct)

The legacy system is two separately-deployed apps (`admin.keroxshop.com`, `manage.keroxshop.com`) that independently grew near-duplicate Games / Price Sync / Orders / supplier-tester screens — a classic two-teams-drift-apart outcome. `prd.md` §6.20's design (one Next.js app, an `/admin` route group and a `/middleware` route group, one shared Laravel API) already avoids this. The thing to actually *enforce* during build: one `games`/`packages` table and one Price Sync job backing both route groups — not two tables kept in sync by hand, which is presumably how the legacy system ended up duplicated in the first place.

## Harden findings (confirms existing ADRs/PRD decisions are worth actually building, not skipping)

- Game → Validation & Fields uses raw JSON textareas with no schema check before save — a typo silently breaks checkout for that game. Ours should validate structurally, or generate the JSON from structured fields.
- Only one admin account exists in the legacy system; no MFA toggle is visible anywhere in account settings; withdrawal approval reads as a single actor with no second-approver step. Confirms AUTH-7 (MFA) and WTH-5/VCH-6 (maker-checker) — already decided in this project's own ADRs — are real, worthwhile gaps to close, not speculative hardening.
- "Clear Database" (Middleware → Product Manager) sits at identical visual weight to the routine "Sync Products" buttons next to it. Destructive actions in our own admin panel should read as irreversible before they're clicked — different color weight, a typed confirmation for whole-catalog actions, not just a browser `confirm()`.

## Not reviewed / lower priority for a future pass

Settings → SEO, Backups detail beyond the list view, the Games edit modal's Content tab, Validators CRUD.

## Product Manager / Games / Price List — package-level pricing across suppliers (2026-07-25, read-only, no sync/add/remove actions taken)

Reviewed specifically to answer a founder question: when two suppliers offer "the same" package at different prices, how does the legacy system pick which one the customer sees?

- **Product Manager** (`manage.keroxshop.com/product-manager`) matches at the **Game level only** — 626 total products, 124 "Matched (Both Suppliers)", 377 MooGold-only, 125 BarbarTopup-only. Confirmed directly from `Game`'s own schema (`/api/supplier/games/list`): matching is stored as `moogold_product_id`/`moogold_product_name`/`barbar_product_id`/`barbar_product_name` — **dedicated columns for exactly two suppliers, hardcoded**, not a list. A third supplier would need a third pair of columns. This project's `Game.supplier_mappings` (JSON array) already avoids this ceiling — worth remembering as a concrete reason that design is right, not just theoretically nicer.
- **Games screen**, per-game detail, has two tabs: **"Available Packages"** (raw, side-by-side per-supplier lists with prices and an `Add` button each) and **"Catalog"** (admin-curated, starts empty, "Edit the final name as needed"). Confirmed via `/api/supplier/games/{id}/compare-packages`: this endpoint returns two flat per-supplier item lists with **no `matched_with`/`group_id` field linking individual packages across suppliers** — package-level matching is not automatic at all, only the Game-level matching above is.
- **Admin can (and does) activate multiple same-or-near-same-named packages simultaneously** — inspected Mobile Legends (Malaysia)'s real Catalog (102 active rows): MooGold's "13 + 1 Diamonds" (RM 0.94) and three BarbarTopup rows all named "14 Diamond ( 13 + 1 Bonus )" (RM 0.96, RM 0.96, RM 0.97) are all `active` at once, un-deduplicated, at the Catalog/admin layer.
- **The actual dedup happens at the storefront**, at query time: live-checked `keroxshop.com/game/mobile-legends-malaysia` and only **one** "14 Diamond ( 13 + 1 Bonus )" card is shown, RM 0.99 — despite 4 differently-priced active Catalog rows behind it. Mechanism (founder-confirmed, matches the evidence above): storefront groups active Catalog packages **by exact display name string** and shows only the cheapest. A package renamed even slightly (e.g. dropping "(13+1 Bonus)") would stop grouping with the others and appear as a second, separate card — this is a **name-string-match**, not an ID/relationship-based grouping, and is therefore fragile to typos/formatting drift. Confirms this is a legacy-proven pattern worth adopting the *behavior* of, but worth hardening the *mechanism* of (normalize the name before grouping) in this project's own build — see `docs/prd.md` §14's Price Sync Stage 3 note.

---

*This file is the durable record for the "legacy system analysis" cited in `prd.md`'s Related Documents table. If a future session reviews more of either legacy app, update this file directly — don't let findings live only in chat history or an external artifact link.*
