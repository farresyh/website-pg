# ADR-125: Login Top-up (Roblox, eFootball) — manual suppliers as a `driver` behind `SupplierAdapter`, one finalize seam for every confirmation, a supplier WhatsApp group per supplier, admin-entered packages at an agreed cost

> **Standing (2026-10-17):** Accepted (design). Build and live status: [`prd.md` §15](../prd.md). The text below is a dated record; a later addendum in this file overrides earlier text, including the **Status** line.

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
