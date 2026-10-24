# ADR-127: Financial truth from dated facts — revenue at recognition, closed months that cannot move, and a scenario builder that proves every page agrees

> **Standing (2026-10-24):** Accepted (design). Revises [ADR-083](./ADR-083-internal-accounting-financial-reconciliation.md) (revenue month, closed-month drift), [ADR-111](./ADR-111-real-cost-profit-reconciliation-at-delivery-time.md) decision 8 (the feature flag is removed) and [ADR-126](./ADR-126-joki-mlbb.md) decisions 11/13 (where penjoki partial and extra pay is classified). Build and live status: [`prd.md` §15](../prd.md). The text below is a dated record; a later addendum in this file overrides earlier text, including the **Status** line.

**Status:** Accepted (design), 2026-10-24. Grilled with the founder (`/mattpocock-skills:grilling`, Q1–Q13, two rounds; the founder delegated Q9–Q13 to the recommended answers), then stress-tested against the code before writing (below). Money-critical trace done in three read-only forks (checkout and pricing; ledger, fulfillment and compensation; downstream readers), with every top finding re-checked by hand and against production read-only. Expands PRD §16 item 131 at the founder's request (2026-10-24) from "revenue by delivery date" to the whole money-reporting surface. Gates the Joki launch (item 114).

## Context

- The platform now earns through six channels: storefront Standard and Member, Affiliate whitelabel, Reseller (portal top-up, REST API, WhatsApp bot), Login Top-up and Joki. All of them feed the same Accounting pages (Monthly Summary, Transaction Register, Settlements, Envelopes, Suppliers), Reports, Report Assistant, Customer Analytics, the Dashboard and the partner portals.
- Production is too small to exercise most of these paths (read-only, 2026-10-24): 46 orders (31 storefront, 15 reseller bot), 32 Delivered, 9 Failed, 99 ledger rows, 0 withdrawals, 0 joki, 1 login order, 0 orders paid in one month and delivered in another. One month is closed (September 2026, close #3; #1 and #2 reopened).
- **Root cause found by the trace: past periods are computed from current state, not from dated facts.**
  - S1. `RecognisedRevenue` files revenue by `paid_at` but includes an order only once it is Delivered *today* (`RecognisedRevenue.php:27,75-79`); the undelivered-orders claim uses `delivered_at` (`CashPositionService.php:195-212`). An order paid 30 Sep and delivered 2 Oct falls out of every month's Bukku lines and is double-counted in September's recomputed position. Every multi-day path hits it: joki, manual and login orders, a late API delivery, a retry, a late partial settlement.
  - S2. The Transaction Register dates an order row by `paid_at` but reads its cost and profit as they are today (`TransactionRegisterService.php:145-169`).
  - S3. Wallet top-up and membership CHIP-unsettled lines are dated by the mutable `updated_at` (`SettlementReconciliationService.php:350-360`); a restored voucher redemption is dated by `updated_at` (`CashPositionService.php:183,209`).
  - S7. Voucher breakage for a past month reads today's `remaining`.
  - S9. A supplier-transfer correction can move `transferred_on` and the amount into a closed month (`SupplierFundingService.php:238-248`), changing that month's funded rate and COGS.
- Other findings from the trace, all re-checked:
  - `REAL_COST_RECONCILIATION_ENABLED` is `true` in production but defaults to `false` (`config/services.php:120`), so every test and seeder runs the profit logic production doesn't.
  - `markDeliveredManually` (`OrderFulfillmentService.php:1414`) records no supplier drawdown; the drawdown dedupe is an app-level `exists()` with no unique index. Production: 0 manual confirms, 0 duplicate supplier-ledger references.
  - A Paid-after-Failed order whose voucher was already restored can be marked Delivered by an admin: the customer keeps the voucher and the goods.
  - Penjoki partial and extra pay is a Budget Envelope operating expense (ADR-126 decisions 11/13), so Reports' joki margin is overstated.
  - Pages disagree on "profit" (funded COGS on the Summary, market-rate cost on Reports and Customer Analytics) and on "sales" (net of wallet refunds or not, created date or paid date). Customer Analytics' "revenue" is `final_amount − cash compensation`, which includes the customer fee.
  - Membership revenue is read from `membership_fee_records` on the Summary and from `ledger_entries` elsewhere. Report Assistant's `llm_report_orders` has no recognition date or channel.
  - The `is_test` filter is missing in `failedOrderCreditIssued`, the Register's voucher rows and `outstandingStoreCredit` (0 test orders in production).
  - Joki in NeedsReview is counted in both the Dashboard's needs-review and the Joki card's stale count.
- Test infrastructure cannot produce realistic money data: `FakePaymentGateway::getPayment` returns no amount, `FakeSupplierAdapter` returns `price: null`, `ReportDemoSeeder` writes orders and ledger rows directly, and `ReportServiceTest::order()` breaks the price identity (900 + 100 + 20 ≠ 1000).
- Facts that are not recorded at all today: CHIP's `payment.paid_on` and `payment.fee_amount` ([CHIP Purchase object](https://docs.chip-in.asia/chip-collect/api-reference/purchases/retrieve)) are received and dropped; `paid_at` is our processing time (`OrderPaymentOutcomeService.php:73,87`); `wallet_topup_attempts` and `membership_checkout_attempts` have no `paid_at`; a voucher restore has no date of its own.
- Standards: MPERS (2025), issued by MASB in October 2025 for periods beginning on or after 1 January 2027, rewrites Section 23 as "Revenue from Contracts with Customers" on IFRS 15's five-step model ([MASB](https://www.masb.org.my/press_list.php?id=490), [IFRS Foundation](https://www.ifrs.org/news-and-events/news/2025/01/january-2025-ifrs-for-smes-accounting-standard-update/)). MFRS 15 is IFRS 15. Designing to IFRS 15 now avoids a second change in 2027.

## Decision

**P1 — the governing rule. A figure for any past period is computed only from dated facts that never change after they are written.** A reader never derives a past month from an order's current status, a voucher's current `remaining`, a row's `updated_at`, or a rate that a later entry can move. Closed months then cannot drift, because nothing they read can move.

1. **Revenue, COGS and affiliate commission are recognised on the recognition date (Malaysia month), for every channel (Q1).** Before it, money received is a contract liability (IFRS 15.106). After it, a later compensation lowers revenue in the month it happens, never the recognition month.
2. **`orders.recognised_at` is the recognition date (Q9).** Set once, only when null, inside `LedgerService::creditOrderProfit()`: the one seam both recognition paths already route through (delivery via `OrderFulfillmentService::creditProfit()`, partial settlement via `OrderSettlementService`), each already holding the order lock. Indexed. Backfilled from `delivered_at`. `RecognisedRevenue` filters on it; `FundedCostOfSales` already takes its units from `RecognisedRevenue` (`FundedCostOfSales.php:29`), so COGS follows with no change of its own. A cost drawn down before recognition (a combo leg delivered before settlement) is a cost to fulfil a contract (IFRS 15.95) until the order is recognised.
3. **A closed month cannot change (Q2).** Later events land in the month they occur. The only way to change a closed month is the existing Reopen (latest close only, with a reason, audited). The live recompute of a closed month must equal its stored snapshot; ADR-083's drift carry-forward stays as a safety net, and any non-zero drift is an alert, not a normal state.
4. **One guard, `AccountingPeriod::assertOpen($date)`, on every writer that takes a user-entered accounting date (Q10):** supplier transfers and their corrections (old and new `transferred_on`), envelope postings, manual supplier adjustments. A test lists every such writer so a new one cannot skip it. **One exception:** a CHIP settlement file is accepted after its month closes; its effect lands in the current month and the closed snapshot is unchanged.
5. **Record the missing facts (F1–F5).**
   - F1. `paid_at` comes from CHIP's `payment.paid_on` when present (orders, wallet top-ups, membership), else from our processing time. Past rows keep their value.
   - F2. Store CHIP's `payment.fee_amount` on payment; the settlement file confirms it and the difference is shown.
   - F3. `recognised_at` (decision 2).
   - F4. `voucher_redemptions.restored_at`, written once with the restore.
   - F5. `wallet_topup_attempts.paid_at` and `membership_checkout_attempts.paid_at`, from F1; readers move off `updated_at`.
   - Compensation time is derived from immutable facts (voucher `created_at`, the `wallet_refund` ledger row, `restored_at`) by one method, `Order::compensatedAt()`. No new column.
   - F6. Supplier-transfer corrections into a closed month are refused by decision 4.
6. **Joki is recognised at a point in time, on delivery (Q3).** IFRS 15.35(a) could support over-time recognition (the stars stay on the customer's account), but ADR-126 decision 11 refunds in full on failure, so there is no enforceable right to payment for partial work (15.35(c)) and the promise is "reach the target rank". The judgement is recorded here for the accountant to confirm.
7. **Membership fees stay recognised in full when paid (Q4).** IFRS 15 would spread a 30-day service over its days; at RM6.90/RM19.90 per member the effect is a fraction of one fee moving across a month end, immaterial. Revisit trigger: membership revenue above 5% of a month's revenue. One source: the `membership_fee` ledger rows (the Summary stops reading `membership_fee_records` for the amount).
8. **Penjoki partial and extra pay is cost of sales, not an operating expense (Q5, Q11).** It stays a Budget Envelope posting (dated, reversible by a posting, never edited), but its categories ("Joki – partial work", "Joki – extra pay") sit in the cost-of-sales block of the Monthly Summary, and `reference_no` must be the order's reference (validated), so Reports and the Joki margin include it. Pay for a failed attempt is an expense in the month paid (IFRS 15.98(c)) on its own line, "Failed joki cost"; it never joins the COGS of a later successful attempt.
9. **Two named profit figures and two named money-in figures, everywhere (Q6).**
   - **Profit (accounts)**: funded COGS, the Monthly Summary's basis.
   - **Profit (market rate)**: ADR-111's management figure, used by Reports and Customer Analytics.
   - A Bridge explains the difference, and the unexplained part must be 0.
   - **Sales**: by paid date, the cash view.
   - **Revenue**: by recognition date.
   - Customer Analytics' figure is renamed **Customer spend**.
   - Every page labels which one it shows.
10. **Remove `REAL_COST_RECONCILIATION_ENABLED` (Q7).** The real-cost path is the only path, so tests run what production runs.
11. **Money-flow fixes, each at its shared seam, not at the line named:**
    - `markDeliveredManually` records its drawdown through the same seam as `finalizePendingDelivery`.
    - A DB unique index backs the drawdown dedupe.
    - Delivered is refused on an order where `isAlreadyCompensated()`.
    - The `is_test` filter goes into the three readers that lack it.
    - Joki NeedsReview is counted once.
12. **A financial scenario builder that drives the real services (Q8, Q13).**
    - It drives `CheckoutService::initiate`, `ResellerOrderPlacementService::placeOrder`, the payment outcome and webhook, `fulfill`, `ManualDeliveryService`, joki assign and progress, `settle`, `resend`, vouchers, top-ups, withdrawals, supplier transfers and month close, under `travelTo` in Malaysia time (23:59 / 00:01 included).
    - The fakes return realistic amounts, `paid_on`, `fee_amount` and supplier prices.
    - It is used two ways:
      - a small CI set (about 60 orders, every case at least once);
      - an additive artisan command that fills the local dev DB with about four months and 1,500 orders across every channel, for eyeballing the pages.
    - `ReportDemoSeeder` is deleted.
13. **Eleven cross-page invariants, run in CI on the scenario data (Q12):**
    1. Σ revenue over all months = Σ revenue of all recognised orders: nothing falls between months or counts twice.
    2. A closed month's live figures = its stored snapshot.
    3. The Summary–Reports Bridge has 0 unexplained difference every month.
    4. Customer liability at each month end = paid, not yet recognised, not yet compensated orders as of that date.
    5. Reseller wallet balance agrees on the Dashboard, Summary, portal and API; affiliate balance agrees on Cash Position and portal.
    6. Per order, `selling = cost + platform_profit + affiliate_profit`, with real cost after delivery.
    7. Per order, Register net plus correction rows = the ledger.
    8. Sales agree on Reports, Customer Analytics and Report Assistant under one definition (with `recognised_at` and channel added to `llm_report_orders`).
    9. Store credit on Reports = Cash Position.
    10. Every Delivered order from an API supplier has exactly one drawdown.
    11. No figure on any page includes an `is_test` order.

### Build order (stacked PRs into `staging`; each fix PR adds its invariant red, then green)

1. **PR A, foundation:** remove the flag; realistic fakes; the scenario builder; the invariants that already hold. No known-failing tests are committed.
2. **PR B, recognition and dated facts:** `recognised_at` and its backfill, P1 across every past-period reader, F1–F5, `assertOpen`, invariants 1, 2 and 4.
3. **PR C, pages:** the named figures and the Bridge, one membership source, Report Assistant columns, the `is_test` filters, invariants 3, 5, 7, 8, 9 and 11.
4. **PR D, money flows:** the drawdown seam, unique index, the compensated-Delivered refusal, joki pay classification, the Dashboard count, invariants 6 and 10.
5. **PR E:** the dev-DB command, delete `ReportDemoSeeder`, a browser pass over every page with the full data set.

## Rationale

- **P1 replaces five one-site fixes with one rule.** S1, S2, S3, S7 and S9 are the same defect in five readers. Patching each where it was found would leave the next reader free to repeat it; P1 plus invariant 2 makes a repeat fail CI.
- **Almost every fact already exists append-only** (`ledger_entries`, `voucher_redemptions`, `voucher_merges`, `supplier_ledger_entries`, `supplier_transfer_corrections`, `currency_rates`, `budget_envelope_postings`, `order_delivery_legs`, `chip_settled_transactions`). P1 needs only the five facts in decision 5.
- **A separate accounting journal was considered and rejected for now.** It would duplicate `ledger_entries` and move every money page to a new source at once, the largest blast radius available, for 46 orders. Because P1 makes every past figure a function of immutable facts, a journal later is a replay of those facts, checkable month by month against today's figures with this ADR's builder. Deferring it costs little, and the cost does not grow with data volume.
- **`recognised_at` in `creditOrderProfit()`** is the one moment the system already treats as "this order became revenue", it is called under the order lock on both paths, and the existing `dedupe_key` makes it once-only.
- **No data patch is needed.** Read-only on production (2026-10-24): for all 32 Delivered orders the paid month, delivered month and `order_profit` month are equal, and no order is paid in one month and delivered in another, so the backfill leaves September's close unchanged. No order has duplicate supplier-ledger references, so the unique index applies cleanly. CHIP payments so far settled within seconds of processing, so F1 changes no existing month.

## Stress test (2026-10-24, before writing)

| Break attempt | Result |
| --- | --- |
| Resend after Delivered | Safe: Delivered is terminal; recognition happens once (`dedupe_key`). |
| Compensation on an already-recognised order | Lowers revenue in the compensation month (decision 1); the recognition month is untouched (decision 3). |
| Combo leg delivered in September, settled in October | Revenue and COGS both in October: COGS units come from `RecognisedRevenue`; the drawdown waits as a contract cost. |
| Paid after Failed, voucher restored, admin marks Delivered | Refused (decision 11). |
| Penjoki paid for a failed attempt, order retried and delivered | Failed-attempt pay is expensed in the month paid (decision 8); the delivered order's COGS is its own cost. |
| CHIP settlement file arrives after its month is closed | Accepted; effect in the current month; snapshot unchanged (decision 4 exception). |
| Paid 23:59, webhook processed 00:01 | `paid_at` from `paid_on` (F1); revenue does not depend on it anyway (decision 1). |
| Reseller wallet order undelivered at month end | Counted in customer liability (invariant 4), same as a CHIP-paid order. |
| Backdated supplier transfer into a closed month | Refused by `assertOpen` (decision 4). |
| A future page computes a past month from current state | Fails invariant 2 in CI. |
| Scale | Every period filter is on an indexed date (`recognised_at`, ledger `created_at`). `MonthCloseService::priorAdjustment` recomputes every earlier closed month on each close, O(months²); acceptable for years of months (its `ponytail:` comment records the ceiling). |

## Consequence to track

- **Introduce a full accounting journal when any of these becomes true:**
  - the accountant needs a trial balance or true double entry;
  - entries should post to Bukku automatically;
  - the books hold more than one entity or currency;
  - monthly reports become slow despite the indexes.
- **Membership:** switch to day-spread recognition if membership revenue exceeds 5% of a month's revenue.
- **The joki point-in-time judgement (decision 6)** is the accountant's to confirm. If it changes to over time, recognition follows `joki_progress_updates`, which are already dated.
- **P1 holds only while every new reader obeys it.** Any new money page adds its figure to the invariant suite.
- **Revises:**
  - [ADR-083](./ADR-083-internal-accounting-financial-reconciliation.md): revenue month and the role of drift.
  - [ADR-111](./ADR-111-real-cost-profit-reconciliation-at-delivery-time.md) decision 8: the flag is removed.
  - [ADR-126](./ADR-126-joki-mlbb.md) decisions 11 and 13: partial and extra pay become cost of sales.
