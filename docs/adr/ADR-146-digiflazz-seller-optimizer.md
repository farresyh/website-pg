# ADR-146: Digiflazz seller optimizer — the backend picks each SKU's seller in the Digiflazz buyer panel, cheapest first, on a schedule

> **Standing (2026-11-21):** In force as a proposal only (Status: Proposed). Not decided for build until the grill and the money code trace below pass. Would extend [ADR-030](./ADR-030-digiflazz-supplier-integration.md) and [ADR-067](./ADR-067-digiflazz-adapter-completion.md) (the Digiflazz integration) and [ADR-138](./ADR-138-package-sale-lifecycle.md) (price swing holds).

**Status:** Proposed, 2026-11-21. PRD §16 item 200. Discussed with the founder in Malay on 2026-11-21. Decisions 1–5 and 7 are the founder's. Decisions 6 and 8–14 are Claude's draft and are open for the grill.

## Context

**What the founder does today.** In the Digiflazz buyer panel (`member.digiflazz.com/buyer-area/product`), each of our SKUs (`buyer_sku_code`, our `packages.supplier_package_ref`) is bound to one Digiflazz seller. Most denominations come as a pair, `…_PG1` and `…_PG2`, and Digiflazz refuses the same seller on both codes of a pair. The founder picks sellers with a Playwright script driving Chrome over CDP on a Windows PC (`digiflazz-seller-optimizer`, outside the repo). It sets PG1 to the cheapest seller and PG2 to the second cheapest, with distinct names. When both current sellers are already the two cheapest, it leaves them in place, even inverted. It skips sellers rated 1.0. It confirms row by row at 1.5 s per row, against the panel's 429 limit. It handles the "Produk dan Seller sudah ada sebelumnya" conflict with a second pass. It never touches PUBG Mobile. The PC has to be on, so it runs only when the founder is there.

**How our system uses PG1 and PG2 (prod, 2026-11-21).** They are separate packages: 776 `…PG1` and 722 `…PG2` Digiflazz packages, 612 and 602 active. `Package::cheapestInGroup()` shows customers the cheaper active package per denomination (or `catalog_code`), by `cost_price`. There is no automatic PG1→PG2 failover. PG2 sells when it is cheaper (132 of 693 Digiflazz orders in the last 30 days), or when an admin picks it on a resend. So "keep both if both are in the top 2, even inverted" matches how we already sell.

**What the panel is (read from its public JS bundle, `js/chunks/41.*.js`, 2026-11-21).**
- A Laravel app. It authenticates with the session cookie plus `X-CSRF-TOKEN` from the page's `<meta name="csrf-token">`. Login asks for a Google Authenticator code. The founder reports a sliding 48-hour session.
- Product list: `GET /api/v1/buyer/product/category/{id}/page?brand_id=…&type_id=…`.
- One SKU's sellers: `GET /api/v1/buyer/product/seller/{id}`. It returns what the "Pilih Seller" dialog shows: price, Nilai (rating out of 5) and rating count, stock or unlimited stock, multi, `start_cut_off` / `end_cut_off`, CS hours, SLA, the seller SKU's status and its description.
- Save: `POST /api/v1/buyer/product/update/multiple` with `{products: [...]}`. Each item carries the chosen seller's `seller_sku_id`, `seller_sku_id_int`, `seller_sku_code`, `seller_connection_type`, `price`, `stock`, `unlimited_stock`, `start_cut_off`, `end_cut_off`, `faktur`, `multi` and more. The per-row ✓ and "Simpan Semua Perubahan" both call it, so one call can save many SKUs.
- The official buyer API (`api.digiflazz.com/v1`, ADR-030) has no call that changes a seller. Its price list only reports the seller already chosen.

**What it touches in money.** A seller change changes what Digiflazz charges us at once. Our `packages.cost_price` follows only at the next price sync, hourly (`PRICE_SYNC_INTERVAL_MINUTES`, default 60). The sync applies ADR-138's swing policy, with Digiflazz's values left at their defaults:
- A move of 30% or more (`price_drop_hold_pct`) on one package: a rise applies at once, a drop is held for 24 hours (`price_hold_hours`).
- More than 10 packages swinging in one sync (`mass_swing_limit`): the run is treated as bad data. Nothing applies, a rise included, and "prices moved together" is emailed.

An optimizer run that moves many SKUs at once would trip the mass limit. A rise caught in that hold sells below cost until an admin approves it.

## Decision

1. **Cheapest available seller first; reliability is recorded, not yet scored (founder).** The choice is by price. Sellers that can't serve are excluded:
   - the seller SKU's status is inactive;
   - stock is zero, unless unlimited;
   - we are inside its cut-off window;
   - Nilai is exactly 1.0, as the founder's script does.

   Every run stores a full snapshot of every seller offered for every SKU it reads (decision 9), so a later reliability score has history to learn from.
2. **A per-game policy, set in `/middleware` (founder).** **Auto** is the default: cheapest available. **Trusted list**: the cheapest available seller among the sellers the founder has marked as trusted for that game, because PUBG Mobile's cheap sellers often fail on its player IDs; an empty list behaves as Manual. **Manual**: the optimizer never touches the game.
3. **Scope: every active Digiflazz package in our catalog (founder).** A panel SKU is matched by `buyer_sku_code` = `packages.supplier_package_ref`. Panel SKUs we don't sell are not touched.
4. **When the current seller can't serve and the cheapest replacement costs more, switch (founder).** Staying means failed orders. The SKU's cost is brought up right away (decision 7), and the rise is listed in the run's summary email. A rise happens only in this case. Otherwise the optimizer moves a SKU only to a cheaper seller.
5. **Schedule: 06:00, 19:00 and 23:00 MYT (founder), editable in `/middleware`.** One scheduled task, `digiflazz-seller-optimizer`, routine tier, in `TaskSchedule` (ADR-122).
6. **The PG1/PG2 rule is the founder's script's rule.**
   - For a pair, take the two cheapest available sellers with distinct names. If both current sellers are already those two, in either order, leave the pair as it is.
   - Otherwise PG1 takes the cheapest and PG2 the second.
   - If only one distinct seller is available, PG1 takes it and PG2 is left as it is.
   - For a single SKU, take the cheapest.
   - Because the panel refuses a seller already held by the pair's other code, the save frees it first, in the order the script uses: move the holder away, then assign.
7. **Price sync knows the optimizer's own changes (ADR-138 extension).**
   - Each switch is recorded with the seller and the price it chose (decision 9).
   - When a later price sync sees a package's new price equal to the price recorded for a switch in the last 24 hours, the move is explained. It applies at once, up or down, and it does not count toward `mass_swing_limit`.
   - Unexplained swings keep ADR-138's rules unchanged.
   - Right after a run that switched anything, the optimizer starts a price sync, so the costs it changed land within minutes, not up to an hour later.
8. **Session: the founder pastes the panel session cookie into `/middleware`; the TOTP secret is never stored.** It is kept in the Digiflazz supplier's encrypted `api_config` (SUPP-5). Three runs a day keep the sliding 48-hour session alive. When a run finds the session expired, or the panel answers 401/419, the optimizer changes nothing and emails the admins to log in again and paste a new cookie. The founder logs in from a separate Chrome profile, so logging out of their own browser doesn't end the optimizer's session.
9. **Two new tables.**
   - `digiflazz_seller_offers`: one row per run × SKU × offered seller, holding price, Nilai, rating count, stock, unlimited, multi, cut-off, SLA and status. This is the snapshot, pruned after a set number of days.
   - `digiflazz_seller_switches`: one row per change, holding the run, the package, the from and to seller, the from and to price, the reason (`cheaper` / `current_unavailable`) and whether the panel accepted it. It is append-only, written only by the optimizer service.
10. **Dry run first.** A `/middleware` switch with three positions: Off, Dry run (read, decide, record, email what would change, save nothing), Live. It starts at Off. Live is turned on by the founder after comparing dry runs with the PC script.
11. **Stop on anything unexpected.** A response missing a field the decision needs, a seller list that isn't a list, an HTTP error other than 429, or a save answering anything but success stops the run. Every SKU not yet saved is left as it is, and an admin email names the step. A 429 backs off and retries a bounded number of times, as the script does. Calls are paced. The save sends a batch per game, not one call per row.
12. **Behind the `SupplierAdapter` seam, not inside it.** Seller choice is a panel action, not an order or catalog call. It lives in its own service (`App\Services\Supplier\Digiflazz\SellerOptimizer`) with its own small panel client. The supplier adapter interface is unchanged (AGENTS.md: extend an implementation behind a seam, don't reshape the seam).
13. **Runs on the `price-sync` queue lane,** never the order lanes. It is long and paced, and must never delay a money-touching order job (ADR-020 decision 5).
14. **Summary email after each run that changed something, or would have in Dry run.** It lists counts, every rise with its old and new price, every SKU skipped and why, and any stop. Sent through `BackupFailureAlerter`'s admin email path.

## Rationale

- **In the backend rather than a cloud box running Chrome.** The panel is a JSON API behind a Laravel session. Calling it directly needs no browser, no VNC and no second server, and the PC script's 1.5 s per row becomes one batch per game. It sits next to the data that makes it safer: the packages it maps to, the price sync it must coordinate with (decision 7), and our own order outcomes for the later reliability score.
- **Not storing the TOTP secret.** Storing it would turn the 2FA into a password. The sliding session plus three runs a day means a login is needed only after an outage longer than 48 hours.
- **The risk of the session is the risk we already carry.** The Digiflazz API key in the same encrypted `api_config` can already spend the deposit, about Rp 39.9 million on 2026-11-21.
- **Unofficial API.** Digiflazz can change the panel without notice. Decision 11 makes a change fail closed, with nothing saved. Decision 10 lets the founder see the decisions before any is saved.

## Open for the grill

- Decision 6 against the panel's real rules: does a save of a whole pair in one batch avoid the "sudah ada sebelumnya" conflict, or must the holder be saved first in a separate call?
- Decision 7: is "equal to the recorded price" too strict? Digiflazz may round, or the seller may move its price between the switch and the sync. What tolerance applies, and what about a price that moves again after the switch?
- Decision 1: is "inside the cut-off window" computed in WIB (Digiflazz's timezone)? What happens when the window crosses midnight?
- Combos: a combo's legs point at component packages. Does a seller change on a component need anything beyond the price sync? Check ADR-107 (combo residual) and ADR-111 (real cost).
- Does the optimizer need a lock against a manual change in the panel during a run, or against two runs overlapping? `withoutOverlapping` covers the second.
- What retention applies to `digiflazz_seller_offers`? It grows by about 1,200 SKUs × sellers × 3 runs a day.
- Terms: does Digiflazz allow automated use of the buyer panel? The PC script already does it, so the exposure is not new, but ask.

## Money code trace (to run before Accepted)

- List every writer and reader of `packages.cost_price` and `standard_selling_price` around a seller change: `PackagePriceSyncService`, `PackageCostService`, the ADR-138 hold, `PendingReactivationAutoApprover`, the combo price (`ComboPricingService`), the resend cost (ADR-105) and real cost (ADR-111).
- Check decision 4 per pricing basis (Standard, Member, Affiliate tier, Reseller wallet): a rise must reach every basis's selling price before an order is priced at the old cost.
- Check that an order in flight when a seller changes is priced and costed consistently: we never send a price to Digiflazz, and `real_cost_price_sen` records what was charged.

## Consequence to track

- A seller list or save shape change at Digiflazz stops the optimizer (decision 11). The founder falls back to the panel by hand until it is fixed.
- The snapshot table is the base for a later reliability score. Joined with our orders and `digiflazz_seller_switches`, it gives each seller's real failure rate on our traffic.
- If Digiflazz ever ships an official seller API or a built-in "cheapest seller" mode, this ADR is superseded by it.
