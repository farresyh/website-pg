# ADR-126: Joki MLBB — owned-brand `/joki` page, a `Fixed` pricing basis, each penjoki a pay-after manual supplier with its own WhatsApp group, payout booked as cost

> **Standing (2026-10-23):** Accepted (design); the 2026-10-23 addendum re-traces it against ADR-125 as built and records grill S1–S26 and stress test F1–F5, overriding the original where they differ. Build and live status: [`prd.md` §15](../prd.md). The text below is a dated record; a later addendum in this file overrides earlier text, including the **Status** line.

**Status:** Accepted (design), 2026-10-17. Grilled with the founder (`/mattpocock-skills:grilling`, Q-A to Q-J plus the earlier scoping answers), after a money-critical trace in three read-only forks (joki checkout/pricing, supplier funding/accounting, reports/analytics), top findings re-checked by hand. Builds on [ADR-125](./ADR-125-login-top-up-manual-supplier.md) (manual suppliers, finalize seam, supplier groups, its 2026-10-17 addendum) and [ADR-124](./ADR-124-customer-account-credentials.md) (credentials). Built after both (PRD §16 item 114).

**Context:**

- The founder wants a joki (rank-boosting) service: the customer picks a current and a target MLBB rank, pays, and hands over their login; one of the founder's own penjoki contacts plays the account to the target. **Owned brands (PekanGame) only**, MLBB only to start.
- References (2026-10-17):
  - **rapspoint.com/joki/ranked**, viewed in the founder's browser. Big rank icons with ‹ › to change tier and ▲▼ for stars. Priced **per star at the rate of the tier the star is won in**, minimum 5 stars. Express adds about Rp1,000 per star. Mythic Grading is a flat Rp170,000. Rates (Reguler/Express, IDR per star): Master 6,000/6,500, GM 6,000/7,000, Epic 7,000/8,000, Legend 8,000/9,000, Mythic Romawi 16,000/17,000, Honor 19,000/20,000, Glory 24,000/25,000, Immortal 29,000/30,000. Step 2 asks for login type (Moonton/Facebook/VK/TikTok/Google Play), user ID, username, email, password, hero request, role request and a play-time window.
  - **Gamevion's "Road to Mythic" pricelist** (founder's image): fixed route packages in RM, e.g. Epic II→Mythic RM65, Legend V→Mythic RM55, Mythic→Honor RM150, Mythic→Glory RM275, Mythic→Immortal RM500, Mythic 200/500/1000 stars RM900/2000/3500, Global Hero "PM for quotation".
- **Trace findings (2026-10-17):**
  - Every storefront price is derived **from cost** (`PricingService::calculate`, `calculateForAffiliate`, via `OrderPricingResolver::resolveStorefront`, always called by `CheckoutService::initiate`). Joki fixes the **selling** price and derives the cost. Through the normal chain, the owned brand's wholesale tier (production: "Owned Brand", 10%) would turn RM65 into RM71.50.
  - `OrderResendService::preflight` rewrites `cost_price` from the target package, even for a same-package resend: a joki payout would be silently overwritten.
  - `voucher_discount` is a **payment** by store credit (the customer's own earlier money, ADR-004), not a discount: it lowers neither `selling_price` nor `platform_profit`. A payout based on "after voucher" would book the old store credit as platform profit and underpay the penjoki.
  - The hidden-game rule exists in `AffiliateGame::hiddenGameIdsFor` plus two drifted copies (`Affiliate/Storefront/PricingController::visiblePackages`, `StorefrontGameController`). `AffiliateGame` is opt-out, so a new game is visible and checkout-able on every affiliate host.
  - About 15 readers use `package->name`; `CheckoutController` does `findOrFail` on `package_id`.
  - `finalizePendingDelivery` reads `$locked->supplier` at finalize time; the only post-checkout writer of `orders.supplier_id` is the resend package swap. Changing `supplier_id` on a Pending order before any drawdown is safe under a lock.
  - No per-order notes or timeline model exists. Customer WhatsApp events are one method each on `CustomerNotificationService` (ADR-116), sent from the customer-support session.
  - A Failed order never draws down; `recordManualAdjustment` must reference an existing transfer and adds to operating profit with a + sign, so a partial-work payment cannot go through Supplier Funding cleanly.

**Decision:**

*Catalog, pricing, checkout*

1. **A joki order is an ordinary `Order`** on a "Joki MLBB" game flagged `owned_brands_only`, always pointing at a real `Package`: one "Joki Ranked (calculator)" package and one per route package. Rank detail (from/to tier, division, stars, Reguler/Express, hero request, role, play window) lives in a `joki_orders` side table, and the WhatsApp status card appends it.
2. **New `PricingBasis::Fixed`** via `OrderPricingResolver::resolveFixed(sellingSen, payoutSharePct)`: selling = the server-computed table price, cost = round(selling × share), `affiliate_profit` = 0, `platform_profit` = selling − cost. `CheckoutService::initiate` uses a pre-resolved `PricingResolution` instead of `resolveStorefront`. No tier markup, no member pricing (ADR-125 decision 7: `member_pricing_enabled` off), by construction. Readers of `pricing_basis` (reports' breakdown, `OrdersWorkbook`, report assistant) get a `fixed` label.
3. **Price is computed server-side** (ORD-9) by a `JokiPricingService` from the admin rate table: per tier a per-star rate for Reguler and Express, or a flat price for a flat tier (Mythic Grading), and a minimum star count. The client sends ranks, stars and the Express flag only. Route packages carry an admin-set joki price; a "quotation" package opens WhatsApp instead of checkout.
4. **Payout base = selling price (Q-A, revised).** The penjoki gets share % (panel, default 100) × `selling_price`, which already excludes the gateway fee. The customer may pay by gateway, by refund store credit, or both. No promo codes (ADR-118 not built; joki eligibility decided when it is).
5. **Owned brands only, one seam.** `hiddenGameIdsFor(Affiliate $brand)` also hides `owned_brands_only` games when `! $brand->is_owned`; the two drifted copies route through it; the joki checkout asserts `is_owned` server-side. A platform toggle switches joki off entirely.
6. **Package-swap resend is blocked** for `pricing_basis = fixed`; plain Retry stays.
7. **Express** = a higher per-star rate and priority, with a panel-set description and **no promised time** (Q-F).

*Penjoki, fulfilment, money*

8. **Each penjoki is a manual supplier** (ADR-125 `driver = manual`) with `funding_mode = pay_after` and its own WhatsApp group. Paid joki orders land on a **"Joki – Unassigned"** manual supplier whose group is the founder's own. **"Assign penjoki"** (admin, Pending only, manual → manual, under a lock) sets `supplier_id`; the ADR-125 post job, keyed on (order, reference, supplier), posts the order and a fresh login link to that penjoki's group. Reassigning before completion kills the old link (the signed link re-checks the supplier) (Q-I).
9. **Payout = the order's cost.** `.done` and the admin's Delivered pass the order's `cost_price` snapshot as the real cost (ADR-125 addendum). The drawdown makes the penjoki's ledger negative: the amount owed. Paying a penjoki is a `SupplierTransfer` with a receipt.
10. **Progress (Q-G, Q-H):** `.progress PG-… <tier> <division> <stars>` (Mythic and above: `<tier> <stars>`), plain text, no emoji, validated against the rank model; the bot confirms in the group. The admin can also update from Order Detail. Updates go into `joki_progress_updates` (order, rank, stars, actor, time) and `joki_orders.current_*`. Shown on admin Order Detail (timeline), on the storefront Track Order page (current and target rank, through the customer-safe payload) and by WhatsApp to the customer from the customer-support session through a new `CustomerNotificationService::jokiProgress()`: the first update sends at once, then at most one message per order per 2 hours (panel setting), carrying only the latest.
11. **Refund is always full** as store credit, excluding the gateway fee as for every order. A partial-work payment to a penjoki on a failed joki is a Budget Envelope expense (category "Joki – partial work", order number in the note), a visible platform loss, never a Supplier Funding adjustment (Q-B).

*Customer page and terms*

12. **`/joki`** on owned brands (backend-gated like membership, matched `loading.tsx`): rank icons with ‹ › for tier and ▲▼ for stars, Reguler/Express, the route-package grid, then the data step (ADR-124 credential fields, consent, hero/role/play window), then payment.
13. **"Joki Terms & Rules"** (Q-E): one rich-text field in the joki settings (`RichTextEditor`), shown as a section on `/joki` and linked from the consent checkbox. A full snapshot of the terms is stored on each `joki_orders` row.
14. **Panel settings (admin):** joki on/off, rate table, flat tiers, minimum stars, route packages and quotation flags, login-type options, role list, payout share %, Express description, terms, progress-message interval.

**Rationale:** joki as an Order on a real Package reuses every reader, voucher and report. A `Fixed` basis is the one honest way to price a service whose selling price is the fixed fact. Penjoki as pay-after manual suppliers reuses ADR-125's groups, finalize seam and Supplier Funding, so payables per penjoki need no new ledger.

**Consequence to track:**

- **Launch prerequisites:** research the current MLBB rank model (stars per division, Mythic sub-tiers by star count) and prepare a proposed RM rate table from the rapspoint rates at a stated exchange rate, compared with Gamevion's route prices; the founder reviews it and enters it in the panel.
- Accounting presentation of penjoki payables and the month scope of orders delivered in the next month go to the accountant (PRD §16 item 81).
- Auto-assignment (round robin) and a penjoki login or portal are later addenda, when volume calls for them.

### 2026-10-23 addendum — re-traced against ADR-125 as built; grill S1–S26 and stress test F1–F5

**Context.** ADR-126 was written on 2026-10-17, before ADR-125 decisions 48–64 were built and before Login Top-up ran on production (first real order PG-YTVHCNMQ2SZX, 2026-10-23, build-log). Before building, the design was re-traced against the code in three read-only passes, one per layer (checkout and pricing; ledger, fulfilment and compensation; downstream readers), with the top findings re-checked by hand. It was then grilled with the founder (S1–S26, in Malay) and stress-tested once more (F1–F5). This addendum overrides the original decisions where they differ.

**Decision.**

*Brand, catalog and page*
1. **Joki follows the Membership pattern (revises decision 5).** A per-brand `joki_enabled` that can be on only when the brand `is_owned` (the same rule `AffiliateController` applies to `membership_enabled`), plus a global Joki switch. `/joki` and its endpoints are gated by one middleware, like `EnsureBrandMembershipEnabled`.
2. **The joki game is never in any catalog, on any brand (revises decision 12's entry point).** It is hidden through `AffiliateGame::hiddenGameIdsFor()` for every brand, which covers the catalog, game page, packages, checkout and preview, and Check ID. The two drifted copies are in the affiliate portal (`Affiliate/Storefront/PricingController`, `StorefrontGameController`), and both route through it. The ordinary game page and checkout refuse a joki game. Entry is a "Joki Rank" menu item plus a home-page banner switched in the panel.
3. **The rank model is panel data, not code.** Tiers, divisions, stars per division, Mythic thresholds, tier names and icons (uploaded through the Gallery). The founder accepts the risk of using Moonton's official rank icons. Each order stores a snapshot of the model and rates it was priced with.

*Price and money*
4. **Customer prices are whole ringgit.** Per-star rates (Reguler, and Express above Reguler), flat tiers, the minimum stars and route-package prices are all set in the panel in whole RM, and the panel refuses sen. The draft rates (rapspoint converted at the platform's own IDR rate of 0.000226 and rounded up; Express = Reguler + RM1) are GM/Epic/Legend RM2, Mythic RM4, Honor RM5, Glory RM6, Immortal RM7, and Mythic Grading RM39. Checked against Gamevion's and Muzzzyyy's price lists, the founder confirms them with his penjoki before launch.
5. **Route packages are founder-set promo prices**, independent of the calculator. The calculator points to a cheaper package for the same route.
6. **Joki prices live only in joki tables (F1).** A joki `Package` row exists only so the order has a package, and its price fields are never read. Bulk Markup (`SettingsController::bulkMarkup`, which rewrites every non-combo package) and the package edit form exclude joki games.
7. **The payout share is a panel setting, default 100%, snapshotted on the order at checkout (S14).** At 100% the platform profit is RM0 by design. The founder raises margin later by lowering the share. The payout may carry sen (e.g. 85% × RM70 = RM59.50), and it is not rounded.
8. **`CheckoutService::initiate` takes an optional pre-resolved `PricingResolution`**, built by `OrderPricingResolver::resolveFixed()`. The joki preview and the order go through one path (`JokiPricingService` → `resolveFixed` → `computeTotal`). The joki selection (ranks, stars, Express) is part of the checkout idempotency hash.
9. **A Fixed order's cost is locked (S17, S18).** `OrderResendService::preflight` keeps `cost_price` and `supplier_id` for a Fixed order, and a package swap is refused. Manual Delivered can't change the cost of a Fixed order. Any extra or partial pay goes through the Budget Envelope.
10. **Retry after a failed joki returns the order to the pool (F2).** Retry starts a new attempt waiting for assignment, never back to the penjoki who failed it. The cost is kept.
11. **`PricingBasis::Fixed` gets a label and every reader is updated:** the enum `label()` match (without it the Reports export throws), the admin `pricing_basis` type, the Report Assistant and `OrdersWorkbook` wording (for Fixed, `cost_price` is the agreed payout, not an estimate). A test checks that every case has a label.
12. **Paying a penjoki uses the existing pay-after flow (S21).** `.done` books the drawdown (the amount owed, "Owed: RM X" on `/admin/balance`). The founder pays by bank transfer and records it with Record Supplier Transfer and a receipt. There is no penjoki portal or withdrawal.
13. **A partial-work payment** (on a failed or reassigned joki) is a new Budget Envelope expense category, "Joki – partial work", shown as its own line on the Monthly Summary (S15, S20). A reassigned order's full cost goes to the penjoki who delivers it.
14. **Revenue recognition by delivery date is a separate item that must ship before Joki launches (S19).** Today revenue is scoped by `paid_at` and the customer liability by `delivered_at`. A joki paid on 28 Oct and delivered on 5 Nov would drift a closed month. That item needs its own grill, MFRS 15/MPERS citations, a data test, and a start month that leaves closed months unchanged (PRD §16).

*Penjoki, assignment and progress*
15. **"Waiting for a penjoki" is an explicit pool supplier (S12, revises decision 8).** It is a manual supplier with an `is_assignment_pool` flag, read through one predicate:
    - no group post, SLA reminder or login link;
    - exempt from the group-before-activation rule;
    - excluded from the Dashboard's "not posted to supplier" and "supplier with no group" counts, and from the Manual Queue;
    - Delivered is refused (Failed stays allowed, so the customer can get a voucher);
    - it can't be switched off from the supplier screens, because the global Joki switch is the only switch (F5).

    There is no WhatsApp alert for a new joki order. The founder monitors the panel.
16. **Assign happens in the admin panel**, on the Joki page or Order Detail. It is Pending-only, under `lockForUpdate`, and only to an active penjoki that has a group. Each assignment writes an audit row (order, from, to, admin, time). It posts the order and a fresh login link straight to the penjoki's group.
17. **The supplier post's dedupe key includes the supplier (S1), for every manual order:** `event:order:<id>:ref:<reference>:supplier:<id>`. A queued post to the previous supplier is skipped by the existing key check. `isRetry` compares the reference, not the key. A migration rewrites the existing rows, and `unpostedWaitingCount` reads the new format.
18. **Delivered and Failed re-check the supplier under the lock.** `ManualDeliveryService::confirmDelivered`/`confirmFailed` take the expected supplier, and finalize refuses when it changed. This closes the race between a reassign and the old penjoki's `.done`.
19. **A penjoki with Pending orders can't be switched off** until they are reassigned (S5).
20. **One "stale" rule for every manual order, on an explicit clock (S7, F3).** A new `orders.manual_activity_at` is written by the group post, assign, `.progress` and an admin progress update. `Order::isStalePending()` reads it (falling back to `updated_at`), so for Login Top-up it equals the post time, as today. The manual SLA maximum goes from 1440 minutes to 4320 (3 days).
21. **Progress (decision 10):** `.progress` and the admin update write `joki_progress_updates` and set `manual_activity_at`. Customer WhatsApp progress messages go only to opted-in customers (S23). There is no customer cancel button: CS, then Failed, then a full voucher (S24).

*Login retention*
22. **Each login's wipe ceiling is stored on the row (S8, revises ADR-124's single D).** `order_credentials.secrets_expire_at` is computed by one helper from the game's optional "keep login at most (days)", else the Customer Login Policy's D. The joki game is set to 30 days, other games keep D (14). Both writers of `secrets_set_at` call the helper. The wipe sweep and the supplier link's `expires_at` read the column. Existing rows are backfilled with `secrets_set_at + D`.

*Admin and reports*
23. **Admin → Joki (S9):**
    - **Orders:** a detailed table like `/orders`, with filters Waiting / In progress / Stale / Done / Failed, showing wait time, ranks, last activity and remaining SLA.
    - **Penjoki:** each penjoki's open orders and amount owed, with a "Record payment" button.
    - **Settings:** rates, share, rank model, packages, terms, switches, banner.

    The Manual Queue keeps only non-joki manual orders.
24. **Dashboard Joki card (S16):** waiting for a penjoki, in progress, stale. Each number opens its filtered list. Joki orders are excluded from the general "Need Action" count and filter (F4).
25. **`/orders` keeps every order, joki included**, as the full record and export. It gains a "Type: All / Top-up / Login / Joki" filter (default All). Reports show Joki as its own line through the existing game breakdown.

**Rationale.** Every change routes through a seam the existing paths already use (the hide-game rule, the resend preflight, the finalize lock, the post dedupe key, the stale predicate, the login-wipe helper), so Login Top-up gains the same fixes and no joki-only copy of a rule appears. The money fixes (F1, decision 9, decision 18) each close a path that would change what a penjoki is owed without anyone deciding it.

**Consequence to track.**
- **Launch gates:** the revenue-recognition item (decision 14), payments re-opened after CHIP's review, the published refund policy back to voucher-only (PRD §16 item 129), the founder's rank icons, and rates confirmed with the penjoki.
- **Build in five PRs:**
  1. the shared manual-order fixes (decisions 17, 18, 20, 22);
  2. the joki backend (Fixed, pool, assign, resend, labels, F1);
  3. progress and WhatsApp;
  4. admin;
  5. the `/joki` page.
- Before launch, a local data test of every joki path (paid this month and delivered next month, failed, reassigned, retried) against the Monthly Summary, month close, Reports, Dashboard and the exports.

### 2026-10-23 addendum — PR 1 build notes (decisions 17, 18, 20, 22)

- **Decision 18:** the re-check lives inside `finalizePendingDelivery()`'s own lock (optional `expectedSupplierId`), not in an outer transaction, so a contradicting result's evidence still commits before the refusal. The admin's Delivered/Failed send the supplier shown on screen, so a reassignment between page load and click is refused too.
- **Decision 20:** "written by the group post" means the moment the post is sent (`SupplierGroupNotifier::prepare()`), not when it is queued. A post skipped for having no group starts no clock.
- **Decision 22:** changing D or a game's days recomputes the ceiling of every unwiped login it governs. Before this decision, the sweep read D live, so a lower D applied at once, and the stored column keeps that behaviour. A wiped login is left as it is.

### 2026-10-23 addendum — PR 2a build notes (decisions 2, 9, 10, 11, 15, 16, 19, F1, F5)

- **PR 2 is split** into 2a (money core: Fixed, pool, Assign, payout locks, hidden game, F1) and 2b (rank model, rates, pricing, joki checkout, `joki_orders`, switches), with the founder's agreement, so each money PR stays reviewable.
- **Decision 15:** the pool flag is `suppliers.joki_role` (`pool` | `penjoki` | null), not a lone `is_assignment_pool` boolean. One column also marks who may be assigned, and a penjoki or the pool can't be picked as an ordinary package's supplier. The pool is also left off every supplier list and Health card, since it never holds money.
- **Decisions 16/17:** a post and a login link belong to one assignment. For an assigned order the dedupe key ends `:assign:<id>`, and a link stores `joki_assignment_id`. Otherwise A → B → A would leave A with no new post and revive A's first link. Assign takes the order lock, then the penjoki's lock, and `setActive` takes the same penjoki lock for decision 19.
- **Decision 10:** the restart happens in `fulfill()`'s attempt start (its one seam), back to the order's package supplier (the pool), for a joki order coming from Failed or NeedsReview.
- **F1:** one `not.joki` route middleware covers every Games & Packages write route, rather than a check per controller method.
- **F4 (Need Action) and the Manual Queue exclusion** move to PR 4 with the Joki page and Dashboard card, so a joki order is never left with nowhere to be seen.

### 2026-10-23 addendum — PR 2b: the pricing rule and its build (decisions 1, 3-8, 12-14)

**Founder's answers, recorded as decisions:**
- **Stars.** A division is worth its own stars (Epic V 0★ → Epic IV 0★ = 5). A division's stars run 0 … n−1, and n stars is the next division at 0★. Elite has 4 stars per division. Both are panel data.
- **Mythic Grading** is a flat price that guarantees Mythic `grading_lands_at_stars` (15). If grading lands lower, the penjoki plays on to 15★ at no charge. Stars past 15★ are charged at the Mythic band's rate. A target of Mythic 0–15★ costs Grading only. An account already in grading picks "Mythic (Grading)" as its current rank.

**The rule** (`JokiPricingService`, the only price):
- Each star is charged at the rate of the tier it is won in.
- Grading is added when the climb crosses from below Mythic.
- Mythic and up is priced on the Mythic star count, in bands from each tier's `mythic_from_stars`.
- Fewer than `min_stars` paid stars is refused unless the climb includes Grading.
- Express uses the Express rates and the Grading Express price.
- A route package sells at its own price, Reguler only. A quotation route is never sold.
- The calculator names a cheaper route package for the same climb.

**Build decisions:**
- **One checkout.** A joki order goes through `/api/checkout` with a `joki` block, not a second checkout. Login, blacklist, idempotency, voucher and the payment leg stay shared.
  - `CheckoutController::sellableHere()` sends a joki game to `JokiCheckoutService`, which checks both switches on an owned brand and the role, then prices.
  - `CheckoutService` takes the pre-resolved Fixed pricing.
  - `OrderFactory` writes `joki_orders` in the order's transaction.
  - The idempotency hash includes from, to and Express. The hero, role and play window are left out, like contact details.
- **`JokiCatalog::ensure()`** creates the joki game and calculator package when joki is set up (PR 4's switch), not in a migration. Seeding them made them appear in every game count, sitemap and catalog test before joki existed. The migration seeds only joki-only tables (settings, rank model, draft rates).
- **Roles** are a panel list in `joki_settings`. Login types are the joki game's credential schema (a select), edited like any login game's.
- **The owned-brand rule** is one list in `AffiliateController` (`membership_enabled`, `joki_enabled`), and `Affiliate::jokiEnabledEffective()` re-checks `is_owned`.
- **The `/joki` endpoints** (`/api/joki/catalog`, `/quote`) expose customer prices only, never the payout share.

### 2026-10-23 addendum — PR 3 build notes (decisions 10 and 21)

- **Pacing.** The first progress message goes at once. After that, a new message is scheduled no earlier than the last one's send time plus the panel interval. While one is queued, newer updates add nothing: the message is worded when it is sent, with the latest rank, so the customer always gets the current state and never a backlog. It is dropped at send time if the order is no longer being played or the customer opted out.
- **`.progress` reading.** For Mythic and up, the rank is read from the star count, so a penjoki who types `Mythic 30` is recorded as Mythical Honor 30★. Tier names match on the full name, the last word, a prefix of 3+ letters, or `GM`.
- **Broadcast.** Progress reaches an open Track Order page through the existing `OrderObserver` seam (now also on `manual_activity_at`), not a second broadcast path.

