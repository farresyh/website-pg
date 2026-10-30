# ADR-125: Login Top-up (Roblox, eFootball) — manual suppliers as a `driver` behind `SupplierAdapter`, one finalize seam for every confirmation, a supplier WhatsApp group per supplier, admin-entered packages at an agreed cost

> **Standing (2026-10-23):** In force; the 2026-10-23 addendum points at ADR-126's revisions (post key with supplier, supplier re-check under lock, explicit stale clock, pool supplier); PR 1 split into 1a/1b and decisions corrected by the 2026-10-18 stress-test addendum and the 2026-10-18 PR 1b pre-build addendum (decisions 36–47); PR 2 split into 2a/2b and corrected by the 2026-10-19 pre-build addendum (decisions 48–61); build addenda add decisions 62–64 (64: a manual supplier's switch takes its packages off sale). Build and live status: [`prd.md` §15](../prd.md). The text below is a dated record; a later addendum in this file overrides earlier text, including the **Status** line.

**Status:** Accepted (design), 2026-10-17. Grilled with the founder (`/mattpocock-skills:grilling`, Q1–Q19), after a money-critical code-trace pass, then an adversarial stress test (three read-only forks, one per layer: checkout/catalog, fulfillment/ledger, bot/link). The stress test changed five decisions before they were written; its top findings were re-checked by hand. Build not started (PRD §16 item 113). Depends on [ADR-124](./ADR-124-customer-account-credentials.md). [ADR-126](./ADR-126-joki-mlbb.md) (Joki) builds on this ADR.

**Context:**

- The founder wants to sell games that suppliers top up **via the customer's login**, starting with Roblox and eFootball, on PekanGame **and Affiliate** storefronts, not Reseller. There is no supplier API: packages are entered by hand, and an external supplier (paid in RM, outside the system) does each top-up. Every business decision must be settable from a panel (founder, 2026-10-17).
- **Code-trace (2026-10-17):**
  - ADR-111's real-cost reconcile is **on in production** (`REAL_COST_RECONCILIATION_ENABLED=true`, read-only check). Formula: `platform_profit = selling − real_cost − affiliate_profit`; affiliate profit is never touched.
  - Member price = `cost × (1 + markup × (1 − discount))` (`MembershipPricingService::calculateMemberPrice`). Production plans give 50% and 80% off the markup, so at 15% markup a Tier 2 member order keeps 3%: a cost estimate 3% low makes it a loss. The only affiliate tier is "Owned Brand", 10% on cost.
  - Today there is **no per-game member switch**: `OrderPricingResolver::resolveMember()` applies to every game. The storefront also shows a member price (`CatalogController::publicPackage`), and the savings badge uses a median over all active packages (`MembershipPricingService`).
  - Combos allow "same game, same supplier" (`StoreComboPackageRequest`), so a manual combo could be created. Resend can swap to another package of the same game (`OrderResendService`).
  - `SupplierTransfer` (receipts, FX) and `SupplierFundingService::recordOrderDrawdown` (idempotent per reference) already track deposits and per-order spend per supplier.
- **Stress test (2026-10-17), what would have broken:**
  - `suppliers.slug` is unique and must name a bound adapter (`CreateSupplierRequest`: `in:registeredSlugs()`); `SupplierAdapterFactory::make($slug)` has about ten callers. **Two manual suppliers could not exist.**
  - Price Sync and the schedule gate select suppliers by `filled(api_config)` (`SyncSupplierPricesJob.php:76`, `TaskSchedule.php:84`). A manual supplier's settings would make it non-empty, and the "vanished → deactivate" path would switch off every manual package.
  - `CheckoutInputValidator::playerIdFormat()` allows `[A-Za-z0-9#._-]` only, so a login email cannot be `player_id`.
  - `OpenWaClient::sendText` queues `SendResellerBotReplyJob` with 3 tries and is not persisted: fine for a bot reply, not for an order post. The persisted, two-day-retry, de-duplicated queue is `CustomerNotification` + `SendCustomerWhatsAppJob`, hard-wired to the customer-support session and `phone@c.us`, with one global send slot.
  - A WhatsApp link preview fetches a URL with GET.
  - The Dashboard stale-Pending count uses the global `pending_stale_minutes` (10), while the reconciler honours the per-supplier value. They already disagree.
  - `reseller_whatsapp_groups.whatsapp_group_id` is unique only in its own table.
- **Stress test, what is already right:** `OrderFulfillmentService::finalizePendingDelivery()` takes a Pending (or NeedsReview) order to Delivered or Failed under `lockForUpdate`, captures the real cost (`captureRealCostSen`, MYR at rate 1.0), applies the ADR-111 reconcile, `creditProfit`, voucher commit and an idempotent drawdown, rejects a second finalize (`InvalidOrderTransitionException`), and has `FinalizePendingDeliveryConcurrencyTest`. Every Reseller channel (portal, REST API, bot, public price list) needs `games.reseller_code`.

**Decision:**

*Suppliers*

1. **`suppliers.driver` names the adapter; the slug becomes a free unique name.** Backfill `driver = slug` for existing rows. `SupplierAdapterFactory::for(Supplier)` resolves by driver and replaces every `make($supplier->slug)` call once. `CreateSupplierRequest` validates `driver` against the registered drivers. A manual supplier is `driver = manual` with `currency = MYR` (Q9), so a new external supplier is panel data, never code (Q1).
2. **Capabilities live on `Supplier`, asked by every reader:** `scopeApiBacked()` (sync, schedule gate, balance refresh, product sync select by it, never by `api_config` emptiness), `pollsStatus()` (reconciler, Check-from-Supplier button), `pendingPolicy()` and `displayBalance()`. `ManualSupplierAdapter` throws an unsupported exception for `listProducts`/`checkBalance`, never an empty success. Manual settings (reply SLA minutes, bot switch) live in `api_config` under a `manual` schema in `SupplierConfigSchema`.
3. **One stale-Pending rule.** An `Order::scopeStalePending()` used by both `ReconcilePendingDeliveriesCommand` and `DashboardService` reads each supplier's `pendingPolicy()`. For a manual supplier: never auto-escalated to NeedsReview, never polled, stale after its SLA minutes (default 30). This also fixes today's Dashboard/reconciler drift, and keeps manual Pending orders out of the 15-minute `->get()` scan.

*Catalog and checkout*

4. **Manual packages are created and edited in admin** (new endpoint + FormRequest): game, name, **agreed supplier cost** (`cost_price`, `> 0`, ADR-025), markup; `supplier_package_ref = MANUAL-{package_id}`. Before the bot (decision 12) the cost is the highest expected cost (Q4). Pricing for Standard, Member and Affiliate tier is unchanged.
5. **A game is either a login game or a UID game, never both (Q5).** "Login game" is derived from a credential schema in `validation_rules['credential_fields']`, not a second flag. One `GameFulfilmentRule` is called from the four write paths: manual-package create (needs a login game), `PromoteSupplierProductRequest` (refuses a login game), `UpdateGameRequest` (schema on/off refused while packages of the other kind exist; `reseller_code` refused on a login game, which keeps every Reseller channel out) and `StoreComboPackageRequest` (refuses a manual supplier, Q6).
6. **Per-game credential schema** `[{key, label, type: text|email|password|select, options?, required, secret, is_identifier}]`. Exactly one non-secret field is the identifier: it fills `player_id` and is validated by its own type, not by `playerIdFormat()`. Secret fields go to `order_credentials` (ADR-124). Player validation is off for login games. The storefront renders the fields in the one `Step1AccountInfo`, for every brand, driven by the schema.
7. **`member_pricing_enabled` on every game** (default on for existing games). One `Game::memberPricingApplies()` is read by checkout (membership id passed as null), by the public package price (`anchorPlan` null) and by the savings-badge sample. Off means no member price and no quota debit. The founder turns it off for Roblox and eFootball at creation (Q3).

*Fulfillment*

8. **Every confirmation goes through `finalizePendingDelivery()`.** The admin Manual Queue's Delivered (real cost in RM required, Q10: any admin), the bot's `.done`, and `.gagal` / the admin's Failed are callers of that one method, passing `price`, `confirmed_by` and `channel` in the supplier response. No new transitions, no generalised `markDeliveredManually`. A second confirmation gets "already finished". Failed lands in the existing Need Action list (Retry / Resend / Issue Voucher; the store credit excludes the gateway fee, Q7). No auto-refund.
9. **Retry from Failed** mints a new reference and posts the order again, labelled RETRY. The bot accepts a reply by order number only while one attempt is open.
10. **Money to the external supplier:** deposits are `SupplierTransfer` rows with receipts; each Delivered records the drawdown. `displayBalance()` shows the funding-ledger balance for a manual supplier, not `suppliers.balance`.

*Supplier group and bot (PR 2)*

11. **One `whatsapp_groups` registry** (`whatsapp_group_id` unique, `owner_type` reseller|supplier, `owner_id`, `is_active`) replaces `reseller_whatsapp_groups`. The pending-link screen links a group to a reseller or a supplier. `OpenWaWebhookController` routes by owner to `ResellerBotService` or a new `SupplierBotService`, each with its own parser, so commands never collide. Bot session only, never customer-support (ADR-116 decision 11).
12. **Every active manual supplier has a linked group (Q14, Q18).** Once PR 2 ships, a manual supplier cannot be activated without one. The group is only for order posts, status replies and escalations; cost never comes from it (Q16).
13. **Order post after commit**, as an idempotent job keyed on (order, reference), through the persisted `CustomerNotification` queue generalised to take `(session, chatId)` with a send slot per session. The post carries order number, game, package, the non-secret login fields and a one-time link to the secret fields (ADR-124 addendum, 2026-10-17).
14. **Supplier commands:** `.done PG-…`, `.gagal PG-… reason`, `.pending` (Q15). A command acts only on that supplier's own Pending orders. The WhatsApp sender is recorded in the supplier response.
15. **SLA reminder:** the 15-minute reconciler scan posts one reminder per order into the supplier's group when it passes the SLA (default 30 minutes, per supplier, Q17). Real latency is SLA plus up to 15 minutes. A post that still fails after its retries is surfaced on the Dashboard.
16. **No per-admin WhatsApp alerts (Q19, reverses Q11).** The founder is in every supplier group, so the post is the alert; before PR 2 the Dashboard stale count (decision 3) is the signal.

*Customer copy*

17. Badge "Manual Processing" with a per-game subtext (ADR-109, Q8). Consent and the change-your-password line come from ADR-124.

**Rationale:** `driver` plus capabilities on `Supplier` turns every "if manual" into a question the supplier answers, so about ten call sites change once and a new manual supplier is data. Reusing `finalizePendingDelivery()` gives locking, real cost, profit, drawdown and a concurrency test for free. Reusing the persisted notification queue and generalising the group registry avoids a second retry system and a second group table.

**Consequence to track:**

- The driver refactor touches the money path: `FinalizePendingDeliveryConcurrencyTest`, the supplier suites and the concurrency suite must stay green, and the backfill must keep `driver = slug` so hardcoded `'digiflazz'` checks still hold.
- `whatsapp_groups` replaces `reseller_whatsapp_groups` with a data migration; the reseller bot's behaviour must not change.
- Generalising `SendCustomerWhatsAppJob` to `(session, chatId)` must keep customer receipts on the customer-support session and their pacing unchanged.
- Build in two PRs: PR 1 decisions 1–10 and 17 (plus ADR-124), PR 2 decisions 11–16.
- Opening hours are not built; revisit if customers complain about night orders.

### 2026-10-17 addendum — funding mode, ledger-only suppliers in accounting, and readers that would show the estimate

A money-critical trace of supplier funding, accounting, reports and analytics (two read-only forks, top findings re-checked by hand), run while designing [ADR-126](./ADR-126-joki-mlbb.md). Production on 2026-10-17: two suppliers (Digiflazz ledger = polled IDR 1,721,624; Gamevion 0), 5 transfers, 32 drawdowns, 2 manual adjustments.

Findings that change this ADR:

- `MonthlyAccountingSummaryService::weightedAverageRate()` returns null for a supplier with no transfers; `CashPositionService::supplierPrepaid()` then values its balance at 0 and the FX true-up skips it. A pay-after supplier owed RM65 before its first payment would vanish from the cash gap.
- `recordOrderDrawdown()` runs after commit and swallows any error, relying on the drift check, which a ledger-only supplier does not have.
- `CashPositionService` sums signed supplier balances as "Supplier prepaid balance" (asset); a pay-after balance is a payable.
- `DashboardService` reads `suppliers.balance` (null → 0) and sums every supplier into the reseller-wallet coverage total.
- Revenue and COGS are scoped by `paid_at`, drawdowns by their own `created_at`: an order paid on the 31st and delivered on the 3rd moves profit across the month boundary (whole in total through MonthClose's prior adjustment, wrong per month).
- `llm_report_orders` exposes only `cost_price`; `profit_reconciled_flagged` has no list filter; `OrderStatusCard` says "Delivery to Player ID … your top-up is in your game account" for every order.

Decisions added:

18. **`suppliers.funding_mode`** (`prepaid` | `pay_after`), read only through `Supplier`. API-backed suppliers are always `prepaid` and behave exactly as today (polled balance, drift, low-balance). A manual supplier picks either in the panel (default `prepaid`; every penjoki `pay_after`), because the founder does not yet know how a login supplier will want to be paid. `displayBalance()` gives the ledger balance for a manual supplier; low-balance applies to `prepaid` only; `pay_after` shows "Owed: RM X" when negative. Labels for a transfer read "top-up" for `prepaid` and "payment" for `pay_after` (wording only; still a capital movement, not P&L).
19. **`weightedAverageRate()` returns 1.0 for an MYR supplier with no transfers**, fixing all three callers at once.
20. **Cash position splits supplier balances:** positive → `assets.supplier_prepaid_sen`, negative → `claims.supplier_payable_sen` (absolute). The gap identity is unchanged. Presentation goes to the accountant (PRD §16 item 81).
21. **Dashboard:** the reseller-wallet coverage total sums `scopeApiBacked()` suppliers only; each manual supplier shows its own line. A health item counts Delivered orders on manual suppliers with no `ORDER_DRAWDOWN` row.
22. **Month close** previews "N orders paid this month are still undelivered" (reusing `ReportService::accountingBridge()['not_recognised']`). Scoping the FX true-up by the order's `paid_at` waits for the accountant (item 81).
23. **Real cost at confirmation defaults to the order's own `cost_price` snapshot** (the agreed cost at checkout), passed as RM to `finalizePendingDelivery`, unless the admin overrides it. This restates decision 8's cost source (grill Q16).
24. **Readers:** `llm_report_orders` adds `COALESCE(real_cost_price_sen, cost_price) AS effective_cost_price`; the Orders list gets a "Profit flagged" filter; `OrderStatusCard` words its copy by the game's fulfilment kind (uid / login / joki).

Must not change: `recordOrderDrawdown`'s contract and idempotency key, `operatingProfit()`'s formula, MonthClose's prior adjustment, API suppliers' polled-balance and drift path, and `SupplierTransfer` as capital rather than P&L.

### 2026-10-17 addendum — the report view's effective cost mirrors `effectiveCostPriceSen()`

Found in the 2026-10-17 docs audit. Decision 24's `COALESCE(real_cost_price_sen, cost_price) AS effective_cost_price` is replaced. That expression is wrong in two cases: a combo keeps its real cost only on `order_delivery_legs.real_cost_price_sen` (the order's own column stays null, so the catalog cost would show), and a non-combo order's stored real cost counts only when it satisfies the profit residual (flag off or no FX rate means it was not used). `llm_report_orders.effective_cost_price` must give the same number as `Order::effectiveCostPriceSen()`: the residual check for a single order, and the sum of the legs for a combo. The same fix covers the live Report Assistant prompt (PRD §16 item 118), which today points at `cost_price`.

### 2026-10-18 addendum — ADR-124 is built first, on its own

"PR 1 … (plus ADR-124)" in the consequences is replaced: ADR-124 was built first as its own PR (PRD §16 item 112, ADR-124 2026-10-18 addendum, decision 1). PR 1 here now adds the checkout path that writes `order_credentials` from decision 6's per-game schema, snapshotting `PlatformSettings::credential_consent_text`. The sweep, reveal/replace, audit and policy settings already exist.

### 2026-10-18 addendum — pre-build stress test of PR 1: split into 1a/1b, twelve gaps closed

Before building PR 1, three read-only forks (checkout and pricing; fulfillment, ledger and compensation; downstream readers) re-traced every decision against current `staging` (which now has items 115, 121, 123, 120 and 112). The top findings were re-checked by hand, and prod was queried read-only: 2 suppliers, both API (Digiflazz IDR, Gamevion MYR); 37 of 38 games have `reseller_code`; 18 have the player validator on; no order is Pending, NeedsReview or Processing; 5 Digiflazz transfers. Founder decisions on 2026-10-18 are marked (founder).

**Still true:** decisions 5 (reseller exclusion by `reseller_code` alone), 8 (`finalizePendingDelivery` is the one confirmation seam), 9 (a manual Failed is confirmed non-delivery, so a new reference is safe), 23 (RM at rate 1.0 gives `real_cost = cost_price`, so profit is unchanged on every basis), the combo one-supplier rule, resend staying within one game, and ADR-124's sweep (a manual Failed keeps its login; voucher or Delivered closes it).

**25. PR 1 is split (founder).**
- **1a:** driver and capabilities on `Supplier`, and every reader. No customer-facing change.
- **1b:** login games, manual packages, checkout, Manual Queue, customer copy.

Both funding modes ship (founder): decision 18 stands, with the accounting fixes in decision 30.

**26. Driver and capability readers (1a).** The slug-keyed logic is wider than decision 1 listed: about 20 sites, including `resubmitReplaysOutcome`, `SupplierConfigSchema`, the circuit-breaker name, `supplierApiConfig`, the webhook, and the sandbox binding `supplier-adapter.{slug}`.
- One rule keeps them correct: `CreateSupplierRequest` requires `slug === driver` for an API driver. Only `driver = manual` takes a free slug.
- Every capability, schema and binding lookup keys on driver. `SupplierAdapterFactory::for(Supplier)` passes the row, so one `manual` binding serves every manual supplier.
- `Supplier::pollsStatus()` replaces the slug list in `resubmitReplaysOutcome`.

**27. Every API-only path asks the supplier (1a).**
- `scopeApiBacked()` is used by four `filled(api_config)` readers, not three: the price-sync job, the schedule gate, item 106's `CheckPriceSyncStalenessCommand` (otherwise a stale-sync email every run), and product sync (which today has no filter).
- `RefreshSupplierBalancesCommand` selects `scopeApiBacked()`. Its `checkBalance()` call sits outside the try, and the task is critical, so one manual supplier would fail `/api/health`.
- `SupplierDeliveryCheckService::check()` refuses a supplier without `pollsStatus()`. This one seam covers the admin "Check supplier" button and the reconciler's `CheckSupplierDeliveryJob`.
- `SupplierController::refreshBalance` refuses a non-API supplier.
- Balance shown anywhere (Middleware Suppliers, Balance page, Dashboard health) reads `displayBalance()`.
- The admin create form chooses a driver, not a slug.

**28. Mark Delivered on a manual NeedsReview order goes through `finalizePendingDelivery` (1b).** `markDeliveredManually` skips real cost, the reconcile and the drawdown. A manual order reaches NeedsReview when a worker dies at Processing or `createOrder` throws. Without this, "Owed" and COGS would be wrong. Prod has never used `manual_confirm`.

**29. `recordOrderDrawdown` skips an `is_test` order (1a), one guard at the seam.** Sandbox orders are also kept out of the Manual Queue (1b).

**30. Accounting for an MYR supplier (1a).**
- `weightedAverageRate()` returns 1.0 for **every** MYR supplier, not only one with no transfers. `FundedCostOfSales::rates()` drops its own MYR special case and calls it.
- Whether a transfer fee belongs in unit cost stays with the accountant (PRD §16 item 81). This keeps today's COGS behaviour: fee excluded for MYR.
- Decision 20 splits each supplier's balance by sign before summing. Stored month-close `equation` JSON reads the new `claims.supplier_payable_sen` with a default of 0.
- Decision 21's health item (Delivered manual orders with no `ORDER_DRAWDOWN`) ships in 1a, because a manual supplier has no drift check.

**31. The login identifier is masked on every public surface (1b, founder).** Track Order, Reverb `OrderStatusUpdated` and the WhatsApp `OrderStatusCard` return the raw `player_id` to anyone with the order number. For a login game that is half the login, which breaks ADR-065.
- One `Order::publicPlayerLabel()`: `ContactMask::email` for an email identifier, a partial mask otherwise. The label reads "Login".
- `OrderStatusCard` words its copy by fulfilment kind (decision 24). For a login order it carries ADR-124 decision 9's "wiped within G hours, change your password".

**32. Checkout for a login game (1b).**
- The server sets `player_id` from the schema's identifier field before validation and the blacklist, and refuses a client `player_id`/`server_id`. Otherwise a clean `player_id` beside a banned username passes the blacklist.
- `CheckoutInputValidator::validate()` branches on `isLoginGame()`, which covers checkout, the resend ID edit and the REST API once.
- Player validation is skipped for a login game.
- The order and its `order_credentials` row are created in one transaction.

**33. Creating a login game and its schema (1b).** Games are created today only by `SupplierProductController::linkCategory`, which needs a supplier category. Its `validation_rules` is a free array that would let `credential_fields` through unchecked.
- A game create plus schema write endpoint is added.
- `linkCategory` becomes a fifth `GameFulfilmentRule` path: it strips `credential_fields` and refuses a login game.
- The rule also refuses the player validator and `delivery_mode = instant` on a login game.

**34. Manual cost edit is audited (1b).** `PackagePriceSyncService`'s private `propagatePrice` is extracted. The manual edit calls it (writing a `PriceChangeLog` with a null run), then `ComboPricingService::recomputeForComponentChange`. The admin-entered real cost at confirmation is bounded (`> 0`, ≤ `selling_price` unless a reason is given), and `confirmed_by` is stored in the supplier response.

**35. Member pricing gate (1b):**
- `CheckoutService` is the one gate for price and quota.
- `CatalogController::publicPackage` reads it.
- `MembershipPricingService::representativePackage` excludes games with it off.
- `resolveMemberEmail` stays separate.

**Can wait (no wrong money total):** decision 24's "Profit flagged" filter and `llm_report_orders.effective_cost_price`, and decision 22's month-close preview. PR 2 note: a resend from NeedsReview reuses the reference, so the post's (order, reference) key would not re-post.

### 2026-10-18 addendum — PR 1b pre-build trace: fourteen corrections, founder answers

Before building 1b, three read-only forks (catalog and checkout; fulfillment and ledger; readers and copy) re-traced decisions 4–9, 17, 23, 28, 31–35 against `staging` with 1a built. The top findings were re-checked by hand. Founder answers on 2026-10-18 are marked (founder).

**Corrections to earlier decisions:**

36. **Real cost at confirmation (replaces decision 34's bound, founder).** It defaults to the order's `cost_price`. Any value different from `cost_price` needs a reason. "≤ `selling_price`" was the wrong bound: `reconcileRealCostProfit` gives `selling − real − affiliate_profit`, so a loss started below it. The reason and `confirmed_by` are stored in the supplier response.
37. **Member gate seam (corrects decision 35).** `CheckoutService::previewTotal()` takes no game, so the service can't be the gate. `CheckoutController` passes no membership id to the preview when `Game::memberPricingApplies()` is false, and passes `memberPricingApplies` to `initiate`, which prices with no membership (no member price, no quota) but still binds the order to the member's email from the membership id (decision 35's `resolveMemberEmail` stays separate; found in the build's self-review). `GameController::update` also flushes the package cache, because `catalog.packages.game.{id}` holds `member_price_sen`.
38. **A login order is known by its credential row, not its game (corrects decision 31).** `orders.game_id` has no foreign key, and a schema can be removed. `Order::publicPlayerLabel()` masks whenever the order has an `order_credentials` row. `ContactMask::email()` returns a non-email unchanged, so the label masks a non-email itself, every character when it is 4 or fewer.
39. **The affiliate sees the masked identifier (founder).** `Affiliate/OrderController` returns `publicPlayerLabel()` for a login order.
40. **No secret in `payloadHash`.** It is stored unsalted. It hashes the non-secret credential fields only. The storefront makes a new idempotency key each time Review opens, so a password-only change can't replay an old order.
41. **Order and credentials in one transaction, inside `initiate`.** A full-voucher order turns Paid in `settleWithVoucher` and dispatches fulfilment at once, so the credential row is written with the order INSERT (through `OrderDraft`), before any gateway call, and the gateway call stays outside the transaction.
42. **Supervised Failed → Delivered for a manual order (founder).** An admin's wrong Failed is otherwise final: `contradictsFinalStatus` keeps a later Delivered as evidence only, and Retry means a second top-up paid twice. The correction is allowed only on a manual supplier, with the order locked, still Failed, and not `compensated` (no voucher issued). It needs a reason and runs decision 36's real-cost path, `creditProfit`, voucher commit and the drawdown. Failed writes no ledger, voucher or drawdown entry (a reserved redemption is restored only by Issue Voucher), so nothing has to be reversed. Every confirmation writes an attempt row with `triggered_by`, which decision 28's NeedsReview path otherwise lost.
43. **Resend may correct the identifier (founder).** `OrderResendService` updates `player_id` and `order_credentials.fields` together, with an audit row. Before, `fields` kept the old identifier.
44. **A wiped login blocks the queue's re-post.** `credential_max_days` wipes regardless of status. The Manual Queue and Retry show "login wiped, ask the customer to replace" and Retry refuses until it is replaced.
45. **One price-propagation seam with an author.** `price_change_logs` gains a nullable `admin_user_id`. `PendingPriceChangeController::approve`'s hand-written copy routes through the extracted `propagatePrice`. A change with no sync run is shown on the package's price history.
46. **The schema endpoint holds the kind switch (corrects decision 5).** `UpdateGameRequest` never takes `validation_rules`. The new schema endpoint refuses a switch while packages of the other kind exist (inactive ones included). For a login game it replaces `validation_rules` whole, dropping `extra_field`, `zone_options` and `player_id_format`, and it lowercases an email identifier before it becomes `player_id`. `GameFulfilmentRule` also requires a `delivery_subtext` on a login game, because `effectiveDeliverySubtext()` otherwise falls back to "1–3 minutes".
47. **Copy (extends decisions 17 and 31).** The "Manual Processing" badge already exists (`delivery_mode`). For a login order, the storefront tracker, `GameFactLine`, `Step1AccountInfo`, the summary sidebar, `ReviewModal` and admin labels read the schema's identifier label, not "Player ID". They don't promise minutes, and the tracker's Delivered banner carries the change-your-password line (founder, default), not WhatsApp only. The Check-from-Supplier button is hidden for a supplier without `pollsStatus()`.

**Placement:** game create and the credential schema live under `/admin/games` (catalog; the schema is the customer's form, not supplier config).

### 2026-10-18 addendum — PR 1b as built (1b-i backend #509, 1b-ii screens #510)

PR 1b shipped in two PRs, backend first, so the money review stayed small. What the build added to or settled within the decisions above (no decision reversed):

- Decision 37 as built: the preview gets no membership id when the game's member pricing is off; `initiate` takes `memberPricingApplies` and prices as a guest while still binding the member's email (decision 35). The first build nulled the id for both and lost the binding; caught in self-review before the PR.
- Decision 32: `credentials.*` skips `TrimStrings`, so a password is stored as typed; non-secret fields are trimmed by `CheckoutInputValidator`. The admin's credential replace (ADR-124) skips it too.
- Decision 42's correction re-opens the order to NeedsReview under the order lock, then calls `finalizePendingDelivery()`; it races Issue Voucher and itself on that lock (`ManualCorrectionConcurrencyTest`). Every manual confirmation writes a `manual_confirm` attempt row (existing type, so the admin delivery log shows it unchanged).
- Decision 43's audit is a `credential_reveals` row with `action = identifier`, written by the admin request after a pre-check; the job re-checks under the lock.
- An admin (not only super_admin) adds manual packages, so `GET /manual-suppliers` lists active manual suppliers' names; the full supplier list stays super_admin middleware config.
- ADR-124 decision 8: the login game's public detail carries `credential_consent_text`, so the consent box shows the text the order snapshots.
- Placement: login game create and its form live in `/admin/games`; the queue is `/admin/manual-queue`, any admin (Q10).

### 2026-10-19 addendum — PR 2 pre-build trace: split 2a/2b, short supplier link, thirteen corrections

Before building PR 2, decisions 11–16 were traced against `staging` (PR 1 built) and prod was queried read-only: 2 reseller groups (both active), 0 pending links, 14 customer notifications, 0 manual suppliers (PR 1 is not released). Founder answers on 2026-10-19 are marked (founder).

**Still true:** decisions 11 (one registry, routed by owner, bot session only), 12 (cost never comes from the group), 14 (commands act only on the supplier's own Pending orders), 15 (SLA reminder from the reconciler scan) and 16. `.done` and `.gagal` call `ManualDeliveryService::confirmDelivered()` / `confirmFailed()`, so the cost is the agreed `cost_price` (decision 36's default) and a WhatsApp reply can never set another. The SLA already exists as `reply_sla_minutes` and `Order::isStalePending()` (PR 1a).

**48. The (order, reference) post key is correct; the PR 1 note's worry does not hold.** A manual order reaches NeedsReview only when the worker dies before Pending commits (`retryStuckProcessing`) or a late Paid is flagged. `checkStalePending` and the max-age scan skip manual suppliers, and `flagStaleDuplicateReferences` reads a Gamevion-only code. So a manual NeedsReview order was never posted, and a resend from it (same reference, any package or manual supplier) posts for the first time. A retry or resend from Failed mints a new reference and posts again, labelled RETRY when an earlier post exists for the order. A test holds this.

**49. The post is queued from `OrderFulfillmentService::fulfill()` after the transaction that commits Pending on a manual supplier**, not from an `OrderStatusUpdated` listener (that stream fires on every status write). Skipped for an `is_test` order.

**50. A short supplier link with a token table (founder; revises ADR-124's 2026-10-17 addendum, point 1).** A signed URL is long and reads as noise to an outside supplier. The link is `https://<api host>/s/<12 random base62 chars>` (~71 bits). `supplier_login_links` stores only the SHA-256 of the token, with `order_id`, `supplier_id`, `reference_number` and `expires_at` (the D ceiling). A DB dump or backup therefore holds no working link. Expired rows are pruned daily. GET and POST keep ADR-124's shape (summary + "Show login"; secrets only from the POST, `noindex`, `no-store`, `no-referrer`, rate-limited per link and per IP).

**51. The link works only while the order is Pending on that supplier, with that reference, and the login is not wiped (tightens ADR-124's "still open").** After `.done` or `.gagal`, or a retry to another attempt, the old link is dead without a revoke step. Each POST writes one `credential_reveals` row per secret field, `actor_type = supplier`.

**52. The link is minted at send time, never stored in a message.** The stored message carries a placeholder; `SendCustomerWhatsAppJob` mints the token and fills it in just before sending. A send whose ack was lost and is retried mints a second link for the same attempt; both die together under decision 51.

**53. `customer_notifications` gains `session` (`customer_support` | `bot`, default `customer_support`) and `chat_id`.** A supplier row has `chat_id` (the group) and no `phone`. Skip reasons are per session: a customer row keeps today's (the WhatsApp-notifications switch, the customer-support session, a usable phone); a bot row is skipped only when the bot session isn't configured or the supplier has no active group. The customer switch never stops a supplier post. The send slot is keyed per session, so customer pacing is unchanged. Order Detail's notification list labels a bot row "Supplier group".

**54. Supplier posts and reminders use the persisted queue, not `SendResellerBotReplyJob`.** That job counts an OpenWA pacing refusal as a failure (3 tries, 5 s / 20 s) and drops the message. Command replies stay on it, like the reseller bot's.

**55. A group has one owner, and a supplier has one active group.** `link()` today is `updateOrCreate` on the group id, so linking a group already owned by someone else would move it silently (a reseller group could start receiving customer logins). Linking refuses a group owned by a different owner, and refuses a second active group for a supplier.

**56. An active manual supplier keeps its group (decision 12, one seam).** Activation without an active group is refused, and so is unlinking the last group of an active manual supplier. Both the Middleware supplier screen and the new Partners screen go through the same check. A manual supplier activated before PR 2 has no group: its post is skipped with a reason and counted on the Dashboard.

**57. The router drops the bot's own messages and reads the sender.** `OpenWaWebhookController` ignores `fromMe` (the bot's own post must never be parsed, and this also covers the reseller bot). The sender is OpenWA's `senderPhone`, else the digits of `author` when it is `@c.us`, else the raw `author`, recorded as `confirmed_by = wa:<sender>` with `channel = supplier_bot` (checked against OpenWA's `message-responses.dto.ts` on the box, 2026-10-19).

**58. Command rules.** `.done PG-…` / `.gagal PG-… <reason>` act only on a Pending order of the group's supplier (NeedsReview was never posted, decision 48). `.gagal` without a reason is refused. A `.done` on a Failed order replies "contact admin" (the correction is admin-only, decision 42). `.pending` lists order number, game, package and age, never a login field. One rate limit per supplier, as the reseller bot has.

**59. The SLA reminder** is one bot notification per (order, reference), dedupe-keyed, from the 15-minute reconciler run, using `isStalePending()`. A retry gets its own reminder.

**60. Placement (founder).** A new Admin → Partners → Suppliers page, super_admin only: manual suppliers, their group (link from the shared pending list, unlink), reply SLA, active switch. Creating a manual supplier and its funding mode stay in Middleware → Suppliers, and API suppliers stay there entirely. The pending-link list moves to a shared `/whatsapp-groups/pending`. The Dashboard counts failed supplier posts and active manual suppliers with no group.

**61. Build in two PRs (founder).** **2a, backend:** `whatsapp_groups` (data migration of the 2 reseller rows), the router and `SupplierBotService`, decisions 48–59, the group-link API. **2b, screens:** Partners → Suppliers, the pending-link picker for both owners, the Order Detail label, the Dashboard counts, e2e.

### 2026-10-19 addendum — PR 2a as built (backend)

Built as decisions 48–61 describe. What the build added or settled (no decision reversed):

**62. Linking or reactivating a supplier's group re-posts its waiting orders.** A post skipped because the supplier had no group would otherwise wait for the SLA reminder, which carries no login link. `SupplierGroupNotifier::repostWaiting()` runs `orderPosted()` for each Pending order. The outbox revives only a skipped row, so a post already sent or queued is never repeated. RETRY is decided against an earlier attempt's post, not this attempt's own skipped row.

**63. `SecurityHeaders` keeps a route's own `Referrer-Policy`.** It runs after every controller and set `strict-origin-when-cross-origin` unconditionally, which would have overwritten the link page's `no-referrer`. It now sets the default only when the response has none.

- One outbox: `WhatsAppOutbox` holds the create/dedupe/revive and the per-session slot. `CustomerNotificationService` (customer rules) and `SupplierGroupNotifier` (supplier rules) both call it. The customer-support slot keeps its old cache key, so its pacing state carries over.
- `SendCustomerWhatsAppJob` asks `SupplierGroupNotifier::prepare()` for a bot row. It returns null for an attempt that is no longer waiting (the row is marked skipped), and otherwise mints the link. A pacing release re-runs `prepare()`, so one attempt can mint more than one link; they all die together (decision 51).
- The link page shows the non-secret login fields (username) with the order summary, before and after the reveal. The secrets come only from the POST. Pruned by `model:prune` daily (`supplier-login-link-pruning`, routine).
- The Partners → Suppliers API is `/api/partner-suppliers` (list with pending count, link, group status, SLA 5–1440 minutes, active switch). The shared pending list is `/api/whatsapp-groups/pending`. Deleting a never-used supplier deletes its groups.
- Prod data: the 2 reseller groups move with their ids. A test runs the migration down and up against seeded rows.

### 2026-10-19 addendum — PR 2b: a manual supplier's switch takes its packages off sale (decision 64), screens as built

**64. Switching a manual supplier off takes its packages off sale, and switching it on restores exactly those (found while building 2b).** A supplier's `is_active` gates nothing at checkout: only sync, balance and staleness jobs read it, and catalog and checkout check `game.is_active` and `package.is_active` only. So decision 56's "an active manual supplier keeps its group" guarded a flag that no customer path reads. An inactive manual supplier's packages could still be bought, and each order would sit Pending with a skipped post. The fix goes through ADR-046's existing seam rather than adding a supplier check to every package reader. The per-supplier bulk package switch (`deactivated_reason = supplier_issue`, reactivate restores only those) moves out of `SupplierController` into `SupplierPackageStatusService`. `ManualSupplierStatusService::setActive()` is the one path for both Middleware → Suppliers and Partners → Suppliers. It checks the group when switching on, then sets the flag and switches the packages in one transaction. A package Price Sync turned off for another reason stays off. An API supplier's flag keeps its old meaning (ADR-031 decision 4). A new manual supplier starts inactive and the manual-package form lists only active ones, so the order is: create, link group, activate, add packages.

As built (2b):
- Admin → Partners → Suppliers (`/admin/suppliers`, super_admin): manual suppliers with group, reply SLA, waiting count and status. "WhatsApp Group" opens the shared `WhatsAppGroupLinker`, the same component the reseller modal now uses. "Edit SLA" takes 5–1440 minutes. "Activate" is disabled until a group is linked. "Deactivate" warns that the packages go off sale.
- Order Detail labels a bot row "Supplier group" with its group id ("Order post" / "SLA reminder").
- Dashboard: red "N orders not posted to the supplier" counts waiting orders whose current attempt never reached the group (a sent post of an earlier attempt doesn't count); amber "N active suppliers with no WhatsApp group".
- e2e `supplier-group-bot.spec.ts` (ADR-023 2026-10-19 addendum).

### 2026-10-23 addendum — changes made by ADR-126's 2026-10-23 addendum

ADR-126's 2026-10-23 addendum revises four rules here, for every manual order, not only joki. The supplier post's dedupe key gains the supplier (its decision 17). `confirmDelivered`/`confirmFailed` re-check the supplier under the lock (decision 18). Staleness reads a new `orders.manual_activity_at` (the post time for Login Top-up, as today), and the SLA maximum becomes 3 days (decision 20). A pool supplier flag exempts the joki pool from decision 56 and from the group posts (decision 15). Detail is in ADR-126.

### 2026-10-30 addendum — two numbers here are out of date

The "Owned Brand" tier is **15%** in production (it appears to have been edited from 10% on 2026-10-30), and member Tier 2 gives **70%** off the markup, not 80% (set 2026-10-28). Neither changes the reasoning above, which only needs "at 15% markup a Tier 2 member keeps 4.5%, not 3%". [ADR-061](./ADR-061-every-storefront-is-a-reseller.md)'s 2026-10-30 addendum also means an owned brand no longer prices through that tier at all: it prices as the platform.
