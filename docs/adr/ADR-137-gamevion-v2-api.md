# ADR-137: Gamevion v2 API — rewrite the adapter for `gamevion.cloud`, async delivery with a signed webhook and same-reference replay

> **Standing (2026-11-06):** Accepted (2026-11-05 addendum: decisions 13–24 after the grill, money code-trace and stress test; the PR 2 build addendum corrects decisions 17 and 19; the last addendum revisits decision 16: games picker, per-game failure, one sync job per supplier). It replaces the API contract of [ADR-040](./ADR-040-gamevion-sandbox-integration.md) (old `api.gamevion.com`, sandbox header, `telp`, `callback_url`) and, for Gamevion only, the `duplicate_reference` → NeedsReview routing of [ADR-026](./ADR-026-delivery-side-reconciliation-ord-10.md); decision 21 tightened by [ADR-138](./ADR-138-package-sale-lifecycle.md) (repoint refuses an inactive product or an open order). Build and live status: [`prd.md` §15](../prd.md). The text below is a dated record.

**Status:** Proposed, 2026-11-04. Decisions 1–4 were made with the founder (in Malay) on 2026-11-04. Decisions 5–12 are Claude's draft from the vendor's API document and a first code-trace; they go through the technical grill and the money code-trace (AGENTS.md lifecycle step 2) before Accepted. The open questions at the end are settled in that grill.

## Context

- Gamevion moved to a new system. Its API document, "Gamevion Dokumentasi API" v62.25 (generated 2026-10-25, held by the founder, not in the repo), describes a different API at `https://gamevion.cloud`. The founder confirmed on 2026-11-04 that the old API no longer takes orders: an order sent there fails.
- Read-only on prod, 2026-11-04: in 14 days, 79 of 672 Price Sync runs were `partial`, and every one was Gamevion failing to connect to `api.gamevion.com` (cURL 7, connect timeout, SSL timeout). On 2026-11-04 (UTC) it was 19 of 29 runs. Digiflazz never failed. Gamevion has 71 packages, none active, and no order has ever been routed to it, so no customer was affected. The founder switched the Gamevion supplier off in `/middleware` on 2026-11-04 (14:11 UTC), so Price Sync skips it.
- The founder keeps Gamevion as the backup supplier. Backup means an admin points a package at Gamevion by hand; there is no automatic failover.
- Today's `GamevionAdapter` treats Gamevion as synchronous: a create-order answer is final, there is no webhook, and any failure is ambiguous (`resendUnsafeWithSameReference`, never confirmed), so every Gamevion failure goes to NeedsReview.

What the new API offers (from the document):

| | Old (`api.gamevion.com`) | New (`gamevion.cloud`) |
|---|---|---|
| Auth | Bearer token + `X-API-KEY` | One key, `Authorization: Bearer gvk_…`, optional IP allowlist |
| Sandbox | `X-ENVIRONMENT: sandbox` | None documented |
| Catalog | One `POST /api/product` | `GET /api/games` (code, `kind`), then `GET /api/pricelist/{code}` per game (`sku`, `price` MYR, `available`); pricelist limited to 40/min, refreshed every 15 s |
| Player fields | `data: id\|server`, `telp` | `GET /api/games/{code}/fields`: `userid`, `serverid`, `charname` as required per game |
| Order | `product_code`, `referenceNumber`; answer is final | `POST /api/order` with `game`, `denom` (sku), player fields, `client_ref` (≤ 64, `[A-Za-z0-9_.:-]`), optional `quantity` 1–5 and `max_price`; answer is usually `pending` |
| Final status | none | `pending` → `successful` / `error` / `cancelled` / `refunded`; webhook `order.updated`, or `GET /api/orders/{order_id or client_ref}` |
| Idempotency | 409 with no status | Same `client_ref` again returns the original order with `duplicate: true`, never a second charge; a concurrent repeat gets 409 `request_in_progress`; `GET /api/orders/{client_ref}` 404 means it was never created |
| Webhook | none | `X-Gamevion-Signature: v1=` HMAC-SHA256(secret, timestamp + "." + raw body), reject if the timestamp is over 5 min old; reply 2xx within 8 s; retried 3 times (5 s, 30 s, 5 min); may arrive more than once |
| Errors | undocumented | JSON `code`: `invalid_request` 400, `unauthorized` 401, `insufficient_balance` 402, `forbidden` 403, `not_found` 404, `price_changed` / `duplicate_order` / `request_in_progress` 409, `invalid_player_id` 422 (no charge), `rate_limited` 429, `service_unavailable` 5xx |
| Validate | none | `POST /api/validate` (player ID + nickname) |

## Decision

Founder decisions (2026-11-04):

1. **Rewrite the adapter for the new API; keep Gamevion as the backup supplier.** Build after PRD §16 item 28, whose trigger falls on 2026-11-05.
2. **v1 covers `kind = gametopup` only.** `vialogin`, `manual` and `giftcard` are left out until a need shows.
3. **No `max_price` in v1**, the same as Digiflazz today (`DigiflazzAdapter` sends none). Margin is protected the way it is for Digiflazz: Price Sync every 30 min, the drift guard, and the real cost recorded at delivery (ADR-111/134). Add it later if a real order shows a price jump.
4. **No top-up to test for now.** Build and verify without spending (decision 11). Every Gamevion package stays inactive until one real test order has been delivered, after the founder funds a small amount.

Draft for the grill (Claude, 2026-11-04):

5. **Same driver, same supplier row.** Rewrite `GamevionAdapter` in place under driver `gamevion` (supplier #1). The old API is dead and no order ever used it, so there is nothing to keep. `api_config` becomes `base_url`, `api_key` (secret), `webhook_secret` (secret); `bearer_token` and `sandbox` go. The `SupplierAdapter` interface does not change.
6. **Product reference is `{game}:{sku}`** (for example `mlbb_sgmy:86`), because a sku is unique only inside its game. `listProducts()` reads `/api/games`, keeps `gametopup`, then each game's pricelist, paced under the 40/min limit. `available: false` maps to an inactive status. The 187 old catalog rows and 71 packages are relinked by an admin in the Product Manager.
7. **Async, like Digiflazz.** `createOrder()` maps `pending` to `SupplierResponse::pending()`, `successful` to success, and `error` / `cancelled` to a confirmed failure (`outcomeConfirmedFailed`, Gamevion has returned the balance). The final status arrives by webhook, with the existing poll as backstop.
8. **`checkStatus()` re-submits the same order with the same `client_ref`**, exactly as `DigiflazzAdapter::checkStatus()` re-submits with the same `ref_id`. A duplicate answers with the stored order and its current status; a missing order is created once. So `gamevion` joins `SupplierAdapterFactory::RESUBMIT_REPLAYS_OUTCOME`, and an ambiguous outcome (timeout, 5xx, `request_in_progress`) goes to Pending with a same-reference poll instead of NeedsReview (ADR-102 addendum). `client_ref` is our `reference_number` (`REF-` + ULID, 30 characters, valid), or a combo leg's own reference.
9. **A webhook endpoint, `POST /api/webhooks/gamevion`**, mirroring `DigiflazzWebhookController`: verify the signature on the raw body with `hash_equals`, reject a timestamp over 5 minutes old, find the order or combo leg by `client_ref`, check it belongs to the Gamevion supplier, and finalize through `OrderFulfillmentService::finalizePendingDelivery()` / `finalizePendingDeliveryLeg()`, which already tolerate a repeat. Reply 2xx fast; no supplier call on the request thread.
10. **`refunded` after `successful` never auto-fails a delivered order.** It is logged and raised to an admin, because the customer may already have the credit.
11. **Verified without money:** the free endpoints (`/api/balance`, `/api/games`, `/api/pricelist`, `/api/games/{code}/fields`, `/api/validate`) through the real adapter, a webhook signed with our own secret against our endpoint, and an order sent at RM0 balance that must answer 402 `insufficient_balance` (no charge). The first successful delivery, its webhook and its real price are proven only by the funded test order of decision 4.
12. **Error mapping:** `invalid_player_id` 422, `not_found` 404 and `invalid_request` 400 are confirmed failures (no order made, no charge). `insufficient_balance` 402 is a confirmed failure too, but `price_changed` cannot occur without `max_price`. `unauthorized` / `forbidden` trip the circuit breaker like any auth failure. 429 and 5xx are transient.

## Rationale

- The new API is the stronger contract: idempotent `client_ref` replay with a status, a documented error catalogue and a signed webhook let Gamevion run through the same proven async seams as Digiflazz, and an ambiguous Gamevion order stops needing a human (today every failure is NeedsReview).
- Rewriting behind `SupplierAdapter` keeps the seam (AGENTS.md: extend an implementation, not the interface); fulfillment, ledger and pricing code learn nothing new about Gamevion.
- `gametopup` only and no `max_price` keep v1 to what the platform already sells.

## Consequence to track

- **Money code-trace before Accepted** (AGENTS.md step 2): every writer and reader of `supplier_ref`, `reference_number`, real cost (`OrderFulfillmentService`, ADR-111, ADR-134's `LandedCostRate` face value for an MYR supplier), the resend and combo paths (ADR-105, ADR-107), and every `slug = 'digiflazz'` scope in the reconcile sweep and webhook that the replay decision must widen or deliberately keep.
- **Open questions for the grill:** does a `client_ref` stay unique beyond the 31 days `GET /api/orders` covers; does Gamevion's balance check come before field validation (decides what the 402 test proves); games whose `/fields` require `charname` (our order has no such field: exclude them in v1?); which outbound IP Gamevion should allow (the box, or `SUPPLIER_PROXY_URL` when the proxy is on); `insufficient_balance` as Failed or retriable after a top-up (Digiflazz's ADR-098 table has the same case); the pacing of a full catalog sync under 40/min.
- **Founder steps at build time:** generate a `gvk_` key, set the IP allowlist, set the webhook URL and keep the secret, then enter the key and secret in `/middleware` → Suppliers and switch Gamevion back on.
- ADR-115's single-supplier scope stays: Gamevion is still unfunded.
- `GamevionSmokeTest`, `SandboxOrderController` and the Developer Tools read the old config shape; they change with the adapter.

### 2026-11-05 addendum — Accepted: grill, money code-trace and stress test

**Context.** The technical grill (three rounds), the money code-trace and a stress test ran on 2026-11-05 against the vendor document (v62.25, read in full) and the code. The founder accepted every recommendation (in Malay). Read-only prod facts, 2026-11-05:
- Gamevion (supplier #1, MYR, off) has 71 packages, all inactive (`supplier_issue`), in five games that also sell through Digiflazz: Free Fire 11, PUBG 6, HOK 10, Valorant 6, MLBB 38. It has 187 catalog rows, 71 of them promoted.
- No order, combo leg, combo component, transfer or `duplicate_reference` row has ever touched Gamevion.
- No order on any supplier is Processing, Pending or NeedsReview.
- Prod sends supplier calls from the box IP `159.223.39.16` (no `SUPPLIER_PROXY_URL`).

**Facts from the document that settle the open questions.**
- The order response, `GET /api/orders/{id}` and the `order.updated` webhook all carry `price` (MYR, per unit). So the drawdown (`recordOrderDrawdown`) and the real cost (ADR-111) record as for Digiflazz. An MYR supplier with no transfer prices at face value (`LandedCostRate`), so no FX code is needed.
- The document's own timeout rule is "GET `/api/orders/{client_ref}`: 404 means it was never created and is safe to send again". A `pending` order under Gamevion admin review must not be re-sent.
- `error`, `cancelled` and `refunded` return the balance. Product codes are stable ("Kod produk kekal").
- Only `/api/pricelist` is limited (40 a minute); `/fields` and the rest allow 3,000 a minute.
- How long Gamevion remembers a `client_ref` for de-duplication is not stated. The order list covers 31 days.

**Decisions.** Decisions 1–12 stand, except where these revise them.

13. **`checkStatus()` reads first** (revises decision 8). It calls `GET /api/orders/{client_ref}`. A found order maps its `order_status`. A 404 sends `POST /api/order` once with the same `client_ref`; a customer who paid gets the delivery. `createOrder(resubmit: true)` does the same. Because the POST runs only after a 404, an error on it answers for the order. A concurrent repeat (409 `request_in_progress`), a timeout or a 5xx stays unknown: Pending, polled with the same reference. `gamevion` joins `SupplierAdapterFactory::RESUBMIT_REPLAYS_OUTCOME`.
14. **Status mapping:**
    - `successful` → delivered.
    - `pending` → Pending.
    - `error`, `cancelled`, or `refunded` while our order is not delivered → confirmed failure (Gamevion returned the balance).
    - `refunded` after delivery → no state change; an email to every active admin (`BackupFailureAlerter`, area "Supplier refund") and a log line. The funding ledger is not written; the admin records an adjustment by hand (no `REFUND` writer exists, and a new money writer needs its own grill).
15. **Error mapping** (decision 12, confirmed). `insufficient_balance` 402 on a new order is a confirmed failure, the same as Digiflazz rc 44.
16. **Catalog:**
    - `listProducts()` keeps `kind = gametopup`.
    - It drops a game whose `/fields` requires anything other than `userid` and `serverid` (checkout has no `charname`).
    - It paces pricelist calls under 40 a minute. The `price-sync` worker's 300 s timeout bounds this at about 150 games. The real count is read once when the key exists. If it is over, a games allowlist in `api_config` is added then, not before.
    - Product reference `{game}:{sku}` (decision 6).
17. **Poll age 30 days** for Gamevion (`api_config.max_reconcile_age_days`), inside its 31-day lookup. Digiflazz keeps 90.
18. **Webhook IP allowlist** on the `gvk_` key: `159.223.39.16`. If `SUPPLIER_PROXY_URL` is ever switched on, the allowlist changes with it.
19. **Dead code goes.** Only the old `GamevionAdapter` ever produced `duplicate_reference`, and prod holds no row with it. Removed with the old adapter:
    - `Order::deliveryRetryUnsafeWithSameReference()`'s `duplicate_reference` branch;
    - `ReconcilePendingDeliveriesCommand::flagStaleDuplicateReferences()`;
    - the Fake adapter's option;
    - the docblocks that describe the old Gamevion.

    `GamevionSmokeTest`, the sandbox controller and Developer Tools move to the new config: `base_url`, `api_key` (secret) and `webhook_secret` (secret).
20. **Supplier cutover guard.** Saving a supplier's `api_config` with a changed `base_url` is refused while that supplier has an order or combo leg in Processing, Pending or NeedsReview. A poll in the new protocol would ask about a reference the new system never saw; with decision 13 that is a second purchase. A protocol change at the same URL is covered by the runbook in the Consequence below.
21. **Repoint a package to a new supplier product** (the general answer when a supplier changes system). Product Manager gets "Link to existing package" beside Promote. It applies when:
    - the supplier is the same;
    - the product is linked to the package's game;
    - the package is not a combo and not manual;
    - the product is not already promoted;
    - the package has no order in Processing, Pending or NeedsReview.

    The package keeps its id, order history, markup, name, catalog code and combo memberships.
    - Cost is set from the new product's price through `PackageCostService::apply()` (a `PriceChangeLog` row with the admin). It is the same trust as Promote: the admin sees the price before confirming.
    - Any open `PendingPriceChange` on the package is closed, since it priced the old reference.
    - The package stays inactive.
    - A new table `package_ref_changes` records the package, old reference, new reference, admin and time.
    - Old orders keep their frozen `supplier_product_ref`; a resend uses the package's current reference.
    - The old promoted catalog row loses its protection and is pruned by the next sync (`pruneVanishedRows`), so no clean-up code is needed.
    - A package with no counterpart is deleted with the existing delete, which refuses one with orders or a combo.
22. **The 71 Gamevion packages.** After the first sync, Claude proposes a match list (old reference → new sku, by name and denomination). The founder repoints the packages wanted as backup and deletes the rest. No bulk path.
23. **The funded test** (decision 4): activate one cheap package and place a real storefront order. That proves the order, webhook, drawdown and real cost end to end before more packages are switched on.
24. **Two money gaps outside Gamevion, found by tracing decision 21, are fixed first** (one PR, before the adapter). Both are recorded where they belong:
    - package activation through one seam with live blockers ([ADR-015](./ADR-015-price-sync.md) 2026-11-05 addendum);
    - the same-reference re-submit age limit on every path ([ADR-102](./ADR-102-order-delivery-failure-guard-unification.md) 2026-11-05 addendum).

**Rationale (stress test).** The first draft of three points was a patch:
- decision 24's gaps were going to be fixed at two of nine activation sites;
- the age limit at two of three re-submit paths;
- the cutover drain as a runbook step only.

Each now sits on the seam every caller passes through, with an invariant test. The repoint keeps a package's identity separate from the supplier's reference, which is the real cause of the clutter a supplier's system change leaves behind.

**Consequence to track.**
- **Supplier system change runbook:**
  1. Switch the supplier off.
  2. Settle every non-terminal order on it (decision 20 enforces this for a URL change).
  3. Release the new adapter.
  4. Enter the new config.
  5. Sync.
  6. Repoint or delete the old packages.
  7. Run one funded test order.
  8. Switch packages on.
- **Founder steps:** generate the `gvk_` key; set the IP allowlist; set the webhook URL `https://api.pekangame.space/api/webhooks/gamevion` and keep the secret; enter `base_url`, `api_key` and `webhook_secret` in `/middleware` → Suppliers; switch Gamevion on.
- Revisit decision 16 when the real game count is known; revisit 17 if Gamevion states its de-duplication window.

### 2026-11-05 addendum — PR 2 build: two decisions corrected by the code-trace

**Context.** Building PR 2 (the adapter, the webhook, the cutover guard and the dead code) traced decisions 17 and 19 against the code. Both changed slightly.

**Decisions.**
1. **Decision 17's 30 days is a protocol constant, not an `api_config` value** (revises decision 17). `Supplier::SAME_REFERENCE_WINDOW_DAYS = ['gamevion' => 30]` feeds `pendingPolicy()`, which every same-reference check reads (`Order::sameReferenceWithinWindow()`, ADR-102 2026-11-05 addendum). As a setting, a blank field would silently fall back to Digiflazz's 90 days and reopen the double purchase. `max_reconcile_age_days` in `api_config` still overrides it, as for any supplier.
2. **Decision 19 removed less than it listed** (revises decision 19).
   - **Removed:** `ReconcilePendingDeliveriesCommand::flagStaleDuplicateReferences()`, a one-time catch-up for rows before August that prod never held.
   - **Kept:** `duplicate_reference` in `FakeSupplierAdapter` and in `Order::deliveryRetryUnsafeWithSameReference()`. They are not Gamevion's: the admin sandbox uses the code to simulate an ambiguous outcome (NeedsReview). Only their comments changed.
   - **Moved:** Developer Tools can no longer live-fire `createOrder` at Gamevion, because v2 has no sandbox mode (`SupplierConfigSchema::isSandbox()` is null). The funded test of decision 23 goes through the storefront.

### 2026-11-05 addendum — decision 16 revisited: the games picker, per-game failure, one sync job per supplier

**Context.** The first sync after release #705 (2026-11-05 11:47) failed: `GET /api/games/ace_racer_id/fields` answered 503 `service_unavailable` four times, and decision 16 fails the whole catalog on any call. A read-only check on prod found:
- **430 `gametopup` games** of 1,344. Decision 16's ceiling (about 150 games under the `price-sync` worker's 300 s) is far exceeded, so its allowlist is now due.
- **Some games are down at Gamevion for long periods.** Ace Racer, Valorant (generic) and Blood Strike Pass answered 503 on both `/fields` and `/pricelist`, again on a later retry.
- **One price-sync job runs every supplier in turn** under that one 300 s limit. Gamevion is supplier id 1, so it runs before Digiflazz. Digiflazz takes 25–79 s a run (7 days of runs). A Gamevion run of about 75 games at the 40-a-minute pricelist pace takes about 2.5–3 minutes. Together they come close to the limit. A timeout would cut Digiflazz, the live supplier, mid-run.
- **The request log would grow about 7,300 rows a day.** That is about 150 logged calls a sync × 48 syncs, about 550 MB at the 30-day retention. The table is 5.4 MB today.

**Decisions (with the founder, in Malay, 2026-11-05).**
1. **Games to sync are chosen in a picker** (revises decision 16's allowlist).
   - `api_config.games` is a `list` of Gamevion game codes, an optional `SupplierConfigSchema` key, so the merge, the coercion and the visible config work as for `category_whitelist`.
   - `/middleware` → Suppliers → Gamevion → Edit loads `GET /api/games` once (one call; that endpoint allows 3,000 a minute). The form shows the `gametopup` games grouped by Gamevion's `family`, with a select-all per family. An unticked game in a family that has a ticked one is labelled "New".
   - The adapter fetches only the chosen games. An empty list fails the sync with a message that says where to choose them.
   - **Codes, not family names.** Gamevion's family names are inconsistent ("Free Fire" and "Freefire"), and a family rule would let the sync time grow unseen. A new region is ticked by hand when the picker shows it.
   - The first list is the families PekanGame already sells (Digiflazz's included), 74 codes. It is seeded once by an idempotent migration, only when `games` is absent. Left out:
     - `call_of_duty_mobile_my`: Gamevion requires no player field, so an order would carry no player;
     - `arena_breakout_infinite`: the PC game; we sell the mobile one;
     - `valorant` and `blood_strike_pass`: down at Gamevion that day; ticked in the picker once they answer.
2. **A game that fails is skipped, not the whole catalog** (revises decision 16's "any failed call fails the whole sync").
   - A game whose `/fields` or `/pricelist` call still fails after the shared retry is left out of this run's catalog.
   - Its packages go off through the existing vanished-product path (`PackagePriceSyncService`, reason `supplier_sync`). They come back through Pending Reactivation: auto-approve is on in prod, after 6 consecutive active syncs (ADR-100). Treating a catalog Gamevion cannot answer as unavailable is the conservative choice: an order for it would fail too.
   - **The whole run still fails** when:
     - `/api/games` fails;
     - any call answers 401 or 403 (a bad key must never switch every package off);
     - more than a quarter of the chosen games fail in one run (Gamevion itself is down).
   - A skipped game is logged as a warning, and its failed calls stay in the request log.
3. **One sync job per supplier.**
   - `SyncSupplierPricesJob` keeps its role and its `PriceSyncRun`. It now dispatches one `SyncSupplierCatalogJob` per syncable supplier as a `Bus::batch` on the same `price-sync` queue. Each job has its own 300 s, and the existing single worker runs them one after another.
   - Each job merges its counts into the run's `stats` under a row lock. A job that fails or times out records itself in `failed_suppliers` from its `failed()` hook.
   - The batch's `finally` sets the run's status by the existing rule: success, partial, or failed when no supplier synced.
   - The Price Sync Center, the stats endpoint, the broadcast and the staleness check read the run unchanged.
4. **Only failed per-game catalog calls are logged** (refines ADR-051 decision 5 for Gamevion). The `/api/games` call is logged every run. The per-game `/fields` and `/pricelist` calls are logged only when they fail. That is about 48 rows a day plus failures.

**Rationale.** Each problem is fixed where every case passes through:
- the batch gives every supplier its own time budget, whatever the order;
- the per-game rule reuses the deactivation and reactivation paths every supplier already has;
- the picker writes the same `api_config` list shape the form already handles.

No new table, worker or supervisor.

**Consequence to track.**
- Ceiling: about 130 chosen games fit one 300 s job (about 2.2 s a game). Near 120, split the Gamevion catalog over more than one job.
- If a skipped game's packages flap often, revisit decision 2 with a grace period before they go off.

### 2026-11-06 addendum — repoint guards

[ADR-138](./ADR-138-package-sale-lifecycle.md) decisions 18–19 add to decision 21: repoint refuses a product that isn't active, and refuses while an order on the package is paid or awaiting payment and not yet delivered. Decision 4 is unchanged and isn't enforced by code, so Promote before the funded test stays the founder's rule.
