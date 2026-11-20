# PekanGame — Build Log

> The running chronological record of what shipped, when, and why — every
> session, the bugs hit along the way, and the live-verification notes.
> Moved out of `prd.md` §14 on 2026-09-11 once it outgrew the spec doc.
>
> **This file is append-only history.** Current per-feature status lives in
> `prd.md` §15; the live backlog is `prd.md` §16; decision rationale is the
> ADR log (`adr.md` index → `adr/`). **Everything before 2026-11-05** lives verbatim in
> [`build-log-archive.md`](./build-log-archive.md): the foundation build, the
> old §14 table and PrimeReact tracker, and the production-era entries
> 2026-09-01 → 11-04 (moved in eleven passes, the last on 2026-11-20) to keep
> this file quick to read at session start.

---

## 2026-11-05 — Fix: Refresh on /admin/balance crashed the page

**Symptom (founder, prod).** Clicking Refresh on a supplier row at `/admin/balance` showed "This page couldn't load". A hard reload then showed the page, with the refreshed balance.

**Cause.** `POST /api/middleware/suppliers/{id}/refresh-balance` answered with the bare model (`$supplier->fresh()`). That answer has no `display_balance` and no `is_api_backed`. The page swaps the answer into its table, so `formatSupplierBalance(undefined)` threw on `toLocaleString`. The `/middleware/suppliers` page never showed the bug because it refetches the list after a refresh. Three more endpoints answered in the same bare shape: `status` (two branches), `store` and `update`.

**Fix.** `SupplierController::present()` is now the one shape for a supplier row. `index()` uses it, and so do all five single-supplier answers (`update` still adds `connection_probe`). The regression test `test_single_supplier_responses_match_the_index_row_shape` asserts that the refresh, status and update answers have exactly `index()`'s keys and never include `api_config`. Full backend suite green (3,632 tests).

## 2026-11-05 — Item 28: supplier buffer forecast and low-balance email (ADR-115 2026-11-05 addendum)

**Why now.** The 2026-10-14 trigger fell on 2026-11-05, two weeks after real payments. Real Digiflazz spend from 22 Oct to 4 Nov was IDR 9.57 million (about RM 2,289), and it came in bursts: 31 Oct and 1 Nov were IDR 7.58 million.

**Founder decisions (in Malay).**
- The buffer learns from sales.
- From Thursday it must last until Monday, because transfers do not land at the weekend.
- A check runs after every webhook.
- The email fires below the higher of the floor and the forecast.
- No recovery email.
- Malaysia calendar days.
- A margin setting, default 0%.

**The stress test changed the design before code:**
- **p90 → the 60-day maximum.** On the real data, p90 was IDR 0.77 million (RM 184), against a real two-day burst of IDR 7.58 million: 36 of the 59 two-day windows were empty.
- **Compute on read** instead of stored columns.
- **Lower of polled and ledger.** The polled balance moves only on a webhook, a poll or Refresh.
- **One seam per trigger.** `recordOrderDrawdown()` is shared by all five delivery paths. `Supplier::recordPolledBalance()` replaces five direct writers.
- **An atomic once-a-day claim**, the price-sync staleness pattern.
- **One assessment** replaces the Dashboard's and the daily command's separate threshold rules.

**Built.**
- `SupplierBufferService::assess()` and `SupplierBuffer`.
- `SupplierBufferAlert` (email through `BackupFailureAlerter`, area "Supplier balance"; one log line with each email).
- `CheckSupplierBufferJob`: unique per supplier, `default` lane.
- `GET /api/dashboard/supplier-buffers` (admin and super admin, like `/dashboard/health`).
- Supplier settings `topup_lead_days` and `buffer_margin_pct`; Low-balance Threshold is relabelled Low-balance Floor.
- `/admin/balance` restyled with the report kit (Campaigns layout): buffer cards, then the two tables as ReportTables. A Refresh now reloads the whole page's data, because the buffer moves with the balance.
- Migration `2026_11_07_120000_add_supplier_buffer_alert`: `suppliers.low_balance_alerted_at`, plus index `(supplier_id, type, created_at)` on `supplier_ledger_entries`.

**Tests.** 18 new tests in `SupplierBufferTest`:
- busiest window, with quiet days counted as zero;
- all six weekdays;
- lead time;
- the Malaysia-midnight boundary (Wednesday 23:59 against Thursday 00:00 MYT, and drawdowns either side of midnight);
- floor, margin and the lower-of balance;
- the email once a day, a reminder the next day, recovery that clears it, and a claim already taken;
- every trigger;
- the daily sweep;
- the Dashboard chip and the endpoint.

The guard tests (route list, `visible_config`) and the old daily-warning test were updated. Full suite: 3,649 tests green. Admin: tsc, lint, 22 unit tests and the build all pass.

**Browser check.** The founder's running dev servers served the branch (`localhost:3000`, API `:8000`).
- The real endpoint answered 200, with a 5-day window through Monday 9 Nov on Thursday 5 Nov.
- Both buffer states were rendered from prod-shaped fixtures intercepted in Playwright, so the dev DB was not written. They were checked in light, dark and 390 px, with no horizontal page overflow and no JS errors.
- Refresh no longer breaks the page.

**Gotcha.** `QueueFake` honours `ShouldBeUnique`: a second dispatch for the same supplier inside `uniqueFor` is dropped. That is intended, so the trigger test uses a second supplier for the drawdown path.

## 2026-11-05 — Released #699: supplier buffer forecast live

**What.**
- `staging` → `main` PR #699, merged on the founder's go-ahead as `d478522e`.
- It ships #695–#698: item 28, the supplier buffer forecast and low-balance email (ADR-115 2026-11-05 addendum); the `/admin/balance` Refresh fix (#697); two docs PRs.
- CI on the PR and on `main` was green on every job, including `backend-concurrency`, `playwright` and `deploy`.
- The box runs release `82151010` at `d478522`.

**Checked read-only after release.**
- Migration `2026_11_07_120000_add_supplier_buffer_alert` `Ran`. The column `suppliers.low_balance_alerted_at` and the index `sle_supplier_type_created_idx` exist.
- `/api/health` 200, Horizon running, and both WhatsApp sessions `ready`.
- `SupplierBufferService::assess()` on Digiflazz:
  - balance IDR 15,363,305 (RM 3,673.81 at the landed rate);
  - forecast IDR 8,716,246, from the busiest window, 30 Oct – 3 Nov;
  - required IDR 10,000,000 (the floor is higher than the forecast);
  - a 5-day window covering until Monday 9 Nov; not short.
- No supplier has an alert claimed.
- The manual suppliers assess to `null`, as designed.
- The four Vercel production deploys succeeded.

**Docs.** PRD §14, §15 (Supplier Management and Balance rows), §16 items 28 and 104 and the line on Error Handling now say released. Item 57's closed line still said "not released yet" after #694; it was corrected. §14 also records CHIP's 2026-11-05 reply on the additional payment methods.

## 2026-11-05 — Item 162 PR 1: package activation seam and the same-reference window

**Why.** Two money gaps found while tracing ADR-137 (Gamevion v2) decision 21, outside Gamevion itself. The stress test showed that the first fix, at two sites, was a patch; the founder accepted the root-cause fix (ADR-015 and ADR-102 2026-11-05 addenda).

**Gap A and B: package activation.**
- Checkout sells any package with `is_active`. Nine places switched a package on by writing `is_active = true, deactivated_reason = null` directly. Each cleared the one stored reason and checked nothing else.
- So a supplier's Reactivate brought back a package with an open price swing (sold at the old cost) or one its supplier no longer lists.
- Approving or dismissing a swing switched on a package that was off because its supplier was off, or because an admin turned it off.
- Prod, read-only, 2026-11-05: none of the 1,210 active API packages is affected.

**Built.**
- `App\Services\Catalog\PackageActivation`:
  - `activate($package, $clears)` and `blocker($package)`;
  - blockers are computed live: an open `PendingPriceChange`, supplier off, unfunded, not listed at the supplier's last sync, a combo component off;
  - a blocked package stays off with that blocker as its reason, so the path that owns the reason brings it back;
  - a caller clears only the reason it owns; `null` is an admin's own switch-on from `/games`.
- All nine writers route through it: Pending Reactivation approve and bulk, the auto-approver, Dismissed restore, swing approve and dismiss, the supplier's Reactivate (and a manual supplier switched on, and the unfunded sync), the `/games` toggle, the two combo cascades.
- The supplier's Reactivate now returns `skipped`, and the admin toast says how many stayed off.
- An invariant test fails CI if other code clears a package deactivation.

**The same-reference window.**
- `max_reconcile_age_days` was enforced by the poll and Check supplier / Confirm Failed, but not by Retry or Resend from NeedsReview, which re-submit the stored reference.
- `Order::sameReferenceWithinWindow()` is now the one rule:
  - `fulfill()` and the combo leg attempt refuse outside it;
  - Retry, Resend and Check supplier on a Pending order answer 422 with `Order::PAST_WINDOW_MESSAGE`.

**Tests.**
- 11 in `PackageActivationTest`.
- 5 added to `OrderConfirmFailedSupplierCheckTest`: Retry, Resend, `fulfill()`, a combo leg, and Check supplier past the window.
- Four tests that built `ComboPricingService` / `PendingReactivationAutoApprover` by hand now resolve them from the container.
- The auto-approver fixture's Digiflazz is MYR: an IDR supplier with no deposit is unfunded, which the seam rightly refuses.
- Full suite 3,666 green. Admin: `tsc` and `eslint` clean.

## 2026-11-05 — Item 162 PR 2: Gamevion v2 adapter, webhook and cutover guard (ADR-137)

**Built.**
- `GamevionAdapter`, rewritten for gamevion.cloud, behind the unchanged `SupplierAdapter` seam:
  - one `Authorization: Bearer gvk_…` key;
  - product reference `{game}:{sku}`;
  - `listProducts()` keeps `gametopup` games whose required `/fields` are `userid`/`serverid` only, paces pricelists 1.6 s apart (40/min limit), and fails whole on any failed call (a partial catalog would switch packages off);
  - `createOrder()` posts with our reference as `client_ref`; a re-submit and `checkStatus()` read `GET /api/orders/{client_ref}` first and post once only on a 404;
  - one mapping, `GamevionAdapter::responseFor()`: `successful`, `pending`, and `error`/`cancelled`/`refunded` as confirmed failures. No-order error codes (400/402/404/422, `price_changed`) are confirmed; 409 `request_in_progress`, 429, 5xx and 401/403 are unknown (401/403 and 5xx count against the breaker).
- `gamevion` joins `RESUBMIT_REPLAYS_OUTCOME`. Its same-reference window is a protocol constant of 30 days (`Supplier::SAME_REFERENCE_WINDOW_DAYS`), so a blank setting cannot fall back to 90.
- `POST /api/webhooks/gamevion` (`GamevionWebhookController`):
  - HMAC-SHA256 of `timestamp.raw body`, rejected past 5 minutes;
  - finalizes a plain order or a combo leg by `client_ref` through `finalizePendingDelivery[Leg]()`;
  - a `refunded` after delivery emails every admin (area "Supplier refund") and changes nothing;
  - `pending` or an unknown status is acknowledged and left to the poll.
- Cutover guard: saving a supplier's `api_config` with a new `base_url` is refused while it has an order or combo leg in Processing, Pending or NeedsReview. A key rotation at the same URL is allowed.
- Config: Gamevion's required keys are `base_url` and `api_key`; `webhook_secret` is optional; `bearer_token`/`sandbox` and the `GAMEVION_*` credential env defaults are gone. The admin form matches.
- `app:gamevion-smoke-test` uses the supplier row: balance, catalog, and with `--order` one order only at a zero balance (expects 402).
- `app:backfill-supplier-credentials` covers Digiflazz only.
- Dead code: `flagStaleDuplicateReferences()` removed. The sandbox's `duplicate_reference` convention stays (ADR-137 PR 2 build addendum).

**Money checks in tests.**
- A delivered Gamevion order records its drawdown and a real cost of RM4.20 = 420 sen at face value (MYR, no transfer).
- A repeated webhook writes one drawdown.
- An `error` writes none.

**Tests.**
- 20 in the new `GamevionAdapterTest`, 9 in `GamevionWebhookControllerTest`, plus the cutover guard and the 30-day window.
- Fixtures that used `gamevion` as a non-replaying or sandboxed supplier now use a generic `legacy-api` supplier or Digiflazz with `testing`, because Gamevion now replays and has no sandbox.
- Full suite 3,675 green. Admin `tsc` and `eslint` clean.
- Local curl: `POST /api/webhooks/gamevion` answers 503 against the dev DB, which has no `webhook_secret` (fail-safe).

**Gotcha.** A test with no `Queue::fake()` on a replay-safe supplier now also runs the recovery poll inline. A fixture supplier with no config then throws from the poll, not the order (`ResellerBotOrderTest`).

## 2026-11-05 — Item 162 PR 3: package repoint in Product Manager (ADR-137 decision 21)

**Why.** When a supplier changes system, its references change. Before this, a package could not be pointed at a new reference: Promote refuses an item that is already a package, and the package update never took `supplier_package_ref`. The only options were a second package beside a dead one, or delete and set up again.

**Built.**
- `POST /api/middleware/supplier-products/{item}/repoint` (`package_id`), super admin, with `RepointSupplierProductRequest`.
- Under a row lock it refuses when:
  - the item has no valid price;
  - the item is already in the catalog;
  - the package is from another supplier, a combo, or a manual supplier's;
  - the item's group is not linked to the package's game;
  - the package has an order or combo leg in Processing, Pending or NeedsReview.
- Otherwise it:
  - records `package_ref_changes` (old, new, admin);
  - closes any open price swing as `superseded`;
  - sets the new reference;
  - sets the cost from the new price through `PackageCostService::apply()` (a `PriceChangeLog` row with the admin; markup kept);
  - recomputes the combos that use the package.
- The package stays on or off as it was. Old orders keep their frozen reference; a resend uses the new one. The old catalog row is no longer protected, so the next sync prunes it.
- Migration `2026_11_08_120000_create_package_ref_changes_table`. On MySQL (docker test DB), `SHOW INDEX` shows a standalone index on `package_id` and `admin_user_id`.
- Admin: "Link to existing" beside "Add to Catalog" opens `RepointProductModal` (`components/ui` kit). It lists this game's packages from the same supplier and shows the current cost against the new price before confirming.

**Tests.** 7 in `SupplierProductRepointTest`. Full suite 3,682 green. Admin `tsc`, `eslint` and 22 unit tests pass.

**Browser check.** On the founder's running dev servers (`:3000`, API `:8000`):
- Product Manager → Arena Breakout (Gamevion, dev data) → Link to existing → pick a package → Link package.
- The POST was intercepted by Playwright, so the dev DB was not written. Its body was `{"package_id": 3}`.
- Checked in light, dark and 390 px: no horizontal overflow, no JS errors.
- The cost comparison showed a deliberately mismatched pick plainly (RM 3.25 → RM 165.24).

**Gotcha.** `php artisan migrate --env=testing` with no `.env.testing` runs against the dev DB. It ran this additive migration there, which lifecycle step 5 needs anyway.

## 2026-11-05 — Released #705: Gamevion v2 live; first sync failed; sync scope fix built (ADR-137 2026-11-05 addendum)

**Released.** #705 (`staging` → `main`, #700–#704) merged 11:28 UTC; the deploy job passed. Read-only checks on prod:
- `0344cf1` is live;
- migration `package_ref_changes` ran (batch 65);
- `/up` is 200;
- `POST /api/webhooks/gamevion` answers 503 "webhook not configured" until the secret is saved.

Gamevion had 71 packages (0 on) and 0 orders. The founder entered the `gvk_` key, Base URL and webhook secret and switched Gamevion on. The balance check passed (RM 0, VIP tier).

**Found.** The first sync (run 3287, 11:47) failed with `[service_unavailable]`. The request log shows ten games fetched cleanly, then `GET /api/games/ace_racer_id/fields` answering 503 on all four attempts. A later check from prod found:
- **Ace Racer, Valorant (generic) and Blood Strike Pass are down at Gamevion.** Both their `/fields` and `/pricelist` answer 503.
- **`/api/games` lists 1,344 games, 430 of them `gametopup`.** At the 40-a-minute pricelist pace that is about 12 minutes, over the 300 s `price-sync` limit (decision 16 put the ceiling near 150).
- **One job ran every supplier in turn under one 300 s limit.** Gamevion (id 1) runs before Digiflazz, which takes 25–79 s a run (7 days of runs).
- **The request log would grow about 7,300 rows a day.** Every catalog call is logged, which is about 550 MB at the 30-day retention. The table is 5.4 MB.

Every `partial` price-sync run in the last 7 days was Gamevion: the old API timing out on 1–4 Nov, then this. The 15 failed jobs (`cs-assistant`, 2026-11-03) were already fixed by `75570048`.

**Built** on `fix/gamevion-sync-scope` (ADR-137 2026-11-05 addendum):
- **Games picker.** `api_config.games` is an optional `list` key.
  - `GET /api/middleware/suppliers/gamevion/games` (super admin) returns `GamevionAdapter::listGames()`: one call, unwrapped, so it never counts against the order breaker.
  - `GamevionGamePicker` in the Edit modal groups the games by Gamevion's family, with a select-all per family, search, and "New" on an unticked game in a ticked family. Built on the ADR-104 tokens.
  - Migration `2026_11_09_120000_seed_gamevion_games_to_sync` writes the 74 agreed codes once, only while `games` is absent.
- **Per-game failure.** `listProducts()` fetches only the chosen games. A game whose `/fields` or `/pricelist` still fails after the retry is left out, with a warning. The whole sync fails on 401/403, a failed `/api/games`, or more than a quarter of the games failing. An empty list fails with "Choose them in Middleware → Suppliers".
- **One job per supplier.** `SyncSupplierPricesJob` dispatches one `SyncSupplierCatalogJob` per syncable supplier as a `Bus::batch` (`allowFailures`, same `price-sync` queue and worker).
  - Each job merges its counts into the run under a row lock (`PriceSyncRun::recordSupplierSynced` / `recordSupplierFailed`).
  - The batch's `finally` calls `finishBatch()`. It counts the suppliers that synced (`stats.synced_suppliers`), never the failures recorded so far, because a timed-out job's `failed()` may run after it.
- **Request log.** `SupplierRequestLogger::attach(..., failuresOnly: true)` for Gamevion's per-game catalog calls.
- **Batch rows.** `queue:prune-batches --hours=168` runs daily (`job-batch-pruning`, routine tier), so `job_batches` keeps a week (48 rows a day).

**Tests.**
- New: 9 adapter tests, 4 controller tests, 3 job tests, 3 migration tests.
- Changed: 15 job tests now go through `dispatchSync`, so the batch runs inline.
- Full suite: 3,701 green.
- Admin: `tsc`, `eslint` and 22 unit tests pass.

**Browser check** on the founder's dev servers (`:3000`, API `:8000`). The local proxy is on, so the picker endpoint was answered by Playwright with prod's real 430-game list; the endpoint itself is covered by the controller tests.
- Light and dark: 290 families, 74 ticked, "New" on Valorant and Blood Strike Pass.
- Ticking a family keeps it in place and doesn't toggle its `<details>`.
- Contrast: code text 5.3 / 5.8, the "New" tag 6.1 / 8.3.
- Save sent `api_config.games` with the codes.
- The error state shows "Gamevion did not return its games: …".

**Gotcha.** A checkbox inside `<summary>` doesn't toggle the `<details>` (the input is the activation target), so no `stopPropagation` was needed. Re-sorting families by live tick state made a ticked family jump under the cursor; the order now comes from the list the form opened with.

## 2026-11-05 — Deleting a game can no longer remove used packages (ADR-119 2026-11-05 addendum)

**Found** in the founder's stress test of catalog management (read-only on prod):
- `GameController::destroy()` was a plain `$game->delete()`.
- The schema then cascaded to every package of the game (`packages.game_id` CASCADE), past ADR-119's guard in `PackageController::destroy()`.
- It nulled the orders' `game_id` and `package_id` (SET NULL) and deleted price, deactivation and repoint history.
- Prod's foreign keys match the migrations. 10 of 40 games have orders (102 orders).
- Order #7 (a combo, 2026-09-15) already has no `package_id`: its package was deleted before the 2026-10-02 guard.

**Built** on `fix/game-delete-integrity`:
- Migration `2026_11_10_120000_restrict_deleting_used_games_and_packages`: `packages.game_id`, `orders.package_id` and `orders.game_id` → RESTRICT.
  - On sqlite, the llm_report_* views are dropped and re-created from their stored SQL around the table rebuild, as `2026_10_08_140000` does.
  - On MySQL (docker), the three rules read RESTRICT afterwards.
- `GameController::destroy()` refuses with "This game has order history and cannot be deleted. Deactivate it instead." when an order points at the game or one of its packages.
  - Otherwise, in one transaction it deletes combos, then the other packages, then the game. Product Manager groups are unlinked.
  - A racing order hits the restrict, rolls back, and gets the same 422.
- Three tests that deleted a used game or package on purpose now use the legacy null-link shape, or assert the refusal.

**Tests.** 4 new (3 controller, 1 database-level package delete). The 4 deletion tests also pass on MySQL. Full suite: 3,704 green.

**Browser check** (founder's dev servers, local DB): Arena Breakout (3 orders) → Edit Game → Delete Game answered 422 and showed the message in the modal. Its 4 packages are still there.

**Gotcha.** The MySQL test run first failed with `llm_report_orders already exists`: views were left in docker `kerox` by an earlier killed run (`backend/AGENTS.md` gotcha 8). Dropping them fixed it.

## 2026-11-05 — Released #707: Gamevion sync scope fix live; first sync clean; #708 merged to staging

**Released.** #707 (`staging` → `main`, #706) merged 13:40 UTC (`ba9a2c6f`), on the founder's go-ahead. CI and the `deploy` job were green, and the box runs release `82204655`.
- Migration `2026_11_09_120000_seed_gamevion_games_to_sync` ran (batch 66). `api_config.games` holds 74 codes; the `gvk_` key was untouched.
- `queue:prune-batches --hours=168` is in the schedule (daily). `/up` and `/api/health` answer 200.

**First run after it** (3293, started by the founder at 14:16 UTC):
- `success`, with `synced_suppliers` [gamevion, digiflazz] and no failed supplier.
- Batch `price-sync-run-3293`: 2 jobs, 0 failed.
- Gamevion synced 74 games and 1,541 products in about 2 min 40 s. Digiflazz followed in 17 s.
- Gamevion wrote one request-log row (the game list); none of its games failed.
- 116 old unpromoted Gamevion rows were pruned; the 71 old package references are kept.
- The 11 packages deactivated in the run are all Digiflazz's (its usual flapping). Gamevion still has 0 packages on.

**Match list for the 71 old packages** (read-only on prod, given to the founder):
- MLBB Malaysia (38) → `mlbb_sgmy`: 33 diamond denominations; Weekly Diamond Pass → `Weekly Pass`; First Topup 150+150 and 500+500 → the same First Recharge items. First Topup 50+50 and 250+250 match only `First Recharge 50 + 5` / `250 + 25`; to be checked on Gamevion or deleted.
- Free Fire Malaysia (11) → `freefire_mysg`.
- Honor of Kings (10) → `hok` (`800 Tokens` … `8000 Tokens` by name).
- PUBG Mobile Global (6) → `pubgm_global` (60 UC RM3.66 → RM4.00).
- Valorant Malaysia (6) → `valorant_my`.

Before a repoint, the five Gamevion groups must be linked to their games in Product Manager.

**#708** (a used game or package can't be deleted by any path, ADR-119 2026-11-05 addendum) merged to `staging` after green CI (`7ea13ddb`). Not released.

**Docs audit** (`docs/cleanup-2026-11-05`), each fact re-checked against prod read-only:
- PRD §14 headline: Gamevion's state, storefront counts (67 paid from 28 emails; OhahaStore 39), the second KOL campaign ("Mik4ca", code `4ARMY`, 31 clicks across both codes, no redemption), the latest release (#707) and what is on `staging` (#708).
- PRD §6 Gamevion row and ADAPT-4's example now describe the v2 API.
- §15 "not released" labels for #705 and #707 corrected, and `.batalbaki` (live since #660) no longer says "on `staging`".
- §16:
  - item 162 is now only the founder's steps (moved to group C);
  - 163 is next (moved to A);
  - 165 added: a KOL paid as a share of profit isn't supported, and the interim way to pay one is "Not a KOL fee".
- `backend/AGENTS.md` (Gamevion's state, the replay-safe suppliers, the smoke test) and `foundation-security.md` (Gamevion's quirks) corrected.
- ADR-031 and ADR-051 banners and index rows now note what ADR-137's addendum revised.

## 2026-11-06 — Item 163 seam audit reported; ADR-138 Accepted (design only, no code)

**#709 merged to `staging`** (`92acb151`), on the founder's go-ahead. #708 is still unreleased, so on production deleting a game can still cascade to used packages.

**Seam audit (item 163).** Three read-only forks, one per layer (order and delivery, stored value, pricing and catalog). Claude re-checked the top findings in code and read production; no test was run. Production: 104 orders, 3 combos, none in `needs_review`, no active combo with an off component, no affected voucher.
- **A.** A price swing switches a component off but leaves its combo on sale.
- **B.** A Paid after a Failed on a voucher order has no exit. Downgraded, see below.
- **C.** Combo legs: reference not durable before the supplier call, `NotStarted` rolled up as partial, combo decided two ways.
- **D.** Voucher merge ignores reserved redemptions; a restore onto a merged or revoked voucher is stranded.
- **E.** Approve writes a stale frozen price.
- **F.** New packages start on sale with no check.
- **G.** An API supplier off keeps selling but stops syncing.
- Six small items are PRD item 170. Ledger writes, wallet debits, withdrawals, the selling price formula and webhook-versus-poll finalization came back clean.

**B was overstated.** The founder asked whether it can really happen. CHIP's spec says `cancelled` and `expired` can't be paid, and `single_attempt` ends a purchase after one failed attempt. Production: 19 failed orders, none with a voucher, none in the 41 settlement rows; CHIP reads 8 `cancelled`, 6 `expired`, and 5 lookups gave 404 (cause unknown); no "Paid after a failed" in logs back to 2026-10-18. The one open path is a customer already at the bank when a purchase is cut off, which CHIP's documents don't cover. ADR-024 decision 10's rule stands: add a guard if it ever happens once. The founder may ask CHIP.

**Grill 1, ADR-138.** A, E, F and G are one problem: nothing owned switching off or creating a package. The founder challenged manual price approval (a rise can't lose money), which led to the direction rule. Production history backed it: package 412 fell 49.5% to RM4.79 for 3.5 hours, passing the 50% guard, while package 1538 sat off 7 days at a correct price.

**The stress test changed the design.** Three forks broke the first version:
- Removing the guard would have let one mistyped transfer reprice the catalog after 24 hours, worse than today, because ADR-134 decision 16 relied on it. Hence the breaker.
- An open row could stick when the price returned to exactly the held cost, then let a later false drop through at once.
- The hold now records the highest price seen, and Approve's live-price refusal was dropped as useless.

Measured for the breaker: at most 3 packages at 30% or more per run, no rise of 30% or more reversed within 24 hours, and the 2026-11-01 landed-cost run moved 1,399 packages at a median of 6.76%.

**Next:** build ADR-138 (item 166), then Grill 2 (item 167), Grill 3 (item 168), `max_price` (item 169).

## 2026-11-06 — Item 166 PR 1: one door for switching a package off (ADR-138)

**Why.** Seam A: a price swing switched a component off and left its combo on sale at the old cost, with no price cap sent to the supplier. Every off path also recorded something different.

**Built.**
- `PackageActivation::deactivate()` and `deactivateMany()` write the reason, the `DeactivationLog` row and the combo cascade in one transaction. The bulk form stays a few statements.
- The cascade moved out of `ComboPricingService`, which already depended on `PackageActivation`. `cascadeReactivate` stays there because it recomputes.
- Sync's two off paths, `flagPriceAnomaly`, `SupplierPackageStatusService::deactivate()` and the admin toggle all go through the door.
- An admin switch-off now stores reason `admin`, a time and a log row, so it reaches Dismissed.
- Migration `2026_11_11_120000` gives the same reason to packages off with none, leaving the Joki pool out. Sale state doesn't change. On prod 2026-11-06 that selected 85 Digiflazz rows, with 8 pool rows left out.
- The Dismissed list filters on `is_active = false`; a combo row reads "Combo" (it has no supplier ref).

**Tests.** `PackageSwitchOffTest`: the seam, a failed write changing nothing, the bulk form, swing to combo off to Approve back repriced, the backfill, every off path leaving no combo on sale, and a static check for writes outside the seam. Tests of the old cascade methods moved there.

**Gotcha.** `Package::create()` without `is_active` leaves it null in memory (the default lives in the database), so `deactivate()` sees it as off and returns false. Three combo tests hit this. PR 3's creation change has to pass `is_active` explicitly, as ADR-138 decision 16 says.

## 2026-11-06 — Item 166 PR 2: price swing rules by direction and the mass change breaker (ADR-138)

**#711 (PR 1) merged to `staging`** (`884103ff`) after green CI. Not released, by the founder's choice, and neither is #708. The founder Approved package 412 first: read-only on prod it shows approved, on sale, cost RM9.49, with no open row and no `price_anomaly` package left.

**Built.**
- `evaluatePriceChange()` goes by direction. A rise applies at once. A drop of 30% or more opens one row and the package keeps selling at the old cost. The row keeps the highest price seen and applies it after 24 hours as `auto_applied`.
- A price back in range, a rise, or a product not seen active closes the row as `superseded`. The check runs before the `price_sen !== cost_price` gate, which would skip a price back at exactly the held cost.
- `isMassChange()` counts a supplier's swings before the loop. Over the limit, both directions are held, auto-apply stops, and one email goes out per Malaysia day.
- Row work runs under the package lock on a freshly read package, and `approveHeld()` shares it.
- `PriceSwingPolicy` reads three new per-supplier fields in the Edit modal.
- Removed: the pending-row blocker, Dismiss for price changes, `price_swing_threshold_percent`. A `closeRow()` branch brings back any package a swing flagged before release; delete it once production shows none.
- Price Sync Center: the section is now "Held Price Changes", with a signed percent and "Approve all" per supplier behind a confirm dialog.

**Tests.** `PriceSwingRulesTest` (21). Tests pinning the old rule were deleted or moved to a drop case, including `PendingPriceChangeConcurrencyTest`.

**Verified:** `php artisan test` 3,723 of 3,723; `PendingPriceChangeConcurrencyTest` on local MySQL; admin `tsc` and `eslint` clean on the touched files. In a real browser on the local admin, with temporary rows removed afterwards: the section, signed percents, the confirm dialog and Approve all changing the rows and costs.

**Gotchas hit.**
- The browser check caught what the API tests couldn't: the first `approveAllPendingPriceChanges` passed `JSON.stringify(...)` to `apiFetch`, which stringifies again, so the server answered "supplier id field is required".
- Local Approve all then answered 500 "cache store does not support tagging" (`backend/AGENTS.md` gotcha 5: local cache is `database`, prod is `redis`, read-only checked), after the rows had applied. That showed the list could go stale on a part failure, so the page now refreshes in `finally`. The final cache forget therefore hasn't run in a browser on Redis; it's the same call single Approve makes.

## 2026-11-06 — Item 166 PR 3: new packages, Promote and repoint (ADR-138)

**#712 (PR 2) merged to `staging`** (`72faf935`) after green CI. Neither PR 1, PR 2 nor #708 is released, by the founder's choice.

**Why.** Seam F: a new package started on sale with no check, Promote trusted the `game_id` the UI sent, and repoint could move a package with orders still to be sent.

**Built.**
- `PackageActivation::activateNew()`: Promote, `storeCombo` and `storeManual` create off, then pass through `activate()`. No blocker means on sale, so the normal flow is unchanged. Joki's creation paths stay outside.
- A combo activates after attach and recompute, inside the transaction. One that stays off is still priced.
- Each response carries `notice`, and the admin shows "Added, but not on sale" with the reason.
- Promote takes the game from the product and refuses a product with none. The admin no longer sends `game_id`.
- Repoint refuses a product that isn't active, and an order paid or awaiting payment that hasn't been sent, including a combo order holding the package. Failed payments and finished deliveries don't block.

**Tests.** `PackageCreationTest` (9) and four new repoint tests. Promote's old refusal tests now give the product a game, or they would pass on the missing game instead of what they name.

**Verified:** `php artisan test` 3,735 of 3,735; admin `tsc` and `eslint` clean on the touched files. In a real browser on a built admin, with temporary rows removed afterwards: Promote under a switched-off supplier (package off, `supplier_issue`, warning shown, no `game_id` sent), Promote with no blocker, and a combo whose component went off while the modal was open. The manual warning was not seen in a browser: the modal lists active suppliers only, so it is covered by the backend test.

**Gotchas hit.**
- `backend/AGENTS.md` gotcha 5 again: the first browser run looked like a missing toast, but the cache forget had answered 500 after the package was saved. `CACHE_STORE=array php artisan serve --no-reload` avoids it without Redis.
- `:3000` was held by an unrelated process, so the admin ran on `:3010` with Playwright routing `localhost:3000` to it, which keeps the session cookie valid. Chromium then needs `--disable-features=LocalNetworkAccessChecks`.
- A test helper named `component()` is a fatal error: Laravel's test case already has one.

## 2026-11-06 — Item 166 PR 4: the supplier switch (ADR-138)

**#713 (PR 3) merged to `staging`** (`66a77f9b`) after green CI, on the founder's go-ahead.

**Why.** Seam G: an API supplier switched off kept selling but stopped syncing, so its packages sold at costs that grew older. On prod 2026-11-06 DigiFlazz has 1,195 packages on sale, so this switch now stops most of the store, which is why it asks first.

**Built.**
- Switching an API supplier off writes the flag first, closes its open price rows, then takes its packages and combos off through the seam as `supplier_issue`.
- Switching it on restores nothing. A manual supplier stays automatic both ways (ADR-125 decision 64).
- Reactivate is refused until the supplier has synced within its limit (default 90 minutes).
- `/middleware/suppliers`: Deactivate has a confirm dialog with the counts, and both dialogs say how packages come back.
- The stale limit moved from `CheckPriceSyncStalenessCommand` to `Supplier::priceSyncStaleMinutes()`, so the email and Reactivate read one setting.

**Tests.** `SupplierSwitchTest` (9). `PackageActivationTest`'s supplier now has a recent sync.

**Verified:** `php artisan test` 3,744 of 3,744; admin `tsc` and `eslint` clean on the touched files. In a real browser on a built admin, with temporary rows removed afterwards and the database read at each step: the dialog's counts, off (package `supplier_issue`, combo `combo_component_deactivated`), on (both stay off), Reactivate refused with a sync five hours old, then allowed after a fresh one (both on). The count line's wording was changed after that run, and only `tsc` and `eslint` saw the new text.

**Open.** A single package switched on after a long stop has no freshness check (ADR-138 PR 4 build notes). For the founder to decide.

## 2026-11-06 — Released #715: package sale lifecycle live; Gamevion key rejected; Dismissed list fixed

**#714 merged to `staging`** (`47d7bd61`) after green CI. **#715 released** on the founder's go-ahead: merge `e2a00fcb`, CI and `deploy` green, box release `82286819` at `e2a00fc`.

**Checked read-only before release:** the three foreign keys under their expected names, the app user's ALTER and REFERENCES, the backfill's 85 rows (all DigiFlazz), and nothing open: 0 price rows, 0 `price_anomaly`, 0 orders in flight, 0 running syncs.

**Checked read-only after release:** both migrations `Ran` (batch 67), three delete rules RESTRICT, 85 rows with reason `admin`, `/api/health` 200, Horizon running, 0 active combos with an off component, 0 open price rows, 0 orders in flight.

**Package count 1,277 to 1,265, not from the release.** Run 3336 brought 2 back and run 3338 took 14 Free Fire Malaysia off, all `status_raw` inactive from DigiFlazz and still inactive at 13:00 UTC. The same flapping ran before the release (2 in run 3317, 7 in 3327; 37 in one run in October).

**Gamevion answers 401 `Invalid access token`.** `GET /api/games` returned 200 at 00:30:06 UTC on 2026-11-06 and 401 at 01:00:08, on every run since, ten hours before this release. The stale alert was set at 02:15 UTC. Nothing on our side deployed then, but no table records a credential change, so the cause is unknown. No customer impact: 0 packages on, 0 orders. The founder is generating a new key. An earlier recap this session called Gamevion syncing, from docs, without checking; it wasn't.

**Dismissed list bug, found by the founder.** "There's no Call of Duty there." The backfill gave all 85 rows one `deactivated_at` and the list sorted on that alone, so MySQL's pages overlapped: 43 unique packages over five pages, 28 of them twice, no Call of Duty. Fixed with `id` as a second sort. SQLite doesn't show the overlap, so the new test pins the order, and the new query ran read-only on production: 87 unique, no duplicates, all 42 Call of Duty (2 more had been dismissed by then). Call of Duty Mobile is off as a game, so restoring those packages sells nothing until the game is on.

**Also in that PR:** `closeRow()` and `PRICE_ANOMALY` removed now production holds none, and tests that still set a held package off moved to it staying on sale. `DismissedPackageController`'s comment no longer says a restore ignores the supplier.

**Verified:** `php artisan test` 3,743 of 3,743.

**Gotcha.** Printing even a credential's prefix or length through tinker was refused in this session, rightly. Diagnose a supplier's auth from `supplier_request_logs`' status codes.

## 2026-11-06 — Released #717: Dismissed list fix live; Gamevion syncing again

**#716 merged to `staging`** (`06826a8d`), **#717 released** on the founder's go-ahead: merge `73008972`, CI and `deploy` green, box release `82305761`. No migration, no env change.

**Checked read-only after release:** the real controller's pages list 87 packages, each once, 42 of them Call of Duty; `closeRow()` and `PRICE_ANOMALY` are gone; `/api/health` ok; 0 open price rows; 0 active combos with an off component.

**Gamevion recovered.** `GET /api/games` still answered 401 at 13:57:20 UTC, `/api/balance` answered 200 at 13:59:17, and run 3341 at 14:00 UTC read `success` with both suppliers synced and `price_sync_alerted_at` cleared. That matches the founder saving a new key; the cause of the first 401 stays unknown.

**Packages on sale 1,265 to 1,248.** An admin switched two Mobile Legends Malaysia packages off at 13:20:36 UTC, and five combos went with them. Run 3340 then took 11 DigiFlazz packages off, Valorant among them, reported unavailable. That is 18 off; the net of 17 leaves one back on, with no reactivation log, so it was not through sync.


## 2026-11-07 — Item 167 designed: ADR-103 addendum Accepted (design only, no code)

**Trigger.** The founder asked to double-check item 163's seam C before fixing it. Combo volume had also changed: 6 combo orders, 3 of them on 2026-11-06, and an admin Retry on orders 111 and 121 that day.

**The audit undersold it.** Proofs run from a scratch test, repo untouched, found two paths it missed: a killed retry of a `Failed` leg loses its fresh reference, so a leg can be bought twice; and a webhook during the pass marks a fully delivered order partial, with RM0 settlement and a stuck leg. No production harm: all 15 legs delivered, and no worker-stop line in the retained logs.

**Grill, Q1 to Q7.** Leg `Processing` committed before the call; a dead pass parks legs and polls; a never-tried leg waits for an admin; one rollup; `Order::isCombo()`; drawdown left as ADR-083 decided (a crash window, not a seam: one writer, 89 of 89 delivered units recorded, variance 0); a past-window combo sends nothing.

**The stress test changed the design.** Three forks broke the first version:
- All three found the "wait while unfinished" rule stranded a dead pass at `Processing`, which can't be retried or settled. Now `Processing` always means a live pass, and the reconciler moves the order out before rolling up. Two forks proposed a flag; the order's status already carries the fact.
- The agreed leg lock deadlocks with a webhook once the reference is visible during the call (`ERROR 1213` on MySQL 8.4, in replica tables). Order then leg, as `fulfill()`.
- The reconciler couldn't write the attempt row correctly, so the row is written at the claim.
- An empty leg set counted as all delivered, with 1,500 sen credited in a test.
- `isCombo()` needed the admin payload and two `page.tsx` checks too.

**Next:** build it from ADR-103 decisions 10 to 17, tests first.

## 2026-11-07 — Item 167 built: combo leg safety (ADR-103 decisions 10 to 17)

**Built on `fix/combo-leg-safety`, into `staging`, not released.** No migration, no env change.
- A leg commits its reference, `Processing` and a `pending` attempt row before the supplier call (`claimLeg()`), then makes the call under order then leg locks.
- `DeliveryStatus::comboRollup()` is the one rule, and `confirmComboLegsFailed()` uses it too. A webhook during a pass records its leg and decides nothing.
- `recoverDeadComboPass()` is the reconciler's way out for a stale combo: it parks legs through `parkUnknownLeg()`, the same rule as the catch block, moves the order out of `Processing`, then rolls up.
- A combo past the same-reference window starts no pass, and Retry refuses it from any status.
- `Order::isCombo()` replaces the `package?->is_combo` reads, and the admin payload carries `is_combo`, so a paid combo with no legs can't be marked delivered.

**Verified.**
- `php artisan test` 3,776 of 3,776.
- Concurrency suite on real MySQL: only `BackupRestoreMysqlTest` fails, the known local gap (no `mysqldump`).
- The three new real-process tests (SIGKILL during a first attempt, SIGKILL during a retry, a webhook racing the leg in flight) fail on the old service and pass on the new one.
- Admin `tsc` and `eslint` clean, admin unit tests pass.
- In a real browser on a throwaway backend: a combo with no legs shows Retry Delivery and Confirm Failed, with no Mark as Delivered; a `processing` leg shows in the breakdown; a plain order keeps Resend Delivery and Mark as Delivered.

**One existing test changed:** `test_a_combo_leg_past_the_window_is_not_re_submitted` became `test_a_combo_past_the_window_sends_nothing`, the decision itself. No other existing test needed changing.

**Open at release:** the release needs no prod step. Confirm afterwards that no leg sits at `Processing` for long.

## 2026-11-07 — Item 168 built: a voucher in use can't be closed (ADR-036 2026-11-07 addendum)

**#720 merged to `staging`** (item 167, not released; the founder wants several items done before a release).

**The hole, proven.** A RM100 voucher with RM40 reserved, then merged or revoked, then the order fails: the RM40 lands on a voucher nobody can spend, and Cash Position and breakage both miss it. Production untouched: 0 reserved, one merge, 0 revokes.

**Built on `fix/voucher-close-guard`, into `staging`, not released.** No migration, no env change.
- `merge()` and `revoke()` refuse a voucher an order is using, and the message names both.
- A no-expiry source keeps the merged voucher without one. The founder asked whether expiry should go and giveaways move to Campaign; the check showed Campaign can't do a fixed-RM gift to a named person, so the line is: customer money never expires, gifts may, public discounts are Campaign.
- The panel hides "Valid until" when the rule applies.
- `/admin/vouchers`: Total Issued and Total Used count a merge once. They were overstated by RM460.42 each, display only.

**The stress test changed the design.** Two forks independently found the agreed locking read deadlocks with `restore()` (`ERROR 1213`, on replica tables), so it is a plain read after the voucher lock. Also: a revoke refusal would have been a 500, the "settle" wording was wrong for an unpaid order, and "Valid until" would have refused every date on today's vouchers. Two claims made to the founder were corrected: a refused revoke does leave the unreserved balance spendable, and Campaign covers the primary brand only (item 171).

**Verified.**
- `php artisan test` 3,785 of 3,785.
- Concurrency suite on real MySQL: 44 of 48, only `BackupRestoreMysqlTest` fails (no local `mysqldump`). It includes a new real-process race of `redeem` against `merge`.
- Admin `tsc`, `eslint` on the changed file, and 22 of 22 unit tests.
- In a real browser on a throwaway backend: "Valid until" hidden for no-expiry vouchers and kept for gifts; a revoke of a voucher in use shows the message naming the order, and the voucher stays active.

**One existing test changed:** `test_a_merge_uses_the_same_end_of_day_rule` now merges vouchers that have an expiry, because the old setup is what this rule refuses.

**Open at release:** confirm no reservation sits on a merged or revoked voucher.

## 2026-11-07 — Item 172 built: a game link belongs to the group (ADR-067 2026-11-07 addendum)

**Found.** The founder's first repoint (item 162) failed: `valorant_my:1000` to `1000 VP`, "This item's group is not linked to the package's game", and the group had no Link button. Confirmed in the live admin through Chrome (read-only, Link package not pressed). `Valorant (Malaysia)` held 6 old items linked since September and 6 new ones with no game; `categories()` calls a group linked if any row is, and the admin hides Link then. Release #705 didn't link anything.

**The audit (read-only prod).** Every package on sale sits in the right region's group, no duplicate references, no package on sale against an inactive item. 3 Gamevion and 5 Digiflazz groups were half-linked (217 rows, including 58 on-sale packages' rows, harmless: selling never reads the link). `MOBILE LEGENDS — Umum` showed as linked to Malaysia on the strength of one stale row, and the founder says it is Indonesia stock. Separately, Gamevion's catalog is shrinking (item 162) and its pricelist answers 503 (item 173).

**The stress test changed the design.** First proposal: a "link the rest" button plus a reactivation guard. Rejected at the founder's request to test it: every sync and returning item would bring the half-linked state back, and the guard protected one package. Trace showed one writer and three readers, all in `SupplierProductController`, so the link moved to the group. Simulated on prod data first: 49 groups, 0 with two games, 217 rows gain a link, 0 packages disagree, no case variants. The founder then asked what a change-game button would risk: a free one strands packages on the old game, so a group changes game only while no package would be left behind.

**Package 516.** The founder pointed out it can't be deleted, it has their failed test order. It isn't: only its reason moves to `admin`, the same write as Dismiss in Pending Reactivation, and the migration test proves the order row is identical afterwards.

**Built on `fix/group-level-supplier-link`, into `staging`, not released.** One migration, `2026_11_12_120000_move_game_link_to_supplier_groups`, which also drops `supplier_products.game_id`. No env change.

**Verified.**
- `php artisan test` 3,799 of 3,799.
- Concurrency suite on real MySQL: 44 of 48, only `BackupRestoreMysqlTest` fails (no local `mysqldump`).
- Migration and rollback on a throwaway real MySQL database: the em-dash label matched, Umum to Indonesia, 516 to `admin` with its date kept, unique and `game_id` indexes present.
- Admin `tsc` and `eslint` on the changed files clean, 22 of 22 unit tests.
- In a real browser on a throwaway backend: the founder's exact repoint answered 200 with a success toast and the package stayed off; a group with packages shows only View, one with none shows Change with its current game selected, an unlinked one shows Link.

**Gotcha.** sqlite refuses the table rebuild under the `llm_report_*` views, so the migration reuses `underViews()` from `2026_11_10_120000`.

**Open at release.** Check on prod: 49 links, Umum on Mobile Legends Indonesia, 516 in Dismissed, then the founder's repoint. Product Manager can error for the seconds between the migration and the release switch.

## 2026-11-07 — Released #723: combo leg safety, voucher close guard, group game link

**Released on the founder's go-ahead.** `main` `88edcdea`, box release `82355688`, CI and `deploy` green. #722 merged to `staging` first. Before release, read-only: 0 orders in flight, 0 in `needs_review`, 0 legs at `Processing`, 0 reservations on a merged or revoked voucher.

**Checked after release.** Migration `Ran` (batch 68). 49 group links, the number simulated. `supplier_products.game_id` gone. `MOBILE LEGENDS — Umum` on Mobile Legends Indonesia. Package 516 `admin`, its date, game and reference kept; order `PG-CGDZOLEHAIR8` unchanged; the only package off its group's game. `/api/health` ok, Horizon running. In the live admin through Chrome: Umum and Valorant (Malaysia) show as expected. The 36 `ERROR` lines in the log tail are all from before the release (Gamevion's 401 on 6 November, `CsGameInfo` on 3 November). Items 167, 168 and 172 closed.

**Gamevion's catalog came back by itself.** `/api/games` read 320 from 03:00 UTC and 1,315 at 05:30; new-API rows are 1,164, PUBG's 35 included. So the morning's drop was on Gamevion's side and temporary, and the earlier reading that PUBG had left the catalog was wrong. With prune deleting un-promoted rows, this is exactly the flapping the group link makes harmless for links; it is still open for packages (item 173).

**Not yet seen:** the founder's repoint on prod.

## 2026-11-07 — Item 175 built: KOL attribution gaps (ADR-118 V21 to V25)

**Why.** The founder asked whether every KOL order is really recorded, since reports can only use what the order and the ledger hold. The store was sound: every storefront checkout stamps through one path and profit reaches the order through the ledger. The stress test the founder asked for then found more than the one hole already named:
- an order placed before the first delivery was never stamped;
- a partly delivered customer belonged to nobody, though the new-customer rule treated them as existing and the discount they kept was charged to the KOL (item 170's finding, closed here);
- two matching redemptions had no defined winner;
- ending a campaign left its fee accruing, in the month close too.

**Built on `fix/kol-attribution-gaps`, into `staging`, not released.** No migration, no env change.
- `PromoCodeRedemption::kept()` is the one rule; `attributionFor()` and the scorecard read it, oldest first.
- `claimLaterOrders()` fills empty stamps after the delivery commits, so it holds no other order's row inside a delivery. A stamp with a value is untouched.
- `PATCH /campaigns/{id}` with `ended: true` needs `last_fee_month` while the fee is open, one transaction, through `setEndMonth()`.
- Order details always shows Promo Discount, and "Counts for KOL". The campaign page lists its customers, adding up to the scorecard's all-time sales.

**Verified.**
- `php artisan test` 3,809 of 3,809; promo and voucher concurrency tests on real MySQL 10 of 10.
- Admin `tsc`, `eslint` on the changed files, 22 of 22 unit tests.
- In a real browser on a throwaway backend, through the real `commit()`: a first order, a later order and a plain order each show the right rows; the customer row shows RM 36.32 on both sides; ending a campaign asks the month, answers 200, and the fee row shows November to November.

**Not proven on prod:** no code has been redeemed yet, so the first real redemption needs checking in the database.

## 2026-11-07 — Items 170 and 174: small findings, one seam each

**Item 174 closed, no code.** The three packages are `inactive` at DigiFlazz with streak 0 (read-only prod), so nothing holds them. After sync 3375, 0 packages were off with a streak of 6 or more; Twilight Pass came back by itself. The morning's "past 6 syncs" can't be rechecked: the streak keeps no history.

**Item 170, each finding re-verified first.**
- **Sandbox order:** Issue Voucher had no `is_test` check. `settle()` now refuses one, so every compensation path is covered; `storeFromOrder()` answers 404 like its siblings. Prod: 1 sandbox order, 0 vouchers.
- **Member above guest:** not reachable. Membership only runs on owned brands, which have no tier. Prod, 1,293 packages, both plans: member cheaper on all. `membershipEnabledEffective()` now checks `is_owned`, as Joki does, so a stale switch can't create the case. No pricing rule added.
- **Wallet-refund SQL:** Reports had its own stricter copy. `Order::walletRefundSql()` now has the owner check, and Reports calls it. Prod: 4 rows, 0 different.
- **Profit formula:** six places spelled it out. All go through `Order::residualProfit()`, with a test that fails on a new copy.
- **Same-reference window:** kept, recorded in ADR-102. It can only refuse, never buy twice.

**Gotcha: a wrong finding of ours.** A read-only simulation first reported OhahaStore's member price 7 sen above guest on one package. It called `wholesaleTierMarkupPct()` directly and so read a leftover "Test Tier" row, which `storefrontPriceTerms()`, the real seam, ignores for an owned brand. Real orders showed the standard price. Simulate through the seam, and compare with a real order, before reporting a number. `wholesaleTierMarkupPct()` is now removed: its one caller, `storefrontPriceTerms()`, holds the lookup, so a brand's tier can't be read past the owned-brand check.

**Tests changed.** Three fixtures sold membership on a third-party brand, which ADR-061 forbids; they are now owned. `test_a_test_order_never_messages_anyone` used Issue Voucher to reach the message code; it now calls `CustomerNotificationService` directly.

**Verified.**
- `php artisan test` 3,814 of 3,814.
- Report tests on real MySQL 62 of 62.
- Concurrency suite 44 of 48, run before the method was removed, which touches no locking; only `BackupRestoreMysqlTest` fails (no local `mysqldump`).
- A read-only prod check of each finding, above.

**Built on `fix/item-170-small-findings`, into `staging`, not released.** No migration, no env change.

## 2026-11-07 — Items 169 and 173 decided: no grace period, `max_price` parked

**Item 173, closed.** Two tests through `SyncSupplierCatalogJob` prove what the PRD had wrong. A package skipped on a 503 keeps its streak, because its row isn't touched, so it is back on the next good sync, about 30 minutes later. Only Gamevion's own `inactive` resets the streak and costs 6 syncs. The founder chose no grace period: it would sell at an unconfirmed price to save 30 minutes.

**Item 169, parked with a trigger.** The founder challenged the need: a selling price follows cost, so where is the loss? Production agreed. 1 loss in 86 delivered orders, 13 sen, on `PG-HMMEGKPYVN8K`: two failed attempts, then an admin's resend after the cost rose from RM17.94 to RM18.61, which `max_price` wouldn't have stopped. Of 67 orders with a real cost, 4 were above the stored cost, at most 18 sen, and none lost money through it. The design and the trigger are in ADR-137's 2026-11-07 addendum.

**Docs corrected.** The PRD said DigiFlazz doesn't document going over the limit. Its response codes have `rc 69`, Gagal, no transaction formed. Its test cases cover neither `rc 69` nor `max_price`, so the sandbox can't prove it.

**Verified.** `php artisan test` 3,816 of 3,816. Tests and docs only, no product code.

## 2026-11-07 — Item 163's last two inputs: Link dialog no longer edits a live game's checkout

**Why.** The audit report never answered two of its inputs.
- **A package can't move games:** deliberate, and enforced at the link and the repoint. On prod 1 of 1,477 packages differs from its group's game: package 516, by decision.
- **A group link merges the game's rules:** the merge is safe and `linkCategory()` is the one writer. The dialog's "Checkout input needed" dropdown, though, edits the whole game. Changing it while linking a Gamevion group could stop checkout asking for a server ID on live DigiFlazz packages.

**Built on `fix/link-modal-keeps-checkout-input`, into `staging`, not released.** Admin only, no migration, no env change. For an existing game the dialog shows the rule as text and sends no `validation_rules`; a new game keeps the dropdown. The Checkout Input tab is the one place an existing game's rule changes.

**Verified.** In a real browser on a throwaway backend: no dropdown for an existing game, the POST body carried only `supplier_id`, `group_label` and `game_id`, the link answered 200, the game stayed on `server_id`, and New game still shows the dropdown. A new backend test covers a link with no rules. `php artisan test` 3,817 of 3,817; admin `tsc`, `eslint` on the changed files, build, 22 of 22 unit tests.

## 2026-11-07 — Released #728: KOL attribution, item 170, Link dialog

**Released on the founder's go-ahead.** `main` `0df8b387`, box release `82362619`, CI and `deploy` green. Before release, read-only: 0 orders and 0 legs in flight, no migration in the diff. Ships #724 (item 175), #725 (item 170), #726 (items 169 and 173 decided) and #727 (Link dialog).

**Checked after release, read-only.**
- `/api/health` ok and `/api/health/whatsapp` 200. No `ERROR` in the log tail since the deploy.
- `Order::residualProfit()` present, `wholesaleTierMarkupPct()` gone.
- `storefrontPriceTerms()`: PekanGame and OhahaStore no tier and no markup, FixFast Demo tier 5 and markup 5, unchanged.
- Membership effective on PekanGame and OhahaStore, off on FixFast Demo.
- `Order::walletRefundSql()` totals 43,004 sen, equal to the ledger's `wallet_refund` total.
- All four Vercel projects success on the merge commit.

Items 163, 170 and 175 closed.

**Package count 1,389 to 1,365, not from the release.** Run 3378 at 08:30 UTC, before the merge, took 26 DigiFlazz packages off (Valorant Indonesia 17, Mobile Legends Global 5, Mobile Legends Brazil 4), all `inactive` at the supplier.

**Gotcha.** `gh pr checks --watch` right after opening a release PR exited 0 on the Vercel checks alone, before the Actions jobs had registered. The merge guard read the real checks and held. Watch the Actions run itself with `gh run watch <id> --exit-status`.

**Not yet seen:** the Link dialog on the live admin, and the first real KOL redemption.

## 2026-11-07 — Gamevion order test at RM0 balance; item 162 moved to group D

**Why.** The founder is not using Gamevion yet, so the repoint stops at 6 of 71 packages. Before parking it, one order at RM0 balance, as ADR-137 decision 11 planned: `supplier_request_logs` held no `createOrder` call for Gamevion, so the order path had never been exercised.

**What ran on production.** The founder ran the tinker script themselves: read the live balance, stop unless it was exactly 0, then `createOrder` through `SupplierAdapterFactory` for `hok:16`, reference `PG-GV402-20261107`, a made-up player ID. Before it: balance 0, debt 0, credit off.

**Result.** `POST /api/order` answered 402 at 10:40:03 UTC, log row 8095: `insufficient_balance`, "Insufficient balance (RM 0.00 < RM 0.71). Please top up." The adapter returned `outcomeConfirmedFailed: true`, `resendUnsafeWithSameReference: false`. RM 0.71 matches the synced price of `hok:16`. Checked afterwards, read-only: 0 orders with that reference, 0 Gamevion packages on.

**What it proves.** The key, the IP allowlist, the order endpoint and our error mapping work. With a made-up player ID it answered 402, not 422, so the balance check appears to come first, which answers ADR-137's open question. Not proven: a delivery, its webhook, drawdown and real cost. Those wait for the funded test order.

**Gotcha.** Supplier Reactivate would not switch on all 71, as earlier notes said. Through `PackageActivation::blocker()` on production today: 6 repointed packages have no blocker, 65 are blocked `supplier_sync`. At RM0 balance those 6 would still sell, so the warning stays, with the right number.

## 2026-11-07 — Signed-out membership page

**Why.** A visitor to `/membership` saw only an email field and learned nothing about the offer. The founder designed a new page; the content and calculation come from the backend (ADR-055 2026-11-07 addendum).

**Built on `feature/membership-page-v2`, into `staging`, not released.** No migration, no env change. New public `GET /api/membership/offer`. New `MembershipLanding` and `MembershipCalculator`, `lib/membership-calculator.ts`, and `lib/membership-signin.ts` so a sign-in survives a reload. The plan chosen in the calculator carries through to its payment step. `chargeSen()` is extracted so subscribe and the page share one charge calculation.

**Departures from the founder's design, after a mobile UX review the founder accepted.** The hero shows the price and a real example instead of an email form. The final band has a button, not an email field. On a phone only the winning plan is a full card. See the Difference comes before How It Works. Copy changed from "billed monthly" to "30 days", because nothing renews by itself. The hero example uses the top plan. The desktop mascot is the kit's `pointing-camera` pose on a flat yellow panel, primary brand only: the design's thumbs-up wasn't in the package, the `hype` pose was cut at its edges, and the founder found the lavender panel with its diagonal stripe generic.

**Calculator changed after the founder's review.** Showing a loss at low spend worked against the point of membership, so the page now shows one number, the saving, with the cost under it, the break-even as a goal, and a 30-day try with no auto-renewal. Copy was rewritten in the plainest words (ADR-055 addendum, decision 10).

**Verified.** Backend 3,825 tests pass. `tsc` and `eslint` clean. Playwright: the calculator's maths on production's plan figures, and a 390px visitor going from the offer through a reload at the OTP step to the plan's payment step. The existing subscribe spec still passes. Screenshots at 390px and 1440px with production's plans; no sideways scroll; hero and FAQ are in the server HTML.

**Gotchas.**
- A throwaway fixture with a mispriced representative package produced a negative saving, shown as `+-RM22.50`. The storefront now floors the percentage at 0.
- An `IntersectionObserver` misses a jump straight past a section, so the sticky button stayed on at the page end. A very large `rootMargin` makes "above the viewport" count as intersecting.
- `text-tertiary` fell to about 2:1 on the light Bumblebee, Red Giants, Emerald and Cobalt presets, so the page doesn't use it.

- The first offer was cached once for every brand, so a game hidden on a brand could still appear as an example there. OhahaStore hid nothing on 2026-11-07, so nothing showed; a test now covers it.

**Not yet seen:** the page on a real phone, the mail-app round trip in a TikTok or Instagram in-app browser, or OhahaStore itself: local has no such brand, so its panel was checked by forcing the non-primary branch with the Bumblebee theme.

## 2026-11-07 — KOL link applies its code; bank logos load up front

**Why.** 109 KOL clicks, 0 redemptions. The check found the codes work on production and end to end in e2e, but a phone customer could pay full price without noticing the code wasn't applied. Most visitors never reach checkout, so this alone won't fix redemptions. The founder also saw blank bank tiles in TikTok's browser.

**Built on `feature/kol-code-auto-apply`, into `staging`, not released.** Storefront only, no migration, no env change. A link's code applies once email and phone are in; a note under the header says the code is saved (ADR-118 2026-11-07 addendum). Bank logos are webp, unoptimized, eager, cached 30 days (ADR-130 2026-11-07 addendum).

**Verified.** Playwright: link to note to auto-apply, Remove stays removed, note stays closed, no note without a link; the checkout spec still passes. Production read-only: all four codes quote RM1.07 on RM21.55.

**Gotcha.** A failed tinker seed looked like an "invalid code" bug: `createCampaign()` needs an admin id, and the error was hidden. Prod's real codes were checked before blaming the code path.

**Not yet seen:** either change in a real TikTok or Instagram browser.

## 2026-11-08 — The proxy stops holding visitors, and the game card stops over-downloading

**Why.** The founder still felt pages load slowly. PageSpeed scored the homepage 95 and 96 on mobile, because it tests one page once, and it had no real-user data. A read-only stress test found the waits it couldn't see: see the ADR-078 2026-11-08 addendum for the measurements.

**Built on `fix/storefront-proxy-off-the-hot-path`, into `staging`, not released.** No migration, no env change. `lib/stale-cache.ts` serves an expired answer and refreshes behind it, one load per key; `proxy.ts` uses it for both reads, reads them together, and times out at 2 s. `images/` leaves the matcher. `ProductCard` has `sizes`, and an ESLint rule requires `sizes` on a `fill` Image. The storefront gains `npm test`, run in CI.

**Verified.** 8 unit tests, red first. New `storefront-proxy.spec.ts` drives the real file: a saved redirect fires, an unknown host gets 503, and an image on that host gets 200. With the old matcher put back, that last test failed 503 against 200, so it can fail. The checkout, SEO, membership and promo-link specs pass. `tsc` and `eslint` clean; a test file with a bare `fill` Image failed lint.

**Gotchas.**
- The first origin control was wrong: `api.pekangame.space/robots.txt` answered `cf-cache-status: HIT`, so it never reached the box. A unique query string forces a real origin read.
- `npx lighthouse` is blocked in auto mode, and the PageSpeed API hit its daily quota. Expanding the rows on `pagespeed.web.dev` with Playwright reads the same detail.

**Not yet seen:** the same measurements on production after release, and a refresh running behind a response on Vercel. If one never ran, the hour limit would still force a fresh read.

## 2026-11-08 — OPcache was full; cleared by hand; a full cache now shows

**Why.** While measuring the slow pages, an empty route took 340 ms straight to PHP-FPM and 1 ms inside Laravel. OPcache was on and full. Cause and decisions: ADR-066 2026-11-08 addendum.

**What ran on production.** The founder ran both, 2026-11-07 about 16:15 to 16:20 UTC. Each wrote one temp PHP file in `~`, outside the web root, ran it through the FPM socket, and deleted it.
1. A read of `opcache_get_status()`: enabled, full, 128 of 128 MB, strings 8 of 8 MB, wasted 0%, 4,520 scripts, hit rate 22.64%, 0 restarts of any kind, started 2026-11-04 06:21 UTC.
2. `opcache_reset()`: returned true. No restart; running requests were untouched.

**Before and after.**

| Measure | Before | After |
| --- | --- | --- |
| `/up`, straight to PHP-FPM | 340 ms | 17 ms |
| `/api/catalog/seo/redirects`, straight to PHP-FPM | 430 ms | 45 ms |
| Public API | about 450 ms | about 100 ms |
| Page, warm | 0.5 s | 0.2 s |
| Page after 18 s of quiet | 0.9 to 3.0 s | 0.3 to 0.5 s |
| 20 bank logos after 17 s of quiet | about 10 s | 0.7 to 1.7 s |

`/api/health` ok afterwards, no new `ERROR`. The reset doesn't last: each release adds to the cache again, which is §16 item 176.

**Built on `fix/opcache-full-is-visible`, into `staging`, not released.** No migration, no env change. `PhpCodeCache` reads OPcache inside PHP-FPM. A full cache fails `/api/health` as `php_code_cache`, and the Dashboard's System details shows size and hit rate.

**Verified.** Tests red first. A full cache fails health, a cache with room passes, and OPcache off, as in the console and the tests, never counts as full. Backend suite, admin `tsc`, `eslint` and unit tests pass.

**Gotchas.**
- Reading OPcache from the console says nothing: it is off there, and FPM's memory is its own. Timing a large file through the FPM socket showed it with no write: 40 to 47 ms against 58 ms with no cache at all, and 5 ms after the reset.
- A reload isn't free: `process_control_timeout` was 0, so the obvious one-line fix would have ended running requests. That setting goes first.

**Not yet seen:** the row on the live Dashboard, and a release clearing the cache by itself.

## 2026-11-07 — Released #733: membership page, KOL auto-apply, bank logos

**Released on the founder's go-ahead.** `main` `45561549`, box release `82375880`, CI, `deploy` and all four Vercel projects green. Ships #731 and #732, plus the docs of #729 and #730. No migration, no env change. Recorded late, in the 2026-11-08 docs audit.

**Checked after release, read-only.**
- `/api/health` ok, Horizon running, 0 failed jobs in 24 hours, no paid order undelivered. Order 133, created after the deploy, was paid and delivered.
- `/membership` loads with no console error on PekanGame and OhahaStore at phone width, with no sideways scroll. `/api/membership/offer` answers on both with the same plans.
- All 20 bank logos answer 200 as webp with a 30-day cache on the storefront. No code refers to the old `.png` files, which now 404.

**Not checked:** auto-apply and the saved note on production, because opening a KOL link records a click; a real TikTok or Instagram browser; a paid checkout.

**Seen by chance.** `/order/<a wrong slug>` shows "Page not found" with HTTP 200, not 404.

## 2026-11-08 — Item 176 on the box: FPM timeout, OPcache size, reload in the deploy script

**What ran on production.** The founder ran these in the droplet console, 2026-11-07 17:18 UTC, then edited the Forge deploy script. Exact values: ADR-066 2026-11-08 addendum.
- `process_control_timeout = 35s` in `php-fpm.conf`, was 0.
- New `conf.d/99-pekangame-opcache.ini`: `memory_consumption=256`, `interned_strings_buffer=32`, `max_accelerated_files=32531`, were 128, 8 and 10000.
- `php-fpm8.4 -t`, then a restart.
- Forge deploy script: the reload right after `$ACTIVATE_RELEASE()`, and `/up` at `--max-time 40`. The script as it stood had no reload.

**Checked after, read-only.** PHP-FPM reports all three values; `process_control_timeout` reads 35s. `/api/health` ok, Horizon running, 0 failed jobs in 2 hours, no paid order undelivered. `/up` straight to PHP-FPM 16 to 21 ms, and the public API 0.06 to 0.09 s, so the gain held through the restart.

**Not yet seen:** a release running the reload line and emptying the cache. If it doesn't, the socket reset in the ADR comes back.

## 2026-11-08 — Docs audit after release #733

**Why.** The founder asked for one after the release: nothing outdated or contradictory against the live system.

**Corrected in `prd.md`.** §14's latest release, whose "no migration" sat over a migration bullet that belonged to #723. "Not released" on three §15 rows, one of them (ADR-119) out of date since #715. Customer and KOL figures, with the fourth campaign and two missing fees. The domain list, missing `admin.` and `onboarding.`. A Digiflazz SKU count that had drifted. Item 159: OpenWA had shipped the fix, and the doc still called v0.27.1 the latest; it is now upgraded and closed.

**Fired for the first time.** The low-balance alert (ADR-115), 2026-11-07 15:59 UTC: DigiFlazz at about RM 2,375 against the RM 2,391 floor. So it left §14's "not yet seen". The log line is proven; the email's arrival is not.

**Checked and right.** The ADR index, 138 rows for 138 files, no duplicates, no last-addendum date older than its file. No broken doc link, no missing path in the guides. Stack versions, the eight required checks, API v1.5.0, region `sin1`. Payment methods, October's close at RM129.22, CS in Shadow with 9 knowledge entries, no Joki order or partner application, 3 resellers, Gamevion 0 of 71, 1 active member. No §16 item both open and closed.

**Not audited.** The text of each ADR against the code, `docs-site` content, and §6 to §9 requirement by requirement.

## 2026-11-08 — OpenWA v0.29.1: the chat list survives a restart (item 159)

**Why.** rmyndharis/OpenWA#1992, the founder's own request, ships from v0.28.0. v0.29.1 also stops a Baileys start blocking on a large store.

**Checked first.** Release notes for v0.28.0 to v0.29.1 against what we use: five API calls and three webhook events. Our session ids are UUIDs, so the new `404` doesn't apply. `message.ack` now fires for group messages, and neither webhook subscribes to it. The daemon stops on `SIGTERM`, and it can read `.env` and `data/.env.generated`.

**What ran on production.** The founder ran each step at 01:50 MYT with 0 orders in the last hour, 0 open payments and 0 notifications waiting: `scripts/openwa-upgrade.sh build v0.29.1`, daemon stopped in Forge, `switch v0.29.1`, daemon started. Backup: `~/openwa-backups/openwa-v0.27.1-20261107-1751.tar.gz` (62 MB). Rollback is `switch v0.27.1` plus that tarball.

**Checked after, read-only.** Offline 17:51:31 to 17:53:06 UTC for `reseller-bot`, 17:53:11 for `customer-support`, about 1m40s. `/api/health/whatsapp` 200, both `ready`. Laravel recorded both `ready` from the `session.status` webhook, so the webhook path works. No webhook delivery failure. Horizon and `/api/health` untouched; no process was killed by the build. The index migration ran at boot.

| Chats listed after a restart | v0.27.1 (2026-11-04) | v0.29.1 |
| --- | --- | --- |
| `reseller-bot` | not recorded | 19, 10 one-to-one |
| `customer-support` | 8, 1 one-to-one | 38, 31 one-to-one |

The 2026-11-04 row is as item 159 recorded it, without naming the session; it is shown under `customer-support` as the nearest match.

**Gotchas.**
- Auto mode refuses piped remote scripts, so the founder ran `build` and `switch` with `!`. `build` passes the 120 s command limit and carries on in the background: that isn't a failure, and running it twice would stop on "already exists".
- `API_KEY_PEPPER is not set` is a boot warning, not new (22 earlier boots). Leave it: setting it now locks out every key.

**Not yet seen:** a real message in and out on v0.29.1. Nothing has arrived at this hour.

## 2026-11-08 — Released #737: storefront speed, code cache check, docs audit

**Released on the founder's go-ahead.** `main` `dc828aee`, box release `82383059`, CI, `deploy` and all four Vercel projects green. Ships #734, #735 and #736. No migration, no env change. 0 paid orders undelivered before it.

**Measured after release, the same way as before.**

| Measure | Before the work | After |
| --- | --- | --- |
| Order page, load event, desktop | 9 to 14 s | 0.5 to 0.7 s |
| Order page, load event, phone | 4 to 6 s | 0.5 to 0.6 s |
| Page after 18 s of quiet | 0.9 to 3.0 s | 0.22 to 0.29 s |
| 20 bank logos after 17 s of quiet | about 10 s | 0.19 to 0.40 s |
| `/up`, straight to PHP-FPM | 340 ms | 18 to 21 ms |

**Item 176 closed.** The deploy output shows `Restarting FPM...` for the first time. A read of OPcache through FPM, run by the founder: started 2026-11-07 18:43:55 UTC, the deploy's moment, not full, 73 of 256 MB, strings 15.7 of 32 MB, 1,581 scripts, hit rate 99.18%. So a reload does clear it on this box, and the socket reset stays unneeded.

**OpenWA v0.29.1 proven both ways.** The founder's test message reached `cs_messages` (272) with a Shadow draft. Three notifications went out with a WhatsApp id: a status card, a voucher and a delivered receipt.

**Seen while checking, not from this work.**
- Order `PG-7JUVSOBGJWJJ` failed at DigiFlazz, `rc` 02, on First Top Up 250+250. Voucher 13 (RM17.69) was issued and spent on order 137, which delivered.
- The low-balance alert fired at 15:59, 18:33 and 18:43 UTC, and then cleared: DigiFlazz's floor went from IDR 10,000,000 to 5,000,000, and 9,533,687 is above the 7,581,286 forecast. Recovery resets the claim, so a floor moved up and down can send it twice in one day. By design.

**Gotcha.** `gh run list --limit 1` straight after a push returned the previous run, so a watch finished early. The merge guard read the real checks and held. Pick the run by `--commit`.

**Not yet seen:** a KOL link and the bank logos in a real TikTok or Instagram browser.

## 2026-11-08 — Admin ↔ Middleware switch link under the sidebar brand

**Asked by the founder.** Switching panels meant editing the URL by hand. A small link now sits under each panel's brand: "Middleware Panel" under "PekanGame Admin" (super_admin only, since the Middleware layout sends anyone else back to `/admin`) and "Admin Panel" under "Middleware Panel". It opens in a new tab (founder's choice). It first sat in a "Panels" section at the foot of the menu; the founder moved it up because the admin menu needs scrolling to reach its end. `PanelSidebar` takes an optional `switchTo`; collapsed, the link shows its icon only.

**Verified** on a local admin dev server (`:3005`, the API given `ADMIN_URL` / `SANCTUM_STATEFUL_DOMAINS` for that port because `:3000` was busy with a video render): signed in as super_admin, light and dark, expanded and collapsed. The link carries `target="_blank" rel="noopener noreferrer"`, the click opens `/middleware` in a new tab with the original left on `/admin`, and the way back works the same. Computed colour `ink-muted` on `surface` in both themes. `tsc` and `eslint` clean.

## 2026-11-08 — Item 164: Suppliers page on the ADR-104 tokens

**Built** on `feature/164-middleware-suppliers-restyle`, a pure reskin: `/middleware/suppliers` and its Create, Edit and Manage Packages modals carry no `gray-*`, `brand-*` or `white/[…]` class any more. Mapping, benchmarked on admin `/orders`: page title `text-page-title text-ink`, card title `text-section-title`, labels `text-ink-muted`, values and form labels `text-ink`, cards `bg-surface border-border`, hand-rolled inputs on the kit input's colours (`border-border-control`, `focus-ring` outline), error banner `danger-surface/ink`, the Reactivate note `info-surface/ink`, the "Not configured" pill `neutral-surface/ink`. The one layout touch: "Add Supplier" no longer wraps to two lines beside the long description (`gap-3`, `shrink-0`).

**Verified** with Playwright screenshots, light and dark, of the page and all three modals beside `/orders`; `getComputedStyle` on the title (26 px), labels and values in both themes; 390 px phone width with no horizontal scroll. `tsc`, `eslint` and `color-tokens.test.ts` pass.

**Gotcha.** A Next dev server left running across a `git checkout` threw a sidebar hydration error (the server still rendered the other branch's module). A fresh load on each branch had none: not a bug.

## 2026-11-08 — Item 164: Price Sync Center on the ADR-104 tokens

**Built** on `feature/164-middleware-price-sync-restyle`, a pure reskin of `/middleware/price-sync` and its three parts (`SyncDetailsModal`, `PendingPriceChangeSection`, `FxRateHistorySection`): 160 old classes, now none. Same mapping as Suppliers, plus table rules `divide-border` / `border-border`, the empty-state box `bg-subtle`, the "Combo — recomputed" pill `cyan-50` / `cyan-ink`, and the run errors and flags `danger-ink` / `warning-ink`. Section headings take `text-section-title`. One layout touch: "Refresh" and "Sync All Prices Now" were squeezed to three lines beside the description; the header now wraps like `/orders` (buttons stay right on desktop, drop below the text on a phone).

**Verified** with Playwright, light and dark, the full page and the Sync Details modal, and the header at 1440, 1024 and 390 px (no horizontal scroll). `tsc`, `eslint` and `color-tokens.test.ts` pass. Checked against the founder's own `./scripts/dev.sh` servers, which had taken `:3000` and `:8000`.

## 2026-11-08 — Item 164: Middleware Dashboard on the ADR-104 tokens

**Built** on `feature/164-middleware-dashboard-restyle`, a pure reskin of `/middleware` (34 old classes, now none), same mapping as Suppliers and Price Sync: page title, section titles, `surface` cards on `border-border`, inner tiles and API-call rows on `border-border`, muted text `ink-muted`, the error banner `danger-surface` / `danger-ink`.

**Verified** with Playwright, light and dark at 1440 px and light at 390 px (no horizontal scroll), against the founder's `./scripts/dev.sh` servers. `tsc`, `eslint` and `color-tokens.test.ts` pass.

## 2026-11-08 — Item 164: Payment Gateways on the ADR-104 tokens

**Built** on `feature/164-middleware-payment-gateways-restyle`, a pure reskin of `/middleware/payment-gateways` and its Edit modal (33 old classes, now none), same mapping as Suppliers: the card title takes `text-section-title`, the modal's inputs the kit input's colours and `focus-ring` outline, the Configured / Not configured pills `success-*` / `neutral-*`, errors `danger-*`.

**Verified** with Playwright, light and dark at 1440 px with the Edit modal open, and light at 390 px (no horizontal scroll). `tsc`, `eslint` and `color-tokens.test.ts` pass. **Seen, not changed:** this modal and the supplier Edit modal are narrow (about 210 px of input), so placeholders cut off; that is layout, outside a reskin.

## 2026-11-08 — Item 164: Validators on the ADR-104 tokens

**Built** on `feature/164-middleware-validators-restyle`, a pure reskin of `/middleware/validators`, `ValidatorCard`, `CreateValidatorModal` and `ValidateByGamePanel` (about 80 old classes, now none). Beyond the shared mapping: the country chips and their arrow on `cyan-50` / `cyan-ink` / `cyan-600`, the text actions (Edit, Game ID Checker, Test this validator) `text-cyan-ink` with an underline on hover, Delete `danger-ink`, the dashed "Add Country" button and add form on `border-border-strong`, result pills on the `success` / `danger` / `warning` surface and ink pairs, the inline inputs on `border-border-control`.

**Verified** with Playwright, light and dark at 1440 px and light at 390 px. The local dev DB has no validator, so the cards were rendered from a mocked `GET /api/middleware/validators` (route interception, nothing written to the DB) with the add-country form open. `tsc`, `eslint` and `color-tokens.test.ts` pass.

## 2026-11-08 — Item 164: Payment Methods on the ADR-104 tokens

**Built** on `feature/164-middleware-payment-methods-restyle`, a pure reskin of `/middleware/payment-methods` (42 old classes, now none): shared mapping, the fee inputs on `border-border-control` / `surface`, the active category chip `bg-cyan-600 text-on-cyan` (the kit's primary button colours), the failed test result `danger-ink`.

**Verified** with Playwright, light and dark at 1440 px and light at 390 px. `tsc`, `eslint` and `color-tokens.test.ts` pass. **Seen, not changed (layout, not colour):** at 390 px the channel table is clipped inside its card (Last Test and Actions are off to the right) and the category chips wrap.

## 2026-11-08 — Item 164: Developer / API Tester on the ADR-104 tokens

**Built** on `feature/164-middleware-developer-tools-restyle`, a pure reskin of `/middleware/developer-tools` (54 old classes, now none): shared mapping, the selected endpoint `bg-cyan-50 text-cyan-ink` (the sidebar's active look) with `hover:bg-subtle` on the rest, the Create Order note `warning-surface` / `warning-ink`.

**Verified** with Playwright, light and dark at 1440 px with Digiflazz chosen and an endpoint selected, and light at 390 px. `tsc`, `eslint` and `color-tokens.test.ts` pass. The screenshot script now signs in once and reuses the session: the fifth sign-in in a few minutes hit the login rate limit.

## 2026-11-08 — Item 164: Backups on the ADR-104 tokens

**Built** on `feature/164-middleware-backups-restyle`, a pure reskin of `/middleware/backups` (68 old classes, now none), the shared mapping only: stat cards and the table card on `surface` / `border-border`, muted labels and cells `ink-muted`, the error banner `danger-*`.

**Verified** with Playwright, light and dark at 1440 px and light at 390 px. The local DB has no backup, so the table showed its empty state; its row cells use the same classes as the header and stat cards. `tsc`, `eslint` and `color-tokens.test.ts` pass.

## 2026-11-08 — Item 164: Request Logs on the ADR-104 tokens

**Built** on `feature/164-middleware-request-logs-restyle`, a pure reskin of `/middleware/request-logs` and its View modal (85 old classes, now none), the shared mapping, plus `text-ink` on the payload `<pre>` blocks: they had no text colour of their own, the gap ADR-104 found rendering black on a dark card.

**Verified** with Playwright, light and dark at 1440 px with a log's View modal open (52 local rows, payloads readable in both themes), and light at 390 px. `tsc`, `eslint` and `color-tokens.test.ts` pass.

## 2026-11-08 — Item 164: Sandbox on the ADR-104 tokens

**Built** on `feature/164-middleware-sandbox-restyle`, a pure reskin of `/middleware/sandbox` and `CreateSandboxOrderModal` (about 60 old classes, now none): the shared mapping, the search box on `border-border-control` / `surface` with the `focus-ring`, the back link `hover:text-ink`. The order detail it opens reuses the admin Orders components, which are not touched here.

**Verified** with Playwright, light and dark at 1440 px (page, and the Create Test Order modal open), and light at 390 px. The local DB has no test order, so the list showed its empty state. `tsc`, `eslint` and `color-tokens.test.ts` pass.

## 2026-11-08 — Item 164: Product Manager on the ADR-104 tokens

**Built** on `feature/164-middleware-product-manager-restyle`, a pure reskin of `/middleware/product-manager` and its Link Category, Promote and Repoint modals (about 150 old classes, now none): the shared mapping, the group search and the in-group search on `border-border-control` / `surface` with a cyan focus, the three group tabs on `/orders`' own active style (`border-cyan-600 text-cyan-ink`, `hover:text-ink` on the rest), the back link `hover:text-ink`.

**Verified** with Playwright, light and dark at 1440 px: the group list (21 local groups), a group's Available Packages tab, and the Link Category modal; light at 390 px. `tsc`, `eslint` and `color-tokens.test.ts` pass.

## 2026-11-08 — Middleware dialogs get a width; Payment Methods chips stop wrapping

**Found during item 164.** A `DialogPopup` with no width class sizes to its content, so the supplier Edit modal and the Payment Gateway Edit modal shrank to about 210 px of input and cut every placeholder, while Manage Packages stretched to about 950 px around its one long note. Fifty-seven dialogs in the admin already pass `w-full max-w-*`; ten did not, seven of them under `/middleware`.

**Fixed** on `fix/middleware-modal-and-table-layout`: the supplier Create, Edit and Manage Packages modals take `max-w-lg`; the Payment Gateway Edit modal and the Activate / Deactivate / Delete / backup confirms on the Suppliers and Backups pages take `max-w-md`. On Payment Methods the category chips no longer break "E-Wallet" over two lines (`flex-wrap`, `whitespace-nowrap`).

**Corrected claim.** The Payment Methods table at 390 px was not clipped, as an earlier entry said: the DataTable's own container scrolls sideways (356 px wide, 600 px of table) and the Test button is reachable by a swipe, the same as admin `/orders`.

**Verified** with Playwright: dialog widths 512 / 448 px at 1440 (inputs 474 and 410 px, from about 210), 326 px at 390; every chip 32 px tall at 390. `tsc` and `eslint` clean. **Left:** the four dialogs without a width outside `/middleware` (admin Resellers, admin Suppliers, Affiliates, Partner Applications).

## 2026-11-08 — `DialogPopup` gets a default width

**Why.** The entry above fixed the seven width-less dialogs under `/middleware` one by one; the cause is the kit itself, where a `DialogPopup` with no width class sized to its content. The founder asked for the fix at the source so a new dialog can't repeat it.

**Built** on `fix/dialog-popup-default-width`: `admin/src/components/ui/dialog.tsx` now defaults to `w-full max-w-md`. A caller's own `max-w-*` still wins (`cn` runs tailwind-merge). The portal copy was regenerated with `node scripts/sync-shared-files.mjs`; the portal uses no `DialogPopup` today, so no portal screen changes. Affected in the admin: the four width-less dialogs left outside `/middleware` (Resellers: Deactivate, Change tier, Delete tier; Suppliers: Reply SLA, Deactivate; Affiliates: Deactivate, Delete, Delete tier; Partner Applications: Approve / Decline / Delete), now 448 px instead of content width, and the Request Logs View modal, which passed `max-w-2xl` without `w-full` and now opens at 672 px instead of about 310 px, so payloads read without wrapping.

**Verified** with Playwright at 1440 and 390 px: each of those dialogs 448 px (Request Logs 672 px), 326 px on a phone; a dialog with its own width keeps it (Repoint `max-w-lg` 512 px, Promote `max-w-md` 448 px). `tsc` for admin and portal, `eslint` clean.

## 2026-11-08 — Admin Orders components: the last old colour classes

**Built** on `style/orders-components-tokens`, the same pure reskin as item 164 for the three admin Orders components the Middleware Sandbox reuses: `DeliveryLogsTable`, `OrderDetailCards` and `ResendDeliveryModal`. They were already on `ink` / `surface`; the cards, table rules and Resend option boxes still drew `border-gray-*` / `divide-gray-*` with `dark:` pairs, now `border-border` / `divide-border`. The negative profit figure and the Resend error banners move to `danger-ink` / `danger-surface`, and the Resend warning banner to `warning-surface` / `warning-ink` (its dark text had been `orange-400`).

**Verified** with Playwright, light and dark, on a failed order's detail page (cards and Delivery & Activity Logs). The Resend modal could not be opened locally: every failed order in the dev DB is already compensated, so the button is hidden; its change is borders and banners only, with the mapping checked on the other pages. `tsc`, `eslint` and `color-tokens.test.ts` pass.

## 2026-11-08 — Released #755: middleware restyle, panel switch link, dialog widths

**Released on the founder's go-ahead.** `main` `261345ca`, box release `82402542`, CI (all jobs) and `deploy` green, Vercel production rebuilt admin and portal from that commit (storefront and docs skipped by their ignore step, as intended). Ships #738 to #754: item 164 (#740 to #751), the panel switch link (#739), the middleware dialog widths and Payment Methods chips (#752), the `DialogPopup` default width (#753) and the admin Orders detail components (#754). Frontend only: no backend change, no migration, no env change. 0 paid orders in flight before it.

**Checked after release.** `/api/health` ok, `php_code_cache` true; `/api/health/whatsapp` 200; storefront, admin, portal, docs and onboarding all 200; the box's `current` at `261345c`; 0 paid orders in flight.

**Gotcha.** Thirteen page PRs that each appended a build-log entry would conflict with each other at every merge and re-run CI each time. Moving the entries to one docs PR left the page PRs touching disjoint files, so they merged in any order on their first green run.

## 2026-11-08 — ADR-139 proposed: reseller milestone rebate and automatic refund (docs only)

The founder reopened item 161. Asked why they won't switch, agents said our price is about the same as their leader's (both at the floor). They proposed a rebate themselves: reach a monthly milestone, claim it, credited to Purchase Balance, "like a battle pass".

**Decided with the founder:**
- A monthly ladder (RM500 to RM20k to start), with a fixed RM reward per step that is set per tier.
- The reward is funded from a share of our own actual profit, rising from 10% to 35%. We keep at least 65%, and a 50% hard ceiling sits in code.
- The reseller claims every step in the portal only.
- Claims are open until the end of the next month.
- Unspent rebate is forfeited at account closure.
- Tier changes take effect on the 1st.
- The ladder is changed only with MFA, effective next month.
- The automatic refund is only for a supplier-confirmed Failed. Tier SSS is the one at the leader's price.

**Code trace:** three read-only forks (pricing; ledger; readers and accounting), with the top findings re-checked in code. It found:
- Closure would have cashed out rebate credit.
- A `wallet_topup` row reads as bank cash in the Register.
- No profit report or month-close line sees a wallet credit.
- Delivery rewrites `platform_profit` from real cost. Production has a delivered wallet order at −13 sen.
- Splitting the wallet into two ledger accounts would touch about 26 balance readers. Unspent rebate is therefore derived by replaying the ledger instead.

**Production, read-only:** 3 resellers, 1 buying (Tier SS, RM446.56 sales / RM12.69 profit to date). 4 failed wallet orders and 4 manual refunds (RM430.04). 0 late contradicting supplier results in 138 orders.

**Grill (three rounds, 18 questions) and simulation, same day:**
- **Simulation:** read-only on production through `OrderPricingResolver::resolveResellerWallet()`.
  - 9 of 11 delivered wallet orders reproduce their selling price exactly.
  - Actual profit is 94.56% of formula profit, which is real-cost drift.
  - Repricing 100 delivered orders shows no rounding loss at any tier.
  - So the ladder top share went from 35% to 32% under a 35% cap. We keep 70% at RM10k and 68% at RM20k.
- **Final stress test, eight findings folded in:**
  - one entitlement function, so an early cut self-heals and is never "claim late, get more";
  - tier change history, so finalise reads the right tier;
  - the claim deadline is checked by date;
  - the refund sweep skips sandbox orders and closed resellers, which `settle()` refuses;
  - a `WalletEntryType` enum makes the unspent-rebate replay exhaustive;
  - step notifications come from a scheduled task, not the money path.
- **Accepted.** The accountant confirms the accounting decisions before PR 3.
- **Accountant review, same day.** The accountant confirmed the treatment: a revenue reduction recognised at reach; a claim moves the liability to Purchase Balance; revenue is recognised on use. Two changes:
  - an unclaimed reward that expires reverses the revenue reduction rather than being breakage;
  - the open month's rebate line is live from `RebateEntitlement` rather than provisional.

  Added:
  - versioned programme terms, accepted at the first claim;
  - per-row calculation inputs and a CSV export, for income tax.

  The founder confirmed there is no marketing obligation and that the business charges no SST (a scope check with a tax agent is company-level, not a gate). ADR-139 addendum; PR 3 unblocked.
- **Programme terms version 1 approved by the founder** and recorded verbatim in the ADR-139 addendum. Clause 2 was aligned with mid-month claims and the monthly cap. Clause 7's notice promise is enforced in code: a ladder version must be saved at least 7 days before its month, and saving it notifies every eligible reseller (portal banner and bot).

## 2026-11-08 — ADR-139 PR 1: automatic refund of a Failed wallet order

Branch `feature/auto-refund-failed-wallet-orders`, into `staging` (not released).

**Shipped:**
- `app:refund-failed-wallet-orders` runs every minute (`CRITICAL`, `wallet-order-auto-refund`). It settles each uncompensated Failed wallet order through `OrderSettlementService::settle()`, with the system as actor.
- It reuses `Order::needsAction()`. It skips sandbox orders, closed resellers (which `settle()` refuses) and orders with a late supplier Success.
- Each order is isolated, and an email goes out on the third failed run.
- `settle()` accepts a null actor for wallet orders only. The `wallet_refund` row now records `created_by`.
- `finalizePendingDelivery()` emails every admin on any late contradicting Success.

**Found while building:** a Success arriving after Failed but before the sweep would have been refunded on top of a possible delivery. It is now skipped and alerted (ADR-139 PR 1 addendum).

**Tests:**
- 7 new, using a sqlite trigger for a real failure inside `settle()`.
- Full suite: 3,837 passed.
- The concurrency suite was not run: no locking changed, and `settle()`'s lock is already proven by `RefundToWalletConcurrencyTest`.
- Production has 0 uncompensated Failed wallet orders (all 4 refunded by hand), so the first run after release refunds nothing old.

## 2026-11-08 — ADR-139 PR 2: wallet tier changes take effect on the 1st; markup fixed per tier

Branch `feature/reseller-tier-from-the-1st`, into `staging` (not released).

**Shipped:**
- `reseller_wallet_tier_changes`, backfilled with each reseller's current tier. The local dev DB has 1 row for 1 tiered reseller after a plain `migrate`.
- `ResellerTierChangeService` is the one writer. A reseller's first tier is immediate. A later change waits for the next 1st (KL); a new choice replaces it and the current tier cancels it.
- An hourly `CRITICAL` `app:apply-reseller-tier-changes`.
- The admin list shows "→ Tier X from 1 Dec 2026". The Change Tier dialog says when the change takes effect.
- A tier's markup is fixed after creation: the API answers 422 with guidance, and the admin edit form disables the field. The founder chose this over hard-coding five 1–5% tiers.
- A tier that a pending change points to can't be deleted.

**Gotcha:** the sqlite `date` cast stores a time, so a string `<=` on `effective_on` missed midnight on the 1st. The comparison uses `whereDate`. Caught by a test pinning 23:59 and 00:00 KL.

**Verified:**
- `php artisan test`: 3,844 passed. Two old tier tests that edited markup now assert the refusal.
- Admin: `tsc`, lint, 22 unit tests and a build.
- Browser, on a local built admin and backend with a throwaway admin, deleted afterwards:
  - scheduled Scenario Reseller → Tier SS and saw it pending until 1 Dec 2026;
  - the Edit Tier form shows markup disabled with the note;
  - cancelled the test change by choosing the current tier, leaving 0 pending rows.
- Fixed an outdated "direct FK assignment" line under Wallet tiers.

## 2026-11-08 — ADR-139 PR 3a: rebate core (ladder, entitlement, claim)

Branch `feature/rebate-core`, into `staging` (not released). It changes no live behaviour: production has no ladder version, and nothing calls the claim yet.

**Shipped:**
- Ladder versions with notice and on-paper ceiling validation.
- `RebateEntitlement`, the one function every amount comes from.
- `RebateClaimService`: claims in order, pays `min(ladder at k, cap) − paid`, accepts terms once per version, and refuses after the window, for an inactive or ineligible account, or for a login of another account.
- `WalletEntryType` across all wallet writers.
- `wallet_rebate` added to the ledger dedupe backstop.

**Build decisions** (ADR-139 PR 3a addendum): the ladder grid is JSON (5 tables, not 6); claims go in order; the master switch is the ladder itself.

**Verified:**
- `php artisan test`: 3,858 passed (14 new).
- On MySQL, `RebateClaimConcurrencyTest` gives one credit from two simultaneous claims. `RefundToWalletConcurrencyTest` and `OrderSettlementConcurrencyTest` still pass after the dedupe-key rebuild.
- Full local concurrency run: 45 of 49 passed. The 4 errors are `BackupRestoreMysqlTest`, which needs `mysqldump`; that tool is not installed on this machine but is in CI's `backend-concurrency` job.
- Local dev DB migrated with a plain `migrate`.

**Gotcha:** the docker MySQL still held the `llm_report_*` views from an earlier killed run (backend AGENTS gotcha 8). Dropped them before the run.

## 2026-11-08 — ADR-139 PR 3b-i: rebate month end and the closure forfeit

Branch `feature/rebate-month-close`, into `staging` (not released). It is inert until a ladder version exists.

**Shipped:**
- `RebateMonthService`: finalise writes available rows and the adjustment row, and alerts on a cut, an adjustment or an overpayment. Expire dates each row at its deadline.
- Two daily `CRITICAL` tasks.
- Claims of finalised and adjustment rows.
- `UnspentRebate`, the ledger replay, which fails closed on an unknown type.
- Closure forfeits unspent rebate, refunds only the rest, and lapses unclaimed rewards.
- `close-check` returns the refund. The admin close modal shows it, with the forfeited part explained.

**PR 3b is split in two** (ADR-139 addendum). 3b-ii carries the accounting lines together with their admin display.

**Verified:**
- `php artisan test`: 3,865 passed (7 new).
- `ResellerClosureConcurrencyTest` and `RebateClaimConcurrencyTest` pass on MySQL.
- Admin: `tsc`, lint and 22 unit tests pass.
- Local dev DB migrated.
- Browser, on a local build with a throwaway admin deleted afterwards: the close modal shows "Balance to refund" from `refund_sen`.


## 2026-11-09 — ADR-139 PR 3b-ii: rebate accounting lines and the month-close gate

Branch `feature/rebate-accounting`, into `staging` (not released). It is inert until a ladder version exists, and no ladder can exist before PR 4.

**Shipped:**
- `RebateLiability`: the revenue reduction, breakage and payable. A month not finalised yet is live, through `RebateMonthService::liveTotalSen()`. That method shares finalise's reseller set and skip rule, so finalising moves no figure.
- Monthly Summary: "Less: reseller rebates" (subtracted in operating profit) and "Reseller rebate breakage" (added).
- Cash Position: the rebate payable claim.
- Reports profit Bridge: both rows.
- A month cannot close while its ladder month is not finalised.
- Admin: definitions for both lines, the cash-equation row and the Bridge rows.

**Found in the code trace, fixed here:** making a reseller eligible again after its month was finalised let a claim add a new row to that month, which may already be closed (invariant 2). A finalised month now takes no claim for a step finalise did not write.

**Moved to PR 4** (ADR-139 2026-11-09 addendum): decision 24's rebate line in Reports' profit totals, export and channel split; the Customer Analytics and Report Assistant labels; the notify tasks. The founder's answers on PR 4's three open points are recorded in the same addendum.

**Verified:**
- `php artisan test`: 3,874 passed (9 new).
- `RebateClaimConcurrencyTest` passes on MySQL.
- The new `FinancialScenario` step: the October gap does not move when a ladder starts, a step is claimed, the account closes with rebate unspent, or the month is finalised; the Bridge has nothing unexplained.
- Admin: `tsc`, lint and the unit tests pass.
- Browser, on a throwaway sqlite backend and admin deleted afterwards:
  - the Summary shows "Less: reseller rebates RM 6.00";
  - September's cash equation shows "Reseller rebates earned, not claimed yet RM 6.00", with the envelopes RM 6.00 lower;
  - the Reports Profit bridge shows the "−" rebate row;
  - the close is blocked with the rebate message until finalise runs.
- No migration in this PR.

**Gotcha.** The first scenario run expected a 25 sen reward and got 21: the scenario reseller's October profit is 43 sen, so the 50% cap applied. That is correct behaviour; the test's rewards were lowered below the cap.

**Same day, a review before PR 4.** The founder asked whether the rebate's money logic and code met our bar before PR 4 started. Three read-only forks ran, one per layer (AGENTS.md money-critical review). None found wrong money; I verified each finding against the code before fixing it. Fixed test-first in the same PR (#762), details in the ADR-139 2026-11-09 addendum, item 6:
- A ragged ladder could make a column fall to 0 (reachable only once PR 4's editor exists).
- A late expiry run could date reversals into a closed month. Month close now also waits for expiry.
- A claim at midnight could put the payable and the balance in two months. Both now take the ledger row's instant.
- Hygiene: one home for the claim deadline; month close asks the rebate module; constructor injection; no repeated claimed-sum query; a `ponytail:` scale note; the wallet-balance text.

Read-only production check: wallet ledger types are only `wallet_topup`, `wallet_debit` and `wallet_refund`.

`php artisan test`: 3,877 passed (3 new). The MySQL concurrency tests for rebate and closure pass. Admin `tsc` and lint pass.

## 2026-11-09 — ADR-139 PR 4a: rebate admin and portal API, bot messages

Branch `feature/rebate-api`, into `staging` (not released). PR 4 is split in three (4a API, 4b admin screens, 4c portal screens; ADR-139 addendum). Nothing reaches resellers yet: no ladder can be saved without 4b's screen, except by a super admin with MFA calling the API directly.

**Shipped:**
- Admin endpoints: ladders (list, plus save behind the step-up, which sends the notice), month view, CSV export, and an eligibility switch behind the step-up with a recorded reason.
- Portal endpoints: the Rebate page data, with the reseller's own tier only and no share, and the claim.
- Rebate detail on statement lines.
- `RebateNotifier` and the `rebate-progress-notify` task (`ROUTINE`, every 10 minutes).
- The `reseller_rebate_eligibility_changes` table.

**Found while building:**
- `SendCustomerWhatsAppJob` sent every bot-session message through `SupplierGroupNotifier::prepare()`, which skips anything without an order, so rebate messages would never have been sent. It now routes by event.
- The `dedupe_key` backstop applies only when `reason` is null, so the statement label is read from the milestone row rather than stored as a reason.

**Gotcha:** a Pint run given the whole `app/` folder reformatted about 30 unrelated files. I reverted them before committing; run Pint only on changed files.

**Verified:**
- `php artisan test`: 3,888 passed (11 new).
- Local dev DB migrated.
- A live check with `curl` on a throwaway sqlite backend (deleted afterwards): the page, the terms refusal, a claim (+RM1.00), a repeat refused, the statement detail, and 401 without a portal login.

**For the founder:** the three draft bot messages are in the ADR-139 PR 4a addendum, item 4.

## 2026-11-09 — ADR-139 PR 4b: rebate admin screens and the Reports rebate line

Branch `feature/rebate-admin-screens`, into `staging` (not released). The founder approved the three bot messages as drafted. A ladder can now be saved from the panel, behind the MFA step-up; nothing reaches resellers until the first one is saved.

**Shipped:**
- Admin → Reseller Rebate:
  - Ladder tab: the next and in-force versions, the no-column warning, all versions, and the New version dialog (effective date, ceiling flag, step-up).
  - Month tab: cards, standings, rows and Export CSV.
- Resellers: a Rebate button for eligibility (reason and step-up required) and a "rebate off" marker.
- Wallet history: rebate labels.
- Reports: a rebate line on the summary, the wallet channel, the CSV and the PDF; owner profit is labelled as before rebates.
- Customer Analytics and the Report Assistant prompt: profit labelled as before rebates.

**Found in the browser check:** a live month's "Waiting to be claimed" read only finalised rows and showed RM0.00 while a step was unclaimed. It now uses entitlement minus claimed, the payable's own figure.

**Gotcha:** the admin's own unit test refuses a `JSON.stringify` body passed to `apiFetch`, which encodes the body itself.

**Verified:**
- `php artisan test`: 3,890 passed (2 new, plus one new assertion).
- Admin: `tsc`, lint and 22 unit tests pass.
- Browser (Playwright, throwaway backend and admin deleted afterwards): ladder page, editor, month view, CSV, eligibility dialog, wallet label and Reports note. Saves answered `mfa_not_enabled` for the MFA-less throwaway admin, as designed; the request payload was correct.

## 2026-11-09 — ADR-139 PR 4c: rebate portal screens

Branch `feature/rebate-portal-screens`, into `staging` (not released). This completes ADR-139's build. Nothing reaches a reseller until the founder saves the first ladder, and that waits for this PR to merge.

**Shipped:**
- Portal → Rebate (`/rebate`, in the reseller nav under Orders & money):
  - the notice for a new table;
  - unspent rebate;
  - this month and last month, each with purchases counted, progress to the next target, and every step with its reward and state;
  - Claim buttons in step order, plus "Claim all";
  - the terms dialog on the first claim;
  - reward history and the programme terms.
- Dashboard: a Rebate card showing "RM X more to reach RM Y" and a Claim button with the count, which also carries the new-table notice. It loads on its own, so a rebate error never blanks the dashboard.
- Statement: `wallet_rebate` is labelled "Rebate" with its step and month, and `wallet_rebate_forfeit` is labelled "Rebate forfeited".
- Backend: the portal history rows now carry `threshold_sen`, so a history line reads like its statement line.

**Verified:**
- Rebate tests: 45 passed (one new assertion).
- Portal: `tsc`, lint, build and 22 unit tests pass (`rebate.test.ts` is new, and `format.test.ts` gains the rebate labels).
- Browser (Playwright, on a throwaway sqlite backend and the portal dev server, both deleted afterwards). Seeded with one SS reseller, a 4-step ladder from October, a new table from December, October finalised at RM1,600 and November at RM2,000:
  - the first claim opened the terms and sent `accept_terms: true`;
  - "Claim all" sent steps 1 and 2 in order;
  - the second claim did not ask for the terms again;
  - unspent rebate reached RM7.00;
  - the statement labels were right;
  - checked in light and dark, and at 390px with no horizontal overflow.

## 2026-11-09 — ADR-139 money review: no wrong money; three small fixes

The founder asked whether rebate profit, accounting and ledger are right. Three read-only forks ran in parallel (entitlement/claim/ledger, accounting, downstream readers). Each simulated through the real services on a throwaway sqlite DB.

**Found no wrong money.** Proven with numbers:
- an early capped claim pays itself back, and finalise writes an adjustment;
- double claims are blocked, and claims must go in order;
- closure forfeit + closure refund = balance;
- over three months, Σ reduction = Σ claimed, and the payable rolls forward exactly and equals Cash Position;
- the cash equation does not move;
- operating profit = profit − rebate + breakage, and the Bridge's unexplained difference stays 0;
- a claim at 00:00 KL lands in the right month;
- no reader counts a rebate as a cash top-up, a CHIP payment or a receipt.

**Accepted risks, unchanged** (ADR-139 by design):
- an overpayment against the final cap after a negative-profit order is not clawed back; admins get an email;
- turning eligibility off is final for a month that has ended.

**Fixed, branch `fix/rebate-report-labels`:**
- The Reports "Before reseller rebates" note now says which months count: each month whose 1st is in the range, less lapses. A range such as 2–30 Nov shows RM0 by design.
- Partners tab: "Reseller rebates, net of lapsed rewards". The figure can be negative.
- Transaction Register: rows for a rebate claim (`reseller_rebate`, with its step and month) and a closure forfeit (`reseller_rebate_forfeit`). Before, the year-end record had no trace of either. `RebateRegisterTest` covers it.
- `RebateLiability::payableSen`: a `ponytail` note. For an open month it uses entitlement as of now, which is exact for every reader today (month-end only).

**Gotcha:** `RebateFixtures::delivered()` writes no `paid_at`, so any test that reaches the register's `orderRecognitionRows` must set one. Real orders always have one.

**Verified:**
- `php artisan test`: 3,891 passed.
- Admin: `tsc`, lint and 22 unit tests pass.
- Pint is clean.

## 2026-11-09 — ADR-139 decision 46: ladder simulation re-run

The re-run happened before the first ladder is saved. Result: decision 3's grid is used as is. Numbers are in the ADR-139 addendum.

**Production, read-only:**
- Nothing new since 2026-11-08: 11 delivered wallet orders, none in November, and every month below RM500.
- SS drift is 97.6%, so the 94.56% design figure stays.

**Grid through the real `RebateEntitlement`** (a scratch test on sqlite, deleted afterwards):
- At 94.56% drift, none of 30 tier/step points is cut, and we keep 66.2–94.5%.
- At 90% and 85% drift, the 35% cap cuts the top steps and holds us at ≥ 65%.

**Gotcha:** on `main`, `monthBounds()` lives on `MonthlyAccountingSummaryService`, not `RecognisedRevenue`. A production script written against staging failed on it first.

**#765 (portal screens) and #766 (review fixes) were merged to `staging`.**

## 2026-11-10 — Cyber Bumblebee dark: the "Bumblebee Dark v3" palette

Branch `feature/bumblebee-dark-v3-palette`, into `staging`. The founder sent a Figma redesign of Bumblebee dark and asked for its colours only; layout, borders and shadows are unchanged. `tokensDark` in `storefront/src/lib/theme-presets.ts` was changed and synced to the portal copy. Affects PajoStore and FixFast Demo (production branding, read-only); PekanGame stays on Digital Architect light. Decision and deviations: ADR-113 2026-11-10 addendum. DESIGN.md's Tinted-Surface Rule names the exception.

**Constraint found:** `--color-ink` is both the 2px border and the bottom-nav pill whose labels are `surface`. A hairline-dark ink (v3's 8% white) would put the labels at 2.4:1, so ink is `#8C8983`.

**Verified** (throwaway e2e backend with the primary brand on Bumblebee dark; production storefront build on :3011; both deleted afterwards):
- Before and after screenshots of home, order and track order, at 1440 and 390. Layout is identical and there is no horizontal overflow.
- Measured with `getComputedStyle`: heading 15.8:1, muted 7.0:1.
- Computed: bottom-nav active label 5.4:1 (was 4.8). Inactive label 2.9:1 (was 2.7), below 4.5 before and after.

**Then the bottom nav** (founder reviewed the mobile screens and approved it):
- What was wrong: the grey `ink` pill looked off on charcoal, and the More drawer's backdrop lightened the page.
- The fix: `BottomNav` now reads `nav` / `on-nav` / `on-nav-muted` / `scrim`. Their defaults are the old ink and `surface`.
- Bumblebee dark: a charcoal pill, the active label in yellow (11.3:1), the inactive labels at 7.0:1, and a black scrim.
- Regression check with `getComputedStyle` on each preset: Digital Architect light and Bumblebee light are identical to before (6.32 / 15.64 and 6.52 / 16.18).
- Red Giants dark is unchanged (2.69 / 4.55). The same four tokens would fix it and the other dark presets.

**Gotchas:**
- A `git worktree` with a symlinked `node_modules` fails `next build`, because Turbopack refuses a `node_modules` outside its root. Build in the real checkout instead.
- Switching a throwaway brand's preset needs `.next/cache/fetch-cache` cleared and both servers restarted; this is the "scripted loop did not switch" mystery in the verification recipes. In zsh, pass `${=t}` to split a "preset mode" pair; a plain `$t` writes one bad value, which the storefront's schema check rejects, so it falls back to the default.
## 2026-11-10 — Release #769: ADR-139 reseller rebate and the Bumblebee Dark v3 palette

The founder asked for it. `staging` → `main`, merge `aac82832`, carrying #756–#768.

**Pre-release checks (production, read-only):**
- 5 new migrations;
- `ledger_entries` holds 267 rows, so the `dedupe_key` rebuild is instant;
- 0 failed wallet orders would be picked by the auto-refund sweep (the 4 Failed are already refunded);
- no pending migrations; health 200.

**CI incident:**
- The `main` run's `backend-concurrency` died after 50 minutes with "The hosted runner lost communication with the server". It was a GitHub runner failure: the same code passed on `staging` 20 minutes earlier, in 19 minutes.
- `deploy` was skipped as a result. The backend stayed on the old release (`/api/reseller-portal/rebate` answered 404) while Vercel had already deployed the frontends from `main`. No money was at risk: no ladder existed and no claim was possible.
- `gh run rerun --failed`: concurrency passed in 15 minutes and `deploy` succeeded.
- The workflow has no `timeout-minutes`, so a hung job would hold a deploy for up to 6 hours. A 30-minute limit is proposed.

**Checked after release (production, read-only):**
- `current` is `aac8283`. The 5 migrations ran in batch 69.
- `reseller_wallet_tier_changes` backfilled 3 rows (resellers 1, 2, 3 on tiers 4, 1, 5).
- `dedupe_key` includes `wallet_rebate`, and its unique index is present; 267 ledger rows, unchanged.
- 0 ladders and 0 milestones; all 3 resellers are eligible.
- Scheduler: `refund-failed-wallet-orders` (every minute), `apply-reseller-tier-changes` (hourly), `expire-rebate-rewards` 16:05 UTC and `finalize-rebate-months` 16:10 UTC (00:05 and 00:10 KL), `notify-rebate-progress` every 10 minutes.
- `/api/health`: ok (database, queue, Horizon, scheduler). `/api/reseller-portal/rebate` answers 401 (live).
- The laravel.log errors are all from before the release: 9 Nov 03:38 memory exhaustion (admin user 2) and a 13:02 Plunk 422.
- Storefront: pajostore.com serves `--color-surface: #111113` and `--color-nav: #18181B`; ohahastore.com (Bumblebee light) still `#fff8e1`. The Vercel production deployments for admin, portal and storefront are on `aac8283`.

**Next:** the founder saves the first ladder by 24 Nov (KL) for 1 Dec, then announces it to agents.

**Separate findings:**
- The 9 Nov dependency audit lists 7 advisories in 4 Symfony packages (for example `symfony/http-foundation`, `symfony/filesystem`).
- A memory-exhaustion error hit an admin request on 9 Nov at 03:38.

## 2026-11-10 — Release follow-ups: report date bounds, CI timeout, Symfony advisories

Branch `fix/release-followups`, into `staging`. These are the three findings from the #769 release check.

1. **Production memory exhaustion (9 Nov 03:38 UTC, four times in 45 s, admin user 2).**
   - nginx's error log names the request: `GET /api/reports/trend?from=0002-10-01`, a year typed half-way into the date filter.
   - The trend zero-fills one row per day, so it tried to build about 740,000 rows and ran out of the 512 MB limit.
   - `ReportService::dateRangeFromDates()` is the one parser behind 12 admin date filters (Reports, Orders, Transaction Register, supplier transfers, Joki, Campaigns, request log, customer analytics, settlement reconciliation). It now accepts only a strict `Y-m-d` between 2020 and 2100. Anything else is a 422 keyed by the field.
   - `ReportDateRangeBoundsTest` is new. Its HTTP case reproduced the crash before the fix: the test process died silently.
2. **CI:** `backend-concurrency` gets `timeout-minutes: 40`. It normally takes 14–26 min; GitHub's default limit is 6 h.
3. **`composer audit`** had failed on `main` since 9 Nov: 7 advisories in `symfony/filesystem`, `http-foundation`, `http-kernel` and `routing`. Those four were updated with their dependencies, all patch releases within 8.1 (for example `http-foundation` 8.1.9 → 8.1.11). The audit is now clean.

**Verified:** `php artisan test` 3,893 passed; Pint is clean; `composer audit` reports no advisories.
## 2026-11-10 — CS knowledge: the Merge modal opens on the entry the AI suggested (ADR-136)

Branch `fix/cs-merge-suggested-entry`, into `staging`. Admin only.

**Found (founder, while reviewing suggestions).** A suggestion row said "Looks like entry #8: consider Merge". But the Merge modal's dropdown showed no numbers and always opened on the first approved entry (#9, the membership entry), so the founder couldn't tell which entry the AI meant. The modal never read `ai_entry_id`.

**Checked first (prod, read-only):** all 11 earlier merges that had an AI match went into the matched entry. Nothing was merged into the wrong entry.

**Fixed:**
- `MergeSuggestionModal` now starts on `ai_entry_id` and marks it "(suggested)". Every option shows `#id`.
- "New way of asking" starts from the AI's cleaned question, falling back to the first line of the raw message as before. The raw message was a chat line like "ok fhm2 tq ah".
- The row hint names the entry: "Looks like entry #8 (Order status: Boleh tolong check order saya?)".

**Verified:** `tsc` and `eslint` pass. Not checked in a browser.

## 2026-11-10 — Storefront dark theme: modal backdrops dim the page; payment logos on a white plate (ADR-113)

Branch `fix/storefront-dark-readability`, into `staging`. Storefront only. The founder reported both from the Bumblebee Dark v3 storefront.

1. **The modals blended into the page.** All four storefront modals used `bg-ink/50` as the backdrop: GameInfo (the "How to Buy" notes), MembershipTransition, RateOrder and Review. Under the Light-Ink Rule a dark preset's `ink` is the light stroke #8C8983, so the backdrop lightened the page to mid-grey instead of dimming it. ADR-113's 2026-11-10 addendum had already given the More drawer the `scrim` token for this reason; the modals were missed. They now use `bg-scrim/50`. In a light preset `scrim` is `ink`, so nothing changes there.
2. **Bank and DuitNow logos were hard to see.** These logos are drawn for white, and the tiles take the theme's dark surface. In `PaymentChannelPicker` each FPX bank logo and each channel icon now sits on a white plate inside its tile. The tile keeps the theme's border, hover and selected colours. This is the same plate the homepage payment cards already use.

**Verified (e2e harness, Bumblebee dark and light tokens injected, screenshots):**
- Dark backdrop computes to `oklab(0 0 0 / 0.5)`; light is unchanged (ink at 50%).
- Every bank logo is readable on dark. The selected tile still shows its yellow border.
- Contrast in the notes popup on dark (unchanged colours, now on a dimmed page): description #A6A39B on #111113 7.5:1, note text on the box 10.8:1, the heading 7.8:1.
- `tsc` and `eslint` pass.

3. **The other dark presets.** Red Giants, Emerald and Cobalt set no `scrim`, so their dark mode still fell back to their light-grey `ink`. Each `tokensDark` now sets `--color-scrim: #000000` (the founder's go-ahead). Only popup backdrops change, in dark mode only: the four modals and the More drawer. No brand on prod uses these presets in dark (read-only: Bumblebee dark 2, Bumblebee light 1, default light 1). The portal's copy was regenerated with `sync-shared-files.mjs`.

**Not changed:** the `neo` shadows are a fixed navy (`rgb(25 25 47)`), which shows as a dark-blue block behind a modal on a dark preset. The founder didn't raise it.

## 2026-11-10 — Horizon: run broadcasts off the long-job lanes; wait thresholds fixed; every failed broadcast logged (ADR-047, ADR-048)

Branch `fix/horizon-wait-keys`, into `staging`. Backend only.

**Alert (founder's email, 2026-11-10):** "redis-long:price-sync long wait, 149s".

**Checked first (prod, read-only):**
- Every queue was empty, with no failed job in 24 h.
- The 16 price syncs in the last 8 h all succeeded, each taking 160–208 s.

**Cause:**
- `PriceSyncRunUpdated` broadcast on the `price-sync` queue. That lane has one worker, and it is busy with the sync job that triggers the broadcast, so the "running" update waited out the whole ~3-minute sync. This was traced in code, not seen on screen: the admin Price Sync card could not show a run live. `BackupRunUpdated` did the same on `backups`.
- The wait alert fired at 60 s, not 300 s. `horizon.waits` is keyed by `connection:queue`, both supervisors run on `redis-long`, and the keys said `redis:`. K-4 (orders-combo) was the same slip.

**Fixed:**
1. Both run broadcasts are queued on `default`, beside `OrderStatusUpdated`. A one-worker lane now holds only its own run jobs.
2. The waits keys are now `redis-long:price-sync` and `redis-long:backups`, at 300 s. Only a run queued behind another run can wait there now.
3. `LogFailedBroadcastJob` logs a failed `BroadcastEvent` on any queue. Its list (orders, price-sync, backups) had drifted: `OrderStatusUpdated` broadcasts on `default`, so a failed push to a customer's live order page was never logged.

**Guards:**
- `HorizonQueueCoverageTest` fails when a `waits` key matches no supervisor lane, and when any broadcast event is queued off `default`.
- `LogFailedBroadcastJobTest` covers a failure on `default`.
- All three were red before the fix.

**Verified:** `php artisan test` 3,897 passed; Pint is clean on the changed files.

**Finding, not changed:** a price sync takes 160–208 s against the supervisor's 300 s timeout. The `ponytail` note in `config/horizon.php` names a bulk upsert once a sync passes ~150 s. The founder decides whether to do it next.

## 2026-11-10 — Follow-ups to #772 and #773: no default merge target; dark scrim guard

Branch `fix/cs-merge-and-scrim-guards`, into `staging`. These came from the founder's ask to check today's fixes for patchwork.

- **CS Merge modal (#772):** with no AI match (9 of the 18 pending suggestions on 2026-11-10), the modal still opened on the first entry, one careless click from the wrong merge. It now opens on "Choose an entry…", and Merge stays disabled until one is picked.
- **Dark scrim (#773):** `storefront/src/lib/dark-scrim.test.ts` fails if a dark preset sets no `--color-scrim`, or if a full-screen overlay (`fixed inset-0`) draws its backdrop with `bg-ink/`. It was red with one preset's scrim removed and one modal reverted. It runs in CI's storefront `npm test`.

**Verified:** admin `tsc` and `eslint` pass; storefront `npm test` 10 passed.

## 2026-11-10 — Price sync: the real ceiling is Gamevion's rate limit, not the database

Branch `docs/price-sync-ceiling`, into `staging`. Comment and docs only.

**Question:** the `ponytail` note on `supervisor-price-sync` said to move price sync to a bulk upsert once it passed ~150 s, and whole runs were taking 160–208 s.

**Measured (prod, read-only, run 3517):**
- The 300 s limit applies to each supplier's own `SyncSupplierCatalogJob` (ADR-137), not to the whole run.
- Digiflazz took ~18 s and Gamevion ~152 s, from the batch start to each supplier's `last_price_sync_at`.
- Gamevion's time is nearly all the 1.6 s pause between pricelist calls that its 40-a-minute limit forces, over 74 chosen games: ~117 s of waiting, plus the HTTP calls.

**Decision (founder):** no bulk upsert; it would save seconds. The note now records the measured split and the real ceiling: about 140 chosen Gamevion games per 300 s. Past that, the fix is a warning on the Gamevion game picker. Gamevion is unfunded and unused (PRD §16 item 162), so nothing is near the ceiling.

## 2026-11-10 — Release #777: #770–#776 to `main`

The founder asked for it. `staging` → `main`, merge `b1c2b459`, box release `82558237`.

**Carried:**
- #771: report date bounds (the 9 Nov memory exhaustion), Symfony patches, CI timeout
- #774: run broadcasts on `default`, Horizon `waits` keys, a failed broadcast logged on any queue
- #772, #775: the CS Merge modal
- #773, #775: storefront dark readability and its guard test
- #770, #776: docs

No migration, no env change.

**Pre-release (prod, read-only):** health 200, no pending migrations, 0 paid orders in flight, no sync running, 0 failed jobs in 24 h.

**Deploy:** every CI job and `deploy` passed on the first run (no runner incident this time).

**Gotcha:** the deploy landed at 07:01 UTC, inside the 07:00 price sync. `/api/health` answered 503 (`horizon: false`) for about two minutes. The old Horizon master, told to terminate, kept only the price-sync worker alive until its job finished at 07:03:23; then the new Horizon started every supervisor and health went back to `ok`. That run (3519) succeeded. Nothing was lost, but a deploy in the first ~3 minutes after :00 or :30 will show this.

**Checked after release (production, read-only):**
- `current` → `releases/82558237`.
- `horizon.waits` has `redis-long:price-sync` and `redis-long:backups` at 300.
- `PriceSyncRunUpdated` broadcasts on `default`.
- Every supervisor is running; `/api/health` ok; `/api/health/whatsapp` 200.
- 0 paid orders in flight; 0 failed jobs.
- The four frontends and the onboarding page answer 200; Vercel production for admin, storefront and portal is on `b1c2b459`.

**Not yet seen live:** the Price Sync card showing "running" during a sync (the #774 change); a long-wait alert no longer arriving.

## 2026-11-11 — Full docs audit after release #777; `docs-check` in CI

Branch `docs/audit-2026-11-11`, into `staging`. The founder asked for a full audit and clean-up of every doc before building more. Three read-only reviewers (status docs vs prod, the ADR log, the spec and guidance docs), then every finding re-checked against code or prod before it was written.

**Wrong for partners (fixed first):** the public API guides still said a failed order waits for PekanGame to decide on a retry before an admin refunds it. Since #769 (ADR-139 decisions 26–29) a Failed wallet order is refunded within a minute by `app:refund-failed-wallet-orders`, a refunded order is never resent (`OrderResendService`), and `order.refunded` comes from `OrderSettlementService::settle()`. `wallet.md` "Refunds" is now the one home for the rule; docs revision v1.6.0 (`scramble.php`, spec re-exported, only `info.version` changed). Three backend comments named the admin action as the only `order.refunded` source.

**Stale or contradicting:**
- PRD §14 counts were restated in §14, §15 and §16, so each release left them stale (92 orders on the page, 166 on prod). §14 now keeps who the customers are plus one dated snapshot line, the only home for counts. Added PajoStore (third-party affiliate, payments approved 2026-11-09; the founder confirmed with CHIP on 2026-11-11 that it may collect), the 4 redeemed KOL codes (the page said none), the saved rebate ladder, the current DB cluster, and the missing not-yet-seen lines (first auto-payout ticket due 1 Dec, first rebate month, #774's card and email).
- PRD §1–13 still described a "spend-only" reseller and an admin-only wallet refund, a manual affiliate payout and the ADR-033 FX rate; the Glossary listed three wallet ledger types of six. Lists that code owns (wallet ledger types, pricing bases) now point to their enums instead of restating them.
- §16: item 161 sat in "needs a grill" with build-plan text though released; 104 and 151 waited on triggers that had fired; 158 was "buildable now" while PR 4 waits on the Live gate; closed 28 and 57 were still in Details. Next up is back to one short entry per item, the long bodies are in Details, and the old preambles and replaced text moved verbatim to `build-log-archive.md` ("PRD §16 text moved 2026-11-11"). Recently closed gets a 30-day rule; nothing was older than that yet.
- ADR log: 137–139 said "Accepted" while 125 others say "In force"; ADR-001, 031 and 051 carried a banner-refresh or supersede date as "Last addendum"; seven Standing cells ran to 450–1,100 characters. One Standing vocabulary, the revise rule aligned with AGENTS.md step 7, rows trimmed to current effect + latest revision (the banners keep the history).
- Two founder decisions had no ADR home: ADR-136 2026-11-10 (Live gate kept) and ADR-137 2026-11-10 (the price-sync ceiling is Gamevion's 40/min limit, about 140 games; PRD §16 item 177).

**Prevention:** `scripts/check-docs.mjs` (no dependencies, `--self-test`) and the CI job `docs-check`: every ADR indexed, Standing words, Last addendum against the file's last dated addendum (three heading formats in use), relative links, no closed item in §16 Details. Run against pre-audit `staging` it reports 13 of this audit's findings. Not a deploy need or a required check.

**Gotchas:**
- The addendum-date parser first matched `**Context:** … addendum … <date>` lines (ADR-112, ADR-120); only a bold label or `###` heading that names an addendum counts.
- The first rebate ladder was saved at 18:55 UTC on 2026-11-09, which is 2026-11-10 in Malaysia; the docs use the Malaysia date.

**For the founder (not docs), answered the same day:** item 108, `pajostore.com` was not on the Game ID Checker's Turnstile widget; the founder added it. Digiflazz's balance (IDR 4.73 million, under its IDR 10 million alert since 2026-11-10 16:10 UTC) is known to the founder. Item 161 closed: the ladder save had already sent the announcement to all three resellers' groups (checked read-only).

## 2026-11-11 — SEO meta strategy built (ADR-140 PR 1)

Branch `feature/seo-meta-strategy`, into `staging`. ADR-140 decisions 1–5; the build choices are in the ADR's PR 1 addendum. Not released.

**Built:**
- **Static page meta.** `storefront/src/lib/page-meta.ts` gives home, `about-us`, `terms`, `privacy`, `refund-policy`, `track-order`, `price-list` and `check-id` each its own title and description (`{store_name}` token; Malay with English gaming terms; no delivery-time or payment-method claim). The seven pages read it. An empty admin `default_meta_*` falls to the registry's home entry instead of the old hardcoded English sentence.
- **Sitemap and `llms.txt`.** The rows are a pure function (`lib/sitemap-entries.ts`): static pages carry no `lastmod`, a game with no date has none, a `noindex` game is left out. `llms.txt` uses the same filter (`lib/indexable-games.ts`). The catalog index summary now carries `no_index`.
- **Organization JSON-LD** gets `alternateName` ("Pekan Game") for the primary brand only; the branding payload carries `is_primary`.
- **Dead fields out of the code:** `seo_title_local`, `seo_description_local`, `seo_keywords` leave `Game::$fillable`, `UpdateGameSeoRequest`, `CatalogController`, the admin screen and its type. The columns stay until a later release drops them.

**Verified:**
- Backend suite 3,901 passed (4 new in `Adr140SeoMetaStrategyTest`, each seen red for the right reason first). Storefront `npm test` 27 passed (17 new), admin 22; `tsc` and `eslint` clean in both; `next build` passed in both.
- Throwaway backend (`APP_ENV=e2e`, sqlite in the scratchpad) with the storefront dev server, read with `curl`: six pages, six different descriptions (before: home, about-us, refund-policy and track-order shared one); the sitemap has no `lastmod` on static pages and a real one on a game; a game set `noindex` is in neither the sitemap nor `llms.txt`; the primary's Organization has `alternateName: "Pekan Game"`; an affiliate host shows its own name (`AcmeStore`) and origin and no `alternateName`.
- Admin in a real browser (Playwright, throwaway admin): the three inputs are gone, the title field fills the row (900 px, same as the description), the save request carries only `seo_title`, `seo_description`, `seo_og_image`, `schema_brand`, `schema_category`, `no_index`, and the saved title is in the database. Throwaway servers, admin and sqlite file removed afterwards.

**Gotchas:**
- The storefront's `node --test` cannot resolve the `@/` alias, so every unit-tested piece lives in a dependency-free module that imports with a `.ts` extension.
- Moving `noIndex` from `GameDetail` to `Game` made `tsc` find a hand-built `Game` in `JokiOrderForm.tsx`; fixed there.
- A branch cut with `git switch -c <name> origin/staging` inherits `origin/staging` as its upstream, so a bare `git push` would aim at the protected branch; unset it right after cutting.

**Not seen live:** anything on prod. The founder still fills the primary's `default_meta_title` and `default_meta_description` in the admin after release (record before and after here), and the three columns drop in a later PR. Next: PR 2, the per-game content (ADR-140 decisions 6–12).

## 2026-11-11 — Per-game content built (ADR-140 PR 2)

Branch `feature/game-content`, into `staging`. ADR-140 decisions 6–12; the build choices are in the ADR's PR 2 addendum. Not released.

**Built:**
- **Backend.** An additive migration (`content_summary`, `content_about`, `content_faq`, `content_published_at` on `games`). `Game::publishedContentFor($brand)` is the one place content is let out (primary brand and published only); `CatalogController` calls it for the game detail (`content`) and the index summary (`content_summary`, `content_published_at`), and a surface that must not carry content has no key. `Admin\GameContentController` (`PUT /api/seo/games/{game}/content`) is the one writer: the limits from the decision, "nothing to publish" refused with 422, and a `content_published_at` that moves only on first publish or a text change. The admin SEO list and overview report Empty/Draft/Published instead of the title-and-description completeness, which read "missing" for every game after ADR-120 and made the overview ask the founder to fill what had been left blank on purpose.
- **Storefront.** `GameAbout` under the reviews (`lang="ms"`, server-rendered, visible), `FAQPage` through one builder shared with the home FAQ, the summary as the meta description (cut at a word inside 155 characters) ahead of the template, the Product JSON-LD description, the `llms.txt` line and the sitemap `lastmod`.
- **Admin.** Admin → SEO → Games → [game] rebuilt on the PrimeReact kit: summary, About, FAQ (up to 8), a Published switch, with the old meta overrides below.

**Verified:**
- Backend suite 3,918 passed (15 new in `Adr140GameContentTest`, including the invariant matrix: primary and affiliate × empty, draft and published, on the detail and the index summary; the two old SEO admin tests were replaced on purpose). Storefront `npm test` 36 passed (19 new since PR 1), admin 22; `tsc`, `eslint` and `next build` pass in both; the migration ran on the throwaway sqlite and on the local dev DB with a plain `php artisan migrate`.
- Throwaway backend + storefront dev + admin, driven in a real browser. After publishing in the admin, with the storefront caches warmed first and nothing restarted, the page's meta description, the `llms.txt` line and the sitemap `lastmod` were updated at once through the purge and Next revalidation webhook. The page showed the section, `lang="ms"`, two About paragraphs and the FAQ, and the `FAQPage` questions equalled the rendered ones. An affiliate host (same game) showed no section, no FAQ, no `FAQPage`, no summary, its own description and the creation date as `lastmod`. Unpublishing removed everything at once; publishing with every field empty was refused with "Add some content before publishing." and changed nothing.
- Screenshots on desktop and a 390 px phone: no horizontal overflow, text contrast 8.43:1 to 15.64:1.
- Throwaway servers, admin and sqlite file removed afterwards.

**Gotchas:**
- Next's Data Cache outlives a dev-server restart and `rm -rf .next/cache/fetch-cache` did not clear it (the first page load after a fresh DB still showed content from the earlier run). The webhook (`NEXT_REVALIDATE_URL` and `NEXT_REVALIDATE_SECRET` on the backend, `REVALIDATE_SECRET` on the storefront) is what keeps it fresh; without it a change shows within 300 s.
- My first browser run of "publish with every field empty" cleared the summary and About but left the FAQ, so it published; the FAQ alone is content. The script, not the code, was wrong.
- Pint rewrites test imports (`fully_qualified_strict_types`); run it on a new test file.

**Not seen:** the section's place relative to the reviews (the throwaway DB has no reviews; the order is the JSX order: checkout, reviews, About, footer), and anything on prod. Whether prod has the revalidation webhook set is not checked. **For the founder after release:** fill the home meta in Admin → SEO → Global Settings, then the content for each game (the drafts come from Claude for review), request indexing for five pages, and put links to game pages on the brand's social profiles. The three dead `seo_*` columns drop in a later PR once PR 1 is on `main`.

## 2026-11-11 — Release #783: ADR-140 SEO meta strategy and per-game content, and the first content published

The founder asked for it. `staging` → `main`, merge `dac033e1` at 11:39 UTC, carrying #778–#782 (the docs audit, ADR-140, PR 1 and PR 2). One additive migration.

**Pre-release checks (production, read-only):** 5 merges and 1 migration in the delta; production had no other pending migration; `games` held 40 rows (four nullable columns are instant); `/api/health` 200; box release `82558237`; 11:22 UTC, away from the :00/:30 price-sync window.

**CI and deploy:** the release PR was green on `55a324c4`; the `main` run `43013753500` was green in every job including `deploy`; the four Vercel deployments succeeded.

**Checked after release (read-only):**
- Box release `82696074`; migration `2026_11_15_110000` ran (batch 70); the four `content_*` columns exist; 40 games, 0 published at that moment; `/api/health` ok.
- Live: the static pages carry the registry titles; home, about-us, refund-policy and track-order have four different descriptions (before: one); the sitemap has 48 URLs, none of the 11 static pages has a `lastmod` and all 37 games have a date; a game page was unchanged (no About yet); FixFast's titles carry its own name.

**Seen on prod:**
- Organization JSON-LD lacked `alternateName` ("Pekan Game") for about 27 minutes although the API returned `is_primary: true`. Next's Data Cache keeps the old branding payload across a deploy; the founder's first admin save (Global SEO Settings) purged it and it appeared. Not a bug.
- The storefront was fresh within the same minute of every publish, so the revalidation webhook is set on production (ADR-140's "check on prod at release" is answered).

**Admin actions (the founder; Claude drove the founder's Chrome session where noted):**
- **Global SEO Settings → Default Meta.** Before: title and description empty (the registry text was served). After: description `Top up diamond Mobile Legends, UC PUBG Mobile, Free Fire dan game popular lain di {store_name}. Pilih game, masukkan ID dan bayar terus tanpa akaun.` and title `{store_name} - Top Up Game Murah di Malaysia | {store_name}`, which prints the brand twice ("PekanGame - Top Up Game Murah di Malaysia | PekanGame") and was still so at the end of the day. Meta Templates are unchanged: the game template still says "harga diskaun ahli" and "saat demi saat", and it also shows for FixFast and PajoStore, which have no Membership.
- **Content, drafts.** 36 games got drafts: Mobile Legends Malaysia typed into the form, the other 35 through the admin's own `PUT /api/seo/games/{id}/content` from the founder's logged-in session (`published: false`, each game read back afterwards); every stored text equalled the reviewed draft by hash. Zenless Zone Zero was skipped (0 packages); Call of Duty Mobile, Joki MLBB and Roblox stayed empty. The region siblings were 0.80 to 0.96 similar, so only the three family pages were to be published. At the founder's request two FAQ answers (time and failed order) were changed on every game, 68 answers, read-modify-write with the publish state kept; PUBG's own "why longer" FAQ was left because it states manual delivery.
- **Content, published.** The founder published Mobile Legends Malaysia, Aniimo, Arena Breakout and Blood Strike; on the founder's word Claude published PUBG Mobile Global, Genshin Impact, Honkai: Star Rail, Wuthering Waves, Honor of Kings, Magic Chess: Go Go and Free Fire Malaysia (13:15 UTC); the founder fixed and published Kingshot, Pokémon UNITE, Rainbow Six Mobile, Racing Master, Gangstar Mirage City, Where Winds Meet and Valorant Malaysia. 18 games are live. The other drafts (Mobile Legends 9 regions, Free Fire 5, Valorant 4) stay drafts on purpose.
- **Verified live for all 18:** the section and `lang="ms"`; `FAQPage` questions equal the rendered and the API questions; the meta description and the `llms.txt` line come from the summary; the sitemap `lastmod` is the publish date. FixFast, OhahaStore and PajoStore carry nothing on any of the 18; the unpublished pages carry nothing.
- **Request Indexing** in Search Console for five pages (Mobile Legends Malaysia, Free Fire Malaysia, PUBG Mobile, Genshin Impact, Honor of Kings) through the founder's Chrome; Honor of Kings needed one retry after a Google-side error.

**What went wrong:** the founder published Aniimo with the "belum disahkan" placeholder sentence in its FAQ, and it was live from about 13:06 to 13:28 UTC. Claude's pre-publish scan covered only the sixteen games it was about to change and its verification script had no placeholder check, so it was found only by the next full scan; the founder removed the sentence. Rule: scan every published game for placeholder text, not only the ones being changed.

**Gotchas:**
- `/api/csrf-token` is rate limited (429). A loop that fetched one token per write got a 419 after 29 writes; fetch one token and reuse it.
- In Search Console the Request indexing button must be clicked by element reference on a freshly loaded inspection; one click by coordinate showed no dialog. Each request waits about a minute for the live test.

**Baseline for the checks (2026-11-11):** 10 pages indexed, 41 "Discovered – currently not indexed", no game page crawled, Crawl Stats HTML 9% and Discovery 2%, 0 external links, 177 clicks (3-month view). Checks on 25 Nov, 9 Dec and 6 Jan 2027.

**Open:**
- the founder's backlinks from the brand's social profiles to game pages (the likeliest lever; external links are 0);
- the home title's doubled brand, and the game template's "harga diskaun ahli" on brands without Membership;
- the Aniimo pack named "Lumin Cystals" (an admin data typo);
- Zenless Zone Zero's content once it has packages;
- the FAQ says 1-10 minutes while the page header says "Average Delivery: 1-5 Minutes" (editable per game in the admin);
- the three dead `seo_*` columns drop in a PR now that PR 1 is on `main`.

## 2026-11-11 — The three dead `seo_*` columns dropped (ADR-140 decision 5, contract release)

Branch `feature/drop-dead-seo-columns`, into `staging`, stacked on the release record (#784). Not released.

**What:** migration `2026_11_16_100000_drop_dead_seo_columns_from_games_table` drops `seo_title_local`, `seo_description_local` and `seo_keywords` from `games`; its `down()` restores the shape, not the data. PR 1 (#781) had already stopped every read and write of them, and it has been on `main` since release #783, so no running code touches them. The Game SEO list's subtitle no longer says "keywords". Nothing visible changes in the admin: the three inputs went with PR 1.

**Production before the drop (read-only, 2026-11-11):** 40 games; `seo_title_local` and `seo_description_local` filled on 38 of them with **one** distinct value each (the seed template with unreplaced `{game_name}` tokens); `seo_keywords` filled on 0; no database view and no index references any of the three. So nothing of value is lost.

**Verified:**
- Backend suite 3,920 passed. A new schema test (`Adr140DropDeadSeoColumnsTest`, seen red first) and the ADR-140 tests: 21 passed on sqlite; the schema test also passed on the real MySQL container, where the `llm_report_*` views are created before the drop.
- On that MySQL database `migrate` dropped the three columns, `migrate:rollback --step=1` restored all three, and `migrate` dropped them again.
- The local dev DB migrated with a plain `php artisan migrate`. Admin `tsc` and `eslint` clean; Pint clean.

**For the release:** the deploy runs the migration on production: three columns removed from `games` (40 rows), instant, no lock of note. Check afterwards that `Schema::hasColumn('games', 'seo_keywords')` is false and a game page and the Game SEO screen still load.

## 2026-11-11 — SEO follow-ups: tracked-games count, small hygiene

Branch `fix/seo-tracked-games-hygiene`, into `staging`. Not released. Found in a read-only review of the ADR-140 work.

**What:**
- **Tracked games** (ADR-140 2026-11-11 addendum). Production showed 38 tracked games in the admin and 37 games in the sitemap; the extra one was `joki-mlbb`. `Game::scopeTracked()` now uses the catalog's visibility rule (`AffiliateGame::hiddenGameIdsFor` for the primary brand), so joki and primary-hidden games are out of "Published N of M" and `sitemap_url_count`. A new invariant test (seen red first) ties the scope to the primary catalog listing.
- A docblock for `scopeJoki()` had been left above `scopeTracked()`; it is back on its method.
- `test_saving_game_seo_ignores_the_removed_fields` was deleted: since the columns were dropped it asserted `null` on attributes that no longer exist, so it could not fail. `Adr140DropDeadSeoColumnsTest` covers the drop.
- The `llms.txt` route comment no longer says it has "no admin-authored field" (the published summary is ADR-140 decision 10's exception).

**Noted for the record:** #785 was merged with the `playwright` check red, on the founder's word. Both runs failed at `npx playwright install chromium --with-deps`, because the GitHub runner could not resolve the Ubuntu apt mirrors (14:14 and 14:36 UTC). No test ran, and every other check was green.

**Verified:** backend ADR-140, SEO and catalog tests 192 passed.

## 2026-11-11 — CI `playwright` no longer depends on apt

Branch `fix/ci-playwright-no-apt`, into `staging`. CI only; nothing deploys.

**Why:** the `playwright` job failed three times on 2026-11-11 (#785 twice, #786 once) at `npx playwright install chromium --with-deps`. The runner could not resolve Ubuntu's apt mirrors (`Temporary failure resolving 'archive.ubuntu.com'`), so no test ran.

**Root cause, checked in the logs:** `--with-deps` runs `apt-get update` and installs system packages. On the runner image (Ubuntu 26.04), every library Chromium needs is already present ("libnss3 is already the newest version"). In six green runs, apt installed the same 9 packages, all fonts: `fonts-freefont-ttf`, `fonts-ipafont-gothic`, `fonts-tlwg-loma-otf`, `fonts-unifont`, `fonts-wqy-zenhei`, `xfonts-cyrillic`, `xfonts-encodings`, `xfonts-scalable` and `xfonts-utils`. No spec needs them: there is no screenshot assertion in `e2e/`. So the job's only apt dependency bought nothing, and it was what failed.

**What:** the flag is gone. The browser binary still comes from Playwright's CDN, now cached by `actions/cache`, keyed on `e2e/package-lock.json` (which pins Playwright and therefore the Chromium build). CI runs only on pull requests and on pushes to `main`, and a PR can read only its own caches and the default branch's. So the cache serves later commits of the same PR at once, and every PR after the next release's `main` run saves it. Proven on #788: 17 passed, no apt step, `Cache saved`.

**Rejected:**
- apt retries: they make a failing dependency fail less often instead of removing it;
- the `mcr.microsoft.com/playwright` container: the job also needs PHP 8.4, and inside a container `setup-php` installs PHP through apt, which only moves the problem.

**If a future runner image lacks a library,** Chromium fails at launch and names the library. Pin `runs-on` then, rather than bringing apt back.

## 2026-11-11 — Reseller API docs v1.6.1: one `Order` schema, headers, guidance, private-terms guard

Branch `fix/reseller-api-spec`, into `staging`. Not released (docs-site deploys from `main`). ADR-084 2026-11-11 addendum.

**Why:** the founder had an AI agent (Hermes + DeepSeek) read docs.pekangame.space as a developer building against PekanGame. It rated the prose 8/10 and the spec 5/10, and listed 17 findings. Claude checked each against the code and the live spec. Confirmed:
- `POST /v1/orders` was typed as a string;
- the status enums were missing;
- no response headers were listed;
- `orderNumber` had no description;
- request examples were missing;
- there was no `llms.txt` or `robots.txt`;
- the docs offered "test credentials", which do not exist.

Not so:
- retention, which is already "indefinitely" on the Idempotency page;
- the "missing" sitemap: Starlight serves `sitemap-index.xml`.

Skipped as low value: portal screenshots, which go stale.

**What shipped:**
- `OrderResource` replaces `ResellerOrderPayload`. One `Order` component is referenced by all four order responses, with enums and `date-time`.
- Response headers are documented, each one proven by a test.
- The request body has examples, and `orderNumber` has a description.
- docs-site:
  - guidance for `needs_review` (do not re-place the order);
  - two keys mean two orders;
  - a client timeout for `POST /v1/orders`;
  - the bot commands are gone;
  - the false test-credentials line is gone;
  - the spec is linked;
  - `llms.txt`, `robots.txt` and the PekanGame favicon are added;
  - changelog v1.6.1.
- `check-docs.mjs` now fails CI on private terms in the public docs.

**Found on the way:** example order numbers were 10 characters; the real ones are 12 (`OrderNumberService`; production's 15 reseller orders all match `PG-` + 12). `order_number` is now documented as opaque.

**Not done:** webhook events in the spec (Scramble cannot emit OpenAPI `webhooks`; see the addendum); the partner sandbox stays PRD §16 item 151.

**Gotcha:** `vendor/bin/pint` with no path reformats about 60 unrelated backend files; run it on the changed files only.

**Verified:**
- Backend suite 3,921 passed. `OrderWireShapeTest` was green before and after the refactor, so the wire shape and the webhook body did not change.
- `scramble:export` reproduces the committed spec.
- docs-site `check` (0 errors) and `build` pass; the built POST page shows the headers, the enum values, the examples and the 12-character number.
- `check-docs` and its self-test pass.

## 2026-11-12 — Cash refund of a failed-order voucher designed (ADR-004 addendum); VC-FO21NVDK revoked by mistake

**Designed** on `feature/voucher-cash-refund`: the ADR-004 2026-11-12 addendum (PRD §16 item 179), in three grill rounds and a stress test against the code. The stress test found that a late supplier Success never changes `delivery_status`, so "the source order is still Failed" would never have caught one. The check reads the recorded late result instead, through one scope shared with the automatic wallet refund.

**Found (read-only, production).** VC-FO21NVDK (id 12) was revoked at 2026-11-12 01:28 UTC, by mistake. It is the merge of two failed-order vouchers, VC-IGUQCF24 (PG-MLH49GJRX6D2) and VC-NPXPMSDU (PG-8CSW9QS6BPNB), RM230.21 each. RM453.88 had been spent on PG-ECHHWBZQFKGA (delivered), and the revoke booked the remaining RM6.54 as November breakage. The admin Revoke button (list and detail) had no confirmation, and any admin could press it without the step-up. Left as history, with no data patch (founder). If the customer asks, an admin issues a RM6.54 voucher with the reason "replaces VC-FO21NVDK, revoked by mistake".

**Production scale on 2026-11-12:** 231 paid retail orders, 16 failed or partly delivered, 14 order vouchers (RM573.13), 1 merge, no gift voucher, no compensated order with a late supplier result, 3 active admins (all super_admin), `ACCOUNTING_DISK=r2_accounting`.

## 2026-11-12 — Cash refund of a failed-order voucher built (ADR-004 addendum, PRD §16 item 179)

**Built** on `feature/voucher-cash-refund`. The backend:
- Migration `2026_11_17_100000`: `vouchers.source`, backfilled in id order so a merge target follows its sources, plus `revoked_reason`, `revoked_by` and the `voucher_cash_refunds` table.
- `VoucherService::refundInCash()`, the one writer, with the refusals from the ADR.
- `Voucher::balanceAsOf()` reads the refund.
- `Order::withLateSupplierResult()` / `withoutLateSupplierResult()`, one JSON path, also used by `app:refund-failed-wallet-orders`.
- `AccountingPeriod::assertTransferDate()`, shared with the reseller closure.
- A Transaction Register row (`voucher_cash_refund`) and a Monthly Summary information line (`failed_order_credit_refunded_sen`).
- Revoke limited to gift credit, with a reason, and moved to Super Admin behind the step-up. The refund route is too, and the proof downloads from `GET /vouchers/{id}/cash-refund/proof`.

In the admin: a "Refund in cash" modal on the voucher detail (the source orders, blocked while a late result is recorded), a Revoke confirmation with a reason, a "Refunded by bank transfer" card, the `refunded` status, and both accounting labels.

**Verified.**
- `php artisan test`: 3943 passed. On the way, two existing tests changed on purpose. The pinned step-up route list gained the two new routes. `test_only_admin_issued_vouchers_debit_the_platform_ledger` merged a gift voucher with a failed-order voucher, which is now refused, so it merges two failed-order vouchers instead (the ledger invariant is unchanged).
- Concurrency suite on MySQL: two refunds pay out once, and a refund racing a redeem never pays out a reserved balance. Voucher merge and closure stay green.
- `FinancialScenarioTest`: a cash refund leaves the October Cash Position gap and operating profit unchanged, and September does not move.
- Mutation-checked: dropping the refund from `balanceAsOf()` or the late-result check fails its test.
- Admin `tsc`, `lint` and `build` pass.
- Browser, on a throwaway sqlite backend with an MFA admin (Playwright, prod build on `localhost:3000`): the late-result voucher's modal blocks the submit. A failed-order voucher refunded through the real step-up prompt (403 `mfa_step_up_required`, then code, then 201) ends `refunded` with RM0.00 left, and the proof is filed under `accounting/voucher-cash-refunds/2026/11/`. Revoke is offered only on the gift voucher, and the confirm button stays disabled until a reason is typed. The local dev DB migrated cleanly (no merge rows lost).

**Gotchas.**
1. `revoked_by` as a foreign key made SQLite rebuild `vouchers`, and the rebuild's drop cascaded into `voucher_merges`. The backfill test caught it. It is a plain column now, like `created_by` and `approved_by`.
2. A late supplier Success never changes `delivery_status`, so the check reads the recorded result (found in the stress test, recorded in the design entry above).
3. In the Playwright check, a TOTP code already used to log in is refused for the step-up within the same 30-second window.

## 2026-11-12 — Portal password reset and the bot's top-up method (ADR-058 and ADR-076 addenda, PRD §16 item 181)

**Why.**
- A portal user who forgot a password had no way back. The admin's Resend invite refused a user with a password.
- `.topupbaki` sent DuitNow QR only because `duitnow_qr` sorts before `fpx`. DuitNow costs the reseller 1%, FPX a flat RM1.
- The trigger: AyrisShop's RM1,000 DuitNow top-up `WT-ILISAXAY52` sat at CHIP `pending_execute` on 2026-11-12. Its `.batalbaki` kept answering that the bank was processing it, which was correct, but the reply never said when the top-up would end. Checked read-only: CHIP `pending_execute`, `paid_on` null, expiry 19:04 MYT. It is the first `pending_execute` seen on production.

**Built** on `feature/portal-password-reset-bot-topup-method`:
- `POST /api/affiliate/forgot-password`: one generic answer, mail sent with `defer()` after the response, 5 requests a minute per IP.
- `AffiliateInviteService::sendPasswordReset()` through the broker's `sendResetLink()`, so the 60-second per-user throttle holds. `sendInviteOrReset()` backs the admin button on both user lists.
- `setPassword()` deletes the user's Sanctum tokens.
- The portal has a `/forgot-password` page and a link on sign-in.
- `PaymentMethod::offered()` is shared by the portal catalog and the bot.
- `platform_settings.bot_topup_payment_method_id` (FK, `nullOnDelete`; the migration sets FPX) is chosen in Settings → Purchase Balance, with a warning when it is switched off.
- The `.topupbaki` reply names the method. The `.batalbaki` in-flight reply gives the end time.

**Verified.**
- `php artisan test`: 3953 passed. The pinned public-route list gained `forgot-password`.
- New tests: `PortalPasswordResetTest` (Reseller and Affiliate, generic answer, throttle, rate limit, old sessions end, single use, admin refusals) and the bot's chosen-method, fallback and same-list tests.
- Mutation-checked: dropping the token revocation fails its test.
- Admin and portal: `tsc`, `lint`, `test` and `build` pass.
- Browser, on a throwaway backend with Plunk pointed at a local catcher so no real mail was sent (Playwright, prod builds):
  - An unknown and a known email got the identical message, and only the known one got a mail.
  - The emailed link set a new password, and sign-in with it reached the dashboard.
  - The admin modal shows "Send password reset" for a user with a password and "Resend invite" for a pending one. The reset sent a mail.
  - The Purchase Balance dropdown saved FPX through the real step-up prompt.

**Gotchas.**
1. `dispatch(closure)->afterResponse()` serialized the closure and failed on a service holding a WeakMap; `defer()` runs it in-process.
2. An invite token counts toward the broker's one-a-minute throttle, so a reset within a minute of an invite is refused with its own message.
3. On a fresh database the setting stays null (payment methods are seeded after migrations), which means "the first offered". On production the FPX row exists, so the migration sets it.

## 2026-11-12 — Release #791: cash refund of a failed-order voucher, portal password reset, the bot's top-up method

The founder asked for it. `staging` → `main`, merge `ea0f8036` at 12:18 UTC, carrying #784–#790 (the #783 release record, the dead `seo_*` column drop, tracked games, Reseller API docs v1.6.1, CI playwright without apt, PRD §16 item 179, item 181). Three migrations, including the column drop.

**CI and deploy:** the `main` run `43216768340` was green in every job including `deploy`.

**Checked after release (production, read-only):**
- `/api/health` 200; the three migrations ran.
- `vouchers.source`: all 18 rows are `customer_cash` (15 at design time, plus three issued that day: VC-WNWGYJPZ RM206.66 for PG-YKY3NIKNYEKW, VC-OPHBR9LC RM41.28 for PG-1RRM6Z4CACM1, VC-IHYB0CRU RM12.48). VC-FO21NVDK is `customer_cash` and stays revoked, as decided. The 5 active vouchers are all failed-order credit, so none can be revoked. 0 rows in `voucher_cash_refunds`.
- `platform_settings.bot_topup_payment_method_id` is 1 (FPX).
- The portal's `/forgot-password` is live on `reseller.pekangame.space`.
- PG-YKY3NIKNYEKW's failed leg: Digiflazz confirmed it Gagal (rc 02) at 11:11 MYT, so its voucher is right.

**Open:** found while answering a NeedsReview question the same day, a late supplier Success on a combo leg that is already Failed is dropped silently. `finalizePendingDeliveryLeg()` throws `InvalidOrderTransitionException`, and `DigiflazzWebhookController` logs it at INFO as "already finalized". No `late_supplier_result` is stored, no admin alert goes out, and no supplier drawdown is recorded, so the cash-refund guard and `app:refund-failed-wallet-orders` can't see it. Single orders store the result and alert, but also record no drawdown on a contradicted Success. No compensated order had a late result on 2026-11-12. The fix needs an ADR-127/ADR-102 addendum and a code trace; the founder will take it up in a new session.

## 2026-11-14 — WhatsApp outbox hardening: one idempotent send path, stale bot commands, alerts (ADR-116 and ADR-075 2026-11-14 addenda)

**Why (read-only prod, 2026-11-13).**
- Duplicate messages: OpenWA's `messages` held 18 bot and 3 customer-support outgoing duplicates (same chat and text within 2 minutes), 5 of them that day. `send-text` timed out at our 10 s (`cURL error 28`, 22:48 and 22:59 MYT) while OpenWA still sent it, and the job retried. Order PG-FXORY5HG2BW3 got its receipt three times.
- Every reseller and supplier bot reply went through `sendText()` → `SendResellerBotReplyJob` (3 tries in about 25 s, then lost); the customer job failed a row after about 6 minutes, not the 2 days `retryUntil` suggested.
- No bot order went without a reply between 2026-09-25 and 2026-11-13 (31 bot orders checked against OpenWA's `messages`), and no "reply lost" since the logs start on 2026-10-31.
- WhatsApp holds messages while the gateway is down and delivers them on reconnect with their original time, so an `.order` sent in an outage could run much later. OpenWA's own automation skips messages older than 5 minutes for this reason.
- OpenWA v0.29.1 (prod) already supports `Idempotency-Key` on send routes; v0.30.0 adds nothing we need for this.

**Process.** Founder grill (three rounds), then a stress test with one fork per layer (outbound, inbound and order path, monitoring) against the code. It changed the plan: `afterCommit()` on the dispatch (jobs ran before their row existed), a real retry-until-back for an unreachable gateway, frozen text (re-rendered joki text broke the key), the DB order key deciding a stale `.order` (a redelivered webhook keeps its old time), `.batalbaki`/`.topupbaki` treated as money, no guard on the supplier bot, restriction read live in the health check instead of from webhooks, and alerts reusing the existing throttle pattern with no recovery email. Found during the build: freezing a supplier post would store its login link (ADR-125 decision 52), so that template is re-rendered and its retry ends `unknown`, never sent twice.

**Built (#793).**
- `WhatsAppOutbox` owns lane, lifetime and supersede by event; `reply()` for bot groups; `afterCommit()`; `due_at` column.
- `SendCustomerWhatsAppJob`: expiry, supersede, frozen text, `Idempotency-Key: cn-{id}-{hash}`, outcome mapping (`OpenWaUnavailableException` → retry with backoff up to 5 min; `OpenWaOutcomeUnknownException` → `unknown`), unconfigured session → `skipped`.
- `OpenWaClient`: `sendText()` removed; `sendNow()` takes the key; `sessionStatus()` returns `restricted`; `isConfigured()`.
- All 12 bot send sites moved to the outbox (reseller bot, order listener, refund notice, wallet top-up notices, supplier bot). An architecture test allows `sendNow()` only in the outbox job and the retired `SendResellerBotReplyJob` (kept one release to drain).
- `ResellerBotService`: stale guard after parsing and before the limiters; `.order` checks its idempotency key first. `PlatformSettings.bot_command_max_age_minutes` (default 3) in Settings → Purchase Balance.
- Webhook: one inbound message handled once (`Cache::add`, released on exception), the message time passed to the bot, media with no text ignored quietly, `message.failed` marks the customer row.
- `/api/health/whatsapp` 503 on a restriction; `app:alert-whatsapp-outbox` every minute (CRITICAL); Horizon `supervisor-whatsapp-reply`; Middleware → WhatsApp (read-only list); portal `/bot-status` and the degraded banner (polled every 60 s, reseller with a bot group only).

**Verified.** Backend 3,987 tests pass (25 new: outbox job, stale commands, webhook once, `message.failed`, restricted health, alert throttle, bot-status, architecture). Admin and portal: `tsc`, lint, build and unit tests pass. Local dev DB migrated. Browser (Playwright, throwaway sqlite backend, after merge): Middleware → WhatsApp lists all seven statuses and the status filter narrows to one; the Settings field shows 3; the portal shows the bot banner for a reseller with a bot group while the session is not ready. The masked phone wrapped onto three lines and the Error text ran off the edge at 1440 px; fixed in a follow-up PR (error truncated with the full text on hover; the table now fits).

**Gotchas.**
1. Removing the fallback fields `data.text` / `data.messageId` is safe: OpenWA's `message.received` carries only `body` and `id` (docs §6).
2. Every existing bot test called `handle()` without a time, which the fail-safe now rejects for money commands; all 66 calls pass `now()`.
3. With the sync queue (tests), an outbox job runs inside the caller's request; an unconfigured session had to skip, not throw, or an admin refund returned 500.
4. Found by CI on the follow-up PR: `PlatformSettings::current()` lists every default because Eloquent never reads back a DB default after INSERT, and the new `bot_command_max_age_minutes` was missing from that list. A freshly created row read the limit as null, a 0-minute limit, so any money command a second old was rejected (a test crossed a second boundary). Production's row already exists and has 3 from the column default; the default is now in `current()` with a regression test.

**Open after release:** PRD §16 items 182 (founder's OpenWA settings), 183 (v0.30.0 upgrade and deleting the retired job), 185 (upstream "skip typing" request), and 184 (receipts without opt-in, design). Item 186 (RedxGame 403 for Free Fire and the other RedxGame-only games) was found in the same session.

## 2026-11-14 — Night Market recorded as a proposal (ADR-141, PRD §16 item 187)

Design only, nothing built. The founder wants a large customer base over the next two to three months (break-even is acceptable) and brought two references: Panera's coffee subscription and VALORANT's Night Market. A read-only production check backed the discussion: 69% of 201 storefront customers bought once, orders under RM10 are 43% of orders but 6% of profit, RM60+ orders are 14% of orders but 64% of profit, Friday is the busiest day and 16:00 MYT the busiest hour. ADR-141 records the founder's four decisions (unique cards per customer, visible to everyone, third-party affiliates too, weekly on Friday) and Claude's draft for the rest, as Proposed. The founder chose to finish PRD §16 groups A and B before grilling it.

## 2026-11-14 — One payment-method lookup by channel code, and a started payment keeps its gateway (PRD §16 item 180, ADR-022 addendum); item 178's column drop confirmed on production

**180.** Found in the 2026-11-12 stress test: 11 places looked up a `PaymentMethod` by `channel_code` inline. A first pass put them behind two scopes. The founder asked whether that was still patching, and a stress test with a second gateway in mind (CHIP for FPX and DuitNow QR, HitPay or Fiuu for e-wallets and cards) found the real cause: an order stamps `payment_gateway` when its payment starts, but a wallet top-up attempt and a membership attempt didn't, so five places looked the gateway up again from the current method row. That was safe only because the admin can't change a row's `gateway`. What shipped (ADR-022 2026-11-14 addendum):
- `wallet_topup_attempts.gateway` and `membership_checkout_attempts.gateway`, not null and no default, stamped at creation and backfilled `chip` (production read-only: 26 attempts, all CHIP). Re-check, cancel, resume and both reconcile commands read the attempt's own gateway. A storefront checkout replay uses its order's `payment_gateway`.
- `PaymentMethod::chargeableForChannel()` for a new charge (checkout, wallet top-up, membership subscribe, the fee resolver) and `forChannel()` for a payment already made (the wallet receipt's label). The four FormRequests share one rule, `ChargeablePaymentChannel`.
- A guard test fails when `app/` looks a method up by `channel_code`, validates `exists('payment_methods'…)` or reads a gateway through a channel lookup outside `PaymentMethod`. Run against `staging`'s files it flags all 15 old sites.
- New tests: a started top-up is reconciled on its own gateway after its method row was pointed at another gateway and switched off; the wallet and membership attempts stamp `gateway`.

95 test fixtures created attempts without the new column; each now passes `'gateway' => 'chip'`. Full suite 3992 passed; the concurrency suite ran on MySQL, which also ran the migration's column change. The second-gateway prerequisites the stress test found (`method_key` values carry the gateway, `channel_code` unique across gateways, `chip_payment_ref`, CHIP-only settlement) are recorded in the addendum and PRD §16 item 189, not built.

**178, part 2.** The dead `seo_*` columns were released in #791 (2026-11-12). Read-only on production on 2026-11-14: migration `2026_11_16_100000_drop_dead_seo_columns_from_games_table` has run and `games` has none of the three columns. Item 178 stays open for the founder's backlinks and the Search Console checks on 25 Nov, 9 Dec and 6 Jan.

## 2026-11-14 — Release #797: WhatsApp outbox hardening, a started payment keeps its gateway; OpenWA settings (PRD §16 item 182)

The founder asked for it. `staging` → `main`, merge `3b815832` at 15:07 UTC (23:07 MYT, Saturday), carrying #792–#796: the #791 release record, WhatsApp outbox hardening (#793, #794), the Night Market proposal (#795, docs only) and item 180 (#796). Two migrations.

**CI and deploy:** the `main` run `43608473123` was green in every job, `deploy` included.

**Checked after release (production, read-only):**
- The box runs `3b81583`; both migrations ran (`2026_11_19_100000_whatsapp_outbox_hardening`, `2026_11_20_100000_add_gateway_to_payment_attempts`).
- `wallet_topup_attempts` (16) and `membership_checkout_attempts` (11) are all `gateway = chip`, none null. `platform_settings.bot_command_max_age_minutes` = 3; `customer_notifications.due_at` exists.
- Horizon running with `supervisor-whatsapp-reply`; `/api/health` 200; `/api/health/whatsapp` 200 with both sessions `ready`.

**Item 182, OpenWA settings (founder, with Claude checking each step read-only):**
1. `~/openwa-deploy/shared/.env`: `SIMULATE_TYPING_MAX_MS=1500` and `WEBHOOK_FAILURE_PAYLOAD_RETENTION_HOURS=72` appended; backup `.env.bak-20261114`.
2. Forge daemon `daemon-1105837` (OpenWA): `stopwaitsecs` 15 → 75, which restarted OpenWA at 15:38:42 UTC. Both values loaded; `customer-support` was `ready` about 12 s later. The other three daemons keep 15.
3. Customer-support webhook events are now `message.received`, `session.status`, `message.sent`, `message.failed`; the reseller-bot webhook is unchanged.
4. Both operator keys (Reseller Bot, Customer Support) have `allowedIps` 127.0.0.1; the admin key is unrestricted. Both operator keys were used successfully at 15:45:22 UTC, after the change; no IP rejection in either log.
5. Cloudflare Access application `bot` on `bot.pekangame.space`, policy "founder only" (the account owner's email, one-time PIN), session 24 h. From outside, `/` and `/api/health` now answer 302 to the Access login. Claude drove the founder's browser for this step and the next.
6. UptimeRobot: the `bot.pekangame.space/api/health` monitor still showed Up while it only reached the Access redirect, a false green, so it is paused. `api.pekangame.space/api/health/whatsapp` (item 95) covers OpenWA down, a session not ready, and a restriction.

**Found:** OpenWA logs "X-Forwarded-For is present but TRUSTED_PROXIES is empty", so every dashboard request shares one rate-limit bucket. Small, since only the founder uses the dashboard; noted on item 183 for the next OpenWA maintenance window.

**Open:** item 183 (OpenWA v0.30.0, delete `SendResellerBotReplyJob`) is now unblocked.

## 2026-11-15 — The first real loss (order 437): `max_price` still not built, a daily loss email instead (ADR-137 and ADR-111 2026-11-15 addenda, PRD §16 item 169)

**What happened.** Checking PRD §16 group D triggers against production (read-only) found item 169's trigger had fired: order 437 (`reseller-wallet`, 571 Diamonds, 2026-11-13 10:47 UTC) was paid at a catalog cost of RM36.52 and a price of RM36.89, and Digiflazz charged RM38.06 (167,325 IDR). The price sync at 11:05 saw the new cost. Loss RM1.17, `profit_reconciled_flagged` set, seen by no one: the flag shows only on Order Detail.

**Evidence (read-only, prod, 2026-11-15).** 367 delivered orders with a real cost: 7 above the stored cost, 2 losses, both reseller-wallet (46: −13 sen, a pre-ADR-134 resend; 437: −117 sen). Margins: Standard min 4.3%, Affiliate 4.2%, Member 4.5%, Reseller wallet −0.7% to a 1.0% median. Of 13 resends at a higher cost only order 46 lost money. Busiest wallet hour: 7 orders. Digiflazz reported a price on all 338 single-package deliveries since 2026-11-01; the 43 combos take their cost from legs.

**Decided (grilled, then stress-tested, with the founder).** Not `max_price` now (a refused wallet order is auto-refunded by ADR-139, but the reseller still sees a failure, for RM1.30 saved so far), not a wallet price cushion (charges every reseller during a growth period). Watch it: a daily email, with a self-checking trigger (over RM20 a month or RM5 on one order → build `max_price` for wallet orders only). The stress test moved the alert from a hook at the two reconciliation sites in `OrderFulfillmentService` to a sweep over stored results, the ADR-139 auto-refund pattern: every writer of `platform_profit` covered, and no change to the money-critical file. It also corrected an earlier claim in the session that a failed order has no automatic compensation (wallet orders do, ADR-139).

**Built.** `app:alert-order-losses` (`order-loss-digest`, daily 00:00 UTC = 08:00 MYT, ROUTINE): orders delivered on the previous Malaysia day with `platform_profit < 0`, not test, not `partially_delivered`; each line has basis, a resend label, expected vs real cost and the loss; the month's total, and item 169's trigger named when crossed. `AlertOrderLossesCommandTest` (6 tests: window edges, exclusions, both triggers, resend label, the 1st of a month). Full suite 3,998 green. The email rendered with one line per order. The same query on production gives order 437 for 13 Nov, order 46 for 17 Oct and nothing for 14 Nov.

**Founder habit, not code:** after switching a Digiflazz seller on the dashboard, run Price Sync before resending, so the resend preview shows the new cost.

## 2026-11-15 — Late supplier results kept for combo legs, in their own table; the drift check emails (ADR-102, ADR-127 and ADR-083 2026-11-15 addenda, PRD §16 item 188)

**The gap.** A Sukses on a Failed combo leg (or a Gagal on a Delivered one) was logged and dropped: `finalizePendingSuccess()` threw, the transaction rolled back, and the webhook answered "already finalized" at INFO. No evidence, no alert, and the refund guards could not see it.

**What the code trace added.** (1) The single-order evidence in `supplier_response.late_supplier_result` was erased by any later `supplier_response` write (fulfill, resend, finalize: six sites), held one result, and mixed a late Gagal with a late Sukses, so a Gagal blocked refunds too. (2) A retry of a Failed order or leg mints a new reference and the webhook matches only the current one, so a late result on a superseded reference is a 404 at INFO, for single orders as well. (3) Settle and Order Detail never looked at the evidence.

**Production (read-only).** No late result ever recorded (0 order rows; 0 contradiction log lines since 2026-11-01; none of 549 references in the supplier request log since 2026-10-16 went Gagal then Sukses). The Digiflazz funding ledger equals the polled balance to the rupiah (76,081,225 IDR; only 2 manual adjustments, both on 2026-09-15), so no Digiflazz charge has gone unrecorded. 51 combo orders; 11 Failed legs in 5 orders (PUBG, 11–13 Nov), all compensated by voucher. One of them (PG-YKY3NIKNYEKW, leg 1, `rc 02`) carries a serial number on its Gagal, unlike the others: worth a look in the Digiflazz history.

**Decided (grilled, then stress-tested).** The stress test replaced the first plan (put leg evidence in the same JSON key) with an append-only table, after the trace showed the key is erased by later writes. Ledger adjustments stay manual (one drawdown per order or leg, ADR-127; precedent ADR-137 decision 14). Superseded references: no registry yet; the drift email catches their money effect (PRD §16 item 190 has the trigger).

**Built.**
- `late_supplier_results` (migration `2026_11_21_100000`), unique on reference and outcome; `OrderFulfillmentService::recordLateResult()` is its one writer, for single orders and legs; `alertLateResult()` emails both directions with the price and the adjustment to record.
- `Order` scopes read only late Successes, so the wallet auto-refund and the voucher cash refund block on a late Success and not on a late Gagal; `VoucherController` reads the table.
- Order Detail returns `late_supplier_results`; a banner lists them; the Issue Voucher and Resend modals warn on a late Success.
- `app:refresh-supplier-balances` emails a drift over the threshold and skips the check while an order or leg of that supplier is pending or processing; the email counts Needs Review orders.
- Tests: leg Sukses-on-Failed recorded once and alerted once across a redelivered webhook; leg Gagal-on-Delivered recorded, alerted, not blocking; evidence survives a later `supplier_response` write; the Order Detail payload; drift email, in-flight skip, Needs Review count; existing tests moved off the JSON key (one cleared evidence by nulling `supplier_response`, the hole this closes). Backend suite 4,004 green; admin `tsc`, lint, 22 tests green; dev DB migrated.

**Founder action:** set Digiflazz's Drift Threshold to 1,000 IDR in Middleware → Suppliers (PRD §16 item 191).

## 2026-11-16 — MLBB player check: isan first, then UniPlay; RedxGame out of the MLBB chain (ADR-005 and ADR-123 2026-11-16 addenda)

**Found (read-only prod).** UniPlay's checker page answers 503 to our server (2 fallbacks in the log on 2026-11-15, 12 on 2026-11-16; `curl` from the box: 503), and RedxGame has 403'd us since about 2026-11-06 (PRD §16 item 186). RedxGame offers official access only to its resellers. So every MLBB check ended `region_unknown`. Bypassing Redx's block was ruled out again. Two GitHub projects the founder found were read: `web-mlbb-checker` calls another store's endpoint through a public CORS proxy (not used); `mlbbchatidbot` uses isan's API (`ihsangan/valid`), which led here.

**Checked from the prod box.** `mlbb-api.isan.eu.org/find`: a known ID → 200 with `countryCode`, 0.13 s; a made-up ID → 404 `success:false`. The `api.isan.eu.org/nickname/ml` wrapper crashes on an unknown ID (500, Cloudflare 1101), so it is not used. No first-top-up bonus field.

**Built** on `fix/mlbb-isan-validator`: `IsanMlbbValidator` (only 404 + `success:false` is invalid; WAF, 429, 5xx, timeout or an odd body is unavailable), MLBB chain isan → UniPlay for checkout, the reseller bot and the public checker; Redx bound only for Free Fire, Genshin, HSR and ZZZ (standalone), its MLBB `timeout` config removed. The founder first said drop UniPlay, then corrected it mid-build: keep UniPlay, drop Redx from MLBB.

**After release, check:** the first real MLBB checks have `player_validations.provider = isan` and a Malaysian ID returns `country_code = MY` (only the README's Indonesian sample was tried live).

## 2026-11-16 — Release #802: MLBB player check restored (isan → UniPlay), loss email, late supplier results

The founder asked for it, to restore MLBB checks. `staging` → `main`, merge `5f1bc5cf`, carrying #798–#801. One migration. CI run `43805670052` green in every job, `deploy` included.

**Checked after release (production, read-only):**
- The box runs `5f1bc5c` (release `83074420`); `2026_11_21_100000_create_late_supplier_results_table` ran (batch 73); Horizon running; `/api/health` ok; Vercel production green for all four apps.
- The bound MLBB chain via tinker on the box: README sample ID → valid, `provider = isan`, "Outrageous Dominance", `ID`; a made-up ID → invalid, `isan`.
- One paid order was in flight at merge (`PG-MDOEVYENJSYA`, `pending` at the supplier); it was `delivered` at 04:46 UTC, after the restart.

**isan beyond MLBB (from the box, 2026-11-16):** Free Fire returns a nickname for a real ID and `success: true` with no name for a made-up one, never a region. Genshin and HSR answered 404 "Not found" for the founder's own real UIDs, as did every README sample for Genshin, HSR and ZZZ; isan's last commits say its author stopped maintaining the Codashop-based lookups ("use gopay games, I'm tired with coda", 2026-02-10). So isan is not a source for those three. PRD §16 item 186 holds the founder's open decision.

**Open:** a real Malaysian MLBB check (`player_validations.provider = isan`, `country_code = MY`); item 191 (Drift Threshold 1,000 IDR) can now be set.

## 2026-11-16 — Gamevion's price sync timed out on every run; the founder switched Gamevion off (PRD §16 items 177, 162)

**Found (read-only prod, in a backlog check).** 183 `SyncSupplierCatalogJob` failures in 7 days, from 2026-11-12, all Gamevion, all `TimeoutExceededException` (300 s). Gamevion synced in 20 of 48 runs on 12 Nov, 4 on 13 Nov, 1 on 14 Nov and 0 of 49 on 15 Nov; runs averaged ~165 s on 7–11 Nov and ~320 s after. The stale/recovered CRITICAL emails alternated, and Digiflazz, on the same single `price-sync` worker, synced ~5 minutes late each run behind it.

**Cause: Gamevion, not our code** (no sync or Gamevion commit between 10 and 13 Nov). Timed from the box, every one of the 74 chosen pricelists answered 200, but about 19 now take 4–10 s instead of ~0.3 s (`freefire_latam`, `valorant_sg/ph/th`, `mlbb_id`, `wwm`, `arena_breakout`, `bloodstrike_*` and others). Pricelists ~184 s + the forced 1.6 s pauses ~117 s + field calls ~7 s ≈ 310 s, and a call over our 10 s timeout is retried up to three times. `supplier_request_logs` agrees: 10 s `exception` rows only from 12 Nov (26–62 a day). Item 177's "about 140 games per 300 s" (2026-11-10) assumed Gamevion answers fast.

**Impact:** none on money (Gamevion unfunded, 0 active packages); false CRITICAL alerts and a late Digiflazz sync.

**Done (founder, 10:04 UTC):** Gamevion (API) switched off in Middleware → Suppliers; 0 packages and 0 combos taken off sale. **Checked after:** `is_active = 0`; the 10:30 run took 31 s, Digiflazz only, synced at 10:30:36; no failed job since 10:06.

**Open:** item 177 now holds the fix to make before Gamevion is switched back on (item 162 step 0).

Also seen in the same check, not yet acted on: three storefront `TrackedOrder` schema-drift warnings (2026-11-14 to 16, delivered orders, a 2xx body that was not JSON, cause unknown); real Malaysian MLBB checks after release #802 (5 `isan/valid/MY`, 1 `uniplay/valid/MY`), which closes release #802's open check.

## 2026-11-16 — Affiliate deactivation hardened: read-only by route, bank-detail changes recorded (ADR-058 2026-11-16 addendum)

**Why.** The founder asked for an audit of affiliate and reseller deactivation before redesigning reseller deactivation (ADR-142, next). The affiliate findings are fixed here first, because they do not touch reseller code and one is money: `Affiliate\ProfileController::update()` had no `assertWritable()`, so a deactivated affiliate could change its payout bank details, and the monthly payout copies them when a ticket opens.

**Built** (`fix/affiliate-deactivation-hardening`):
- `EnsureAffiliateWritable` (`affiliate.writable`) on the whole affiliate portal group; opt-outs `pricing/preview` and `impersonation/end` pinned in `RouteAccessTest`. The `AssertsAffiliateWritable` trait and its 16 per-method calls are gone.
- `AffiliateStatus` enum cast on `affiliates.status`, `Affiliate::isActive()` at the nine former string comparisons; the model's `$attributes` default mirrors the column.
- `AffiliateBankDetailsService::change()`: the bank fields are no longer fillable; a locked write plus an `affiliate_bank_changes` row (migration `2026_11_22_100000`; FK column `impersonation_session_id`, since the long name was 65 characters, over MySQL's 64); after the commit, emails to the affiliate's logins and contact email (`emails.affiliate-bank-changed`) and to every admin through `BackupFailureAlerter` (area "Affiliate bank"). The three fields are required together.
- `AffiliateController::updateStatus()`: 422 for the primary affiliate; status, impersonation end and domain suspension in one transaction; `propagateChange()` flushes caches after the commit.
- The portal profile returns `writable` and locks its form with the existing `InactiveNotice`; the admin dialog no longer says "503".

**Found on the way.** The admin Withdrawals page already flags a ticket whose bank details differ from the last approved payout, so that was not rebuilt. Three tests set `status` to `'deactivated'` or `'suspended'`, values the code silently treated as inactive; production holds only `active`.

**Verified.** Backend suite 4,017 green; `AffiliateWithdrawalConcurrencyTest` green on MySQL (the new migration runs there); portal `tsc`, lint and 22 tests; admin `tsc` and lint. Dev DB migrated. Live on the local Herd backend with a throwaway inactive affiliate: profile `writable: false`, `PUT /profile` and `PUT /storefront/branding` 403, `POST /storefront/pricing/preview` 200; the portal profile page (Playwright) shows the notice with all five inputs and Save disabled. The throwaway rows were deleted afterwards.

**For ADR-142 (reseller), recorded here so it is not lost:** the bot's `.topupbaki` (and `.info`, `.checkid`, `.list`, `.batalbaki`) still runs for a deactivated reseller: `ResellerBotService` blocks only `.baki`, `.trackorder` and `.listharga`, and `ResellerWalletTopupService::initiate()` has no `is_active` check, despite a comment saying it does. `ResellerOrderPlacementService` checks `is_active` before taking the wallet lock.

## 2026-11-16 — Reseller dormancy and one reseller status seam (ADR-142, PRD §16 item 193)

**Why.** The founder wants approved resellers who never use the platform switched off: no transaction for 7 days → warned, then deactivated (not closed, so no refund) until an admin reactivates. Grilled in three rounds and stress-tested against the code (ADR-142). Production: three resellers, all active; with 7 days, all three would have been deactivated at some point.

**Built** (`feature/reseller-dormancy`):
- `ResellerAccountStatus`: the one writer of `resellers.is_active` (no longer fillable; the model default mirrors the column). `deactivate()` / `reactivate()` hold the wallet `ledger_accounts` row, write `deactivation_reason` (`manual`, `dormant`, `closed`), `deactivated_at` and a `reseller_status_changes` row, and end portal tokens. The admin toggle and Close account go through it.
- One denial (`ResellerDenial`, English and Malay): the portal login now refuses a deactivated reseller with the reason; `EnsureAccountType`; `EnsureResellerApiKey` (`RESELLER_INACTIVE`, message names the reason); the bot refuses every command except `.batalbaki` (ADR-135 decision 8, found in the trace); `ResellerWalletTopupService::initiate()` checks too. This closes the live bug: `.topupbaki` ran for a deactivated reseller.
- `ResellerOrderPlacementService` re-reads the flag under the wallet lock.
- `ResellerDormancyService` + `app:deactivate-dormant-resellers` (02:00 UTC = 10:00 MYT, `ROUTINE`): activity is the latest `wallet_debit` or `wallet_topup` ledger entry; anchor = latest of activity, approval, last reactivation, switch-on time; warning at day (period − lead); deactivation only when a warning for the same anchor went out the full lead earlier, never with a pending top-up, re-checked under the lock; `reseller_dormancy_notices` unique per spell; WhatsApp via the outbox, email via Plunk, an admin digest only on a day something happened.
- Settings on `platform_settings` (switch off by default, 7 days, 3-day lead, WhatsApp number), `PUT /settings/reseller-dormancy-policy` behind step-up; Admin → Settings → Reseller Inactivity; the Resellers page shows the reason and date, a status filter, the auto-deactivate state and a status-history dialog.
- Reseller API docs v1.6.2 (the `RESELLER_INACTIVE` message names the reason; code unchanged).

**Two gotchas hit.**
1. **The concurrency suite caught a double spend I introduced.** The first version of the seam's lock called `openAccount()` (a plain `firstOrCreate` SELECT) before `lockForUpdate()`. MySQL REPEATABLE READ fixes a transaction's snapshot at its first plain read, so the debit's later balance read missed a rival order's committed debit: two simultaneous orders of RM9.45 both passed on a RM10 balance (`ResellerOrderPlacementConcurrencyTest`). Fixed: only locking reads before the lock; the account is opened only when missing. Rule worth keeping: inside a money transaction, take the lock before any plain SELECT.
2. **The first migration broke the local dev DB.** A foreign key on `resellers` made sqlite rebuild the table, and the `llm_report_*` views blocked the rename, leaving only `__temp__resellers` (4 rows, intact). Recovered from a file copy taken first: in one transaction the views were dropped, `resellers` recreated from a fresh schema's DDL, rows copied, the temp table dropped and the views recreated; `integrity_check` and `foreign_key_check` clean, schema identical to a fresh migrate. The migration now adds plain nullable columns only (who made a change lives in the history). Recorded as `backend/AGENTS.md` gotcha 10.

**Verified.** Backend suite green after merging `staging` (count below in the PR); reseller concurrency tests green on MySQL (placement, closure, top-up, refund). The two key tests fail without their fix (order from a stale active copy; `.topupbaki` while deactivated). Admin `tsc` and lint. Live on local: a dormant reseller's portal login answers 422 with the reason and the WhatsApp number; Playwright screenshots of Settings → Reseller Inactivity, the Resellers page (warn tag "inactive", "for inactivity, 16 Nov 2026") and the history dialog ("Deactivated for inactivity · automatic"). Throwaway rows deleted afterwards.

**After release (founder):** turn the switch on and set the WhatsApp number. Resellers #1 and #2 would then be warned 4 days later and deactivated 3 days after that unless they transact.

## 2026-11-17 — Release #806: affiliate deactivation hardening, reseller dormancy (switch off)

The founder asked for it. `staging` → `main`, merge `9a3b9674`, carrying #803 (docs: #802 record, Gamevion timeouts), #804 (ADR-058 2026-11-16 addendum) and #805 (ADR-142). Two additive migrations. CI run `43911091142` green in every job, `deploy` included.

**Checked before release (production, read-only):** no pending or processing order, no pending top-up, no pending migration. The 33 failed jobs of the previous 24 hours were all `SyncSupplierCatalogJob` timeouts, the last at 10:05 UTC on 2026-11-16, before Gamevion was switched off.

**Checked after release (production, read-only):**
- The box runs `9a3b967` (release `83143684`); both migrations ran (batch 74); Horizon running; `/api/health` ok on every check; Vercel production green for all four apps.
- `app:deactivate-dormant-resellers` is scheduled at 02:00 UTC (10:00 MYT); dormancy is off (7 days, 3-day lead); the 3 resellers are active with no deactivation reason; the three new tables exist; no failed job since the deploy.

**Open (founder):** turn on Admin → Settings → Reseller Inactivity and set the support WhatsApp number (PRD §16 item 193); item 191 (Drift Threshold 1,000 IDR).

## 2026-11-17 — Founder switches after release #806: dormancy on, drift threshold 1,000 IDR, player checks off for FF/GI/HSR/ZZZ

All three set by the founder in the admin; checked read-only on production.
- **Reseller dormancy (ADR-142, PRD §16 item 193):** on since 09:14 UTC, 7 days, 3-day lead, support WhatsApp set. Every reseller's clock starts 2026-11-17 (last transactions: FixFast 2026-10-05, Naeem Industries 2026-10-22, AyrisShop 2026-11-17), so the first warnings are due 2026-11-21 and deactivations 2026-11-24 unless they transact. FixFast's login was given to CHIP for its review; the founder leaves it to deactivate and an admin reactivates it if CHIP asks. (An existing account can't be marked internal: `internal_reason` is set only at creation.)
- **Digiflazz Drift Threshold (item 191, closed):** `api_config.drift_threshold` = 1000.
- **Player checks (item 186):** `player_validator_enabled` = 0 on the six Free Fire games, Genshin, HSR and ZZZ; the nine MLBB games stay on. The founder leaves the check off until RedxGame gives official access or another official checker appears; the item moves to group D.

## 2026-11-18 — Database memory alert: root cause, ADR-144, backups that restore every table

**Trigger.** DigitalOcean emailed "Memory Utilization is running high" (critical, above 90% for 5 minutes) for `topup-prod-mysql`, four times on 18 Nov (90.2% to 91.3%). The founder asked for the cause and for a decision, not a patch.

**Found (read-only: SSH, `SELECT`, DO Insights).** The cluster is 1 GiB / 1 vCPU, not ADR-020's 2 GiB, and nothing recorded the change (ADR-114 rebuild). Memory sat flat for 14 days (about 83% → 86%, spikes to about 91%), no restart, 9–10 connections; November orders went from 39 (October) to 613 with no change in memory. Pulse was the database's main workload: 217k entries a week against about 22k business rows, 87% of them `cache_hit`/`cache_miss`, and `pulse_*` was 72 MB of the 101 MB database. Small tables (4–5 rows) answered in 0.3–2 s now and then; 2.4 million table-cache overflows. Full numbers: ADR-144 Context.

**Found while tracing the backup path (a latent bug, since ADR-039).** `exclude_tables` is `mysqldump --ignore-table`, which drops structure as well as rows, so no backup ever held `player_validations` or `order_credentials`. A disaster restore would have lacked both (Game ID check and Login Top-up down) while `migrations` said they existed. The restore test passed because it skipped them. mysqldump 8.4 on the box has no `--ignore-table-data`.

**Decided (ADR-144, grilled in three rounds, then stress-tested).** Keep 1 GiB on purpose; upgrade at the 95% alert, a database restart, or the 2026-12-03 small-table check (PRD §16 item 195). Pulse's `CacheInteractions` off by default. A structure-only companion dump for the excluded tables. Audit tables kept in full, revisit at 1 million rows or 500 MB. The stress test changed two answers (Redis `INFO` cannot stand in for Pulse's per-key hit rate; an order-count trigger had already been passed 15x without memory moving) and found four details that would have broken the build, below.

**Built (`fix/db-telemetry-hygiene`).**
- `config/database.php`: one `$dumpExcludedTables` list; a `mysql_dump_schema` connection, the mysql connection with the same dump flags plus `include_tables` and `do_not_dump_data`.
- `config/backup.php`: the companion is dumped only on MySQL; `database_dump_filename_base` = `connection`.
- `BackupRestorer`: `extractDumps()` returns every dump; `restore()` restores all of them under one empty-target check; `verify()` adds `excluded_tables_with_rows` (fails) and `excluded_tables_absent` (reported). `BackupRestoreTester` demands every live table once backups carry the companion. `app:restore-backup` warns when an older archive lacks the excluded tables.
- `config/pulse.php`: `CacheInteractions` defaults to off; the dashboard shows the Cache card only while it is on.

**Gotchas the stress test caught before they shipped.**
1. Spatie names a dump `{type}-{database}.sql`: the companion, being the same database, would have **overwritten the full dump** in the archive. Fixed by naming dumps by connection.
2. `extractDump()` returned only `$dumps[0]`, so the companion would never have been restored.
3. A companion without `--set-gtid-purged=OFF` and `--single-transaction` would fail to restore on DigitalOcean (the 2026-09-14 GTID incident) or lock live tables (ADR-099). Both dumps now share one flag set, and a test asserts both commands carry the flags.
4. Spatie silently skips a dump option it does not know, so a typo in `do_not_dump_data` would copy customer PII into every backup with no error. The new invariant (an excluded table must come back empty) fails the restore test in that case.

**Verified.** Backend suite 4047/4047 green locally; new tests: the real mysqldump commands for both dumps (`BackupDumpConfigTest`), restore of every dump, the excluded-table checks, and the operator command's warning. The real-MySQL proof (`BackupRestoreMysqlTest::test_the_excluded_tables_come_back_empty_with_their_structure`, a seeded `player_validations` row must not come back) runs in CI's concurrency job; this machine has no MySQL client. `config:cache` with `DB_CONNECTION=mysql` resolves both dump sources and the Pulse default.

**Done the same day (founder's request, through the founder's browser):** the DigitalOcean policy "Memory Utilization is running high" on `topup-prod-mysql` changed from 90% to 95% for 5 minutes, same email recipient; re-opened afterwards and confirmed at 95 (PRD §16 item 11).

**Open.** After release: Pulse's weekly entries down about 87%, the next nightly archive holds two dumps and its restore test passes with `excluded_tables_absent` empty, then the 2026-12-03 check (item 195).

## 2026-11-18 — Release #811: ADR-144 (backups restore every table, Pulse cache recorder off)

The founder asked for it and merged it. `staging` → `main`, merge `77762e37`, carrying #810 (ADR-144) and the docs PRs #807 and #808. No migration, no env change. CI green, `deploy` job succeeded.

**Checked before release (production, read-only):** no pending or processing order, 0 failed jobs in the previous 24 hours, backup run #109 (02:07 UTC) success with restore test passed, no pending migration.

**Checked after release (production, read-only):**
- The box runs `77762e3` (release `83310044`). Horizon was inactive and `/api/health` 503 for about 40 s while the deploy restarted it (release created 07:44:42 UTC, health 200 from 07:45:26); then `running`, 13 workers.
- Config: backup sources `["mysql","mysql_dump_schema"]`, dump names by connection; `CacheInteractions` off. Pulse entries after 07:45:30 UTC: `user_request` only, no `cache_hit`/`cache_miss`.
- **Backup run #110** (manual, the founder's "Backup Now", 07:48 UTC): success. The archive holds `db-dumps/mysql-mysql.sql` (44 MB) and `db-dumps/mysql-mysql_dump_schema.sql` (3.4 KB, structure only), both AES-256. Restore test passed with 140 tables (#109: 138; the two are `player_validations` and `order_credentials`), `excluded_tables_absent` and `excluded_tables_with_rows` both empty. Nothing left in `storage/app/backup-temp`.

**Open:** PRD §16 item 195's check on 2026-12-03 (small-table slow queries, Pulse volume).

## 2026-11-19 — Digiflazz Gagal callbacks lost the failure reason (PRD §16 item 196)

**Found (read-only prod audit, founder's request).** Every failed combo leg on production (20 of 20) had `order_delivery_legs.failure_reason` = NULL, so the admin's combo leg breakdown showed "—"; the reason ("Nomor Tujuan Salah", "Transaksi Gagal") was only in `laravel.log`. Tracing every writer and reader showed it was wider: 21 failed plain orders stored the raw callback with no `error_code`/`error_message`, so Order Detail's "Latest supplier result" showed no code or message either (the raw JSON sat behind the disclosure).

**Cause.** Five producers hand the finalize seam its evidence: the first attempt, the poll for an order and for a leg, the Gamevion webhook, and the Digiflazz webhook. Only the Digiflazz webhook passed Digiflazz's raw payload (`rc`, `message`); the others write `error_code`/`error_message`, which is what every reader uses (leg `failure_reason`, Order Detail, NeedsReview banner, Resend modal, `Order::deliveryRetryUnsafeWithSameReference()`, the reconcile reclassification).

**Built (`fix/digiflazz-webhook-failure-evidence`).** `DigiflazzWebhookController::evidenceFrom()`, used by the plain and combo branches: on Gagal it adds `error_code` = `rc` and `error_message` = `message` (the same mapping `DigiflazzAdapter` uses for `SupplierResponse::failure()`) and keeps the raw payload. Fixed at the one producer that drifted, not at the leg reader, so plain orders are fixed too and there is one evidence shape.

**Checked for delivery-state effects.** `error_code` drives two pieces of logic: the reconcile reclassification (needs_review → failed, Digiflazz codes) and the Resend disable (NeedsReview only). A Gagal callback always passes `outcomeConfirmedFailed`, so it lands on Failed, never NeedsReview; neither path changes for these orders, and the code written is the one the poll already writes.

**Verified.** The existing Gagal webhook tests now assert the evidence keys (plain) and `failure_reason` (both combo tests); red before the fix, green after. Backend suite 4047/4047; concurrency `FinalizePendingDelivery|OrderDeliveryLeg` 3/3 on MySQL. The 41 old rows stay as they are (history; the raw payload still holds the reason).

## 2026-11-19 — CHIP fee counted twice on overlapping settlement files (PRD §16 item 197, ADR-110 addendum)

**Found (read-only money audit, three forks: money in, cost and ledger, reports).** Every money-in, cost and ledger invariant held on production (details in the ADR-110 2026-11-19 addendum and below). One reporting bug: November's "Payment processing net gain/(loss)" read −RM14.81; the real figure is −RM0.03. Consecutive CHIP exports share their boundary day; the shared transaction is reconciled once (by the first upload), but the line subtracted the later file's whole fee total, shared day included.

**Built (`fix/settlement-fee-overlap-double-count`).** `MonthlyAccountingSummaryService::paymentProcessingGainLoss()` sums `local_fee_sen − fee_sen` over the `chip_settled_transactions` of the settlements whose `date_to` is in the month. One seam, every reader (Monthly Summary, operating profit, the Reports profit bridge, the month close) follows. No migration, no frontend change (the line's definitions still read true).

**Stress-tested before the code.** Unmatched rows (CHIP's fee still a loss, as before), the Settlement re-check (writes no settlement row), concurrent uploads (a losing row is never inserted), the period key (kept on `date_to`: per-row `settled_on` would move #7's 31 October rows into closed October). On prod, the new formula equals the old for settlements #1–#7 and differs on #8/#9 by exactly the shared day; September 0 and October 0 as closed, November −3 sen.

**Verified.** New end-to-end test (two overlapping files through the real ingest; −100 sen before the fix, 0 after) and the three existing period tests rewritten onto reconciled rows, with file totals set to a decoy so a regression to file totals fails. Backend 4048/4048.

**Audit notes for the November close (founder).** CHIP settlement files for 11–19 Nov not uploaded yet (380 orders, RM14,504.75); no KOL fee payment recorded for November (RM1,600 accrued). Accepted by ADR: about RM35 order-level cost above funded cost in November, from deposits recorded hours into the transfer day (ADR-134 decision 14). Voucher liability rolls forward to the sen (RM39.20); Digiflazz ledger equals its balance; every wallet and ledger invariant holds.

## 2026-11-20 — KOL fee as a share of sales, with a frozen monthly statement (PRD §16 item 165, ADR-118 2026-11-19 addendum)

**Decided (founder grill, 2026-11-19; stress test, 2026-11-20).** A share of the campaign's recognised **sales**, not profit: sales can be proven to a KOL line by line and reveal no cost. One fee row carries a fixed fee, a share (basis points) and an optional cap. The share never exceeds the campaign's profit that month and is at most the promo margin floor (one setting). The two-fork stress test (money, code) changed four decisions before any code: a reopen voids the statement and the re-close freezes a new one (the first text relied on drift, which carries only operating profit, and KOL fees sit outside it); the floor can't be lowered under a running share; terms are kept apart from amounts (`covers_until` counts the fixed fee only); closed months read frozen rows (a per-month recomputation was ~3,600 queries at 50 campaigns × 24 months).

**Built (`feature/kol-revenue-share`).**
- Migration `2026_11_24_100000`: `campaign_fees.revenue_share_bp` (default 0) and `revenue_share_cap_sen`; table `campaign_month_statements` (one per campaign per close, append-only except `voided_at`, unique on campaign + close).
- `RecognisedRevenue::attributedSales()`: the one base for the share, the profit limit and the statement lines (delivered orders at `selling_price`, settled partials at their kept amount; Failed and test orders never).
- `CampaignFeeSchedule`: `termsFor()` (the deal), `feeForMonth()` (frozen payable once closed, else live), `monthFigures()`, `freezeMonth()` / `voidMonth()` (the only statement writers), `assertFloorAllows()`. Every existing fee reader (Campaigns page, scorecard, Monthly Summary KOL lines, close net, Envelope preview) already routed through it.
- `MonthCloseService::close()` freezes after writing the close, in its transaction; `reopen()` voids with the close.
- `GET /api/campaign-statements/{statement}/pdf`: the Sales Statement (dompdf, the receipt pattern): shortened order number, date, game, package, player ID masked by `ContactMask::identifier()`, sales; the share computed once on the total; no cost or profit; a re-close says what it replaces, a voided one says it was replaced.
- Admin: the fee form takes "Share of sales (%)" and "Share cap"; the list and the Envelope campaign picker show terms ("RM 100.00 + 2% of sales"); the campaign page shows this month's live share and a Statements table with the PDF.

**Verified.** `CampaignRevenueShareTest` (9, red first): the share base (attributed, recognised, partial kept amount; Failed, test, other month and other campaign excluded), the profit and cap limits (never negative, the fixed fee stands), the floor at row write and on the setting, the close freezing every covered campaign with masked lines and `profit_lines.kol_fees_accrued_sen` = Σ frozen payable, a late stamp not moving a closed month, reopen → void → re-close, the fixed-only `covers_until`, ≤ 3 queries for 5 campaigns' accrued totals, the API and the PDF. Backend 4057/4057; admin `tsc`, lint, tests. Browser (throwaway sqlite backend, admin dev server): list terms, campaign page, the PDF downloaded and read (KLuq October: RM 450.50 sales, 2% = RM 9.01 + RM 100 fixed = RM 109.01; Mik4ca capped at RM 5), and the floor error on a 3% row.

**Gotcha.** CI's MySQL job failed on the first push: Laravel's default name for the statement table's unique index is 72 characters, over MySQL's 64 (sqlite accepts it). Named explicitly; the KOL, fee and month-close tests then passed on local MySQL (26/26).

**Not done.** No production data changes; the four live campaigns are fixed-fee rows (`revenue_share_bp` 0) and read exactly as before. Months closed before this release (September, October) have no statements; their fee is the fixed fee, which no row can change.

## 2026-11-20 — Server patching: a daily alert, a patch-window runbook, a smaller attack surface (ADR-145, PRD §16 item 198)

**Trigger.** The founder forwarded another company's internal notice about the 2026-11-19 PHP security release (8.5.13, 8.4.28, 8.3.37, 8.2.36; seven critical issues) and handed Claude the decision.

**Audit (read-only).**
- The box runs PHP 8.4.27. Ondrej's PPA, through both the `ppa.setup-php.com` mirror and Launchpad, still had 8.4.27 on 2026-11-20.
- unattended-upgrades covers Ubuntu's security pocket only, so PHP, nginx, Redis, Node and the MySQL client are never patched by it.
- `/var/run/reboot-required` is present: the running kernel is 6.8.0-124, with 142 to 149 and `libc6` installed but waiting. 40 packages are upgradable.
- CVE exposure: FPM smuggling needs `fastcgi_keep_conn` (not set). Upload progress needs a runtime switch of a non-default `serialize_handler` (ours is `php`; Laravel uses its own sessions). The timezone use-after-free has no published preconditions yet.
- The origin rejects non-Cloudflare requests (Authenticated Origin Pulls: 400 when hit directly).
- Redis and Memcached listen on `0.0.0.0` but are closed from outside. All Redis clients are local; Memcached has none.
- FPM loads six extensions the app doesn't require (`composer check-platform-reqs`).
- The quietest hours over the last 30 days are 03:00 and 06:00 MYT.

**Decided (ADR-145).**
- No maintenance mode.
- Third-party repositories are patched in planned windows (full upgrade, then reboot), not automatically.
- A daily alert closes the "no signal" gap.
- The first window also binds Redis to localhost and purges Memcached and the unused extensions.

**Built (`feature/server-patch-alert`).**
- `App\Services\Ops\ServerPatchCheck` checks the running PHP against `php.net/releases/?json=&version=<branch>`, plus `reboot-required` and its package list, de-duplicated.
- `app:check-server-patches` runs as task `server-patch-check`, daily at 01:00 UTC (09:00 MYT), routine tier. It emails through `BackupFailureAlerter` (area "Server") once per problem, then every 7 days while open. When php.net fails, it logs a warning and sends nothing.
- Checked on the box (read-only tinker): php.net answers 200 with 8.4.28, and the reboot file and its package list are readable as `forge`.

**Verified.** `CheckServerPatchesCommandTest` (5 tests, red first) and `TaskScheduleTest` pass. Backend 4062/4062.

## 2026-11-20 — OpenWA v0.29.1 → v0.30.3, `TRUSTED_PROXIES`, retired reply job deleted (PRD §16 item 183)

**Why v0.30.3, not v0.30.0.** Item 183 named v0.30.0 for the inbound-media crash fix (`TypeError: terminated`) and the `passkey_required` restriction. v0.30.1–v0.30.3 add, among others, an ack for the top-level `<status>` stanza that made WhatsApp end a Baileys stream about 50 minutes later (`Stream Errored (ack)`). Read before upgrading: the release notes and `docs/14-migration-guide.md` for v0.30.0–v0.30.3 (every upgrade hazard is MCP-only, and we don't use MCP), no OpenWA DB migration between the two tags, `.env.example` changed only an MCP comment, engines `node >=22.19` (box: 22.23.4). Nothing in the repo filters on `baileys_connection_dropped`, which v0.30.0 renamed for the post-link 515 close.

**`TRUSTED_PROXIES`.** The path is Cloudflare → nginx on the box → `127.0.0.1:2785`. nginx already rewrites `$remote_addr` to the real client from the Cloudflare ranges (`/etc/nginx/conf.d/cloudflare.conf`, `real_ip_header X-Forwarded-For`) and appends it to `X-Forwarded-For`; OpenWA walks that header right to left past trusted hops (`src/common/utils/ip.ts`). So `127.0.0.1,::1` is enough, without the Cloudflare ranges.

**What ran (founder, 2026-11-20).**
- `scripts/openwa-upgrade.sh build v0.30.3`: built in the background past the 120 s tool limit, as expected. Both new install-time patches applied (`patch-baileys-media-stream`, `patch-baileys-status-ack`).
- `TRUSTED_PROXIES=127.0.0.1,::1` appended to `~/openwa-deploy/shared/.env` (idempotent guard).
- Daemon stopped in Forge, `switch v0.30.3` (backup `~/openwa-backups/openwa-v0.29.1-20261120-0825.tar.gz`, 232M), daemon started. Down about 08:25–08:26:43 UTC.

**Before / after (read-only).**
- Before: `/api/health/whatsapp` `ok`, both sessions `ready`; OpenWA 0.29.1; 20 "TRUSTED_PROXIES is empty" warnings in the daemon log.
- After: the process runs from `releases/v0.30.3`; both sessions auto-started and `ready` within 6 s; `/api/health/whatsapp` `ok`; five `session.status` and one `message.sent` webhook events `dispatched`, 0 delivery failures since the restart; a real CS message sent at 08:27:07 UTC; 0 `TRUSTED_PROXIES` warnings since boot.
- The boot warnings `API_KEY_PEPPER is not set` and `No explicit CORS_ORIGINS` are not new: 26 of each since 2026-09-25.

**Code.** `SendResellerBotReplyJob` and its test deleted (no dispatcher since the ADR-116 2026-11-14 addendum; 0 queued on prod). Its 7 old `failed_jobs` rows can no longer be retried, which is fine: they are lost bot replies from before the outbox. `HorizonQueueCoverageTest` loses its queue-pin test; `WhatsAppHardeningEdgesTest` now asserts `SendCustomerWhatsAppJob` is the only `sendNow()` caller.

## 2026-11-21 — First server patch window: PHP 8.4.28, kernel 6.8.0-151, Redis on localhost (PRD §16 item 198, ADR-145)

**What ran (founder, over SSH as `forge`, 20:59–21:12 UTC = 04:59–05:12 MYT).** The `forge` sudo password had been lost. Forge doesn't store it and has no reset button, and our SSH key is refused for `root`. The founder set a new one with `passwd forge` from DigitalOcean's Droplet Console, which needs no reboot. Then the ADR-145 runbook:
- The PPA had `php8.4-fpm` 8.4.28-2 as candidate.
- `redis.conf` line 93 `bind 0.0.0.0 -::1` → `bind 127.0.0.1 -::1`, then Redis was restarted. `ss` showed only `127.0.0.1:6379` and `[::1]:6379`, and `redis-cli ping` answered `PONG`. Claude checked right after: Horizon running, `/api/health` 200.
- Purged `memcached`, `php8.4-{memcached,imap,swoole,pgsql,soap,imagick}`.
- `apt update`: the Ondrej PPA's label changed to "PPA for PHP (deprecated)", and the founder accepted it with `y` (ADR-145 2026-11-21 addendum).
- Non-interactive `full-upgrade` with `--force-confold`: 61 upgraded, 14 new, 11 systemd packages deferred by Ubuntu's phasing. `redis.conf` was kept ("Keeping old config file as default"). `autoremove` removed kernel 149 and the Memcached/IMAP/pgsql libraries.
- `sudo reboot` at 21:10:49 UTC. SSH answered again within a minute.

**Before → after.**

| | Before | After |
| --- | --- | --- |
| PHP CLI / FPM | 8.4.27 / 8.4.27 | 8.4.28 / 8.4.28 |
| Running kernel | 6.8.0-124 (up 57 days) | 6.8.0-151 (124 kept as the GRUB fallback) |
| `/var/run/reboot-required` | present (`libc6`, kernels 142–149) | gone |
| nginx / Redis / MySQL client / Node | 1.31.6 / 8.8.1 / 8.4.11 / 22.23.4 | 1.31.9 / 8.10.3 / 8.4.13 / 22.23.5 |
| Listeners | Redis `0.0.0.0:6379`, Memcached `0.0.0.0:11211` | Redis `127.0.0.1` and `[::1]` only, 11211 closed |
| Pending upgrades | 74 | 11 (phased systemd) |

**Verified after the reboot (read-only).**
- `php8.4-fpm`, nginx, redis-server, supervisor active, `systemctl --failed` empty.
- `/api/health` 200, Horizon running.
- `SchedulerLiveness::criticalTasksHealthy()` true, with tasks recorded at 21:13 UTC.
- OpenWA auto-started, both sessions `Session ready` at 21:11:56 and 21:11:59 UTC, local health 200.

**nginx CVEs closed by this window.** DigitalOcean emailed the founder at 03:46 MYT the same morning about CVE-2026-123633 and CVE-2026-123007: critical, CVSS 9.2, unauthenticated code execution through HTTP/2, fixed in 1.30.7 stable and 1.31.9 mainline. The box serves HTTP/2 (`http2 on;` in 5 server blocks), from nginx.org's mainline repository, so the upstream version is the proof. After the window it runs 1.31.9, with a master process started at 21:11:33 UTC, after the reboot. Exposure ran from disclosure on 2026-11-18 to this window, with the origin reachable only through Cloudflare.

**Not yet seen.** A paid order created after the reboot that delivers. The daily `server-patch-check` sending nothing can't be checked until #816 is released: it is on `staging` only.

**Gotchas.**
- A plain `apt full-upgrade -y` would have stopped at dpkg's conffile question for the edited `redis.conf`. The runbook now uses `--force-confold` (ADR-145 addendum).
- Harmless noise: apparmor's postinst `Illegal number: yes`, `my.cnf` alternative "broken" (the stopped local `mysql-server`), and the motd showing 100% swap before the reboot (12 MB after).
- Not caused by the window: order #853's leg 1 failed at Digiflazz with "Cut Off (Perbaikan Sistem Seller)", a supplier maintenance cut-off. Its first attempt was at 20:41 UTC, before the window. Leg 2 delivered, so the order shows `partially_delivered` for an admin to resend or settle.

## 2026-11-21 — Pre-release review of #820: KOL share input fix, patch-alert wording

The founder asked for a review of everything in release #820 (#813–#819) before it merges to `main`: maintainable, reliable, no patching over.

**Checked and kept.**
- `CampaignFeeSchedule`: the share, limit and freeze maths.
- Settled partials: `platform_profit` is already the kept profit (`OrderSettlementService`), the same base the ADR-139 rebate reads.
- Every `feeForMonth` reader: the Monthly Summary and the ReportService scorecard.
- The frontend move from `monthly_fee_sen` to `terms`.
- #814's other readers of `file_fee_sen` / `matched_fee_sen`: none feed the P&L.
- #813's `evidenceFrom()`.
- The append-only guard on `CampaignMonthStatement`'s model events. It mirrors `LedgerEntry` and `BudgetEnvelopePosting`, and the one write path (`freezeMonth` / `voidMonth`) is the real seam.

**Fixed: a typed "2%" saved a 0% KOL share.** The admin fee form turned the share with `Math.round(Number(share) * 100)`. `"2%"` and `"1,5"` gave `NaN`, which JSON sends as `null`, and the backend saved `revenue_share_bp` = 0 with no error. A fee row can't be edited once saved, and a new row must start after the latest one, so that month could not be corrected. `admin/src/lib/campaign-input.ts` `toBasisPoints()` accepts `2`, `2%` and `1.5`, and turns anything unreadable into -1, which the server rejects (`campaign-input.test.ts`, 3 tests). It is a separate module because `campaigns.ts` imports `@/` aliases, which the admin's `node --test` runner can't resolve.

**Wording: the patch alert no longer hardcodes `php8.4-fpm` or names Ondrej's PPA.** The branch comes from the running PHP, and the source is about to move (PRD §16 item 199).

## 2026-11-21 — Release #820 (`staging`→`main`)

**What shipped.** #813, #814, #815, #816, #818, #821, and docs #809, #812, #817, #819 (PRD §14). The founder merged #820 after the first patch window and the pre-release review (#821). Main CI was green on every job, including `deploy` and `playwright`. Merge `74385e7d`, box release `83604218`.

**Checked on prod after the deploy (read-only).**
- Migration `2026_11_24_100000_add_revenue_share_to_campaign_fees` ran in batch 75.
- `/api/health` 200, Horizon running, `criticalTasksHealthy()` true, 0 failed jobs since the deploy.
- All 7 `campaign_fees` rows read `revenue_share_bp` = 0, so every KOL fee is unchanged until a share is set. `campaign_month_statements` is empty. November's close (in December) freezes the first ones.
- `server-patch-check` is scheduled (`0 1 * * *`, 09:00 MYT). `ServerPatchCheck::problems()` run by hand returns `[]`: PHP 8.4.28 is current, and no reboot is waiting. The first scheduled run should send nothing.
