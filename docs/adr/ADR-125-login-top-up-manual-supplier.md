# ADR-125: Login Top-up (Roblox, eFootball) — a Manual supplier behind the existing `SupplierAdapter` seam, a Manual Queue, admin-entered packages and real cost at delivery

> **Standing (2026-10-17):** Proposed (design, grill pending). Build and live status: [`prd.md` §15](../prd.md). The text below is a dated record; a later addendum in this file overrides earlier text, including the **Status** line.

**Status:** Proposed, 2026-10-17. Shape agreed with the founder in chat; the grill and the money-critical code-trace pass (AGENTS.md lifecycle step 2) are still to run. Depends on [ADR-124](./ADR-124-customer-account-credentials.md). [ADR-126](./ADR-126-joki-mlbb.md) (Joki) builds on this ADR's Manual Queue.

**Context:**

- The founder wants to sell games that some suppliers top up **via the customer's login**, starting with Roblox and eFootball, on PekanGame **and Affiliate** storefronts (not Reseller). There is no supplier API: the founder enters the packages and does each top-up by hand at an external supplier, paid outside the system. Every business decision must be settable from a panel (founder, 2026-10-17).
- Survey of the code on 2026-10-17 (three forks, top findings re-checked by hand):
  - **Packages come only from sync today.** `SupplierProductController::promote` is the only plain-package creator, and it needs a synced `SupplierProduct`. `packages.supplier_id` is nullable (combos). Price Sync and the balance refresh skip a supplier whose `api_config` is empty (`SyncSupplierPricesJob`, `RefreshSupplierBalancesCommand`), so a manual supplier is safe from sync **while its `api_config` stays empty**.
  - **A Pending order is escalated to NeedsReview after `pending_max_hours` = 2 h** (`config/services.php:413`, `ReconcilePendingDeliveriesCommand::checkStalePending`), polled every `pending_stale_minutes` (10). The Dashboard stale-pending banner uses the same global 10 minutes. `retryStuckProcessing` re-dispatches a Processing order with no `supplier_ref` after 15 minutes.
  - **`markDeliveredManually()` works only from NeedsReview**, writes no `real_cost_price_sen`, makes no supplier drawdown, and credits profit from the checkout estimate (`OrderFulfillmentService.php:1392-1440`). ADR-111's real-cost recompute (`reconcileRealCostProfit`) is gated by `REAL_COST_RECONCILIATION_ENABLED` (default `false`, `config/services.php:120`).
  - Checkout knows only `player_id`/`server_id` (`CheckoutInputValidator`), requires a recent valid player check where validation is on, and blacklists by `player_id`.
  - Store-credit refund (`OrderSettlementService::settle`) runs only from Failed / PartiallyDelivered and refunds `final_amount − transaction_fee` (founder confirmed 2026-10-17: the gateway fee stays non-refundable, one policy for every order).
  - Money paid to an external supplier outside the system appears in no `SupplierTransfer`, so cash position would be overstated.

**Decision:**

1. **One `Supplier` row with slug `manual` and a `ManualSupplierAdapter`** bound as `supplier-adapter.manual`, behind the existing seam (no interface change). `createOrder` returns `Pending` with a `MANUAL-<order id>` reference; `checkStatus` always returns Pending; `checkBalance`/price list are no-ops.
2. **Manual orders are never auto-escalated or re-dispatched.** `checkStalePending`, `retryStuckProcessing`, the admin "Check from Supplier" button and the Dashboard stale count skip orders whose supplier is `manual`. This is one shared predicate (`Supplier::isManual()`), not an `api_config` `pending_max_hours = 720` workaround. A guard refuses a non-empty `api_config` on the manual supplier, so it can never enter Price Sync.
3. **Manual packages are created and edited in admin** (new endpoint + FormRequest): game, name, **estimated cost** (`cost_price`, `> 0` floor per ADR-025), markup. Selling price and every pricing basis (Standard, Member, Affiliate tier) come from `cost_price` exactly as for synced packages. Not sold on the Reseller channels.
4. **Per-game credential fields**, a schema on `Game` (e.g. `[{key, label, type: text|email|password|select, options?, required, secret}]`) set in admin. Checkout validates against it. Non-secret fields fill `player_id` (ADR-124 decision 3), secret fields go to `order_credentials`. Player validation is off for these games.
5. **The Manual Queue** in admin lists Pending orders on the manual supplier, oldest first, with two actions:
   - **Delivered:** the admin enters the **real cost** (required) and an optional reference. The order goes Pending → Delivered, `real_cost_price_sen` is written and profit is recomputed with ADR-111's formula `selling − real_cost − affiliate_profit` **regardless of `REAL_COST_RECONCILIATION_ENABLED`**, then the existing `creditProfit` + voucher commit + delivered notification run. Credentials are wiped (ADR-124 decision 6).
   - **Failed:** Pending → Failed, then the existing store-credit settle (`final_amount − transaction_fee`), credentials wiped.
6. **External-supplier spend is recorded** as a `SupplierTransfer` against the manual supplier when the founder tops up the external account, so cash position stays true. Exact shape decided at the code-trace pass.
7. **Panel settings:** the manual supplier (middleware), manual packages and credential schemas per game (admin), member-quota eligibility per game (admin, founder 2026-10-17), and the credential sweep window (ADR-124).

**Rationale:** extending an implementation behind `SupplierAdapter` keeps checkout, ledger, vouchers, reports, affiliate earnings and Track Order unchanged. A dedicated Manual Queue with an explicit real-cost step is the only way profit can be true when the cost is known only at delivery.

**Consequence to track:**

- Every place that branches on supplier slug or polls Pending orders must use `Supplier::isManual()`. Grep `checkStatus(` and `pending_stale_minutes` at build time.
- The Manual Queue's Delivered action must sit beside, not replace, ADR-026's NeedsReview "Mark Delivered".
- Customer copy: the delivery badge uses the existing `delivery_mode = manual` (ADR-109) with a subtext set per game.

**Open for the grill (and the code-trace pass):**

- List every writer and reader of `cost_price`, `real_cost_price_sen`, `platform_profit`, `affiliate_profit` and confirm each handles a manual order (resend ADR-105, combo residual ADR-107, real cost ADR-111). Should resend be blocked for manual orders?
- Affiliate tier pricing on an estimated cost: if the real cost comes in higher, the residual hits Platform, not the Affiliate. Is that the rule?
- Opening hours: should a manual game show "closed" outside the founder's working hours?
- How the founder records a top-up of the external supplier account (one `SupplierTransfer` per top-up of their balance vs per order).
