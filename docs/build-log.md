# PekanGame — Build Log

> The running chronological record of what shipped, when, and why — every
> session, the bugs hit along the way, and the live-verification notes.
> Moved out of `prd.md` §14 on 2026-09-11 once it outgrew the spec doc.
>
> **This file is append-only history.** Current per-feature status lives in
> `prd.md` §15; the live backlog is `prd.md` §16; decision rationale is the
> ADR log (`adr.md` index → `adr/`). **Everything before 2026-10-23** lives verbatim in
> [`build-log-archive.md`](./build-log-archive.md): the foundation build, the
> old §14 table and PrimeReact tracker, and the production-era entries
> 2026-09-01 → 10-22 (moved in nine passes, the last on 2026-11-01) to keep
> this file quick to read at session start.

---

## 2026-10-23 — First real Login Top-up order on prod; FPX on for CHIP's review; item 129 on hold

**Status check (read-only).** The first encrypted database backup (run #57, 02:07 UTC) and the first scheduled OpenWA backup (#56, 19:37 UTC 2026-10-22) both passed their restore tests. `/api/health` is back to 200. Digiflazz is at IDR 797,643, which the founder accepts because no buyers are expected right now.

**FPX is on, on purpose.** `fpx_chip` has been active since 2026-10-22 09:52 UTC so CHIP can review the site. The founder will switch it off again. PRD §14 had said all three methods were off; corrected.

**Item 129 on hold.** The founder says the refund-to-original-method wording exists for CHIP's merchant review, and the policy goes back to voucher-only once CHIP approves. Moved to PRD §16 group D with that trigger.

**Login Top-up, end to end on prod (ADR-124/125, first real order).** The founder bought PG-YTVHCNMQ2SZX (Roblox, test package "0 Robux", Gamevion Manual, `pay_after`), paid RM1.57 through FPX. Checked read-only at each step:
- Paid at 04:36:42 UTC, order `pending`, `initial` attempt `pending`; `order_credentials` held the username in plain and the five secret fields encrypted, with the consent text.
- The `supplier_order_post` was sent to the supplier group at 04:36:51. The founder opened the full-login link at 04:37:22, which wrote five `credential_reveals` rows (actor supplier, with IP).
- `.done` in the group at 04:42:00: order `delivered`, real cost 50 sen (the agreed cost), profit 7 sen, the `initial` attempt resolved `success` plus a `manual_confirm` row naming `wa:601118884011` (decision 42; no reader sums attempt costs). Gamevion Manual got one `ORDER_DRAWDOWN` of −RM0.50, the amount owed. The bot replied in the group (the founder confirmed; the reply is a direct send, not queued).
- The link stops working once the order leaves `pending`. `app:wipe-order-credentials` stamped `closed_at` at 04:45:07; the secrets are wiped G = 24 h later.
- Track Order shows Paid / Delivered with the "change your game password" line (founder's screenshot).

## 2026-10-23 — Item 130: a balance check never throws (ADR-069 addendum)

**Root cause, from the prod log of 2026-10-22 00:00:26 UTC.** Gamevion refused connections; `GamevionAdapter::checkBalance()` let the `ConnectionException` through, and `CircuitBreakingSupplierAdapter` rethrows it on purpose (K-3: `createOrder` needs the throw for M-1). `app:refresh-supplier-balances` didn't catch it, so the sweep exited 1 at Gamevion. Digiflazz, next in line, wasn't refreshed that day, and the CRITICAL heartbeat turned `/api/health` red. The same throw 500'd Refresh Balance, the credential probe and Developer Tools. The item's first framing ("health judges an unfunded supplier") would have patched only the symptom.

**Fix, at the seam.** The breaker decorator returns a connection failure as `CONNECTION_FAILED` for `checkBalance` only, after counting it once. Order calls and `listProducts` are unchanged. The daily refresh now isolates each supplier: an unreachable one is recorded and the task succeeds; an unexpected exception is recorded `failed: [ERROR] …`, reported, the rest still refresh, and the task exits FAILURE.

**Tests (red first).** The breaker test that used `checkBalance` to prove propagation now uses `createOrder`/`checkStatus`. New: a balance connection failure is returned and counted once (threshold 2); the daily refresh keeps refreshing Digiflazz after an unreachable Gamevion (real decorator) and after a crashing one (task fails); Refresh Balance on an unreachable supplier (real Gamevion binding, `Http::failedConnection`) returns 200 with `CONNECTION_FAILED` and keeps the last balance (it was a 500). Full suite: 2958 passed. No migration, no frontend change.

## 2026-10-23 — Joki (item 114): ADR-126 re-traced and re-grilled before building

**Why.** ADR-126 dated from 2026-10-17, before ADR-125's decisions 48–64 were built. The founder asked for a full re-check before building ("tidak tampal menampal").

**How.**
- Three read-only code-trace forks, one per layer: checkout/pricing, ledger/fulfilment, downstream readers. Their top findings were re-checked by hand.
- A grill with the founder in Malay (S1–S26), then a final stress test (F1–F5).
- Competitor price lists (Gamevion, Muzzzyyy) compared against a draft rate table.

**Findings that changed the design:**
- **Post dedupe key.** It had no supplier, so an assigned penjoki's group would never get the post.
- **Reassign vs `.done` race.** The old penjoki's `.done` could book the drawdown against the new penjoki.
- **Resend.** It rewrites `cost_price` and `supplier_id` from the package, even on a plain retry.
- **Bulk Markup.** It rewrites every non-combo package's price.
- **Stale rule.** It reads `orders.updated_at`, and the manual SLA maximum is 1 day.
- **Wipe ceiling.** D = 14 days for every login.
- **`PricingBasis::label()`.** It would throw on a new case, crashing the Reports export.
- **Revenue month.** Revenue is scoped by `paid_at` but the customer liability by `delivered_at`, which drifts closed months for a multi-day joki. That is now item 131, a launch gate.

**Result.** The ADR-126 2026-10-23 addendum (25 decisions), with pointer addenda in ADR-124 and ADR-125. Item 114 moves to group A, with five PRs and launch gates. The rapspoint page refused automated fetches (HTTP 403), so the draft rates use the 2026-10-17 rates recorded in ADR-126 at the platform's own IDR rate (0.000226, 2026-10-23). Competitor prices confirmed the 25/50-star Mythic thresholds.

## 2026-10-23 — Joki PR 1 (item 114): shared manual-order seams (ADR-126 decisions 17, 18, 20, 22), PR #533 to `staging`

Nothing joki-specific yet. Every manual order, which today means Login Top-up, gets these fixes.

- **Post key (17).** The dedupe key is `…:ref:<ref>:supplier:<id>`. RETRY compares the reference only, so a reassigned order's first post to a new group reads ORDER BARU. A migration appends each existing row's current supplier. Nothing reassigns an order yet, so that supplier is the one the post went to.
- **Supplier re-check (18).** `finalizePendingDelivery()` takes an optional `expectedSupplierId` and refuses inside its own lock, writing nothing. A first version wrapped finalize in an outer transaction instead. It was dropped because a contradicting result's `late_supplier_result` evidence, which finalize commits before throwing, would have been rolled back. The admin sends the supplier on screen (required by both FormRequests), and the bot sends the group's supplier.
- **Stale clock (20).** `orders.manual_activity_at` is stamped in `SupplierGroupNotifier::prepare()` when the post is actually sent, so a post skipped for having no group starts no clock. `Order::pendingClock()` returns it, else `updated_at`. The SLA reminder's minutes and `.pending` read it too. SLA max is 4320 minutes.
- **Login ceiling (22).** `order_credentials.secrets_expire_at` (NOT NULL) comes from `OrderCredential::expiryFor()`. `games.credential_max_days` is set in Edit Game, for a login game only. Before, the sweep read D live, so lowering D applied to every held login at once. To keep that behaviour, changing D or a game's days recomputes the unwiped logins it governs (`refreshExpiry()`).
- **Gotchas.** The migration loops use `get()`, not `each()`: `each()` pages by offset while the loop rewrites the column it filters on, which skips rows. Testing the migrations on docker MySQL left the `llm_report_*` views behind, and every concurrency test then errored (`backend/AGENTS.md`, gotcha 8).
- **Verified.** `php artisan test`: 2969 passed, including 11 new tests, red first. Concurrency suite: 33/37; the 4 are `BackupRestoreMysqlTest` (no local `mysqldump`). Migrations on MySQL: rewrite, backfill (set_at + D), NOT NULL and `down()`. Admin: tsc, lint, build, 17 unit tests. e2e: 8/8 on the last full run. Two earlier full runs each had one spec fail once (`admin-login-topup`, `storefront-checkout`), and both passed alone and on the rerun. `admin-login-topup` now sets a login game's days to 30 and asserts a 30-day ceiling on the order.

## 2026-10-23 — Joki PR 2a (item 114): the Fixed basis, the assignment pool and Assign

PR 2 was split in two with the founder's agreement: 2a is the money core, 2b the joki catalog and checkout. No customer can buy joki yet.

- **Fixed basis.** `PricingBasis::Fixed` ("Fixed (joki)") and `OrderPricingResolver::resolveFixed(selling, share%)`: payout = `round(selling × share)` to the sen, platform = the rest, affiliate = 0. `Order::isJoki()` is the one predicate, and every path that could move the payout asks it:
  - resend keeps the cost and refuses another package;
  - a joki attempt after Failed/NeedsReview restarts on its package's supplier, the pool, inside `fulfill()`'s lock;
  - Manual Delivered refuses any other cost;
  - Bulk Markup skips joki games.

  The only writers of `orders.cost_price` are OrderFactory and OrderResendService (grep), and both are covered.
- **Catalog.** `games.is_joki`, and `AffiliateGame::hiddenGameIdsFor()` now always includes joki games. The affiliate portal's two copies of the rule were removed and route through it. The portal's game list leaves joki out, and its toggle 404s. A `not.joki` middleware covers every Games & Packages write route, and `GameFulfilmentRule::manualPackage` refuses a penjoki or the pool as a package supplier.
- **Pool.** `suppliers.joki_role` (`pool` or `penjoki`; one column instead of the ADR's `is_assignment_pool` flag), plus the pool row created by the migration. `Supplier::isAssignmentPool()` is read by:
  - the group post, the login link and the SLA reminder (none for the pool);
  - Delivered and Correct-to-Delivered (refused);
  - `setActive` (refused; F5);
  - `SupplierPackageStatusService` and group linking (refused);
  - the Dashboard "no group" and "not posted" counts and the Health supplier cards;
  - the Middleware and Partners supplier lists. The manual-package supplier picker leaves out every joki role.
- **Assign.** `JokiAssignmentService`, `POST /api/orders/{order}/joki/assign`, with a `joki_assignments` audit row. It takes the order lock, then the penjoki's lock, which is the same lock `setActive` now takes, so a penjoki with Pending orders can't be switched off (decision 19). Allowed for a Pending joki order only, to an active penjoki with a group, and never to the same penjoki.
- **Found while building.**
  - Reassigning A → B → A: the decision-17 key `(ref, supplier)` already had A's sent row, so A would get no post, and A's first link would have opened again. The key now ends `:assign:<id>` for an assigned order, and `supplier_login_links.joki_assignment_id` must match the order's current assignment. `customer_notifications.dedupe_key` widened 100 → 191.
  - The migration's `down()` first deleted the pool and failed on the packages FK during the MySQL concurrency teardown. It now deletes the pool only when nothing references it; otherwise it switches the pool off.
- **Verified.**
  - `php artisan test`: 2986/2987. The one failure, `SyncSupplierProductsCommandTest::test_command_syncs_every_active_supplier_via_its_own_adapter`, passed alone and in two runs of the Console suite. That command reads `apiBacked()` suppliers only, so the pool can't reach it.
  - New tests: `Adr126JokiCoreTest` (17), a portal test, and the route allowlist.
  - Concurrency suite: 35/39. The 4 are `BackupRestoreMysqlTest` (no local `mysqldump`). The new `JokiAssignConcurrencyTest` covers Assign vs the old penjoki's `.done` and Assign vs switch-off; both passed 4 runs in a row.
  - Admin `tsc` passed (`pricing_basis` gains `fixed`).
- **Left for PR 4 on purpose:** joki out of the Manual Queue and Need Action (F4), together with the Joki page and Dashboard card that replace them. Until then a pool order past the pool's SLA appears as stale.

## 2026-10-23 — Joki PR 2b (item 114): rank model, joki pricing and checkout

**Founder's answers (2026-10-23):**
- A division is worth its own stars: Epic V 0★ → Epic IV 0★ = 5 stars.
- Elite has 4 stars per division (the 2026 sources; it is panel data anyway).
- Mythic Grading is the 10 placement matches when an account first enters Mythic. Winning all 10 gives about 15 stars, which is why it is sold flat. Grading's flat price guarantees Mythic 15★: if the account lands lower, the penjoki plays on to 15★ at no extra charge, and only stars past 15★ are charged.

**Built:**
- **Tables.** `joki_settings` (one row), `joki_tiers` (rank model), `joki_routes` (route packages and quotations), `joki_orders` (snapshot) and `affiliates.joki_enabled`.
- **Seeded draft data.** The 2026 ladder (Warrior 3×3, Elite 4×4, Master 4×4, GM/Epic/Legend 5×5, then Mythic bands at 0/25/50/100) and the ADR's draft rates: below Mythic RM2 (Warrior/Elite/Master assumed the same as GM; the ADR had no figure), Mythic RM4, Honor RM5, Glory RM6, Immortal RM7, Express = +RM1, Grading RM39. Grading Express is seeded at RM39 too and needs the founder's figure. Joki stays off.
- **Pricing engine.** `JokiLadder` turns a rank into a star position, and validation errors are keyed `joki.from`/`joki.to`. `JokiPricingService::quote()` is the one price:
  - each star at the rate of the tier it is won in;
  - Grading added when crossing into Mythic;
  - at least `min_stars` paid stars, unless the order includes Grading;
  - a route package at its own price, Reguler only;
  - a quotation route is refused;
  - `cheaper_route` points to a route package selling the same climb for less.
- **Checkout.** One storefront checkout: `CheckoutController::sellableHere()` (the two copies of the game/package availability check in `store()` and `previewTotal()` are merged into it) takes a joki game past the hidden-game rule and through `JokiCheckoutService::resolve()`, which checks both switches on an owned brand, validates the role against the panel list, and takes the quote and `resolveFixed` at the share in force now. `CheckoutService` uses the pre-resolved pricing, `OrderFactory` writes `joki_orders` in the order's transaction, and the idempotency hash includes the climb (from, to, Express).
- **Endpoints for the `/joki` page.** `GET /api/joki/catalog` and `POST /api/joki/quote`, behind `brand.joki`. The payout share is never exposed.
- **Admin.** `AffiliateController`'s owned-brand-only rule is now one list (`membership_enabled`, `joki_enabled`).

**Found while building.** The first migration also seeded the joki game and calculator package. That broke 23 unrelated tests that count games or packages (SEO, game list, sitemap, reseller catalog, the CHIP smoke test), and it would have put a joki game into every catalog count on prod. The game and package are now made by `JokiCatalog::ensure()` (idempotent), which the admin's joki switch will call in PR 4. In the same file, PR 2a's `Game::scopeJoki()` had been inserted between `isLoginGame()` and its docblock; fixed.

**Verified.**
- `php artisan test`: 3005/3005, including `Adr126JokiPricingTest` (10, prices worked out by hand) and `Adr126JokiCheckoutTest` (8).
- Concurrency suite: the joki, finalize, fulfil and settle tests (5/5).
- e2e: 8/8. Dev DB migrated.

## 2026-10-23 — Joki PR 3 (item 114): progress, Track Order and the customer's WhatsApp update

- **One seam.** `JokiProgressService::record()` is used by the penjoki's `.progress` and by the admin's `POST /api/orders/{order}/joki/progress`.
  - Under the order lock: a Pending joki order held by a penjoki (never the pool), and for the bot only the penjoki it is assigned to.
  - It checks the rank against the panel model, then writes `joki_progress_updates`, `joki_orders.current_rank` and `manual_activity_at` (decision 21).
- **`.progress PG-… Epic III 2`.** `JokiRankParser` reads a division in Roman numerals or digits; a tier by name, last word, a 3+ letter prefix, or `GM`. For Mythic+ it reads the star count and takes the band from the stars (`Mythic 30` → Mythical Honor 30★). The bot answers in Malay, and the help text lists the command.
- **Customer WhatsApp (decision 10, S23).** `CustomerNotificationService::jokiProgress()`:
  - opted-in customers only (the receipt rule);
  - the first update goes at once; after that at most one message per `joki_settings.progress_message_interval_minutes` (default 120);
  - while one message is queued, a newer update adds nothing, and the message is worded at send time with the latest rank (`prepare()`, now called by `SendCustomerWhatsAppJob` for the customer session too);
  - dropped at send time if the order finished or the customer sent STOP.
  - `WhatsAppOutbox::enqueue()` gains `notBefore`.
- **Track Order.** `customerSafePayload()` gains `joki` (from, to, current, Express; labels only). `OrderObserver` also broadcasts on a `manual_activity_at` change, so an open page updates. The WhatsApp status card shows the climb and the current rank.
- **Storefront.** The Game & Package card shows "Climb" and "Rank now". A joki order no longer gets the login top-up copy ("usually within 30 minutes", "Your top-up is done").
- **Verified.**
  - `php artisan test`: 3015/3015 (`Adr126JokiProgressTest`, 10 new).
  - Storefront `tsc` and lint pass.
  - A temporary e2e spec (since deleted) created a joki order in the throwaway e2e DB and opened Track Order. Screenshots at 1280 and 390 wide showed the climb, the current rank and the joki help text, with no horizontal scroll.

## 2026-10-23 — Docs sync after Joki PR 1–3 (#536 merged)

Checked against `git log origin/main..origin/staging` and read-only prod: payment methods (FPX on since 2026-10-22 09:52 UTC, FPX Business and DuitNow QR off) and the last migration (2026-10-22's, so nothing from #529–#536 runs on prod yet).

- **PRD §14.** New "On `staging`, not released" paragraph (#529–#536). It says plainly that the item 130 fix isn't live, and that Joki PR 1 also changes Login Top-up.
- **PRD §15.** New Joki MLBB row.
- **PRD §16.** Items 114 and 130 updated; 130's closed line now says "not released".
- **PRD §6.24 and §6.25.**
  - LOGIN-6: per-login ceiling.
  - MSUP-3/4/5/6: supplier re-check, posts per supplier and assignment, `.progress`, SLA up to 4320 and the explicit clock.
  - New §6.25 Joki (JOKI-1..10). MSUP-5 already pointed at it.
- **PRD §8.** New columns on Game, Supplier, Affiliate, Order (`fixed` basis, `manual_activity_at`), OrderCredential, SupplierLoginLink and CustomerNotification, and six joki entities.
- **PRD §13 Glossary.**
  - Joki no longer says "Designed, not built": the Glossary never states build status (the `adr.md` rule).
  - Funding Mode cites ADR-126 for penjoki.
  - New terms: Assignment pool, Mythic Grading.
- **PRD §1 and §10.** The CHIP lines no longer repeat "all three methods off since 2026-10-07", which contradicted §14. Payment status now lives in §14 only.
- **ADRs.** Standing updated, index and banner together:
  - ADR-126: Accepted, with the later addenda;
  - ADR-116: joki progress pacing;
  - ADR-061: the owned-brand rule gates Joki.

  ADR-126's PR 2a notes now record that the game flag is `games.is_joki`, not the original `owned_brands_only`.


## 2026-10-24 — Joki PR 4a: the admin backend (ADR-126 decisions 23-25, F4)

PR 4 is split like PR 2 (founder, 2026-10-24): **4a** is the backend and its tests, **4b** the admin screens.

- **`JokiBoard`** (`app/Services/Joki`) defines the Joki page's filters and the Dashboard card in one place, over paid, real joki orders:
  - `waiting`: Pending on the pool;
  - `in_progress`: Pending with a penjoki;
  - `stale`: past its SLA (`isStalePending`), or NeedsReview;
  - `needs_voucher`: `needsAction()`;
  - `done` and `failed`.
- **F4.** `Order::scopeJoki()` / `scopeNotJoki()` (Fixed basis) take joki out of:
  - `/orders` Need Action (filter and summary);
  - the Dashboard's `needs_action_orders` and stale-pending part of `stuck_orders`;
  - the Manual Queue.
  - **Founder's choice (2026-10-24):** a Failed, uncompensated joki is the card's 4th number, "needs voucher", so it is never left with nowhere to be seen.
  - Reports' "awaiting compensation" keeps joki: it is money, not a work queue.
  - `needs_review` stays general.
- **`/orders?type=all|topup|login|joki`** (decision 25):
  - login = has an `order_credentials` row;
  - joki = Fixed;
  - the export's scope line names the type.
- **`/api/joki-admin/*`** (`JokiAdminController`):
  - every admin: `orders` (paginated board rows: ranks, penjoki, payout, last activity, SLA due) and `summary`;
  - every admin: `penjoki` (open orders, `owed_sen` from the pay-after ledger, has group);
  - super admin: `settings`, `tiers` and `routes`.
  - Assign and progress stay on `JokiAssignmentController`. Recording a payment is the existing supplier-transfer action.
- **Settings.**
  - Whole ringgit is enforced by `integer` rules. Share: 0–100, two decimals. Grading Express is never below Grading. The Mythic cap must be above the highest band. `terms_html` is Purified (`rich_text`).
  - **`is_enabled: true` calls `JokiCatalog::ensure()`.** Off only stops new sales; the pool stays active for paid orders.
- **Rank model.**
  - Only existing tiers are edited (adding or removing a tier is not built). Express is not below Reguler.
  - The Mythic bands must start at 0★ and climb.
  - Every route must still read on the new ladder, or the whole edit rolls back.
- **Route packages.**
  - A new route is a joki `Package` on the pool (price fields 0, F1) plus its `joki_routes` row.
  - The climb is checked on the ladder: the target must be above the start.
  - A route is switched off, never deleted, because past orders point at its package. A quotation clears the price.
- **Penjoki** are created in Middleware → Suppliers: `joki_role: penjoki` is accepted only on a pay-after manual supplier, and the pool can't be made there.
- **Banner and "Joki Rank" menu.** No new setting. The banner is an ordinary Hero Slide linking to `/joki`, and the menu item is PR 5's, shown when `/api/joki/catalog` answers.
- **Verified.**
  - `php artisan test`: 3026/3026 (`Adr126JokiAdminTest`, 11 new; `RouteAccessTest` lists the three admin-readable routes).
  - On the dev DB, `notJoki()` keeps all 189 existing orders.

## 2026-10-24 — Joki PR 4b: the admin screens (ADR-126 decisions 16, 23-25)

Stacked on PR 4a (#538).

- **Admin → Joki** (`/admin/joki`, sidebar under Commerce):
  - **Orders:** `JokiBoard`'s filters (All, Waiting, In progress, Stale, Needs voucher, Done, Failed). Each row shows the climb with the current rank, role and hero, the penjoki, time waited, last activity, SLA left or overdue, and sold price and payout. Assign is inline for Pending rows; other rows open Order Detail. `?filter=` deep-links from the Dashboard card.
  - **Penjoki:** open orders, owed and group status. Record payment opens the existing `SupplierTransferModal` (its prop narrowed to the six fields it reads). Super admin only, like Accounting.
  - **Settings** (super admin):
    - the Joki switch: the first time it is switched on, it creates the joki game and calculator package;
    - prices and rules;
    - roles, Express wording and terms (`RichTextEditor`);
    - the rank model (names, shape, whole-RM rates, icons from the Gallery);
    - route packages (add, change the price, switch off).
- **Order Detail Joki card.**
  - The order detail response gains `joki`: ranks, requests, payout share, `is_with_penjoki`, Assign and progress history, and the rank model for the picker.
  - For a Pending order, the card has Assign or Move, plus Update progress (penjoki only) through a shared `RankPicker`.
- **Dashboard Joki card:** waiting, in progress, stale, and failed needing a voucher. Each number links to its board filter, and urgent non-zero numbers are red. The card is hidden while all four are 0, so it doesn't sit empty before launch.
- **`/orders` Type select** (All / Top-up / Login / Joki). It accepts `?type=` and is part of the export filter.
- **Brand form:** "Sell Joki on this storefront" (owned brands only), next to Membership. The brand list tags it "joki".
- **Middleware → Suppliers create form:** an "A penjoki" tick on a pay-after manual supplier sends `joki_role: penjoki`.
- **Backend touch-ups:**
  - a tier icon accepts any URL (local Gallery URLs are `http`);
  - a price with sen is refused with "Whole ringgit only, no sen."
- **Verified.**
  - `php artisan test`: 3027/3027. Admin: `tsc`, lint and `npm test` (17) pass, and `next build` succeeds.
  - Browser check: built admin on `localhost:3000`, against a copy of the dev DB in the scratchpad (seeded with three joki orders and a penjoki; queue `sync`; OpenWA pointed at a dead port). The real dev DB was not touched.
  - Flows checked:
    - board, Assign, the Order Detail card and the Penjoki tab;
    - Record payment modal;
    - settings save (sen refused, Grading Express saved);
    - route add;
    - Dashboard card, and the `/orders` Joki filter (3 rows).
  - At 390 px wide there is no horizontal page scroll; the table scrolls inside its card.
  - Fixed during the check: a Failed row read "Waiting" for its penjoki, status tags were lowercase, and order numbers wrapped on mobile.

### 2026-10-24 — PR 4b restyled on `/admin/orders`; board filters (founder)

The founder found the first Joki screens untidy and unlike `/orders`, and asked for `/orders` as the benchmark plus the same filters.

- **Shared components, not copies:**
  - `StatusText` and the delivery/payment severity maps moved out of `orders/page.tsx` into `components/orders/StatusText.tsx`;
  - `OptionSelect` moved out of `CreateSupplierModal` into `components/ui/option-select.tsx` (with a placeholder).
  - Joki uses them with `DataTable`, `Input` and `Label`. No native selects or hand-rolled inputs are left.
- **Orders tab:**
  - five summary cards in `OrderSummaryCards`' style (Waiting carries the purple marker; All Joki uses a new `all` count from `/summary`);
  - search with the underline filter tabs, and a filter row: penjoki, plus the same date presets as `/orders`;
  - dot status text, and a two-line Climb / Waiting+SLA / Amount;
  - View on every row, and Assign (in a dialog) on unassigned rows.
- **Backend:** `/api/joki-admin/orders` takes `search` (order # or email), `supplier_id`, and `date_from`/`date_to`, with the same KL-day bounds on `created_at` as `/orders`. One new test.
- **Penjoki tab:** in `DataTable`. **Settings:** cards in Order Detail's style, `Input`/`Label`, and the rank model and routes in bordered tables. **Order Detail Joki card:** laid out like `OrderDetailCards`. **Dashboard card:** summary-card style.
- **Verified:** built admin against a seeded copy of the dev DB, with screenshots next to `/orders`. Assign through the dialog, search `DEMO3` returning 1 row, and no horizontal scroll at 390 px all passed. `tsc`, lint and tests pass.

## 2026-10-24 — Joki PR 5: the `/joki` page (ADR-126 decisions 1, 2, 3, 12)

#538 (4a) and #539 (4b) were merged to `staging` with the founder's go-ahead. #539 was retargeted to `staging` before #538 was merged with `--delete-branch`.

- **`/joki`** (`app/joki/page.tsx`, `components/joki/JokiOrderForm.tsx`) follows the top-up order page. It reuses `Stepper`, `StepCard`, `LoginAccountFields`, `OrderSummarySidebar`, `ReviewModal` and the sticky mobile bar, with three steps:
  - **Choose your climb.** A calculator (`JokiRankStepper`) for any climb, or route packages. Quotation routes link to WhatsApp.
  - **Account & requests.** The joki game's login form, consent, role, hero and play window.
  - **Payment.**

  The price is always the backend's: `/api/joki/quote` for the climb, and `preview-totals` with the `joki` block for the fee and total. Checkout sends the `joki` block and never a price. It answers 404 where the brand doesn't sell joki.
- **Rank calculator (founder, after comparing rapspoint.com).**
  - ‹ › move one division or Mythic band; ▲ ▼ set the stars, which are kept across divisions as far as they fit.
  - Mythic stars can be typed, and the band follows them.
  - The target never goes below the start, and moving the start up pushes it along.
  - It opens on Epic V → Epic IV, priced at once. Both sides sit side by side on a phone too.
  - Icons come from the panel's rank model, with a lettered badge until the founder's icons are set.
- **Shared, not copied.** `PaymentChannelPicker` was moved out of `OrderForm` unchanged. The login-field wire schema and its mapping are exported from `lib/catalog.ts`.
- **Menu and sitemap.** "Joki Rank" is in the header and the mobile More drawer, and `/joki` is in the sitemap, all only where `/api/joki/catalog` answers. `BottomNav` treats `/joki` as a checkout (the sticky bar takes its place).
- **Fix (found building it): the voucher preview priced the joki package's RM0 (F1).** `VoucherPreviewController` now prices a joki game through `JokiCheckoutService` and takes the `joki` block. `ReviewModal` passes it. There is a red-to-green test.
- **Cache.** Joki settings, rank model and route saves, and a brand's `joki_enabled` change, call `NextRevalidation::purge()`.
- **E2E golden path** (`storefront-joki.spec.ts`):
  - the admin switches Joki on and ticks "Sell Joki";
  - a guest prices a climb (RM10, then one more star RM12) and pays;
  - the webhook comes in, and the order waits on the pool;
  - the status page shows the climb, and the board shows it Unassigned.
- **CI catch:** `check-loading-boundaries.mjs` failed because `/joki` had no `loading.tsx`, so navigation would freeze. It now reuses `OrderPageSkeleton`, since the page has the order page's shape. Run that script locally before pushing a new storefront route.
- **Gotcha: the e2e storefront's Data Cache survived rebuilds while the DB is rebuilt every run.** A cached catalog from an earlier run named a package that no longer existed ("The selected package id is invalid"). The storefront `webServer` command now clears `.next/cache/fetch-cache` first. This also explains a flaky "Joki is not available" seen earlier the same day.
- **Verified.**
  - `php artisan test`: 3029/3029.
  - Storefront and admin: `tsc`, lint and build pass.
  - e2e: 9/9 in the full run (and repeatedly before the calculator change).
  - Screenshots of `/joki` on desktop and at 390 px (no horizontal scroll), the Review Modal, the status page and the admin card.

## 2026-10-24 — #540 merged (Joki PR 5); session paused

- **#540 merged to `staging` (`53ade00`)** with the founder's go-ahead, after CI went green on its second run. The first failed only on `check-loading-boundaries.mjs`, fixed with the skeleton. Every Joki PR (1–5) is now on `staging`, not released. Joki is off on prod until its launch gates are met (PRD §16 item 114).
- **Local only:** the founder's dev DB has Joki on, "Sell Joki" on PekanGame, and the Epic tier's icon (a founder-supplied image, through the Gallery ingest).
- **Open, not diagnosed:** in the founder's Chrome, every request to `https://kedairuncit-backend.test` fails with 503 or "Failed to fetch", so `/joki` shows broken icons and no price. `curl` to the same URLs gets 200, the `.test` resolver and Herd's nginx are fine, there is no system proxy, and the Herd nginx log is empty, so the requests seem not to reach Herd.
  - A headless check with `--ignore-certificate-errors` hid this. Verify local UI in the founder's own browser, not only headless.
  - Picked up next session.

## 2026-10-24 — ADR-127 designed (item 131 widened); PR A: real-cost flag removed, financial scenario builder

- **Design (#542, merged).** The founder widened item 131 from "revenue by delivery date" to every money page. A three-layer read-only trace (checkout and pricing; ledger, fulfillment and compensation; downstream readers), the grill (Q1–Q13) and a stress test produced [ADR-127](./adr/ADR-127-financial-truth-dated-facts.md). Its rule P1: a figure for any past period comes only from dated facts that never change. It revises ADR-083, ADR-111 decision 8 and ADR-126 decisions 11/13.
- **Production, read-only, 2026-10-24:**
  - 46 orders, 32 of them Delivered.
  - No order was paid in one month and delivered in another.
  - September is the only closed month.
  - There are no duplicate supplier-ledger references.
  - `REAL_COST_RECONCILIATION_ENABLED=true`.
  - So no data patch is needed for ADR-127.
- **PR A (`feature/adr-127-pr-a-scenario-builder`):**
  - **The real-cost flag is removed (ADR-127 decision 10).** Real cost is the only path. Five tests failed once it was gone:
    - Four combo tests wrote the supplier's `price` in sen (`480`), while adapters return ringgit. The off default had hidden this.
    - One Digiflazz webhook test asserted the checkout estimate, under a comment saying `price` is "never used". In production it has been the real cost since ADR-111.
  - **`Database\Seeders\FinancialScenario\FinancialScenario`** builds money data by driving the real system:
    - Checkout, the CHIP webhook and the Reseller API through the HTTP kernel, so prices are resolved server-side.
    - Back-office actions through the admin's services.
    - The API supplier is a `ScriptedSupplierAdapter`. By default it returns the catalog cost in ringgit, and a scenario can queue a failure or a different real cost for the next order.
  - **The builder is safe by construction:**
    - It refuses to run in production.
    - The queue is `sync`, so no Horizon worker can reach a real supplier.
    - Outbound HTTP is refused, and mail and notifications are faked.
    - The world is created at the scenario's start time.
  - **`FinancialScenarioTest` proves invariants 5, 6 and 10** on that data:
    - Wallet and affiliate balances agree on Dashboard, Summary, Cash Position, the portal and the API.
    - The price identity holds with real cost.
    - Every delivered API order has exactly one drawdown.
  - The other invariants arrive with the PR that makes them true. `FakeSupplierAdapter` (sandbox, e2e) is unchanged.
- **Gotcha:** the first version created the reseller's opening top-up at the real clock (October). September's Cash Position correctly left it out, and invariant 5 read -RM22.10. Scenario data must be created on the scenario's own clock.
- **Verified:** `php artisan test` passes 3032/3032, and Pint is clean.

## 2026-10-24 — ADR-127 PR A merged (#543); PR B1: revenue at `recognised_at`

- **#543 merged** with the founder's go-ahead, after CI went green.
- **PR B split** (ADR-127 2026-10-24 addendum). This is **B1**.
- **Red first.** `FinancialScenarioTest` gained an order paid on 30 September at 23:50 and delivered at 00:10 on 1 October (a scripted Pending, finished through `finalizePendingDelivery`).
  - Before the fix, September's paid money (RM74.84) showed as recognised plus owed = RM101.79: the order counted twice (S1, proven).
  - October's revenue did not contain the order at all.
- **Fix:**
  - `orders.recognised_at` (indexed, backfilled), stamped once in `creditOrderProfit()`.
  - `RecognisedRevenue`, affiliate commission and Cash Position's undelivered claim read `recognised_at`.
  - The Reports Bridge gained "Recognised here, paid in another range". Admin `AccountingBridge` shows the new row.
- **Invariants 1 and 4 now hold.** Paid, uncompensated money is either recognised or owed at every month end.
- **Local dev DB:** migrated (plain `migrate`). 167 of 167 Delivered orders recognised, none moved month.
- **Verified:** `php artisan test` passes 3035/3035; admin `tsc` and lint pass.

## 2026-10-24 — ADR-127 PR B2: dated facts everywhere, closed months that cannot move

- **Stacked on B1 (#544).** Every step below went red first, then green:
  - **CHIP's own paid time and fee (F1, F2).** `paid_at` was our processing time, so a late webhook moved a 23:58 payment into the next month. It is now `payment.paid_on`, with a fallback to processing time when the value is missing, implausible, or in a closed month. The fee is kept as `gateway_fee_sen`.
  - **One date per fact (F4, F5).** Restores carry `restored_at`. The top-up and membership CHIP-unsettled asset is dated by the same ledger fact as the wallet liability; the attempt's `updated_at` is no longer read.
  - **Closed-month guard.** Transfers, transfer corrections and voids, and envelope postings and voids refuse a closed month. A void used to silently rewrite the month it voided.
  - **Breakage at expiry (S7).** `Voucher::balanceAsOf()` is now shared with Cash Position.
  - **Invariant 2.** The scenario closes September, then keeps trading. The new invariant caught the last Summary line reading current state: the reseller wallet balance (S10), now taken at the month end. The Register gained `order_recognised` rows (S2).
- **Gotchas:**
  - Several test fixtures were impossible in production, and were masked until now:
    - a voucher whose `remaining` doesn't match its redemptions;
    - a "Paid" top-up with no wallet credit;
    - Delivered orders with no recognition.
  - A wallet test had asserted the old "always today's balance" rule. It now asserts the month-end rule.
- **Local dev DB:** migrated (`gateway_fee_sen`, `restored_at`).
- **Verified:**
  - `php artisan test` passes 3046/3046.
  - Admin `tsc` and lint pass.
  - Pint is clean.

## 2026-10-24 — ADR-127 PR C: one truth across the money pages

- **Stacked on B2 (#545).** Everything below went red first (see the ADR-127 PR C addendum for details):
  - **Profit by when it was earned.** Reports, the Report Assistant (`recognised_at_kl`, `placed_via`) and the Summary's commission now file profit by recognition. A manual correction is filed by the day it was made.
  - **One store credit.** Reports now matches Cash Position (`Voucher::outstandingAt`).
  - **One membership source** (the ledger), plus the missing `is_test` filters.
  - **Names.** Sales, Revenue (recognised), Owner profit (market rate), Customer spend. The Overview chart had called paid-date sales "Revenue".
  - **A profit bridge** from owner profit to operating profit on the Profit tab, with nothing unexplained.
  - **The settlement fee check.** The fee CHIP reported at payment is shown beside the settlement file's fee.
- **Findings fixed in this session** (founder rule: finish findings in session):
  - A later manual profit correction moved a past month's Reports profit.
  - A voucher revoke left no date, so Cash Position kept owing a revoked balance forever. It is now a locked, dated `VoucherService::revoke()`, and its unspent balance is breakage of the revoke month.
- **Local dev DB:** migrated (`revoked_at`, `llm_report_orders` view, `reported_fee_sen`).
- **Verified:**
  - `php artisan test` passes 3056/3056.
  - Admin `tsc` and lint pass.
  - The browser pass over every money page is still to do, with the full data set (ADR-127 PR E).

## 2026-10-24 — ADR-127 A, B1, B2 and C merged to `staging`; docs synced

- **Merged with the founder's go-ahead, each after green CI:**
  - #543 (PR A);
  - #544 (B1);
  - #545 (B2), retargeted to `staging` before its parent merged;
  - #546 (C), also retargeted first.

  Every one is on `staging` and unreleased; the release waits for the founder.
- **Docs sync.**
  - PRD §14 lists #529–#546 and the six ADR-127 migrations.
  - The §15 rows for Reports, Vouchers, Customer Analytics and Internal Accounting carry the ADR-127 notes.
  - The §16 item 131 entry and its detail text are rewritten (the old "pick a start month" step was not needed).
  - ADR-024, ADR-083, ADR-087 and ADR-104 have Standing and dated addenda for what ADR-127 revised.
- **Next session: ADR-127 PR D.**
  - Mark Delivered goes through the drawdown seam, with a unique index.
  - Delivered is refused on a compensated order.
  - Penjoki pay is booked as cost of sales.
  - Joki is counted once on the Dashboard.
- **Then PR E:**
  - a dev-DB seeder;
  - delete `ReportDemoSeeder`;
  - a browser pass over every money page.

## 2026-10-24 — ADR-127 PR D: money flows

- **Founder decisions (grilled in Malay):**
  - Mark Delivered takes the supplier's charged price.
  - There is no partial or extra penjoki pay. Decision 8 counted that cost twice: an envelope expense already reduces its envelope, and the operating profit leaves envelope expenses out (ADR-127 2026-10-24 addendum).
- **Built, each test red first:**
  - **Mark Delivered (NeedsReview).** The admin enters `supplier_price`, in the supplier's currency, read off its dashboard. It becomes the real cost and the one drawdown. The path had recorded no drawdown.
  - **The drawdown is unique in the database.** A virtual `drawdown_key` column is unique for `ORDER_DRAWDOWN` only, so repeated transfer corrections stay allowed. On prod, read-only today: 35 drawdowns, no duplicate.
  - **Delivered is refused on a compensated order.** This happens at `finalizePendingDelivery()` (Manual Queue, `.done`, a webhook) under the lock, and in `markDeliveredManually()`. A Paid-after-Failed order with a restored voucher could have reached Delivered through Manual Queue or `.done`.
  - **The Dashboard's stuck count leaves out a joki in NeedsReview**; the Joki card counts it.
  - **`FinancialScenario`** gains a NeedsReview order confirmed above catalog cost, covered by invariants 6 and 10.
- **Gotchas:**
  - MySQL reads `||` as OR, so the virtual column's expression is per driver.
  - MySQL JSON reorders keys, so a JSON `assertSame` passed on sqlite and failed on MySQL.
  - A killed MySQL run left the `llm_report_*` views behind (backend gotcha 8).
- **Combo legs need no guard.** Traced: a combo can be compensated only once no leg is Pending, and a resend is refused afterwards.
- **Local dev DB:** migrated (`drawdown_key`).
- **Verified:**
  - `php artisan test` passes 3061/3061, including the new tests.
  - The PR D service tests and `FinancialScenarioTest` pass on MySQL (35/35).
  - Admin `tsc` and lint pass.

## 2026-10-24 — ADR-127 PR E: dev data, browser pass, two findings

- **#548 (PR D) merged** to `staging` with the founder's go-ahead, after green CI.
- **Joki in the scenario:** delivered, failed (the penjoki is owed nothing), reassigned (only the second penjoki is paid), and a joki paid in September and finished in October. A new invariant covers the penjoki payout.
- **`FinancialScenarioSeeder`** fills the dev DB with four months (about 1,500 orders) through the real system. `ReportDemoSeeder` is deleted (ADR-088 addendum).
- **Local dev DB** (founder: "buang je"):
  - backed up to `backend/database/database.sqlite.bak-before-pr-e-2026-10-24`;
  - 189 `DEMO-` orders, their 199 ledger rows and one voucher removed;
  - the seeder run: 1,578 orders, no price-identity or drawdown exception, 0 unexplained on the bridge every month, the September close equal to its live figure.
- **Gotchas:**
  - The first seeding runs failed with "database is locked": `./scripts/dev.sh` (Horizon) was on the same sqlite file. Claude stopped it at the founder's request, restored the backup, removed `DEMO-` again, seeded, and restarted it.
  - The database cache store kept rate-limit hits from an earlier run, because the scenario replays the clock. The scenario now uses the array cache.
  - Restarting `dev.sh` let the storefront take :3000, which broke admin login (its cookie works only on :3000). The admin and storefront ports are now pinned.
- **Browser pass:** 11 money pages, with no API error. Two findings, both fixed:
  - **A pay-after supplier payment was counted as a prepaid top-up** on the Monthly Summary. It now has its own line, "Supplier payments — pay-after". Production, read-only: no pay-after payment yet.
  - **The Joki "Waiting" column** showed a finished order's age since payment.
- **Verified:**
  - `php artisan test` passes 3064/3064.
  - Admin `tsc` and lint pass.
  - The Summary's split was checked in the browser: October's prepaid RM12,000 and pay-after RM1,170 add up to the old RM13,170.

## 2026-10-25 — ADR-127 PR E completed: the six untested flows, two findings

- **Founder question:** were the accounts correct? Claude listed what the scenario did not drive yet (membership, CHIP top-ups, withdrawals, resends, an IDR supplier, CHIP settlement with the bank). The founder asked why they weren't tested with the rest. Claude had scoped them out without raising it. They are now in #549.
- **Built:**
  - The `MoneyFlows` trait and a `ChipSettlementFile` writer; the bank modelled as dated flows.
  - Five new invariants: the cash equation closes with a 0 gap, membership revenue agrees, IDR costs at funded rate, CHIP fees recorded, resend and withdrawal.
  - The seeder drives all of it.
- **Finding 1, fixed:** the membership gate ran before the brand resolver, so an affiliate's domain was gated by the platform's switch (ADR-080 2026-10-25 addendum). Production: "FixFast Demo" (off) had the surface open; no attempt there.
- **Finding 2, pending (§16 item 132):** IDR COGS at a cumulative average leaks between months (RM4.01 over three months on the dev data). The founder chose a moving average, after a grill.
- **Gotchas:**
  - `membership_checkout_attempts` and `wallet_topup_attempts` have no `paid_at`. On sqlite, `where('paid_at', …)` compared a string and returned nothing, silently.
  - A first invariant formula ("gap = Σ fee − RM1 × count") passed by coincidence, while memberships were never settled. The true invariant is a 0 gap.
- **Dev DB:** restored from the backup, `DEMO-` removed again, reseeded. The result:
  - 1,590 orders, 53 members, 115 settlements, 4 withdrawals;
  - no price-identity or drawdown exception;
  - the bridge leaves 0 unexplained every month.
- **Verified:**
  - `php artisan test` passes 3070/3070.
  - 11 money pages in the browser, no API error. The October Summary adds up to its operating profit by hand.

## 2026-10-25 — #549 merged; item 132 grilled and designed

- **#549 (ADR-127 PR E) merged** to `staging` with the founder's go-ahead, after green CI. Every ADR-127 PR (#542–#549) is now on `staging`, unreleased.
- **Item 132 grilled in Malay** (Q1–Q12) and stress-tested. It is ADR-083's 2026-10-25 addendum: foreign-currency deposits at a moving weighted average from 1 October 2026, a replayed pool opening at September's close value, a drawdown's cost fixed when drawn, and voided transfers with all their rows left out.
- **Production facts, read-only:**
  - three live Digiflazz deposits, all in September;
  - the running IDR balance never negative (41 rows);
  - the two voids of 15 September were written as `MANUAL_ADJUSTMENT` rows (−1,224,819 IDR).
- **Founder:** build in the next session.

## 2026-10-25 — Item 132 built: IDR deposits at a moving weighted average

- **What:** ADR-083's 2026-10-25 addendum, built on `feature/item-132-idr-moving-average` (#551).
  - `MovingAveragePool` replays a foreign-currency supplier's pool from 1 October 2026. It opens with the units at 30 September at that day's cumulative average, which is September's close value, so September does not move. A deposit adds its net units and ringgit at the start of its `transferred_on` day. Every other row takes `round(pool_sen × units ÷ pool_units)`. An empty pool uses the last known average.
  - A voided transfer and every row that references it are left out.
  - `SupplierRowValuer` costs a row the same way for every reader: COGS, drawdowns not in COGS, and the Summary's supplier corrections line. `CashPositionService` reads the pool's month-end value. An MYR supplier and any row before the opening keep the old rate.
- **Test first:** `FinancialScenarioTest` gained IDR deposits at RM1/3,500 IDR (October) and RM1/3,900 IDR (November), plus a November balance correction. On the old method the October close had a gap of −33 sen. It is now 0 for both months. `MovingAveragePoolTest` checks the arithmetic by hand: conservation to the sen, the empty pool, the opening and month-end cut, the voided transfer, and a correction at the average.
- **Not done yet:** merge, release, then a read-only check that prod October moves only a few sen.
## 2026-10-25 — Joki offered from Epic up

- **Founder:** offer joki from Epic up and drop Warrior, Elite, Master and Grandmaster. The founder chose a migration over a per-tier panel switch (ADR-126 2026-10-25 addendum).
- **Built** on `feature/joki-epic-and-up`:
  - The migration `2026_10_25_100000_remove_joki_tiers_below_epic` deletes the four tiers. It refuses if a route references them; `down()` restores them.
  - The `GM` alias is removed from `JokiRankParser`.
  - Tests now expect six tiers starting at Epic.
- **Verified:**
  - `php artisan test`: 3070/3070.
  - The local dev DB was migrated and now has Epic, Legend and the four Mythic tiers.
  - The local `/api/joki/catalog` lists Epic first. A quote from Epic V 0★ to Legend V 0★ is 25 stars × RM2 = RM50.
  - The storefront and admin read the tiers from the API, with no tier name hardcoded. There was no browser screenshot, because local Chrome to `.test` is still broken.

## 2026-10-25 — Dashboard System details in plain words

- **Founder:** the (i) tooltips on System details showed developer text (column names, class names), which an admin can't act on. Rewrite them for an admin.
- **Built** on `fix/dashboard-plain-tooltips`:
  - The seven definitions now live in `DashboardService::DEFINITIONS` as plain English. Each says what the figure is and what to do about it; the exact rule stays in the code that computes it.
  - A late scheduled task now shows "Supplier balance refresh", not `supplier-balance-refresh`.
  - The scheduler's raw "failed with exit code [1]" line becomes "stopped with an error on its last run (details in the server log)". The raw text is kept in the hover title.
- **Verified:**
  - Dashboard tests pass (40), along with admin `tsc` and lint.
  - Local screenshot of the panel with the suppliers tooltip open and a late task.
  - Locally, `supplier-balance-refresh`'s heartbeat was set late on purpose to show the error line.

## 2026-10-25 — Release #554 live (`staging`→`main`, #529–#553)

- **Before release:**
  - Prod `/api/health` had been 503 since 25 Oct 00:00 UTC. `supplier-balance-refresh` failed on a Gamevion `check-balance` SSL timeout (cURL 28), which aborted the sweep before Digiflazz. This is item 130's failure; its fix (#531) was on `staging` only.
  - Read-only checks: 0 duplicate drawdowns, so the unique `drawdown_key` index was safe. October snapshot: COGS 38,506 sen, Digiflazz prepaid 18,549 sen.
- **Merged** with the founder's go-ahead:
  - #551 (item 132);
  - #552 (joki from Epic up), after a docs conflict with #551 was resolved by keeping both;
  - #553 (plain tooltips), same kind of conflict;
  - then release #554 as `0d6b8ad`. CI and `deploy` were green.
- **Box, read-only:**
  - release `81129239` is live, and all 14 migrations ran;
  - Horizon is running;
  - `joki_tiers` = Epic, Legend and the four Mythic tiers; joki is off;
  - October COGS and prepaid are unchanged. The pool matches the old method to the sen, at 797,643 IDR = 18,549 sen, because there has been no deposit since September.
- **Still red:** `/api/health` stays `scheduler: false` until `supplier-balance-refresh` succeeds. `schedule:test` would not clear it, because it runs the event without firing `ScheduledTaskFinished`, so no heartbeat is written. It clears at the next daily run (00:00 UTC), now with the fix live.

## 2026-10-25 — Affiliate vs Reseller, one place per order; Joki rank model save; clear upload errors

- **Founder findings:**
  - Reports → Partners showed PekanGame at RM563.72 / 37 orders. Its storefront sold only RM110.60 / 22; the rest were reseller-wallet orders (Naeem RM421.71), also listed under Resellers.
  - Customer Analytics' Affiliate filter had the same fault.
- **Fix (ADR-086 2026-10-25 addendum):** `Order::scopeSoldByAffiliate()` and `affiliateKeySql()` hold the rule. It now applies to:
  - Reports (`scopedOrders`, `profitTotals`, `profitByGroup`, `affiliateBreakdown`);
  - Customer Analytics' filter;
  - the Affiliates list count;
  - the portal sales aggregate;
  - the `llm_report_orders` view (migration `2026_10_25_110000`) and the Report Assistant's column note.
- **Accounting is not affected** (read-only on prod): wallet orders have `affiliate_profit` 0, and their 11 affiliate ledger rows are RM0.
- **Joki rank model would not save** ("tiers.2.divisions must be an integer"). The admin sent every shape field for every tier, so Mythic went with `divisions: null`. The new `tierUpdate()` (in `admin/src/lib/joki-tiers.ts`, unit-tested) sends only a tier's own kind's shape. A backend test saves all six tiers as the page sends them. The bug had been there since PR 4b.
- **Gallery "Upload failed":**
  - nginx's default 1 MB body limit refused 2 MB and 12 MB images with a 413, before Laravel. That response has no CORS headers, so the admin saw only a network error.
  - The founder set Forge's Max File Upload Size to 12 MB. Verified on the box: `upload_max_filesize`/`post_max_size` 12M, and `/etc/nginx/conf.d/uploads.conf` sets `client_max_body_size 12M`.
  - `apiUpload` now refuses a file over 12 MB by name and explains a refused upload. The gallery names a file over its own 5 MB before uploading.
- **Verified:**
  - `php artisan test` 3079/3079, and the invariant test was red first.
  - admin `npm test`, `tsc` and lint.
  - Local browser: the rank model `PUT` returns 200; a 6 MB file shows "big-photo.png is 6.0 MB. Each image can be up to 5 MB."; Partners shows PekanGame at 1,046 storefront orders (243 wallet orders no longer counted).

## 2026-10-25 — Release #557 live (#556); the day in one place

- **Release #557** (`staging`→`main`, founder's go-ahead), merged as `369fbbb`. CI and `deploy` were green.
- **Box, read-only:**
  - release `81131278` is live; migration `2026_10_25_110000` ran; Horizon is running;
  - the `llm_report_orders` view has 0 rows with both an affiliate and a reseller;
  - all-time Partners, before → after: PekanGame RM563.72 / 37 → RM110.60 / 22. FixFast Demo is RM8.19 / 4; Naeem RM446.56 / 11 and FixFast RM6.56 / 4 stay under Resellers.
  - Brands plus resellers = RM571.91 = total sales. Before, they added up to RM1,025.03.
- **The day, in order:**
  1. Item 132 (IDR moving average) built test-first, #551.
  2. Joki offered from Epic up, #552.
  3. Plain Dashboard tooltips, #553.
  4. Release #554, which also carried Joki (off), ADR-127 and the item 130 fix.
  5. The founder found the Joki rank model wouldn't save, gallery uploads failed, and wallet orders were counted under PekanGame. All three were fixed in #556 and released as #557.
- **Founder actions done:** Forge Max File Upload Size set to 12 MB.
- **Open:**
  - `/api/health` is 503 until `supplier-balance-refresh` runs at 00:00 UTC on 2026-10-26, now with the item 130 fix live.
  - The Joki launch gates (§16 item 114): CHIP approval, item 129, rank icons, and the rates with the Grading Express price.
- **Lesson:** a figure that "groups by brand" must say which orders a brand owns. The wallet order's primary-brand `affiliate_id` (ADR-073) had been handled correctly in three views and wrongly in five. The rule now lives in one place: `Order::scopeSoldByAffiliate()`.

## 2026-10-25 — Items 71 and 96: GSC recheck, OpenWA cleanup and retention

- **Item 71, closed (GSC, property `pekangame.com`, data to 16/10).** ADR-120's duplicate and canonical reasons are gone. 2 pages indexed. 3 "Page with redirect" are `http://pekangame.com/`, `http://www.` and `https://www.` → apex (intended). 42 "Discovered – currently not indexed", all never crawled. `robots.txt` allows them, the pages answer 200 with a self canonical, and the sitemap (50 URLs) was read 25/10 with Success, so this is crawl priority on a new domain, not a site fault. Requested indexing for `/order/mobile-legends-malaysia`, `pubg-mobile-global`, `free-fire-malaysia`, `mobile-legends-indonesia` (top games by orders) and `/price-list`. `call-of-duty-mobile` was rejected: it serves `noindex` because the game is `is_active=0` since 2026-10-18 — correct.
- **Item 96, prod writes (founder go-ahead in session).** Evidence first: `backup_runs` shows "Backup Now → OpenWA" ran 2026-10-22 (run #55) and the nightly OpenWA runs #56/#58/#60 passed their restore tests. R2's zip headers showed 10 plaintext database archives (2026-09-20 … 2026-10-22) and every archive from 2026-10-23 (database) and all `openwa/` ones encrypted.
  - Deleted via tinker on `r2_backups`: only archives whose zip encryption flag is unset. Second run deleted 0. Remaining: `PekanGame/2026-10-23..25` and the 4 `openwa/` archives.
  - `rm -rf ~/OpenWA-v0.23.7 ~/openwa-backups` (1012 MB + 26 MB; disk 9.9G → 9.0G used). No `.env.bak*` left in `~/OpenWA`.
  - Appended `MESSAGE_RETENTION_DAYS=90` to `~/OpenWA/.env`; `resolveMessageRetentionDays()` reads 90 through dotenv. Oldest stored message is 2026-09-25, so the first prune deletes nothing until late December.
- **Item 96, closed.** The founder restarted OpenWA in Forge (08:13 UTC); both sessions came back `ready` and the log no longer says retention is disabled. Restore drill: the 2026-10-25 database archive (3,024,314 bytes, same as R2) was copied to the scratchpad and the founder ran `bsdtar -xOf … | tail` in their own terminal with the password from the password manager; it decrypted to a complete dump (the normal closing `SET` lines). The local copy was deleted afterwards.

## 2026-10-26 — Docs sync after releases #554 and #557

Docs only, no code. Checked against git and production (read-only) before
editing: `origin/main..origin/staging` is docs-only, no open PRs,
`/api/health` 200 with `scheduler: true` (the 25 Oct heartbeat cleared at
the 26 Oct 00:00 UTC run), `fpx` active since 2026-10-22 09:52 UTC,
`fpx_b2b1` and `duitnow_qr` off, no Sentry package or `SENTRY_*` env.

- PRD §16: items 131 and 132 closed (released via #554); group A now
  empty; item 114 moved to group D (built and released, waits on its launch
  gates); item 131's detail removed, item 81 and 114 details no longer
  name 131 as open; Epic-up is released, not on a feature branch.
- PRD §15: the Orders, Reports, Vouchers, Customer Analytics, Customer
  Login and Joki rows said "on `staging`" / "not released" for work that
  shipped in #554.
- PRD §14 and §11: health and payment state as of today; Joki points at
  group D.
- ADR-126 and ADR-127 Standing: "Accepted (design)" → "In force" (both are
  built), and ADR-127 no longer says IDR averaging is pending.

## 2026-10-26 — Gallery delete warning covers every image field; Joki rank icons

- **Found:** prod's Joki tier "Epic" pointed at a Gallery URL that 404s and
  has no `gallery_images` row: the image was deleted after it was set, with
  no warning. `GalleryImageController::references()` checked only Game,
  Hero Slide and Affiliate Branding. Three `ImageUrlInput` fields were
  missing: `joki_tiers.icon_url`, `affiliate_seo_settings.default_og_image`
  and `player_validator_profiles.public_image_url`. No customer impact (joki
  is off), but a brand's OG image or a checker image could break the same way.
- **Fix:** `references()` lists all three; test red first. Full suite green
  (3080).
- **Rank icons:** the founder's four PNGs (Epic, Mythic, Mythical Honor,
  Mythical Glory) were cut out (Mythic was a JPEG on black; Glory carried a
  leftover black patch), trimmed and centred on a 256×256 transparent
  canvas at one height, for the stepper's 84 px (64 px phone) and 44 px
  faded sizes. A local round-trip through `ImageIngestService` kept the
  alpha in the WebP. Legend has no icon yet; Epic needs re-uploading.

## 2026-10-26 — ADR-128: partner portal visual system (design)

Design only. The founder wants the partner portal (`reseller/`) redesigned:
next to the admin it looks plain, and its money pages (Wallet) read as
untrustworthy. Grilled Q1–Q15 in three rounds. Layer 1 is a frontend-only
reskin onto ADR-104's design system as a "PekanGame Partner" variant (nude
shell accent), with trust signals (company line, panel-configured support
contacts from `/api/catalog/branding`, "processed by CHIP"), tokens shared
by generation with a drift check (the `sync-theme-presets.mjs` pattern),
mockups in the design-system artifact first, then four PRs. Facts checked
for it: Reseller has no dashboard endpoint, a pending top-up is not
readable after creation (stays item 24), the SSM number exists only in the
About Us HTML, prod has 2 Resellers and 1 test Affiliate. PRD §16 item 133.

## 2026-10-26 — ADR-128 PR 1: shared tokens and the PekanGame Partner shell

- Mockups: 10 Partner Portal pages published in the design-system artifact
  (`pp-*` styles, a Partner Portal section). Writing them against the real
  API shapes removed what layer 1 can't show: the Reseller order list has
  no search and no client reference (`reference_number` is the platform's
  ORD-8 reference), no `paid_at` / `placed_via` on order detail, and wallet
  ledger rows carry no game or payment method. A draft line saying a
  withdrawal is "held" while pending was wrong: the ledger debit happens at
  admin approval (`Admin\WithdrawalController`), so the copy says earnings
  drop only on approval. No support hours, payout time or licence claim.
- Tokens: `admin/src/app/tokens.css` is the source, the portal imports a
  generated copy (`scripts/sync-shared-files.mjs`, CI drift check). Admin
  CSS output checked byte-for-byte against `staging`'s build: same bytes,
  one rule moved.
- Shell: grouped sidebar per role, nude account block, support box from the
  primary brand's public branding, top bar with breadcrumb and sign out,
  trust footer, restyled phone bar and More drawer, Figtree. Removed the
  TailAdmin rail, header, toggle and icon set.
- Verified: portal and admin `tsc`, `eslint`, `npm test`, `build` green;
  Playwright against the dev server with a mocked API at 1440 (both roles,
  light and dark), 768 and 390 (bar, More drawer): computed colours match
  the tokens, no horizontal overflow, no console errors.
- Found by the new portal colour-token test: `warning-300`, `warning-700`,
  `error-200` never had tokens, so those colours never rendered; fixed.

## 2026-10-26 — ADR-128 PR 2: dashboards, Orders and Order detail

- Built to the mockups on existing API data only (ADR-128 build note).
  Shared pieces in `components/ui.tsx` (badge, card head, copyable ID,
  balance hero, facts, pager, skeleton) and `components/orders/OrderTable`;
  the older `PageHeader`/`Panel`/`StatusTag` keep their API with the new
  look, so PR 3–4 screens already read better.
- Fixed in the shared tokens (affects the admin too): dark-mode
  `on-cyan` and `focus-ring` were missing, so every admin primary button in
  dark mode had white text on light cyan at 2.09:1. Now the artifact's
  values (8.1:1, ring 8.4:1).
- JetBrains Mono files added (from the design-system artifact), declared in
  `tokens.css`; both apps now render IDs in it (verified bundled in the
  admin build and loaded in the portal).
- Found while verifying at 768px: an `sr-only` table header escaped the
  scroll box and widened the page. Fixed with `relative` on the box.
- Verified: portal and admin `tsc`, `eslint`, `npm test`, `build`; Playwright
  with mocked API data at 1440 (both roles, light and dark), 768 and 390:
  no horizontal overflow, no console errors, mono font loaded.

## 2026-10-26 — ADR-128 PR 3: Wallet, Earnings and Withdrawal

- Built to the mockups. Shared pieces were added: `components/money/LedgerTable`, `MoneyForm` (amount field with quick amounts, steps card) and `shell/PaymentMark`. Every line of copy about money flow was checked against the code before it was written (ADR-128 PR 3 build note).
- The payment marks and the CHIP badge are now generated copies from the storefront, and CI checks them.
- Fixed: the portal's `proxy.ts` redirected `public/` files to `/login`, which broke the image optimizer.
- Found, not changed: the storefront checkout still says "BNM Compliant • 256-bit SSL" (`storefront/src/components/order/PaymentChannelPicker.tsx`). This is although #527 (2026-10-22) removed the storefront's Bank Negara Malaysia lines for CHIP's review. Raised with the founder.
- Verified: `tsc`, `eslint`, `npm test` and `build` all pass. Playwright with mocked data at 1440 (light and dark), 768 and 390: no overflow, no console errors, and the marks load.

## 2026-10-26 — ADR-128 PR 4: settings pages, Login and Set password

- Every remaining portal screen is moved to the semantic tokens. The ~700 legacy colour classes were mapped with a scripted one-to-one table, and the diff was confirmed class-only. A shared accessible `inputClass` replaces five copies that had `outline-none` and no focus style. Login and Set password share the mockup's `AuthLayout` (details in the ADR-128 PR 4 build note).
- The portal colour test now also blocks TailAdmin colour classes and `dark:` colour overrides.
- Verified: `tsc`, `eslint`, `npm test` and `build` pass. Playwright with mocked data covered Login (light, dark, 390), Profile (both roles), Subscription, Domains (light and dark), API keys (desktop and 390) and Storefront (desktop and 390): no overflow, no console errors.
- Layer 1 of ADR-128 is fully built. The founder reviews and tests it page by page before release.

## 2026-10-26 — Shared component kit decided; docs synced for the session

- #565 (PR 4) merged, so layer 1 of ADR-128 is on `staging` (#562–#565) and
  not released.
- Audit, at the founder's question (does the portal still carry legacy
  libraries, and does it follow the admin's library?):
  - TailAdmin is gone from the portal: icons, rail, `menu-item`, and the
    colour classes, which a test now blocks.
  - The portal uses no PrimeReact component. `primereact`, the provider
    and the PrimeUI licence check do nothing, and the screens run on a
    portal-only Tailwind kit. This departs from ADR-038 and was not raised
    before building.
  - The admin has no unused dependency (`react-is` is recharts' peer). It
    still carries 3,346 TailAdmin colour-class uses in 131 files (kept on
    purpose by ADR-104 decision 7) and the `menu-item` utility in
    `PanelSidebar`.
  - Its `components/ui` kit is mostly still TailAdmin-styled, and its
    primary `Button` measures 4.23:1 (white on `brand-500`).
- Founder decision: one shared kit, recorded in the ADR-128 2026-10-26
  addendum (admin kit onto the tokens first, then generated into the
  portal).
- Docs synced:
  - root and `reseller/` AGENTS.md: the token source, the sync script, the
    current kit gap;
  - PRD §14: what `staging` holds beyond prod;
  - PRD §15: a new "Partner portal UI" row, and the Gallery row's #560
    marked staging-only;
  - PRD §16: item 133 next steps, and new item 134 (the storefront's "BNM
    Compliant" line);
  - ADR-038 and ADR-128 Standing.

## 2026-10-26 — Shared component kit, PR 1: the admin kit onto the semantic tokens

**Why.** ADR-128 2026-10-26 addendum, step 1. The admin `components/ui` kit
(the PrimeReact wrappers) still carried TailAdmin/stock colours while the
portal's screens ran on the semantic tokens — two kits would drift. This PR
moves the admin kit onto the ADR-104 tokens so it can become the shared
source for PR 2.

**Shipped (branch `feature/adr-128-shared-kit-pr1`).**
- **Bridge repoint (`tokens.css`).** `--p-primary-color`/`-contrast`/`-hover`/
  `-active` now point at `cyan-600`/`on-cyan`/`cyan-700` (both `:root` and
  `.dark`) instead of the `brand-*` ramp. White on the primary button goes
  4.23:1 → 5.6:1 (dark: 8.1:1). `reseller/src/app/tokens.generated.css`
  regenerated via `scripts/sync-shared-files.mjs`.
- **Kit migration (10 files), one-to-one class swap, `dark:` twins removed:**
  - `button.tsx` — default/secondary/contrast + outlined/text variants onto
    `cyan-600`/`on-cyan`, `subtle`/`border`/`border-strong`,
    `ink`/`ink-muted`; the focus outline is now one `focus-ring` in the base.
  - `input.tsx` — `rounded-lg`→`rounded-md` (8px), `border-border-control`,
    `bg-surface`, `placeholder:text-ink-subtle`, the outline-based
    `focus-ring` (was `outline-hidden` + ring).
  - `select.tsx` / `switch.tsx` / `tabs.tsx` / `label.tsx` / `dialog.tsx` /
    `popover.tsx` — grey/brand/surface classes onto `ink`/`surface`/`subtle`/
    `border`/`cyan-*`.
  - `datatable.tsx` — `surface-*` → `surface`/`ink`/`subtle`, `bg-primary` →
    `bg-cyan-600`, sort badge `cyan-600`/`on-cyan`, and the frozen-edge
    shadows' hardcoded `rgba(226,232,240,1)`/`rgba(39,39,42,1)` →
    `var(--color-border)`.
  - `tag.tsx` — the last `contrast` severity (`surface-950`/`surface-0`) →
    `bg-ink text-surface`.

**Deferred, not built (PRD §16 item 135).** The button severities
`info`/`success`/`warn`/`danger`/`help` still use Tailwind's stock
`blue`/`green`/`amber`/`red`/`violet`. The design system defines status only
as `-surface`/`-ink` badge pairs, and those `-ink` tokens flip to a light
value in dark mode — a solid `bg-*-ink text-white` fails dark contrast. Needs
a founder decision (solid vs badge) before mapping. Left as-is with a code
comment in `button.tsx`.

**Verified.**
- `npm test` 19/19 (incl. `color-tokens.test.ts`), `npm run lint` clean,
  `npm run build` compiles 51/51 pages.
- Live browser (`getComputedStyle` on `/login`): primary button light
  `rgb(11,114,133)` = `#0b7285` with white (5.6:1); dark `rgb(76,195,212)`
  with `rgb(11,31,36)` (8.1:1). No console errors (the two 401s are
  `resumeSession()` on a fresh browser).
- `RichTextEditor` (the one hand-rolled primitive, ADR-038) is untouched —
  it is not part of the shared-kit source and its `gray-*`/`brand-*` classes
  are already retinted.

**Not live.** Ships with PR 2 (the kit generated into the portal) as item 133,
before the founder's page-by-page review.

## 2026-10-26 — Shared component kit, PR 2: the kit generated into the portal, hand-rolled kit deleted

**Why.** ADR-128 2026-10-26 addendum, step 2. With the admin kit on the
semantic tokens (PR 1), this makes the portal use the same kit instead of
its hand-rolled `ui.tsx`, so the two apps can't drift.

**Shipped (branch `feature/adr-128-shared-kit-pr2`).**
- **Severity colours resolved** (PRD §16 item 135, was deferred in PR 1):
  the Button severities `info`/`success`/`warn`/`danger`/`help` now use the
  design system's `-surface`/`-ink` badge pairs (soft fill + a border that
  intensifies on hover), never a solid `bg-*-ink` (which fails dark contrast)
  and never a `dark:` colour override. This unblocked generation, because the
  portal's `color-tokens.test.ts` rejects `dark:` colour overrides.
- **Touch targets** (ADR-112): Button `small`/`normal` and Input height are
  now `min-h-11 sm:min-h-9` (44px phone / 36px desktop), and the Input gained
  `focus:border-transparent`. A new `Textarea` (native `<textarea>` on
  `inputVariants`) completes the form primitives.
- **Generation.** `scripts/sync-shared-files.mjs` now also copies the 11
  PrimeReact wrappers plus `lib/utils.ts` (and `textarea.tsx`) into
  `reseller/src/components/ui/`; the portal gained the kit's deps
  (`@primeicons/react`, `class-variance-authority`, `tailwind-merge`).
- **Portal swap.** The hand-rolled `reseller/src/components/ui.tsx` is
  deleted. Its composites moved to `reseller/src/components/composites.tsx`
  (`PageHeader`, `Panel`, `BalanceHero`, `Facts`, `CopyId`, `Pager` …), with
  `Badge`/`StatusTag` as thin wrappers on the shared `Tag` (keeping the
  portal's `tone` vocabulary and its always-on dot) and `Pager` on the shared
  `Button`. 21 files migrated: `<button className={buttonClass(…)}>` →
  `<Button>`, `<input/textarea className={inputClass}>` → `<Input>`/`<Textarea>`.
  `buttonClass` stays only for `<Link>`/`<a>` (a navigation link must keep its
  `<a>` semantics), imported from `composites.tsx`. `Toggle` in the storefront
  `shared.tsx` is now the shared `Switch`.

**Verified.**
- Portal: `npm test` 8/8, lint clean, `npm run build` 21/21. The generated
  kit passes the portal's stricter `color-tokens.test.ts` (no `dark:` colour
  override, no TailAdmin class).
- Admin: `npm test` 19/19, `npm run build` 51/51 (the severity/sizing changes
  re-verified).
- Live browser (`getComputedStyle`): portal `/login` "Sign in" button
  `rgb(11,114,133)` = `#0b7285` cyan-600, inputs white 8px radius, no console
  errors. The admin's severity buttons (soft fill) and the portal's full
  page-by-page look are the founder's review (next step in item 133).

**Not live.** Ships with the rest of item 133 (the founder's page-by-page
review of layer 1, then a release).

## 2026-10-27 — Dependency advisories: targeted upgrades (next, zod, livewire, docs-site)

**Why.** The weekly `dependency-audit` (2026-10-17) went red on newly
published advisories. The one that matters is a **critical `next` RCE**
(unauthenticated, via the Image Optimization API with SVG — plus a DoS in
Server Actions and an RCE in `use cache`), affecting all three Next.js apps.

**Shipped (branch `fix/dependency-advisories`), targeted, no `--force`/`--with-dependencies`:**
- `next` `^16.3.8` → `^16.4.3` (admin, storefront, reseller). Patch bump
  within 16.x; 16.4.0 was the vulnerable floor.
- `zod` `^4.4.3` → `^4.6.7` (storefront, direct runtime dep per ADR-044).
- `livewire/livewire` v4.4.2 → v4.4.8 (backend; transitive via
  `laravel/pulse`, the Pulse dashboard). Medium: `#[Authorize]` authorization
  bypass. No Livewire use in `app/`/`routes/` — Pulse's own UI only.
- `zod` dev-only (admin, reseller, docs-site, via the `eslint-config-next`
  → `eslint-plugin-react-hooks` → `zod-validation-error` chain): bumped
  transitively by `npm audit fix` (non-force). Not shipped; the CI's
  `--omit=dev` never flagged it.
- docs-site (build-time only, static SSG): `undici` 8.10.2→8.12.3,
  `vite` 8.3.0→8.3.5, `fast-uri` 3.1.8→3.1.9,
  `postcss-selector-parser` 6.1.4→6.1.5, `zod` 4.6.1→4.6.7 via `npm audit fix`
  (non-force). `astro` stays: the one remaining advisory is **low** (CSS
  injection in View Transitions) and needs `--force` to reach 7.4.2, which is
  outside the stated range — the same `--force` the policy says not to use.

**Verified.**
- `composer audit` clean; `npm audit` = 0 in admin/storefront/reseller;
  docs-site `npm audit --omit=dev --audit-level=high` passes (only the `low`
  astro advisory remains, below the CI threshold).
- admin build 51/51 + test 19/19; storefront build 14/14; reseller build
  21/21 + test 8/8; docs-site build complete; backend `php artisan test`
  3080/3080.

**Not live.** Ships with the portal redesign + shared kit in the next
`staging`→`main` release (one regression window, see item 133).

## 2026-10-27 — Pre-release review of the portal redesign + shared kit; release #571 live

**Context.** The shared-kit PRs (#567, #568) and the dependency fix (#570)
were built in OpenCode, not Claude Code, as the founder's test of another
agent. Claude reviewed them, merged #570, then took over the page-by-page
review and the release at the founder's request.

**Review of the OpenCode PRs.** The process matched AGENTS.md: branches off
`staging`, stacked PRs, granular commits, docs updated, tests and builds run,
and the founder was asked before the status-button colour was chosen. Two
gaps, closed in this session's docs PR:
- The founder's choice of soft over solid status buttons was recorded as
  "forced by" the colour test, with no ADR addendum. Now ADR-128 2026-10-27
  addendum; PRD item 135 reworded.
- No admin screenshot of the restyled status buttons or the 30px `small`
  size before "done". Covered by the review below.

**Pre-release review (local dev, real dev-DB data, Playwright).**
- The founder's `./scripts/dev.sh` was still running Next 16.3.8 from
  2026-10-25 after `npm install` had moved `node_modules` to 16.4.3, so every
  admin and portal page answered 500. Restarted with the founder's go-ahead.
  Gotcha: Next 16.4.3's dev server rewrites the banner heading in
  `admin/`, `storefront/` and `reseller/` `AGENTS.md` (`#` → `##`);
  committed so the tree stays clean.
- Portal: Reseller (Dashboard, Orders, Wallet, API keys, Subscription,
  Profile) and Affiliate (Dashboard, Orders, Earnings, Withdrawal,
  Storefront, Domains, Profile), light and dark, 1440px and 390px, plus
  Login and Set password: 58 screenshots. No horizontal overflow, no error
  state, no console error except a 403 the Reseller gets on the
  Affiliate-only `/subscription`.
- Admin: 12 pages (Dashboard, Orders, Withdrawals, Vouchers, Games,
  Resellers, Affiliates, Gallery, Accounting Summary, Users, Middleware
  Suppliers, Price Sync), light and dark. Console errors were local-only
  (CSP for the Herd image host and Reverb's `ws://localhost:8080`).
- Measured (`getComputedStyle`, Vouchers page): primary button 5.59:1 light,
  8.14:1 dark; danger "Revoke" 5.70:1 light, 7.66:1 dark.
- A full-page screenshot draws the portal's fixed phone bottom bar
  mid-page and cuts the sidebar at the viewport height. That is how the
  screenshot works, not a layout bug.

**Release #571 (`staging`→`main`, requested by the founder).** Merged as
`f4177bc` at 2026-10-26 17:06 UTC; ships #560–#570. CI and `deploy` green.
Verified read-only:
- the box runs release `81251053` at `f4177bc`;
- `livewire/livewire` v4.4.8 in the deployed lock;
- Horizon running, `/api/health` 200;
- all four Vercel production builds succeeded;
- `reseller.pekangame.space/login` serves the "PekanGame Partner" shell.

No migrations, no env changes. PRD item 133 closed.

## 2026-10-27 — ADR-129: action feedback (toasts) — PR 1 (wrapper + provider + three screens)

**Why.** Neither frontend gave consistent feedback after an action — success
and error were inline page-top `<p>` state (`setError` / `setNotice` /
"Saved.") inside each modal or page (112 admin files, 20 portal files). An
admin couldn't tell whether an action ran, was running, or failed. ADR-129
(design, PR #573) decides: one PrimeReact headless toaster, one shared
wrapper, a toast-vs-inline policy, promise-driven success, sticky money
errors.

**What shipped (PR #574, merged to `staging` 2026-10-27; not yet on `main`).**
- `admin/src/components/ui/toast.tsx`: `ToastProvider` + `notify`
  (`success` / `info` / `warn` / `error` / `stickyError` / `promise`) +
  `useToast()`, on the semantic `-surface` / `-ink` tokens only (no `dark:`
  overrides — passes both `color-tokens.test.ts`). Built on
  `@primereact/headless/toaster` (`toast()` manager + `useToaster()`), which
  share one manager — no bespoke store.
- Added `"toast"` to the `KIT` list in `scripts/sync-shared-files.mjs`, so the
  portal's copy is generated, never hand-edited.
- Mounted `ToastProvider` in both root layouts.
- Three screens: admin Withdrawals (approve/reject/complete → success toast +
  `stickyError`), admin Orders (refund-to-wallet, mark-delivered,
  confirm-failed, voucher-issued, export, retry-queue error → toast; the async
  resend/retry "waiting for outcome" line stays inline), portal Withdrawal
  (success toast; form errors stay inline).
- E2E `admin-mark-delivered` and `admin-issue-voucher` now assert the split
  toast title.

**Gotchas.**
- Playwright caught two specs still asserting the old inline strings
  (mark-delivered "— ledger profit credited.", issue-voucher trailing period);
  both updated in the same work.
- `@primereact/headless` was a phantom dependency (transitive via
  `primereact`); declared it explicitly in both `package.json` + lockfiles.

**Follow-up (review notes, separate PR).** The #574 review raised five notes —
reduced-motion and focus-on-dismiss were promised but not yet done; the
`limit: 4` prop and `data-enter-from` classes had no effect; Withdrawals
refreshed only on success; and there was no build-log entry. All five are
addressed in the follow-up sweep PRs, which stay open together against
`staging` for one review.

## 2026-10-27 — ADR-129 action feedback (toasts): full rollout (#575–#580)

**Why.** After the PR-1 wrapper (#574), the remaining screens still showed
action outcomes as inline `setError`/`setNotice`/"Saved." state — not silent,
but not toasts. This closes ADR-129 (§16 item 136): every action outcome is a
toast; field validation, load/empty states, persistent warnings and the
order-modals' confirmations stay inline on purpose.

**What shipped (#575–#580, released to `main` via #581).**
- #575: the five review notes on #574 — `@primereact/headless` declared,
  focus-on-dismiss returns to the region, entrance motion dropped
  (a mount-flip sets state in an effect, which the lint forbids), the
  visible `limit` honoured by slicing, Withdrawals refreshes after a failed
  action too, and this file's first entry.
- #576: Vouchers, Affiliates, Resellers, Admin users (the shared `guard()`
  takes a success message).
- #577: Middleware panel (suppliers, payment methods, gateways, product
  manager, sandbox, validators). A follow-up fix read the response body, not
  just the exception: a failed payment-method test answers 200 with the
  failure in `last_test_result`, and a breaker-open supplier probe is not a
  credential failure (ADR-069 stress-test Q3).
- #578: Games/SEO/Gallery/Hero slides/Reviews/Blacklist — replaces the
  inline "Saved." flag on the SEO save screens.
- #579: the money-critical flows — manual-order finalize, membership payment,
  supplier transfer, envelope posting/void/month close, transfer correction,
  portal wallet top-up. Failures are sticky (`duration: Infinity`).
- #580: the remaining money actions (AffiliateDetailModal tier charge/
  reactivate/assign, Settlements update, TierCard price, Joki payout share)
  and the non-money config (Joki rank model/routes/settings, admin Settings
  sections, Reseller API-keys/webhook/portal-users, portal api-keys/webhook/
  profile/domains).

**Money-safety discipline.** A success toast fires only after the promise
resolves (and, where the endpoint returns an outcome in its body, only when
that outcome says so); money-action failures are sticky so they can't vanish.
No backend, ledger or money-logic change anywhere in the rollout.

**Gotchas.**
- The review caught one more "200 with a failure in the body": the affiliate
  tier-fee charge answers 200 when earnings are short (no debit, the cycle
  goes to grace) or the cycle is already paid, so "Tier fee charged" could
  show for a charge that never happened. The success toast now requires an
  advanced `next_charge_at`; otherwise it warns "Fee not charged". The
  payment-method test (#577) is the same class of fix.
- The `guard`/`run`/`save` helpers across screens were each extended with an
  optional success message instead of a bespoke toast store, so the library
  stays swappable in one file.
- Removed several now-unused `error`/`notice`/`saved` states; lint caught the
  leftover `JOKI_ERROR`/`JOKI_NOTICE` imports and `setError` assignments.
- The portal copy is generated (`scripts/sync-shared-files.mjs`), never
  hand-edited; CI's `theme-preset-drift` guards it.

## 2026-10-28 — DuitNow QR was sent to CHIP under the wrong code; Test action fixed (ADR-022 addendum)

**Found (read-only prod + CHIP portal).** The founder's DuitNow test order ended `Cancelled`, "No matching terminal", Visa. Cause: our code `duitnow_qr` vs CHIP's `dnqr` (`GET /payment_methods/` with the live credentials: `fpx`, `fpx_b2b1`, `dnqr`). CHIP kept `["duitnow_qr"]` as the whitelist (the purchase JSON echoes it), so no error. The Test button's HTTP 400 on every channel was a separate bug: no `client.email`. Prod had 28 real FPX orders while Test said FPX "failed".

**Built** on `fix/chip-dnqr-channel-code`: seeder + rename migration, `verifyChannel` in the Test probe, email in the probe, CHIP field errors in `failureFrom`. `php artisan test`: 3084 passed.

**Gotchas.** (1) Live CHIP credentials come from the `payment_gateways` row (`ChipCredentialResolver`), not `.env`: reading `config('services.chip')` in tinker used a different key (a purchase GET returned 404). (2) An unknown whitelist value is not rejected by CHIP, so checking the purchase response for an empty whitelist would not have caught this. **Not yet verified:** a paid DuitNow QR payment; after release, run Test on `dnqr`, then pay RM1 for real. Until released, DuitNow QR should be switched off in Payment Methods.

## 2026-10-28 — MLBB validator: Redx → UniPlay replaces Redx → MooGold (ADR-005 addendum)

**Found (read-only).** Redx's `POST /tools/check` answered 503 "temporarily unavailable" for `mobile-legends` three times in a row, while `free-fire` and `genshin-impact` returned 200 with the identical session/CSRF handshake. So our `RedxGameValidator` was fine; Redx had turned its MLBB checker off. Our code already treats a non-check response as unavailable and falls back. The public `/check-id` MLBB page was Redx-only, so it showed "busy".

**Built** on `feature/uniplay-validator-replace-moogold`: `UniplayValidator` (token read from the page and cached, renewed once on 401/403; `status:false` = invalid), chain Redx → UniPlay for checkout and the public checker (ADR-123 addendum), MooGold validator/test/config deleted. `php artisan test`: 3080 passed.

**Gotcha.** The UniPlay token regex first rejected the test token `tok-1` because base64 has no `-`. **Not verified from our server:** the live UniPlay call (this session's automation was blocked from using their page token); the founder confirmed the response shape in a browser. After release, check `player_validations.provider` for an `uniplay` row.


## 2026-10-29 — Docs sync after #582–#584 (merged to `staging`, not released)

Merged #582, #583, #584 into `staging` (merge commits). #584 conflicted with #583 on `build-log.md` (both appended at the end); both entries kept. #584's first CI run failed `scramble-drift`: its migration called `Cache::forget`, and that job migrates with no Redis; the cache calls were removed (catalog caches expire in 60 s).

Docs corrected against prod and git (read-only, 2026-10-28/29): PRD §14 said DuitNow QR "stays off (checked 2026-10-26)" and "staging = main"; prod showed DuitNow QR on since 2026-10-28 13:28 UTC (unpayable with the wrong code) and `staging` ahead of `main` by #582–#584. Also corrected: the Game ID Checker row and `foundation-security.md` ("Redx only, never MooGold"), ADR-076/093 notes ("only MooGold remains"), ADR banners and index dates. Prod runs #581 (`2db6d62`): MLBB checks go Redx (HTTP 503 since 2026-10-28) → MooGold; the three `player_validations` rows since then are `moogold`.

Correction to this session's earlier count: prod has 23 *paid* FPX orders, not 28 (28 includes failed and pending).

## 2026-10-29 — FPX bank-direct, PR 1 (ADR-130): the customer picks the bank on our page

**Designed first, then built.** ADR-130 (#586) records the decision, the 2026-10-29 CHIP test-mode run and the code trace that overturned the first plan: appending the parameter in `ChipGateway::createPayment` would have done nothing for orders (they re-read the URL through `getPayment`) and would have pinned the first bank on membership/wallet (stored `checkout_url`).

**Built (PR 1, branch `feature/fpx-bank-direct`, not yet released):**
- `backend/config/fpx-banks.json` (20 B2C codes from CHIP's FPX doc table, popular first) read by `config/fpx-banks.php`; `FpxBankRedirect` appends `?preferred=fpx&fpx_bank_code=<code>` to the `WEB_URL` action only for a listed code and never to an `/invoice/` URL.
- `CheckoutController::buildCheckoutResponse` applies it to a Pending order's response (create and every replay); `CreateCheckoutRequest` gets `fpx_bank_code` (`Rule::in` the list, `prohibited_unless:channel_code,fpx`). Nothing is stored; `payloadHash` is unchanged, so a replay with another bank is the same order.
- Storefront `PaymentChannelPicker`: a 20-logo grid (3 columns on a phone, 4 from `sm`) replaces the FPX button, plus "Other bank? Choose it on the next page" (same request, no bank). `OrderForm` and `JokiOrderForm` keep `bankCode` separate from `channelCode` and send `fpx_bank_code`. `CheckoutPayloadSchema` needed the key too: Zod strips unknown keys, so without it the bank vanished before reaching the API.
- Logos: the founder's 20 files, resized to 240 px wide, named by lower-cased CHIP code in `storefront/public/images/banks/`.
- `scripts/sync-shared-files.mjs` copies the JSON to `storefront/src/lib/fpx-banks.json`; CI's `theme-preset-drift` now diffs that path too (it only covered `reseller/` before).
- Four e2e specs clicked the removed "Online Banking (FPX)" button; they now pick Maybank2u.

**Verified:** backend suite 3097/3097 (new: `FpxBankRedirectTest` pins the 20 codes, `CheckoutControllerFpxBankTest` 7 cases: bank applied, plain without, replay with another bank, replay via the other-bank link, nothing stored on the order, unlisted code 422, bank on another channel 422); storefront `tsc`, `lint`, `build`. In a real browser, on a temporary preview page (deleted): 20 tiles render, selecting a bank sets `fpx` + its code, "Other bank" sets no bank, DuitNow clears the bank; at a 358 px container the grid is 3 columns of 103x48 px with no horizontal overflow.

**Gotchas hit:** the local backend's `/api/catalog/games` 500s on `CACHE_STORE=database` (backend/AGENTS.md gotcha 5), so the storefront showed no games and the full order flow could not be clicked through locally; the picker was checked on a throwaway page instead. Chrome blocks iframing a storefront page, so the phone width was simulated by narrowing the container.

**Not done / owed:** PR 2 (membership onto the shared picker, wallet top-up, the portal's copy of the JSON and logos); one real RM1 payment through a real bank after release (test mode accepts any code, ADR-130); bank-down status stays unbuilt (ADR-130 decision 9). The review modal still shows "Online Banking (FPX)" without the bank name.

## 2026-10-29 — FPX bank-direct, PR 2 (ADR-130): membership and wallet top-up

**Built (PR #588, `feature/fpx-bank-direct-pr2`, not yet released):**
- Backend: `FpxBankRedirect::rules($channelField)` is the one rule for `fpx_bank_code` (`SubscribeRequest` names the channel `payment_method`, so a hard-coded `channel_code` would have silently never fired there; `CreateCheckoutRequest` now uses it too) and `applyToUrl()` the one URL helper (`apply()` for the order's `actions` array uses it). `MembershipController::subscribe` and `WalletController::topup` apply the bank to the response `checkout_url` for a Pending attempt only; the stored `checkout_url` stays plain, so a retry with another bank gets the right URL.
- Storefront: `MembershipSubscribe` drops its own radio list and "Secured via CHIP" strip for the shared `PaymentChannelPicker`. The two auto-select-when-one-channel lines are gone (they would have shown "Other bank" as chosen without a click), so "Continue to Payment" waits for a bank or "Other bank". `subscribe()` sends `fpx_bank_code`. The "BNM Compliant • 256-bit SSL" line is removed from the picker (item 134, founder's decision).
- Partner portal: `components/money/FpxBankGrid.tsx` in the portal's own styling over generated copies of `fpx-banks.json` and the 20 logos (`sync-shared-files.mjs`; the CI drift diff now covers `reseller/src/lib/fpx-banks.json`). The wallet's default channel skips the bank-grid channel, so nothing is pre-selected on it. Two lines that said the fee/payment is on "CHIP's page" now say "payment page", since bank-direct skips CHIP's page.

**Verified:** backend suite 3104/3104 (new: membership 3 and wallet 2 feature cases, `applyToUrl` and `rules` unit tests); storefront `tsc`/`lint`/`build`; reseller `tsc`/`lint`/8 tests (incl. the colour-token test)/`build`. In a real browser on a throwaway preview page (deleted, plus the test cookie): the portal grid renders 20 tiles, a click sets the bank and `aria-pressed`, "Other bank" clears it, `cyan-600` resolves, no horizontal overflow.

**Gotchas hit:** the portal's `proxy.ts` redirects every unauthenticated path to `/login`, so the preview page needed the presence-only `kerox_reseller_session` cookie (not a credential). PR #587's CI failed once on `Adr126ManualOrderSeamsTest` (a 1 s flake: `credentialFor()` reads `now()` per call; fixed by freezing time in that test).

**Not done / owed:** the membership e2e and the full membership and wallet click-throughs run only in CI or need a verified member / reseller login; one real RM1 payment through a real bank after release (item 138); bank-down status (item 139, only if needed).

## 2026-10-29 — A failed payment attempt is final, PR A backend (ADR-110 addendum, item 140)

**Why.** The founder cancelled at a bank's login page after FPX bank-direct went live. Read-only prod: purchase `be09ba53-…` went `created` 04:33:24 → `error` 04:33:30 (CHIP attempt error `1C`) → `expired` 05:03:23; CHIP showed its payment form again (the customer could pay the same purchase) and our order stayed Pending until the sweep failed it at 05:15:05, 42 minutes later. The founder's rule: after a cancel the customer starts a new checkout.

**Tested first, CHIP test mode (the founder ran the commands):** with `purchase.single_attempt: true` and the simulator's "Test Failure", CHIP moves the purchase `error` → `cancelled` one second later, sends the customer to `failure_redirect`, and opening the link again goes straight to `failure_redirect`. So no `cancel` call is needed.

**Built (PR A, `feature/failed-attempt-final`, not yet released):**
- `ChipGateway::createPayment` sends `purchase.single_attempt: true` (every purchase: orders, membership, wallet top-up and the bot's, the admin Test probe). `normalizePurchase` adds `failure_is_final` (cancelled/expired/overdue/blocked always; `error` only on a single-attempt purchase, so a purchase created before this ships is never failed early). `PaymentResponse::isFinalFailure()` is the one reader.
- Orders: `PaymentReconciliationService::reconcileOrder($order, earlyCheck: true)` applies a failure only when final (paid answers still applied). Used by a new public `POST /api/track-order/{orderNumber}/check-payment` (brand-scoped, Pending orders with a `payment_ref` only, `throttle:10,1` plus a 10 s per-order cache lock so one CHIP call per window, a failed lookup returns the order unchanged) and by the checkout replay path in `CheckoutController::respondForExistingOrder` (a replay whose purchase ended now fails the order and answers `checkout_closed` instead of handing out the dead link). `RouteAccessTest`'s public-route list got the new route on purpose.
- Membership: `initiate` fails a recent Pending attempt whose purchase ended and creates a new one; a replay on the same idempotency key of a failed attempt answers 422 `checkout_closed` (`MembershipAttemptClosedException`).
- Wallet top-up: `failPendingIfFinal` runs before the transaction that holds the ledger-account lock (no CHIP call under the lock), so a reseller who cancelled at the bank is no longer blocked 30 minutes and the bot no longer re-sends a dead link. The lock's own check stays for races.
- Every failure goes through the existing seams (`OrderPaymentOutcomeService::applyFailed`, `LeavesPendingOnce::leavePending`), so voucher and quota are given back as before.

**Verified:** `php artisan test` 3129/3129; new `CheckoutControllerFailedAttemptTest` (10: cancelled fails the order, voucher given back, retryable error left pending, open purchase pending, a paid answer applied, one CHIP call per window, non-Pending not looked up, 404, replay closed, replay of an open purchase returns the link), `ChipGatewayTest` (+11: single_attempt sent, the `failure_is_final` matrix), membership (+3) and wallet (+2) cases; concurrency suite for wallet top-up and membership (3) on local MySQL; `scramble:export` shows no drift.

**Gotchas hit:** the concurrency run first failed on leftover `llm_report_*` views (backend/AGENTS.md gotcha 8, dropped with the documented command). `RouteAccessTest` fails on any new public POST until it is listed.

**Not done / owed:** PR B (storefront status page: one check on open, "Payment not completed" + "Try again"; membership clears its key on `checkout_closed`); one real RM1 cancel at a bank after release (item 138/140): CHIP error `1C`, our failed page, order Failed within seconds, a new checkout works.

## 2026-10-29 — Release #589 live, verified read-only on prod; PR B (#592) built; docs synced

**Release #589 (`staging`→`main`, `c7cfcf06`, 12:15 MYT).** The box repointed `current` at 04:27 UTC (release `81523398`). Read-only on prod at 06:31 UTC: the rename migration is in `migrations`; `payment_methods` is `fpx` on, `fpx_b2b1` off, `dnqr` on; `player_validations` since the deploy has 3 `valid` rows from `uniplay` (latest 04:33 UTC), none from `moogold`.

**What the founder tested, and what the data shows.** The founder reported DuitNow paid, FPX redirected straight to the bank and the MLBB check worked. Prod data: **no order has been paid since 2026-10-28**; the three orders since the deploy are all Failed. `PG-6DGYYPTUUEXR` and `PG-UOOPLDNB9GVW` (FPX) reached the bank through the grid (CHIP attempt `fpx_buyerBankId` `BCBB0235`, CIMB) and were cancelled at the login page (error `1C`). `PG-XM0J3KQRTKJF` (DuitNow) reached DuitNow (attempt `payment_method: dnqr`, which proves the code fix) and was cancelled by the user ("Transaction cancelled by user"); its CHIP purchase ended `expired`, not paid. So the routing fixes are proven and a completed payment through either is not. Recorded as it is; the PRD says the same (§14, §16 item 138). The Test action's stored results for `fpx` and `dnqr` are still the old HTTP 400 ones: it has not been run since #584.

**The cancel finding that led to item 140.** `PG-UOOPLDNB9GVW`'s purchase went `created` 04:33:24 → `error` 04:33:30 → `expired` 05:03:23; CHIP showed its payment form again (the same purchase could be paid) and our order was Failed only at 05:15:05 (sweep every 15 minutes, orders older than 30). Fix designed and built the same day: ADR-110 addendum (#590), backend #591, storefront #592 (see the entry above for #591).

**PR B (#592, merged to `staging`, not released):** `OrderStatusTracker` asks `POST /api/track-order/{n}/check-payment` once per page open for a Pending order and shows "Confirming your payment…" meanwhile; a failed payment shows "Payment Not Completed", "Nothing was charged…" and "Try Again" as the primary button (`/order/{slug}`, `/joki` for a joki order); `MembershipSubscribe` clears its idempotency key on 422 `checkout_closed`. A first version cancelled the check's answer in an effect cleanup that ran on every poll update (the page would have stayed on "Confirming…"); caught on review and removed before the PR. `tsc` and `lint` clean.

**What was and was not verified for #591/#592.** Proven against CHIP test mode with local orders (Herd serving the merged branches): after the simulator's "Test Failure" the check fails the order at once, a second call within 10 s does not call CHIP again, a replay answers `checkout_closed` with and without a prior check, and the purchase carries `single_attempt: true`, ends `cancelled`. **Not verified:** the status-page copy in a browser. The Chrome profile the assistant uses does not trust Herd's local certificate, so the page could not load the API (the assistant did not click through the warning), and an in-page `fetch` rewrite did not survive Next's full navigations. Local order `PG-CSITBGSMJJI3` was left Pending (its CHIP purchase is cancelled) in the dev DB for a hand check; the dev DB also holds two local test orders, both Failed. Herd's web service was not answering mid-session (nothing listening on 80/443); the assistant ran `herd start` and it answered afterwards.

**Docs synced (this entry's PR).** PRD §14: the "payments are switched off" paragraph is replaced by the checked payment state, the unreleased-work paragraph by the #589 release and the new unreleased #590–#592; §15 rows for Payment Gateway, Image Gallery (live since #571, not "on staging"), Middleware Panel and Game ID Checker (UniPlay released) and the Reseller wallet (payable by FPX); §16 items 134 and 137 closed, 138–140 added, group A empty. Facts came from git (`origin/main`, merge commits), the Forge `current` symlink and SELECT-only queries.

**Owed, in order:** release #590–#592; then item 138 (a completed RM1 FPX payment through a grid bank, a completed DuitNow QR payment, Test on `fpx` and `dnqr`) and item 140 (a real cancel at a bank).

## 2026-10-29 — ADR-131 PR A built: Purchase Balance name, limits, receipts (on `feature/purchase-balance-limits-receipt`, not released)

**Why.** CHIP's activation conditions for LWF Group's merchant account (ADR-131; PRs #594 design, #595 test fix). PR A covers the rename, the limits and the top-up receipt; PR B (account closure and the two Transaction Register rows) and PR C (the affiliate-domain payment gate) are next, and all three ship in one release before CHIP is answered.

**Built.**
- Migration `2026_10_29_150000_add_purchase_balance_limits`: `platform_settings.topup_max_sen` (RM10,000), `balance_cap_default_sen` (RM100,000), `company_legal_name`/`company_reg_no`/`company_address`; `resellers.balance_cap_sen` (null = the default).
- `ResellerWalletService::limits()` and `assertWithinLimits()`: one check for the CHIP top-up start (portal and bot) and the admin manual credit. It takes the reseller's `ledger_accounts` mutex first, because `LedgerService::credit()` takes no lock. `completeTopup` and the order `wallet_refund` never call it. Two exception subclasses of `InvalidWalletTopupAmountException` (over the maximum; over the cap) so the portal answers 422 with the numbers and the bot answers in Malay instead of "minimum RM10".
- `PUT /settings/purchase-balance-policy` and `PUT /resellers/{id}/balance-cap`, both `mfa.stepup` (`AdminMfaStepUpTest` list updated); the generic reseller update cannot touch the cap (a missing field is a 422, not a reset). Seller details through the existing platform settings (`sometimes`).
- Payment Receipt: `ResellerWalletReceiptService` + `GET /reseller-portal/wallet/receipts/{number}` (dompdf, no new dependency), `PB-<attempt reference>` / `PB-M-<ledger entry id>`. The fee printed is `total_charged_sen - amount_sen`; CHIP's own `gateway_fee_sen` is never shown. Another reseller's number is a 404.
- Rename of every Reseller-visible string: portal (route `/wallet` -> `/balance`, permanent redirect, so in-flight CHIP return URLs still land), Reseller API messages and descriptions (`openapi.json` regenerated), docs-site prose, bot replies, the description sent to CHIP. Kept on purpose: admin panel, code names, ledger types, JSON field names, error code `INSUFFICIENT_BALANCE`, the bot commands, the e-Wallet payment icon.
- Admin: a "Purchase Balance" settings tab (limits with step-up, company details) and a "Balance cap" card in the reseller wallet modal.

**Verified.** `php artisan test` 3164 passed; reseller 8/8, admin 19/19, lint and `astro check` clean; the `tsc` error left in `reseller/` is only the stale, gitignored `.next/types` and clears on the next dev or build. Concurrency (real MySQL): `test_two_simultaneous_manual_credits_cannot_both_pass_the_balance_cap` passes 3/3 with the lock and **fails 3/3 with the lock removed**. Dev DB migrated with plain `php artisan migrate`.

**Gotchas hit.**
- `backend-concurrency` failed on docs-only #594: the wallet top-up test helper's fake gateway threw from `getPayment()`, which #591 made `initiate()` call. A timing flake, fixed in #595 (proved by forcing the order).
- A manual-credit-vs-top-up-start race test was dropped: a pending CHIP attempt is deliberately not counted against the cap (ADR-131 decision 6), so both may legitimately pass. Only the two-manual-credits race is asserted.
- Existing admin wallet tests created a reseller with no ledger account; the limits check locks it, as `initiate()` always did. Real resellers get it at creation, so the test fixture was fixed, not the code.

**Browser check (local, Playwright against the founder's `./scripts/dev.sh`).** Reseller portal `/balance` and dashboard: no "wallet" or "top-up" word left on either page; limits line, terms card, an amount of RM20,000 shows "The maximum per payment is RM 10,000.00." with the submit disabled; the Receipt button downloads a real PDF (200, `PB-WT-LOCALTEST01.pdf`); the longer "Purchase Balance" nav label fits the phone bottom bar at 390px. Admin: the new Purchase Balance settings tab renders; Company details save (`PUT /settings/platform` 200); the reseller wallet modal shows the Balance cap card ("Currently RM 100000.00 (platform default)", using the modal's own `toFixed` format like its balance). The receipt layout was checked by rendering the same Blade view to HTML (no poppler to render the PDF). Found and fixed here: step 2 of "How adding balance works" still said "No receipt to send", and the terms card touched the card above it.

**Redirect verified after a restart.** The first check failed because the old dev servers (a leftover run that held ports 3000-3002, 8000, 8080) had not loaded `next.config.ts`; after stopping them and starting `./scripts/dev.sh` fresh, `GET /wallet?topup=success` answers `308` with `location: /balance?topup=success`, query kept (in-flight CHIP returns still land).

**Not verified in a browser.** The step-up save path: the test admin has no MFA, so "Save Limits" got the existing 403 "require MFA on your account" toast (same as Customer Login Policy); the route is covered by `AdminMfaStepUpTest` and `PurchaseBalanceAdminTest`.

**Not done / owed.**
- PR B and PR C; the docs for them (`foundation-security.md` closure wording, ADR-060 domain gate line); production screenshots from a test reseller after the release.
- `TrustFooter.tsx` hard-codes "LWF Group Sdn. Bhd. (202601038896)": the receipt reads its seller details from the new settings instead, so the two can drift; the founder decides whether to seed the settings from it and point the footer at them.

## 2026-10-29 — ADR-131 PR B built: reseller account closure and the two Register rows (on `feature/reseller-account-closure`, not released)

**Prod check first (read-only, SELECT).** `wallet_topup` ledger rows: 3, all CHIP (`wallet_topup_attempt`), 0 manual; 2 resellers, both active, none deleted. So the new manual-credit Register rows change no existing figure. (The `accounting_periods` table I guessed does not exist; the real one is `accounting_period_closes`.)

**Built.**
- `ResellerClosureService::close()` (one transaction): locks the wallet `ledger_accounts` row, then the reseller row; refuses while a wallet order is not `closed()` or an Add Balance attempt is `Pending` (whatever its expiry); debits the whole balance as `wallet_closure_refund` (none for a zero balance; the transfer date and bank reference go in `reason`); revokes API keys, portal logins and tokens, unlinks WhatsApp groups, disables the webhook; deactivates and soft-deletes. `POST /resellers/{id}/close` (super_admin + `mfa.stepup`, listed in `AdminMfaStepUpTest`), read-only `GET /resellers/{id}/close-check`; the bare `DELETE` is gone (405).
- The transfer date must be a day of this Kuala Lumpur month, not in the future, in an open month (a ledger row is always dated now, so this is what keeps the debit and the cash in the same month). A repeat on a closed reseller is a 404 from the route; the service has its own 422 for a race.
- `OrderSettlementService::settle()` refuses a closed reseller's order (the one seam `refundToWallet`, the voucher-from-order action and the test command all use); `completeTopup` still credits and logs `critical`.
- Transaction Register: `reseller_wallet_manual_credit` (cash in by bank transfer, the pre-existing gap) and `reseller_wallet_closure` (cash out); `WalletTopupAttempt::reseller()` is `withTrashed()` so a closed reseller keeps its name.
- Admin: "Close account" replaces "Delete" on the Resellers page, with a modal that runs the preflight, lists blockers, and asks for the transfer date and bank reference; the two new Register types and the closure ledger label; the portal's ledger label.

**Verified.** `php artisan test` 3193 passed. New: 23 closure tests (each blocker, the date rules, a closed accounting month, revocations, the settlement guard, a late payment); Register row tests; `FinancialScenarioTest` closes the scenario reseller and the cash-equation gap is unchanged (it **fails** if the bank movement is left out), and a Failed unrefunded wallet order blocks it until refunded. Real MySQL, two processes: a closure racing an order placement conserves money, and two closures refund once, 3/3 each; the double-closure test **fails 3/3 with both row locks removed**. admin 19/19 and reseller 8/8, lint and `tsc` clean. Browser (local, Playwright): the modal shows the balance, the instructions and the date/reference fields; with a pending Add Balance attempt it lists the blocker and hides the form.

**Gotchas hit.** Two old controller tests exercised the removed `DELETE`; replaced by tests on the close route (which needs a stepped-up admin). `AccountingPeriodClose` has many NOT NULL columns, so the closed-month test fills them all. Pint flagged style on my own edits only (baseline was clean), fixed.

**Not verified in a browser.** The final submit: the test admin has no MFA, so the existing step-up refusal applies; the route is covered by backend tests.

**Owed.** PR C (the affiliate-domain payment gate); the glossary note for "closed" accounts is not needed (no new term); production screenshots from a test reseller after the one release; the founder's recording of the first real closure's bank reference in the build-log if one ever happens.

## 2026-10-29 — ADR-131 PR C built: a branded domain takes no CHIP payment until approved (on `feature/affiliate-domain-payment-gate`, not released)

**Built.**
- Migration `2026_10_29_170000_add_payments_approval_to_affiliate_domains`: `payments_approved_at` (null = not approved) and `payments_approved_by`. It approves the rows of the `is_primary` affiliate and nothing else (an `is_owned` brand's rows stay unapproved). Run for real on the dev DB with a seeded primary-affiliate row: rolled back and re-applied, the primary row is approved, the scenario affiliate's row stays null.
- `StorefrontBrand::assertPaymentsEnabled()` / `paymentsEnabled()`, fed by `ResolveStorefrontBrand` from the domain row it already loads (no second query). Only a resolved custom hostname can say no: the primary host and every non-storefront caller carry no hostname and are never gated. Called at the top of `CheckoutService::initiate()` (before the Order exists; skipped when a voucher covers the whole order), in the no-link branch of `CheckoutService::resume()`, and at the top of `MembershipSubscriptionService::initiate()`. `PaymentsNotEnabledException` renders itself as `403 { code: payments_not_enabled }`.
- `GET /catalog/branding` returns `payments_enabled`, computed per request outside the per-brand cache (two domains of one brand share that payload but not their approval).
- Admin: `PUT /affiliates/{id}/domains/{id}/payments-approval` (super_admin + `mfa.stepup`, listed in `AdminMfaStepUpTest`, 404 for another affiliate's domain), the affiliate detail shows it, and an Approve / Revoke action with a confirm. The affiliate's own portal shows "Payments awaiting approval" on a live domain. The storefront shows a notice and disables pay on a domain that is not approved.

**Verified.** `php artisan test` 3204 passed. `AffiliateDomainPaymentGateTest` (11): refused with no Order created; approved works; the primary host and a header-less request are never gated; revoke then a no-link replay waits for approval and resumes after; a replay of an order that already has a link still returns it (accepted, decision 21); membership refused with no attempt; branding per domain without cache bleed between two domains of one brand; approve/revoke stored with the admin. Removing the three `assertPaymentsEnabled()` calls fails exactly the three gate tests. e2e is unaffected (it runs on the primary host `localhost`, which has no domain row).

**Gotchas hit.** Existing tests that pay on a brand domain (`AffiliateStorefrontCheckoutTest`, one membership test, and the `FinancialScenario` brand domain) needed `payments_approved_at` on their domain fixture; the fixture was the thing that changed, not the code. The branding route is `/api/catalog/branding`.

**Owed before the one release.** Re-check prod's `affiliate_domains` read-only (any `Active` row besides the primary affiliate's would stop taking payments on deploy); the browser check of the three screens; then, after CHIP's written approval of `ohahastore.com` (not in prod yet), approve it in the admin. `fixfastapp.com` stays unapproved (to be deactivated, item 129's switch-over steps).

## 2026-10-29 — Release #599 live: ADR-131 (CHIP's activation conditions) and the failed-attempt work (#590–#598)

**Release.** `staging`→`main`, PR #599, merged as `a6ae7f4a` at 12:01 UTC (merge commit, `staging` kept). Before it: draft PR first; `origin/main..origin/staging` was 34 commits, then 36 after #600 (the receipt note fix); a trial merge was clean (the main-only commits are earlier release merges). CI (nine jobs) and `deploy` green.

**Read-only prod check before the release.** One custom domain, `fixfastapp.com` (FixFast Demo, active, not the primary affiliate): after the deploy it cannot pay (intended). No row for the primary affiliate (its hosts are config), so the migration's backfill touched none. `fpx` and `dnqr` on, `fpx_b2b1` off; 2 resellers, 3 paid top-ups.

**Verified on prod after the deploy (read-only).** `current` → release `81561008` at `a6ae7f4`; both `2026_10_29_*` migrations in `migrations`; `topup_max_sen` 1000000, `balance_cap_default_sen` 10000000, company details empty, no reseller cap set; `ResellerClosureService` and `StorefrontBrand::assertPaymentsEnabled()` present; `/api/health` 200; the four Vercel production builds succeeded.

**Found before sending CHIP the receipt.** The receipt note said "An invoice is issued for each order", which is false (the system issues no per-order invoice; e-Invoicing is outside our code, ADR-083 decision 6). Fixed in #600 before the release, with a test and an ADR-131 addendum.

**Also settled while drafting the reply to CHIP.** A reseller's order is paid from the Purchase Balance, never through a payment method; a failed order returns to that balance (its payment source), so the offer "tell us if you want it back to the original payment method" was dropped as wrong for the reseller model. The reply and the onboarding procedure PDF live outside the repo (it is public). The FixFast reseller is a test account whose login CHIP already has; FixFast Demo / `fixfastapp.com` is the test affiliate.

**Dev environment notes.** `lsof` and a `curl` port check were denied by the harness; Playwright against the founder's `./scripts/dev.sh` and read-only SSH worked. A second `dev.sh` fails with `EADDRINUSE` while the first runs; a Next config change (the `/wallet` redirect) needs the dev server restarted. The local storefront catalog answers 500 when the local `CACHE_STORE` does not support tags (backend/AGENTS.md gotcha 5), so storefront order pages cannot be viewed locally without a Redis cache.

**Owed.** PRD §16 items 138 and 140 (real FPX and DuitNow payments, a real cancel at a bank) and 141 (company details, deactivate `fixfastapp.com`, the browser checks, the reply to CHIP, approving `ohahastore.com` only after CHIP's written approval); new items 142 (receipt redesign and its follow-ups) and 143 (the portal footer hard-codes the company details).

## 2026-10-29 (evening) — Live checks after release #599

**What was seen on prod.** The company details are filled (read-only check: name, SSM and address set), and a receipt PDF for the FixFast test reseller's earlier RM10 top-up prints them. The founder cancelled one FPX and one DuitNow QR payment at the bank. Read-only on prod: the FPX order (RM2.07) was created 12:44:50 UTC and Failed 12:45:02; the DuitNow order (RM1.08, channel `dnqr`) was created 12:45:51 and Failed 12:46:11; neither has a voucher and none was left Pending, and the "Payment Not Completed / Try Again" page showed for both (item 140 closed). Also seen: the storefront notice on `fixfastapp.com` ("Payments are not available on this store yet"), the admin "Payments not approved" tag with the Approve button, the Purchase Balance portal and dashboard (no "wallet" word, the limit line, the headroom "you can add up to RM 99,996.56 now", the receipt link in Balance history, a failed order shown as refunded to the Purchase Balance).

**Defects seen on the failed-payment status page** (item 144, not blocking): "AMOUNT PAID RM2.07" on an order the page says was not charged; two badges at once ("Payment Failed" and "Waiting for payment"); the Method row prints raw codes (`fpx`, `duitnow_qr`).

**Not yet done.** Item 138 (a completed FPX and DuitNow payment; none has completed since #589), the affiliate portal's "awaiting approval" badge and the `/wallet` redirect on prod, step-up saves with an MFA admin, and approving a branded domain only after CHIP's written approval. `fixfastapp.com` stays active but unapproved (the FixFast Demo affiliate was not deactivated; the domain cannot take a payment either way).

**Later the same evening.** The affiliate portal's "Payments awaiting approval" badge on `fixfastapp.com` was seen (through admin impersonation). An Add Balance was started and cancelled at the bank: the attempt ended `failed` straight away (not after its 30 minutes) and the browser came back to `/balance`, so the wallet top-up path of item 140 works too. The legacy `/wallet` link redirecting has still not been hit on prod (a new return URL goes straight to `/balance`); it was checked locally (`308` to `/balance?topup=success`).

The legacy link was then checked on prod: `https://reseller.pekangame.space/wallet` answers `308` to `/balance`, and `/wallet?topup=success` to `/balance?topup=success` (query kept).


## 2026-10-30 — Failed-payment status page labels (item 144), smaller FPX tiles, "Other bank" link removed

**Item 144** (`OrderStatusTracker.tsx`): the amount box reads "Amount Due" until the order is paid; the delivery badge is hidden when the payment failed (it said "Waiting for Payment" next to "Payment Failed"); the Method row shows the channel label ("Online Banking (FPX)", "DuitNow QR") by reusing `CHANNEL_GROUPS`, now exported from `PaymentChannelPicker`.

**FPX grid** (founder: the logos were too big): storefront 6 columns on desktop and 4 on mobile (was 4 and 3), tile `h-10`, logo `max-h-5`. The founder also decided the 20 banks are enough, so the "Other bank" link is removed from the storefront grid and the partner portal grid (ADR-130 2026-10-30 addendum).

**Verified:** `tsc`/`lint` clean on the storefront and the portal. The grid was seen in the browser on `/order/arena-breakout` (desktop width, and 606 px, the smallest the window goes). The failed-payment status page was **not** seen: locally the browser's `track-order` preflight gets a 503 (curl gets 204), and the dev order is Pending, so check it on the PR's Vercel Preview with a real cancelled FPX order. Gotcha: after a `git checkout` the running storefront dev server kept serving the old classes until it was restarted.
## 2026-10-30 — Payment Receipt redesign (item 142, layout only)

The Purchase Balance receipt (`resources/views/receipts/purchase-balance.blade.php`) now follows a plain Stripe-style layout and carries the LWF logo (`backend/resources/img/lwf-logo.png`, black on transparent, embedded as a data URI). `ResellerWalletReceiptService` adds `bill_to` (business name, contact name, email). No money or ledger code changed. Decisions and what was left out (tax lines, card detail, address): ADR-131 2026-10-30 addendum.

**Verified:** `PurchaseBalancePortalTest` and `PurchaseBalanceAdminTest` 20/20 (new: the receipt bills the reseller and embeds the logo); the PDF was rendered and read page by page (title, logo, meta block, parties, headline, items, totals, note). Not rendered from a real prod top-up: do that after release with the FixFast test reseller.

## 2026-10-30 — Items 142 (rest) and 143 closed as not needed

Founder's decision after #602 and #603 merged to `staging`: the footer's hard-coded company details (143) stay as they are, and the three open parts of item 142 (admin download of a closed reseller's receipts, stored snapshot of the company details, automatic email) are not planned. Recorded in PRD §16 "Recently closed" and the ADR-131 2026-10-30 later addendum. Docs only; nothing built.

## 2026-10-30 — Affiliate monthly auto-payout (item 145, ADR-132)

The founder wanted the platform, not the affiliate, to open the withdrawal ticket: an affiliate could hold a balance for months and request any amount at any time. The money concept did not change (ledger debit still at admin approve, maker-checker unchanged).

**Design.** Grilled with the founder, then stress-tested by three read-only reviewers (creation and reserve; approve and accounting; portal, admin and hygiene). Their findings changed the design: the tier fee became a **reserve** held back from the payout (an early charge would shift billing cycles and push affiliates into `grace`); the amount is `min(balance at cut-off, current balance) − reserve`; the command runs **daily** at 00:10 MYT with a unique `payout_period` so a missed 1st repairs itself; only a **Pending** ticket blocks the next one; the "skip inactive affiliate" rule was dropped (in this codebase `inactive` only means soft-deleted, which needs a zero balance).

**Built (`feature/affiliate-auto-withdrawal`).** Migration `withdrawals.payout_period` + unique `(owner_type, owner_id, payout_period)`. `AffiliateWithdrawalService` is now `plan()` / `openMonthly()` / `openAll()` / `worklist()`; `AffiliateTierFeeService::feesDueBetween()` owns the cycle maths; `LedgerService::balance()` takes an optional `$before`. Command `app:open-affiliate-payouts` (`CRITICAL`, daily 00:10 MYT). Removed: `POST /affiliate/withdrawals`, `store()`, `CreateAffiliateWithdrawalRequest`, the portal form. Admin: `GET /withdrawals/payout-run` (skipped affiliates with a reason, stale Pending tickets), affiliate name and period on the index, a "needs attention" panel and a "this month only" toggle. Portal: schedule note, scheduled amount, tier-fee reserve, bank-details banner, copy changes.

**Gotcha found by a test:** a `Carbon` in Malaysia time bound into `where created_at < ?` uses its local clock, but `created_at` is UTC, so the cut-off was 8 hours off. `LedgerService::balance()` now converts to UTC itself.

**Verified.** Backend suite green apart from `RouteAccessTest` (new admin route, fixed); the concurrency suite on real MySQL: two simultaneous runs open exactly one ticket, and the six existing withdrawal concurrency tests pass. Portal and admin: `tsc`, `lint`, unit tests clean, admin `build` passes. Dev DB migrated (plain `migrate`). Production read-only before the build: 2 affiliates, none at RM10 or more, no open tickets. **Admin page seen in a local browser** (Playwright, throwaway sqlite DB, light, dark and 390 px mobile, no page overflow): the needs-attention panel, the Affiliate/Period columns and the this-month toggle work. That check showed the stale-ticket line had no affiliate name; fixed (the payload now carries it). Approve from the page was not exercised: it needs MFA step-up, which the throwaway admin does not have (backend tests cover it). **Portal seen in a local browser too** (same setup, two affiliates; light, dark, 390 px, no console errors, no page overflow): the Withdrawal page shows the schedule note, the scheduled RM 220.00 ticket, RM 30.00 kept for the tier fee with its due date, the bank, and the bank-details banner for an affiliate without bank (the warning text colours were read with `getComputedStyle`); Dashboard and Earnings show "Earnings balance" and "Monthly payout" and no request button.

**Accepted risk.** A tier change to a higher fee after the ticket opens, "Charge Now", or `reactivate()` can push the balance under the ticket; approve then returns a clear 422 and the admin rejects (the money joins next month's payout).

## 2026-10-30 — Storefront: Bumblebee header colour; package list browsable before the ID (founder, Ohaha store)

Two founder findings from the live `ohahastore.com` on a phone. **Header:** the header and the page were the same cream (`bg-surface` on both), so the nav bar had no edge; Cyber Bumblebee now has a yellow header with an ink "Track Order" button, through five new `--color-header*` tokens whose defaults leave every other preset unchanged (ADR-090 addendum). **Package list:** Step 2 was `inert` until the ID was verified, so "Show all N packages" could not be pressed; it is now browsable, a tapped package is kept, and on a phone the page scrolls up to Step 1 (ADR-064 addendum). Step 3 now needs both the ID and a package.

**Verified.** `tsc` and `lint` clean; reseller tests pass (its `theme-presets.ts` copy regenerated with `sync-shared-files.mjs`). Seen in a headless browser on a throwaway sqlite backend with the e2e data and 17 packages: mobile header yellow `rgb(255,199,0)` against the page `rgb(255,248,225)`, button ink with yellow text; Step 2 not inert and not dimmed; "Show all" works while locked (17 packages); tapping a package scrolled from y=1014 to y=408 with the ID field in view, the package stayed selected, Step 3 stayed locked; entering the ID and Continue unlocked Step 3 and scrolled to it; on a 1280 px desktop tapping a package scrolled 0 px and the active nav link is ink with an ink underline. **Not seen:** the dark mode of Bumblebee (read from `generateThemeCss`, which applies only `tokensDark` in dark mode, so the header falls back to the dark surface), and the Joki and membership order pages (untouched). The e2e checkout spec (`storefront-checkout.spec.ts`) has not been run on this branch.

**Not in this change:** the `www.ohahastore.com` certificate error (the affiliate adds `www.` as a second domain in the portal for now; the automatic `www` pairing is a separate item that needs its own ADR addendum to ADR-060).

## 2026-10-30 — Fix: approving a domain's payments failed with "The approved field is required"

Found by the founder approving `ohahastore.com` in the admin. `approveAffiliateDomainPayments()` (ADR-131 PR C, released in #599) passed `body: JSON.stringify({ approved })` to `apiFetch`, which already JSON-encodes its body, so the server received a JSON string, not an object, and Laravel saw no `approved` field. The backend and its tests were right (they send a plain object); no frontend test sent the real request, so it shipped. It was the only `apiFetch` caller doing this (the other `JSON.stringify` bodies in the apps call `fetch` directly).

**Fix:** `body: { approved }`, plus `admin/src/lib/api-body.test.ts`, which fails if any admin file that uses `apiFetch` passes a `JSON.stringify`'d body (red on the old line, green on the fix). **Verified** in a browser on a throwaway backend: the PUT now carries `{"approved":true}` as `application/json`; the throwaway admin has no MFA so the step-up answered 403 there, which the real admin does not hit. Admin tests 20/20, `lint` and `tsc` clean.

**Also in this PR:** CI's `backend-tests` failed once on `CustomerAnalyticsListingTest::test_search_matches_part_of_the_email_ignoring_case_and_wildcards`, unrelated to the change: every order in that class gets `paid_at = now()` less some days, so a second boundary between two orders reversed the tied `last_order_at` sort (the same flake class as 2026-10-16, which had frozen time in one test only). `setUp()` now freezes time for the whole class. Proved by adding `sleep(1)` to the order helper: red without the freeze, green with it.

The next CI run failed `backend-concurrency` on `CheckoutSettleWithVoucherConcurrencyTest` (two simultaneous full-cover checkouts). The money was safe (the voucher was spent once and the second checkout refused), but the loser was refused earlier, with `InvalidVoucherException`, because a slow CI start put its `initiate()` after the winner had committed, and the test treats any `error:` as a failure. `app:checkout-test-initiate-full-cover` now takes an optional `startAt`: both processes boot, then wait for the same instant before `initiate()`, so they overlap. Green 3 of 3 on local MySQL; CI to confirm.

**Owed:** the fix is on `staging`, not live: the admin Vercel app deploys from `main`, so the approval works in production only after a `staging` → `main` release. The founder will approve `ohahastore.com` after that, once CHIP has approved it in writing (ADR-131 condition 5).

## 2026-10-30 — Releases #608 and #610, the header mix-up, and the owned-brand facts

**Releases.** #608 (`staging`→`main`, merged 07:31 UTC as `8f3e9ea8`) shipped #601–#607: the affiliate monthly auto-payout (ADR-132), the receipt redesign and failed-payment labels, packages browsable before the ID, the domain payments-approval fix, and two flaky-test fixes. #610 (merged 08:44 UTC as `9140ba8c`) shipped #609: the original header restored and the bottom nav labels fixed. Both deploys were green; read-only checks on prod afterwards are in PRD §14 ("Latest release 2026-10-30").

**Corrections to earlier entries of today (this log is append-only).**
- "Storefront: Bumblebee header colour; package list browsable…" — the header half was a **mistake**. The founder's report was about the mobile **bottom nav**; I read "nav bar" as the header and made it yellow. #609 removes it. What actually shipped for the nav is the label colour fix (ADR-090 addendum, later). Lesson: when a UI term can mean two elements, confirm which one on the screenshot before changing anything.
- "Fix: approving a domain's payments failed…" — said "Owed: not live". It was released in #608.
- "Affiliate monthly auto-payout" — said "Not seen in a browser" then "seen" in the same entry; both are now seen. `MoneyFlows::affiliateWithdraws` (ADR-127's seeder) used the removed `request()`; 27 accounting tests failed until it wrote the ticket directly. Lesson: grep the **whole repo** (including `database/seeders`) for a method's callers before removing it; the first grep covered `app`, `tests` and `routes` only.

**Bottom nav.** Labels were `text-on-primary` on `bg-ink`; Cyber Bumblebee's `on-primary` is ink, so ink on ink. Now `text-surface` / `text-surface/60`: 16.18:1 and 6.52:1 on Bumblebee light, measured on a local storefront (alpha composited; the first measurement used a colour parser that misread `oklab()` and was wrong). The other presets and Bumblebee dark were not measured: the theme switch in my throwaway harness did not take effect (the storefront and the API both cache branding), so only the first state was valid. The founder confirmed `ohahastore.com` on a phone after the release.

**CI.** Two flakes blocked #607 and are fixed there. `CustomerAnalyticsListingTest`: every order got `paid_at = now()` less some days, so a second boundary reversed a tied sort; `setUp()` freezes time (proved with a `sleep(1)`). `CheckoutSettleWithVoucherConcurrencyTest`: on a slow start the second process began after the winner committed and was refused earlier (`InvalidVoucherException`), which the test counts as an error; the money was safe (one voucher spend, one refused checkout). `app:checkout-test-initiate-full-cover` takes an optional `startAt` so both processes overlap. Local MySQL: 3 of 3 green.

**OhahaStore (`ohahastore.com`).** The apex had a fresh certificate; `www` (not registered here) served an expired `*.ohahastore.com` wildcard from February 2026, so a PC showed `ERR_CERT_DATE_INVALID`. Adding `www` as a second domain stayed `pending`: Vercel wants one `_vercel` TXT per hostname. ADR-060 addendum and PRD items 147 and 148 hold the details. Also found in prod: OhahaStore has no bank details.

**Owned-brand numbers (read from code and prod, for the next session; PRD item 149).** `is_owned` changes reporting, membership and Joki gating only. Prod: PekanGame (owned, primary, markup 0%, no tier); OhahaStore (owned, markup 0%, max 20%, membership on, active "Owned Brand" tier: fee RM0, markup 15%); FixFast Demo (not owned, markup 5%, max 25%, the same tier). Price = cost × 1.15 × (1 + brand markup %) for a tier brand; the standard price for a brand without one; with 1,249 of 1,257 active packages at exactly 15% markup the two agree today at markup 0%. The member price is cost × (1 + package markup × (1 − discount)) with Tier 1 = 50% and Tier 2 = 70%, so it ignores the brand's tier and markup. The screenshot figures RM1.13 and member RM0.97 (−14%) fit a ~5% brand markup at the time (cost 94 sen: 108 + 5 = 113), and OhahaStore's row was updated 14:19 MYT, after the screenshot (14:06): probably the markup went back to 0%. Not proven.

## 2026-10-30 — An owned brand prices as the platform: no tier, no brand markup (ADR-061 addendum, PRD item 149)

**Why.** An owned brand (OhahaStore) ran the third-party affiliate pricing path (wholesale tier plus its own markup) and matched PekanGame only because the tier's 15% equals the packages' 15% and its markup was 0. They already disagreed by 1 to 2 sen on 29 active combos (whose standard price is summed per leg). Raising an owned brand's markup would have put `affiliate_profit` into its own ledger account, and the monthly auto-payout (ADR-132) would have paid it to the brand's bank. The founder and I grilled it (two rounds, then a stress test), traced it against code and read-only production data, and recorded it as an addendum to ADR-061 (Accepted).

**What shipped (branch `fix/owned-brand-pricing-seam`, one PR into `staging`, not released).**
- `Affiliate::storefrontPriceTerms()` returns (tier, markup): `(null, 0)` for an owned brand, otherwise the tier and `min(markup, max_markup_pct)` (null ceiling = none). Checkout, checkout preview, the catalog, voucher preview and the portal's pricing preview read it; so do the primary's three raw reads (member-savings badge, plan preview, sandbox order).
- Guards at both ends: the read-time seam ignores a stored markup or tier on an owned brand; the admin form forces markup 0 (one `affiliate_markup_changes` row, source `admin`, written for every admin markup change), refuses a tier, hides the fields; the portal refuses markup writes with a 422.
- `Affiliate::tierSubscription()` (null for owned) feeds the portal Subscription screen and dashboard; the dashboard gains `is_owned`. "Charge fee now", "Reactivate" and the tier-fee cron skip owned brands. The catalog cache flushes when `is_owned` or `max_markup_pct` changes.
- Tests: `Adr061OwnedBrandPricingTest` (seam parity matrix for a third-party brand and the primary; owned brand priced as standard on checkout, preview and catalog, including a 1-sen-off combo; member price never above the guest price; ceiling clamp and null ceiling), plus additions to the admin affiliate, portal pricing, portal read and tier-fee command tests. Two older tests that set a non-zero markup on the primary (an owned brand) were rewritten to prove the new rule. Backend 3,251 passed (after the review fixes; the concurrency suite was not run, no locking changed); admin and portal `tsc` and `eslint` clean; `node --test` 20 and 9 passed.

**Review.** Three read-only forks (checkout and pricing; ledger, fulfilment and payout; downstream readers) found seven real gaps, all fixed with tests: the ceiling's cache flush, the portal's null-ceiling contradiction ("ceiling is now 0%"), the primary's raw reads, "Charge fee now" and "Reactivate" on an owned brand, the admin detail still showing a tier, the admin form wiping an owned brand's ceiling on save, and the dashboard's tier copy. The tier-fee cron's first version used `whereHas`, which silently excluded soft-deleted affiliates; it is now a `whereNotIn` over `withTrashed()` so non-owned brands bill exactly as before.

**Verified in a browser** (throwaway backend, the admin and portal dev servers, an owned and a third-party brand each). Admin: the list shows the owned brand as "walk-in rate"; its detail shows "Markup %: None (owned brand)" and a Pricing note with no tier controls; its edit form has no markup, ceiling or tier fields. Portal: the owned brand's dashboard has no wholesale-tier card, its Subscription screen reads "You are not on a wholesale tier — your storefront prices at the standard guest rate", and its Pricing tab shows the no-markup notice; the third-party brand shows the tier card, its plan and the markup form as before. **The e2e suite** passed 9 of 9 locally after the founder said to stop the stale servers from the first session (which held :3001 and :8000, a throwaway sqlite that no longer existed); CI's `playwright` job was green too.

**Production facts recorded.** The "Owned Brand" tier is 15% (it appears to have been edited from 10% on 2026-10-30, two minutes after OhahaStore's subscription); member Tier 2 is 70% off the markup. ADR-125 and ADR-126 carry dated pointers to the right numbers. OhahaStore has 0 orders; no data migration was needed.

**Owed.** Not live until a `staging` → `main` release. After it: the RM0 OhahaStore tier subscription can stay (it prices nothing); rename or remove the "Owned Brand" tier after FixFast Demo is deactivated (item 141).

## 2026-10-30 — A custom domain brings a `www` redirect twin (ADR-060 second addendum, PRD item 148)

**Why.** The first `www` report (OhahaStore) had been fixed by hand: `www.ohahastore.com` was added as a second full domain and approved separately, so the brand had two identical addresses and two CHIP approvals. The founder asked to automate it. Grilled with the founder (five questions, all accepted), then stress-tested against the lifecycle code and Vercel's documentation.

**Facts checked.** Vercel's add-domain endpoint takes `redirect` and `redirectStatusCode` (301, 302, 307 or 308), and `PATCH /v9/projects/{project}/domains/{domain}` takes the same, so an existing domain can be converted without being removed. Vercel asks for an ownership TXT only when the domain is in use by another Vercel account or team, and a removed and re-added domain gets a new value: the earlier addendum's "one TXT per hostname" over-generalised OhahaStore's case and is withdrawn (the portal guide already said so). On production, both OhahaStore hostnames were `active` with valid certificates and approved payments, each serving the store (200).

**Stress-test findings that shaped the design (all fixed in the build, not patched later).** Every reader of `affiliate_domains` assumed a row is servable: the first domain to go active became primary, so a twin verifying first would have become the canonical origin; `canonicalOrigin()` and the CORS origins would have listed it; `ResolveStorefrontBrand` would have resolved it; the cap would have counted it; the CHIP approval control would have offered it; the sync command would have emailed reminders for it. Converting must use PATCH because a re-added domain gets a new ownership value. Removal must detach the twin before its target.

**What shipped (branch `feature/affiliate-www-pairing`, not released).** Migration `2026_10_30_180000_add_redirects_to_domain_id_to_affiliate_domains`; `AffiliateDomain::servable()` used by every reader; the provider's `attach($hostname, $redirectTo)` and `redirect()`; `AffiliateDomainService::pairWww()` (create, re-attach, convert in place, idempotent); portal `with_www` option and `POST /api/affiliate/domains/{domain}/www`; the Domains screen and guide (English and Malay); admin approval refused for a twin and hidden in the detail modal.

**Tests.** `AffiliateDomainWwwTwinTest` (16: creation with the redirect target, idempotence, refusals, provider failure leaving no twin, never primary, no failover to a twin, set-primary refused, not canonical, not a CORS origin, not resolvable, cap, removal order, delete order, stuck-pending teardown, conversion in place, no reminders), plus additions to the Vercel provider, portal domain controller and payment-gate tests. Backend 3,278 passed; admin and portal `tsc`, `eslint` and `node --test` clean; no API-spec drift (`scramble:export` against a migrated database); the migration on real MySQL 8.4 shows one explicit index (reused by the foreign key, no duplicate) and `ON DELETE CASCADE`. **In a browser** on a throwaway backend: the option ticked by default and disabled for a `www.` name, a domain added with its twin ("Redirects to …"), "Add www" on one added without, "Redirect www here" converting a hand-added `www.` row, the counter at 3 of 5 with three twins, and the admin detail with no approval control on a twin.

**Owed.** Not live until a `staging` → `main` release. Then the founder presses "Redirect www here" on OhahaStore's Domains screen (the only path: no console command, no hand-run patch), and `curl -I https://www.ohahastore.com` should answer 308 to `ohahastore.com`; the `www` row's CHIP approval stops mattering.

## 2026-10-30 — A delivery receipt that never came (PG-L4RCBELJ9RE3), the phone normaliser, and item 138 closed

**Report.** The founder asked why a customer who used the Contact Support button (a failed order), got the status card, and then had the item resent and delivered, received no delivery message.

**Cause (read-only on prod).** The order's `customer_phone` had ten digits starting with `1`: a Malaysian mobile typed without its leading `0`. WhatsApp wrote from the twelve-digit `60…` number, so the silent opt-in (12:29:06 UTC) was stored under the `60…` number while `orderDelivered()` normalised the order's number to the unchanged `1…` ten digits, found no opt-in and returned without a row (by design). `PhoneNumber::normalize()` took anything not starting `0` or `00` to already carry its country code. 1 of 42 real storefront orders had this shape. The same normaliser feeds joki updates, voucher messages and the voucher redemption phone match.

**Fix (branch `fix/phone-normalize-malaysian-mobile-without-zero`).** A 9 or 10 digit number starting with `1` becomes `60…` (country code 1 numbers always have 11 digits). Tests: `PhoneNumberTest` (the new shapes, North American numbers kept, every way of typing one mobile is the same number) and an end-to-end case in `CustomerWhatsAppOptInTest` that reproduces the order (number typed without the zero, opt-in from the full number, receipt after a Mark Delivered). Recorded as ADR-116's 2026-10-30 addendum. The customer's missed receipt is not recoverable by code; the founder was advised to tell them by hand (not confirmed as done).

**Item 138 closed.** The same order was a completed DuitNow QR payment (RM25.70; CHIP `paid`, `is_test` false, method `dnqr`, one successful attempt), and an earlier order was a completed FPX payment through the bank grid (Maybank2u). With the **Test** results, every part of item 138 is proven. The CHIP responses carried the buyers' names and emails; none of it is recorded here.

## 2026-10-30 — Membership OTP emails landing in spam: diagnosis, Plunk and DNS changes, and a subject/copy change

**Report.** The founder said the membership code email goes to the spam folder, and shared one such message (Gmail "Show original").

**Diagnosis (read-only).** Authentication was fine: SPF PASS, DKIM PASS with `d=pekangame.space` (aligned), DMARC PASS (the zone has `p=quarantine`). The causes were in the message and the sender: (1) Plunk's project tracking was **Enabled**, so every link went through the shared `track.useplunk.dev` redirect, a 1x1 open pixel was added, and the free plan's "Powered by Plunk" badge image and link; (2) the sender domain `pekangame.space` differs from the domain every link points to (`pekangame.com`, the real storefront, which `.space` redirects to), and `.space` is a low-reputation TLD; (3) `Reply-To` was `noreply@pekangame.space`, a domain with no MX; (4) a generic subject.

**Changes made by me in the founder's logged-in browser, with the founder's go-ahead (before to after).**
- **Plunk, Settings, General:** Email tracking `Enabled` to `Marketing only` (transactional sends are no longer tracked; campaigns still are). Reversible in the same place.
- **Plunk, Settings, Domains:** added `pekangame.com` (it was not there; only `pekangame.space` was). Optional Custom MAIL FROM and inbound were skipped on purpose.
- **Cloudflare, `pekangame.com`, DNS:** 9 records to 12. Added three CNAMEs, **DNS only**, for Amazon SES DKIM: `dhzrtflz5z4zybf2nrwmdcjezlamtaqj`, `zba5w2gyjitwlfsifm6pjt2iuldtmg5z` and `jtlpl5rv4oaxkl4rumi6z6nxe4rrrgg3`, each `._domainkey` to `<same>.dkim.amazonses.com`. **The SPF record was not touched** (it also serves the Zoho mailbox; DMARC only needs the aligned DKIM), and the Zoho MX, SPF, verification and DKIM records were read back unchanged afterwards. Plunk then reported "Domain pekangame.com is verified!".
- **A slip, disclosed.** One Save click missed (the form grows after the target is typed), and the follow-up clicks landed on the Cloudflare dashboard and opened the `fixfastapp.com` overview. Nothing there was edited (no form was open); the `pekangame.com` record count was checked (10, only the intended one) before continuing, and each remaining record was zoom-checked before Save.
- **Not done by me:** buying the Plunk plan that removes the badge (a purchase; the founder says they will subscribe), and the production `PLUNK_FROM_EMAIL` change (handed to the founder as exact commands, below).

**Code (branch `fix/membership-otp-email-deliverability`).** The OTP email's subject is now `{code} is your {brand} verification code` (the form Gmail trusts for one-time codes), with a test that was red first, and the storefront's code screen says to check Spam or Junk and mark the email Not spam. No Reply-To change: I could not confirm Plunk's field for it.

**Owed.** The sender switch is not made: set `PLUNK_FROM_EMAIL=noreply@pekangame.com` on the Forge box (blast radius: every system email, which all go through Plunk), `php artisan config:cache`, `php artisan horizon:terminate`, then request one OTP and read the Gmail headers. Optionally a Zoho alias for `noreply@pekangame.com` so replies do not bounce. The code change ships with the next release.


## 2026-10-30 — Local test runs sent real emails through production Plunk (found while checking the sender switch)

**Found.** After the founder switched `PLUNK_FROM_EMAIL` on production (checked read-only: `noreply@pekangame.com`, the config cache applied, Horizon restarted 30 minutes earlier, no `Plunk send failed` in the log), I opened Plunk's Activity to look for a send from the new sender. It listed batches of emails to addresses like `member@example.com`, `waived@example.com`, `race-sub@example.com` and `dereck33@example.net` ("Your Tier 2 membership — payment received", "[PekanGame Security] Account locked"), at times that match local test runs. Plunk's Analytics for 1–30 October: **513 sent, delivery rate 5.8%**, open rate 3.1%, bounce rate 0.19%, so most of what the project sent was test mail, and only a few dozen messages were real.

**Cause.** `backend/.env` holds real keys for local work: Plunk, CHIP, Gamevion (key and bearer), Gemini and the supplier proxy (checked as set or not set only; no value was read). `phpunit.xml` and `phpunit.concurrency.xml` did not blank them, and the e2e boot script did not either, while CI has none of them. A test that did not fake an outbound HTTP call therefore called the real service. Running the suite with the keys forced blank and `Http::preventStrayRequests()` showed four leaks: the admin lockout test (it sends the "Account locked" alert through Plunk, the source of those emails) and three Digiflazz tests that fetched the IDR to MYR rate live from `open.er-api.com` (they depended on the public internet, so they could also fail in CI for no reason of ours). The "payment received" and "race-*" mails came from membership and concurrency tests, which the blank key now also stops. This was not new: the earlier 30 days carry the same pattern. My own full-suite, concurrency and e2e runs today added to it (about six full runs), which I did not know until now.

**Effect on the OTP spam report.** Probably small: Gmail never saw these messages, so they do not enter the sender reputation it judges. They do pollute the project's statistics (the 5.8% delivery rate), they added example.com recipients to Plunk's contacts (127 contacts today), and sending to invalid addresses at volume is a risk to the sending account's standing. The causes found earlier (tracking, domain mismatch, `.space`, low volume) stand.

**Fix (branch `fix/tests-never-use-live-credentials`).** Both phpunit configs force `PLUNK_API_KEY`, `CHIP_SECRET_KEY`, `CHIP_BRAND_ID`, `GAMEVION_API_KEY`, `GAMEVION_BEARER_TOKEN`, `GEMINI_API_KEY` and `SUPPLIER_PROXY_URL` blank (CI's state); `Tests\TestCase` calls `Http::preventStrayRequests()`; the four leaking tests now fake their calls (the lockout test also asserts the alert was sent); the e2e boot script blanks the same keys; `backend/AGENTS.md` records the rule. Backend fast suite 3,286 passed; e2e 9 of 9 after the change.

**Not done / owed.** Mail already sent cannot be recalled. The founder may want to delete the example.com contacts in Plunk (Contacts) so the project's numbers reflect real customers. Nothing here changes production code.

**Also fixed here: a concurrency flake that failed PR #616's CI** (a docs-only PR). `ResellerClosureConcurrencyTest::test_two_simultaneous_closures_refund_once` got outcomes `["", "closed:150000"]`: its helper command looked the reseller up with `findOrFail` at start, and when the other racer had already soft-deleted it (two booting processes on a loaded runner), the helper threw `ModelNotFoundException` and wrote nothing instead of a refusal. Money was never at risk (exactly one closure and one refund). The helper now treats an account that is already closed as a refusal (`refused:`), which is what a later attempt always was. The fix and the credentials change are in the same PR because both are test hygiene.


## 2026-10-31 — Partner onboarding designed (ADR-133, PRD §16 items 13 and 146); nothing built

**Request.** The founder asked for a docs and backlog audit, then to grill item 146 with the reference video (a UX-pattern video for a budget app: one question, a checklist that starts at 1 of 3, one-button empty states, one coach mark, a skip that remembers). Transcribed locally (the audio only repeats the on-screen text). It is a style reference, not a spec.

**What the grill settled (four rounds, then a code stress test).** The founder widened the scope: a public reseller application with KYC (documents only **after** a first screening, so no identity document is held for applicants who will not be accepted), plus onboarding inside the portal. All decisions are in [ADR-133](./adr/ADR-133-partner-onboarding.md). Item 13 (self-serve reseller signup) is folded into item 146.

**Facts found read-only (code, 2026-10-31).** No onboarding, first-run or readiness code exists. The dashboard's "Live" badge ignores `payments_approved_at`. Bank details are never forced and the admin cannot edit them; the "bank present" check is already copied in two places. A reseller with no tier cannot order but can still add balance; creating a reseller makes no login. The admin sandbox (ADR-018) is alive (21 tests pass) but admin-only; reseller paths never mention `is_test`. The Spatie backup is a SQL dump only, so documents would never be in it and deleted rows linger in older dumps.

**Stress test (seven findings, all folded into the ADR).** One `Affiliate::hasBankDetails()` instead of a third copy; the tier guard goes in `ResellerWalletTopupService::initiate()` and never in `completeTopup()`; one `ResellerProvisioningService` for the application approval and the admin create; retention wording admits dump retention; host split in `proxy.ts`; an email of a closed account stays reserved; a single `PartnerApplicationService` with a row lock and a unique `reseller_id`.

**PDPA research.** Notices must be Malay and English (section 7). The 2024 amendment adds breach notice, DPO thresholds (far above our size) and a new cross-border regime; the figures come from secondary sources and are marked to re-check. No NRIC guidance was found. The notice text is to be written with a lawyer.

**Backlog.** PRD §16: item 13 points to 146; 146 carries the build order and the owed list; new group D items 151 (partner sandbox, its own money-critical ADR) and 152 (refresh the admin sandbox). **Not touched:** PRD §14 still says the OhahaStore `www` row "becomes a redirect after the next release"; item 148 is built and released, so that sentence needs a docs-audit fix.

## 2026-10-31 — Month-end CI failures: four tests built "last month" with `now()->subMonth()`

**Found.** PR #619 (docs only) failed `backend-tests` (3 tests) and `backend-concurrency` (1), all around the affiliate payout and customer analytics. CI ran at 18:54 UTC on 2026-10-30, which is 02:54 on 31 October in Kuala Lumpur.

**Cause (tests, not production).** Two date traps in the test setup: `now()->subMonth()` on the 31st overflows ("31 Sep" is 1 Oct) into the current month, and a UTC "last month" on the last evening of a month is already the current month in Kuala Lumpur, where the payout and the reports cut the month. The payout command runs on the 1st at 00:10 MYT, which never overflows, so production was not affected. `CustomerAnalyticsServiceTest` had been fixed once for the UTC side (2026-09-30/10-01) but still used `subMonths(1)` in Malaysia time.

**Fix (branch `fix/date-dependent-tests-month-end`).** One shared `TestCase::midLastMonth()` (the middle of last month, Malaysia time, safe on every day) used by the four affiliate tests; `subMonthsNoOverflow(1)` in the analytics test. Of the 14 `subMonth`/`addMonth` uses in tests, the other 7 only mean "in the past" or "in the future" and are harmless on any day. Red first: on this machine's clock (31 Oct, 09:28 MYT) the old code failed 2 of 30; the new code passes 30 of 30, and the full fast suite passes (3286). The concurrency suite needs Docker MySQL, so CI is its proof.

## 2026-10-31 — Partner onboarding PR 1a: the backend foundation (ADR-133)

**Built (no UI, no migration, no behaviour change for any user).**
- `Affiliate::hasBankDetails()` replaces two hand-written copies (the payout plan's `no_bank` skip and the portal's `has_bank_details`); the readiness service is the third reader.
- `AffiliateDomain::paymentsApproved()` replaces three `payments_approved_at !== null` reads (the storefront resolver, the admin domain list, the portal domain list). `Order::scopePaidAndReal()` replaces the paid + `is_test = false` pair in the affiliate sales figures.
- `ResellerProvisioningService` (`create()`, `addPortalUser()`): `Admin\ResellerController` `store()` and `storeUser()` call it; same behaviour, one copy.
- `PartnerReadinessService` (`forAffiliate(s)`, `forReseller(s)`), `PartnerReadiness`, `ReadinessStep`, `StepState`. Read-only, a fixed number of queries per batch (tested with 2 and 9 partners).

**Verified.** Red first (24 errors, the classes did not exist). The invariant test asks the real storefront (the public branding endpoint, ADR-131's gate) across seven domain states and both account states; a deliberate mutation (readiness ignoring the payments approval) made it fail, then it was restored. Full fast suite 3312/3312. Pint passes on the changed files (it fails on an untouched seeder, `database/seeders/FinancialScenario/MoneyFlows.php`, and CI does not run it).

**A test trap met again.** Sanctum keeps the first authenticated user for the rest of one test, so two portal requests as two users in one test both ran as the first: one affiliate per test.

**Next.** PR 1b: the endpoints that expose this, the portal checklist, banners and empty states, the admin readiness column, and the approval email.

## 2026-10-31 — Partner onboarding PR 1b: checklist, banners, empty states, admin Setup column, approval email (ADR-133)

**Built.**
- **Backend:** `GET /api/affiliate/onboarding` and `GET /api/reseller-portal/onboarding` (each behind its own account-type gate); a `readiness` summary on every row of the admin Affiliates and Resellers lists (one batch, no query per row; the platform's own brand has none); `PaymentsApprovedNotice`, an email to the affiliate when an admin approves a domain for payments (only on the move to approved; a mail failure never undoes it). The `order_channel` step now says which channels are set up (`api_key`, `whatsapp_group`).
- **Portal (`reseller/`):** a collapsible "Get started" checklist (collapsed by the partner, remembered in `localStorage`, or by itself when every required step is done); non-dismissable banners for what costs money (no bank details, payments off, a reseller with no tier); the store badge split into "Ready to sell" and "Online, payments off"; one-button empty states for Orders; the WhatsApp bot's link state and instructions; a "Your first order" card (there is no partner sandbox); the DNS teardown deadline; the set-password page in English.
- **Admin:** a "Setup" column on the Affiliates and Resellers lists.

**Verified.** Backend tests red first, then green; full fast suite 3324/3324. Portal and admin lint, `node --test` (18 and 20) and build pass. A browser check on a throwaway backend (sqlite file in the scratchpad, API on :8000, portal :3002, admin :3000; all stopped and deleted afterwards): 44 checks across every state, mobile 390 px, contrast at least 4.5 in light and dark, and screenshots read by eye. The browser check found one thing the tests did not (an Optional step with a destination showed no link); fixed.

**Traps met.**
- I ran Pint over the whole of `app/` and it reformatted 25 untouched files (the repo has existing Pint failures; CI does not run it). Reverted with `git checkout` on every file that was not mine. Run Pint only on the files you changed.
- A "queries do not grow with the rows" test must not compare equal counts: the first measured request pays one-off queries (settings cache). Warm it, then assert the larger set is not more than the smaller.
- A set-password field with `minLength=8` stops the browser before the JS message appears, so a check of that message needs a long enough, mismatched pair.
- Sanctum keeps the first authenticated user for the rest of a test (met again in PR 1a).

**Next.** PR 2 (the tier-less top-up guard in `ResellerWalletTopupService::initiate()`, never `completeTopup()`; the admin create made to use `ResellerProvisioningService` with the tier required; wallet trace first), then PR 3 and PR 4.

## 2026-10-31 — Partner onboarding PR 2: the tier-less top-up guard, the admin create with a login, Create internal account (ADR-133)

**Built (branch `feature/partner-onboarding-pr2-topup-guard-provisioning`).**
- **Top-up guard (decision 20):** `ResellerWalletTopupService::initiate()` refuses a reseller with no tier before any CHIP call; the portal answers 422, the bot replies in Malay (`topup_no_tier_assigned`). `completeTopup()` is untouched. The portal's no-tier banner and checklist step now say adding balance is blocked too.
- **`ResellerProvisioningService::provision()` (decision 21):** reseller, wallet account, first portal login and invite in one transaction, tier required in the service. The admin "Add Reseller" requires an active tier, the contact name and an email no portal user has.
- **Create internal account (decision 12(c)):** `POST /api/resellers/internal`, super_admin + MFA step-up, a required reason in `resellers.internal_reason` (new migration) and the log. The list shows "Internal · No KYC on file" or "KYC outside the system".

**Trace before code.** Callers of `initiate()`: the portal, `.topupbaki`, the `FinancialScenario` seeder (its reseller has a tier) and the race-test command. Credit paths: the CHIP webhook, the 15-minute reconcile, the race-test complete command, the admin manual credit; none reads the tier. A tier can only be swapped, never cleared. Production (read-only, 2026-10-31): 3 resellers, all with a tier and a login, no pending top-up.

**Verified.** Red first (top-up: 3 failing; provisioning and admin: 11 failing), then green. Full fast suite 3335/3335. The concurrency suite on local MySQL: 38 of 42, every wallet, top-up and order race passing; the 4 failures are `BackupRestoreMysqlTest`, which needs a `mysqldump` binary this machine lacks (CI has it). Admin `tsc`, lint, 20 unit tests; portal `tsc`, 18 unit tests. Three read-only review forks (wallet and ledger; provisioning and auth; admin, portal and bot readers): no money finding; their three small findings were fixed (an inactive tier refused on create, the race-test command catching the new refusal, the portal copy). Browser check on a throwaway backend with Plunk pointed at a local fake (no real email): the submit stays disabled until a tier is chosen, the inactive tier is not offered, create sends the invite and the row shows "KYC outside the system", a taken email shows "email has already been taken", the internal form sends the reason and a no-MFA admin gets the step-up refusal, the Internal tag and the KYC line have contrast 5.3 or more in light and dark. Everything stopped and deleted afterwards.

**Trap.** The local `.env` has a live Plunk key, so a browser check that creates a portal login sends a real email unless `PLUNK_BASE_URL` points at a fake.

**Not done.** FixFast is not marked internal in production (a data write, the founder's call). Next: PR 3 (the public application, KYC, retention), then PR 4 (`max_markup_pct`).


## 2026-10-31 — Game ID Checker: OhahaStore's checker stuck, and a message when Turnstile fails (ADR-123)

**Found.** `https://ohahastore.com/check-id/mobile-legends` stayed on "Verifying you're human…" with Check ID disabled; the browser console showed Turnstile error `110200` (hostname not allowed). The widget "PekanGame Game ID Checker" lists only `fixfastapp.com`, `pekangame.com`, `pekangame.space`. ADR-123 already says every new affiliate domain must be added to the widget; it was missed when OhahaStore went live.

**Fix (two parts).** (1) Add `ohahastore.com` to the widget's hostnames in Cloudflare (a founder action; staged in the dashboard but not saved by Claude). (2) Code: `PlayerCheckClient` now shows "We couldn't verify you on this page. Please reload, or try again later." when Turnstile calls its error callback, instead of waiting forever; a later success clears it.

**Verified.** Local storefront against a throwaway backend with one checker family, using Cloudflare's test site keys: the always-failing key shows the message with the button disabled (390 px); the always-passing key shows "Ready to check." and no message. Everything stopped and deleted afterwards.

## 2026-10-31 — CHIP approved FPX and DuitNow QR (docs only)

Recorded from the founder: the reply to CHIP (PRD §16 item 141(d)) was sent on 2026-10-29. CHIP answered that it is checking the documents, and that FPX and DuitNow QR may be used meanwhile; its other payment methods are still under review. The email is the founder's record outside the repo. PRD §14 (payment methods: FPX no longer "on only while CHIP reviews"; the stale "`www` becomes a redirect after the next release" line), §16 items 141, 114 (the CHIP gate) and 129 (the founder's choice between voucher-only and refund to the original payment method is now due). Still open in ADR-133: whether CHIP receives copies of reseller documents.

## 2026-10-31 — ADR-133 PR 4: a blank markup ceiling opens no markup (ADR-061 addendum)

**What.** `max_markup_pct` null had two meanings: no ceiling in the admin form and in `Affiliate::storefrontPriceTerms()`, a ceiling of 0 in the portal's markup save and Pricing tab. The founder chose fail-closed (ADR-061 2026-10-31 addendum): null is a ceiling of 0 everywhere. `storefrontPriceTerms()` clamps to `max_markup_pct ?? 0`; the admin create and edit refuse a non-zero markup on a third-party brand with no ceiling (422 on `markup_pct`; an owned brand is forced to 0 as before); the admin form checks the same and its hint says blank means no markup. The portal already behaved this way and is unchanged.

**Trace.** Every writer of the column: the admin store/update (validated `gte:markup_pct`), the seeders (owned brands only). Every price reader goes through `storefrontPriceTerms()` (checkout, checkout preview, catalog, voucher preview, sandbox order, membership plan preview, member-savings badge); the last three read the primary, an owned brand, so they cannot move. Orders snapshot the effective markup, so no stored order changes. Production, read-only on 2026-10-31: the only third-party affiliate (FixFast Demo) has a 25% ceiling and 5% markup, the two owned brands ignore markup, so no live price moves and no cache flush is needed.

**Verified.** Red first: five tests failed for the intended reason (the seam dataset row, a checkout under a null ceiling, the portal `show`, two admin refusals). Then the full backend suite green; four existing fixtures that set a markup with no ceiling got one (two admin create payloads, a catalog and a voucher-preview brand). Admin `tsc` and `eslint` clean. No browser check: the admin change is one hint and one client-side check that mirrors the server's 422.

## 2026-11-01 — ADR-133 PR 3a: the reseller application backend

**What.** The backend half of the public reseller application with KYC (ADR-133 decisions 2–11 and its 2026-11-01 addendum). New tables `partner_applications` (both stages in one row; `active_email` nulled on approve or decline, so a unique index guards "one open application per email"), `partner_application_documents` and `partner_document_views` (short names: MySQL caps identifiers at 64 characters); `platform_settings.partner_applications_open` (default off). `PartnerApplicationService` owns every status change under a row lock: stage 1 (refreshes itself while unconfirmed), email confirmation, Invite directly, send or regenerate the stage-2 link, save and submit stage 2, decline, approve (an active tier, `ResellerProvisioningService::provision(..., inviteAfterCommit: true)`), delete data. `PartnerApplicationDocuments`: kinds per applicant type, one file per kind except two bank statements, files stored before the row and deleted after commit. Public routes under `/api/onboarding` (own limiters, Turnstile on stage 1); admin routes under `/api/partner-applications` (super_admin; delete behind step-up); KYC files only through a 5-minute signed link that re-checks the admin and logs the view. The bilingual notices list recipients by class. `app:prune-partner-applications` (daily, routine): declined after 90 days, approved after 90 days from account closure, unconfirmed after 7 days. The reseller list shows "KYC on file".

**Founder decisions (2026-10-31):** split PR 3 into 3a/3b/3c; "Add Reseller" becomes "Invite directly" (button in 3c); the open switch, off by default; 10 MB per file; recipients by class (checked with a contact in the legal industry). The founder created the R2 token; Claude created the `pekangame-kyc` bucket from the founder's browser (Asia-Pacific, Standard, public access disabled).

**Verified.** Service tests (15), endpoint tests (16), the retention command test, the step-up pin list and `TaskScheduleTest`; the full backend suite; the local dev DB migrated (plain `migrate`); `curl` against the local Herd site: `GET /api/onboarding/status` returns the closed switch and the notice, an empty stage-1 POST answers 422. No UI yet (3b, 3c).

**Gotchas.** `Http::fake()` in `setUp` wins over a later `Http::fake([...])` in the same test, so a "mail fails" test passed without failing anything; the fix is one closure fake reading a per-test response. A missing `use ($inviteAfterCommit)` in a nested closure only showed at runtime.

## 2026-11-01 — ADR-133 PR 3b: the public reseller application forms

**What.** Three pages in the portal app under a `(onboarding)` route group: `/apply` (stage 1: applicant type, details, reselling answers, the bilingual notice with an English/Malay switch, one consent box, Turnstile), `/apply/verify` (confirms the email on a click) and `/onboard/[token]` (stage 2: addresses with "Save progress", one upload row per required document, the stage-2 notice and consent, submit). `proxy.ts` serves them only on an `onboarding.` host (everything else there goes to `/apply`, with `X-Robots-Tag: noindex`) and answers 404 for them on the portal host. Backend: `ONBOARDING_URL` is now an allowed CORS origin. Details in ADR-133's PR 3b addendum.

**Found in the browser check, fixed before the PR.** (1) The portal's CSP blocked the Turnstile script, so the submit button never enabled: Turnstile is now allowed only on the application pages. (2) Confirming the email on page load spent the single-use token twice in development (React's double effect) and could be spent by an email scanner in production: confirmation is now a button.

**Verified.** Throwaway sqlite backend on :8000 (a local fake for Plunk and the Turnstile check, so no real email left the machine), the portal on :3002 with Cloudflare's always-pass test site key, Playwright on `onboarding.localhost:3002`: 18 checks passed (portal host 404s both paths, `/login` on the onboarding host goes to `/apply`, noindex header, notice switches language, stage 1 sent, opening the link alone does not confirm, confirm works and a bad link explains, stage-2 link emailed, save, two uploads, resume after reload, mobile 390 px with no sideways scroll on both pages, submit, a used link explains, no page errors), and the database row read back as `documents_submitted` with two files. Text contrast 4.8 to 16.4 in light and dark. `tsc`, `eslint`, `npm test` (18) and `next build` clean. Servers stopped, the sqlite file and the test KYC files deleted afterwards.

## 2026-11-01 — ADR-133 PR 3c: the admin applications screen; "Add Reseller" becomes "Invite directly"

**What.** `/admin/applications` (sidebar Partners → Applications): status tabs with counts, a list in the `/admin/orders` style, the detail in `OrderDetailCards`-style cards, actions by status (send or resend the stage-2 link, approve with an active tier, decline, delete data behind step-up), documents opened through the 5-minute signed link with their view log, and the "Public applications open" switch. `InviteApplicantModal` is shared by Applications and Resellers. On Resellers, "Add Reseller" is replaced by "Invite directly", and an approved account shows "KYC on file". Backend: `POST /api/resellers` and `ResellerController::store()` removed (grep of the whole repo: only the admin page and four tests used them); the tests now exercise `/api/resellers/internal`, plus one that the old route answers 405.

**Verified.** Throwaway sqlite backend, a local fake for Plunk, a throwaway admin, the admin dev server on `localhost:3000`, Playwright: the list, the switch, the detail, a file opened in a new tab (200, PDF) and its view shown on the detail, approve with a tier (set-password email sent), send stage-2 link (one email), decline (one email), decline of an unconfirmed email (no email, and the dialog says so), delete asking for step-up (the throwaway admin has no MFA, so the prompt is as far as it goes), Resellers without "Add Reseller" and with "KYC on file", Invite directly landing on the new application, no page errors; the list and detail at 390 px fresh-loaded with no sideways scroll, and dark mode by screenshot. The first mobile screenshot showed detail rows squeezed one letter per line (a `shrink-0` label beside an `overflow-wrap: anywhere` value): rows now wrap. The admin's colour-token test caught a `success-700` class with no token: the banner uses `success-surface`/`success-ink`. `/admin/orders` itself scrolls sideways at 390 px; that is older than this work and left as it is. Admin `tsc`, `eslint`, `npm test` (20) and `next build` clean; backend reseller, step-up and route tests green. Servers stopped and test files deleted afterwards.

## 2026-11-01 — Release #633: partner onboarding (ADR-133) live

Requested by the founder. PR #633 (`staging`→`main`) merged as `08afce86`; CI and the `deploy` job green; the box runs release `81738369` at `08afce8`. Migrations applied: `2026_10_31_120000_add_internal_reason_to_resellers_table`, `2026_11_01_100000_create_partner_applications_tables` (batch 55). Ships #619–#632 (PRD §14 lists them).

**Before the release** the founder set `ONBOARDING_URL`, `KYC_DISK=r2_kyc`, `R2_KYC_ACCESS_KEY_ID` and `R2_KYC_SECRET_ACCESS_KEY` in Forge; Claude checked read-only that each key is present (values not printed) and that the token lists the `pekangame-kyc` bucket (0 objects). The founder added `onboarding.pekangame.space` to the `pekangame-reseller` Vercel project (CNAME at Cloudflare, proxy off; DNS and SSL checked) and `NEXT_PUBLIC_TURNSTILE_SITE_KEY` there.

**Checked after the release:** `/api/health` 200 (database, queue, Horizon, scheduler); `app:prune-partner-applications` scheduled daily; `partner_applications_open` false, 0 applications; `config('filesystems.kyc_disk')` = `r2_kyc`; `GET /api/onboarding/status` 200; `https://onboarding.pekangame.space/apply` 200 with `X-Robots-Tag: noindex, nofollow` and Turnstile in `script-src`, a real browser shows "Applications are closed" with no console error; `/login` there redirects to `/apply`; `https://reseller.pekangame.space/apply` 404; the four Vercel deploys succeeded. Not yet proved: a write to the KYC bucket (the first upload proves it).

**Next (founder):** read the notice text on `/apply`, try "Invite directly" on a test address, then turn on "Public applications open" in Admin → Applications.

## 2026-11-01 — Docs audit after release #633 (docs only)

The founder asked whether the docs were current and free of contradictions. Read-only audit first, then fixed with the founder's go-ahead:
- **Contradictions:** PRD item 141 said item 129 "stays on hold" while item 129 said "now due"; ADR-133's Status line still said "Nothing is built".
- **Stale:** ADR-129 to ADR-133 had the Standing "Accepted (design)" though all five are in force (now "In force", with the "later addendum overrides the Status line" sentence where it was missing); ADR-131's banner and index row now say condition 4's documents are collected in the system since ADR-133; ADR-072's decision 6 points to ADR-133; PRD §11 still listed self-serve signup on the roadmap; the old §16 Details item 13 ("ADR-085 candidate… ADR-084 assumes invite-only") removed; items 13 and 146 moved to Recently closed; ADR-133's "Owed by the founder" list marked as settled; the §16 header's re-check date.
- **Founder decision (2026-11-01):** the portal never opens to public signup; the ADR-133 application is the only way in. Item 111's second trigger dropped (ADR-019 2026-11-01 addendum).
- **Gaps:** `foundation-security.md` gained the public application endpoints (§4) and the KYC file handling (§7); the root `AGENTS.md` `reseller/` row names the onboarding host. New §16 item 153 holds opening the application, including the docs-site "Request access" text that still says to email.
- **Housekeeping:** `build-log.md` entries 2026-10-15 → 2026-10-22 (64 entries) moved verbatim to `build-log-archive.md` ("Ninth archive move"); both headers, PRD §15/§16 pointers and the root `AGENTS.md` now say 2026-10-23.
