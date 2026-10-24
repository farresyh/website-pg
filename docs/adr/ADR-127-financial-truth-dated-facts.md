# ADR-127: Financial truth from dated facts — revenue at recognition, closed months that cannot move, and a scenario builder that proves every page agrees

> **Standing (2026-10-24):** Accepted; decision 8 withdrawn (2026-10-24 addendum: no partial or extra penjoki pay); PR E build notes split pay-after supplier payments on the Monthly Summary; PR E completed 2026-10-25 (cash equation closes, membership gate fixed, IDR averaging pending, §16 item 132). Revises [ADR-083](./ADR-083-internal-accounting-financial-reconciliation.md) (revenue month, closed-month drift), [ADR-111](./ADR-111-real-cost-profit-reconciliation-at-delivery-time.md) decision 8 (the feature flag is removed) [ADR-126](./ADR-126-joki-mlbb.md) decisions 11/13 (penjoki partial and extra pay, removed by the 2026-10-24 addendum), and, through its build addenda, [ADR-104](./ADR-104-admin-panel-visual-redesign.md) R10 (store credit), [ADR-087](./ADR-087-admin-reports-llm-assistant.md) (Report Assistant view) and [ADR-024](./ADR-024-voucher-at-checkout.md) (dated restore and revoke). Build and live status: [`prd.md` §15](../prd.md). The text below is a dated record; a later addendum in this file overrides earlier text, including the **Status** line.

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

### 2026-10-24 addendum — PR A and PR B1 build notes (decisions 1, 2, 10, 12)

- **PR B is split into B1 and B2.**
  - B1: recognition by `recognised_at`, plus invariants 1 and 4.
  - B2: CHIP `paid_on` and fee (F1, F2, F5), the restore date (F4), `assertOpen`, the remaining past-period readers, and invariant 2.
- **`recognised_at` is stamped in `LedgerService::creditOrderProfit()` before its `is_test` return,** so a sandbox order is recognised like any other. Every reader already excludes `is_test`.
- **The backfill takes the first `order_profit` ledger row's time, else `delivered_at`.**
- **The Reports Bridge needed a new row:** "Recognised here, paid in another range" (`recognised_from_other_periods`). "Orders not recognised" now also holds orders paid in the range but delivered after it. An order that was recognised but never paid stays unexplained on purpose.
- **The Monthly Summary's affiliate commission and Cash Position's undelivered claim** now use `recognised_at`. COGS follows through `RecognisedRevenue`.
- **Test fixtures:**
  - `OrderFactory::delivered()` sets `recognised_at` to `delivered_at`, else `paid_at`.
  - A settled partial is recognised at the moment it is settled, so a test that settles one freezes the clock inside its range.
- **The scenario builder creates its world on the scenario's clock** (`boot($startsAt)`). A top-up on the real clock fell outside September and broke invariant 5.

### 2026-10-24 addendum — PR B2 build notes (decisions 3, 4, 5; F1–F5; invariant 2)

- **F1/F2.**
  - `ChipGateway::normalizePurchase()` reads `payment.paid_on` and `payment.fee_amount`. The webhook event and the reconciliation sweeps take them from there.
  - `applyPaid()` sets `paid_at` from `paid_on`. It falls back to our processing time when CHIP sent none, when the time is outside the order's life (before `created_at` or in the future), or when it falls in a closed month.
  - `gateway_fee_sen` is stored on orders, wallet top-ups and membership attempts. Comparing it with the settlement file's fee is a page change and belongs to PR C.
- **F5 revised.** No `paid_at` was added to the top-up and membership attempts. With one, a top-up near midnight could put the CHIP-unsettled asset and the wallet liability in different months. Instead, the asset, the liability and the Register all read the same fact: the wallet credit (`WalletTopupAttempt::ledgerCredit`) or the fee record (`MembershipCheckoutAttempt::feeRecord`).
- **F4.** `voucher_redemptions.restored_at` is written by the one restore writer and backfilled from `updated_at`. `Voucher::balanceAsOf()` is now the one place a voucher balance at a past instant is rebuilt; Cash Position and breakage both use it.
  - Breakage is the balance at the moment the voucher expired.
  - A redemption restored after expiry counts as breakage in the month of the restore.
- **Decision 4.** `AccountingPeriod::assertOpen()` guards:
  - recording a supplier transfer, and correcting one (on the old and the new date);
  - voiding a transfer (the void removes it from its own month's funding lines);
  - posting to an envelope, and voiding a posting, since the reversal is dated with the original. Reopening a month is exempt.

  Manual supplier adjustments are dated today, so they need no guard. `ClosedPeriodGuardTest` lists every guarded writer.
- **Invariant 2 caught S10.** The Summary's reseller-wallet line was today's balance. It is now the balance at the month end, read from the ledger. The admin "as of" timestamp is gone.
- **Register (S2).** An order recognised in its paid month keeps one row. An order recognised in a later month keeps only the money received on its `order` row, and its cost and net go on its own `order_recognised` row, dated `recognised_at`.
- **Invariant 2 is proven on scenario data.** September is closed, then a September order is delivered, the wallet moves and a failure is compensated. September's live Summary, Cash Position (assets and claims) and Register equal what they were at the close.

### 2026-10-24 addendum — PR C build notes (decisions 7, 9, 11; invariants 3, 7, 8, 9, 11; two new findings)

- **Reports and the Report Assistant file profit by when it was earned.**
  - `LedgerEntry::orderProfitDateSql()` is one rule: the automatic credit is dated by its order's `recognised_at`, and a manual correction (`reason` set) by its own `created_at`.
  - Reports, the trend buckets, and the Summary's affiliate commission all read it. Sales stay on `paid_at`.
  - `llm_report_orders` gains `recognised_at_kl` and `placed_via`, and the prompt files profit by recognition.
- **New finding: a manual profit correction moved a past month.** Reports dated it by its order's month, so a correction made today rewrote an earlier month's profit. The Register already dated it correctly; Reports and the commission line now do too.
- **Store credit is one figure (invariant 9), revising ADR-104 R10.** `Voucher::outstandingAt()` together with `balanceAsOf()` is used by both Cash Position and Reports. Reports had counted today's `remaining` of compensation vouchers only. An admin-issued (Path A) voucher now counts too: the Summary expenses it when it is issued, so its unspent balance is owed.
- **New finding: a voucher revoke left no dated fact.**
  - What happened: `VoucherController::revoke` flipped `status` with no lock and no date. Cash Position kept owing the balance forever, and no month recorded it.
  - Now: `VoucherService::revoke()` locks the voucher and writes `revoked_at`. The balance is 0 from then on, and the unspent balance is income of the revoke month, on the breakage line. Production has no revoked voucher.
- **Membership revenue has one source (decision 7):** the platform's `membership_fee` ledger rows. A waived RM0 fee writes no ledger row, so the totals are unchanged.
- **The `is_test` filter is added** to `failedOrderCreditIssued` and to the Register's voucher rows.
- **Decision 9, the labels.**
  - The Reports Overview chart said "Revenue" for paid-date sales; it now says "Sales".
  - The Summary's line is "Revenue (recognised)".
  - The owner-profit KPIs say "(market rate)".
  - Customer Analytics says "Customer spend".
- **Decision 9, the profit bridge.** `ReportService::profitBridge()` walks owner profit (market rate) to operating profit (accounts) through the Summary's own lines. Those lines are now available for any range via `MonthlyAccountingSummaryService::profitLines()`. Manual corrections are their own row, and the unexplained difference must be 0. It is shown on the Reports Profit tab, and hidden under an affiliate filter.
- **F2, shown.** Settlement ingest copies the fee CHIP reported at payment to `chip_settled_transactions.reported_fee_sen`. The Settlements page flags any row where the file's fee differs.
- **Proven in `FinancialScenarioTest`:**
  - invariants 3, 7, 8, 9 and 11;
  - the profit bridge;
  - Reports profit by recognition;
  - a later correction never moves a past month.

### 2026-10-24 addendum — PR D build notes (decision 11; decision 8 withdrawn; invariants 6 and 10)

- **Decision 8 is withdrawn (founder, 2026-10-24): a penjoki gets no partial or extra pay.**
  - A failed joki is refunded to the customer in full and the penjoki gets nothing. On a reassigned order, only the penjoki who delivers is paid, the full payout.
  - The agreed payout (`cost_price`) is the whole joki cost, so the Joki margin on Reports is exact and nothing is posted to the Budget Envelope for joki.
  - Why: decision 8 as written counted the cost twice. An envelope expense already reduces its envelope, and the Monthly Summary's operating profit leaves envelope expenses out for that reason (`MonthlyAccountingSummaryService::operatingProfit()`). Moving the same posting into cost of sales would also lower the month's operating profit, and with it the allocation. The categories were never built, so nothing is removed from data.
  - The penjoki carries the risk of a failed or reassigned order. That belongs in the penjoki's terms.
- **Mark Delivered (NeedsReview only) takes the price the supplier charged.**
  - The admin copies it from the supplier's dashboard with the invoice number, in the supplier's currency (`supplier_price`, required).
  - It becomes the real cost (ADR-111, `captureRealCostSen` and `reconcileRealCostProfit`) and the drawdown, recorded after commit like every other drawdown. Before this, the path recorded neither.
  - The founder chose this over converting the catalog cost back, which would drift with the exchange rate.
- **The unique drawdown index is on a virtual column.**
  - `supplier_ledger_entries.drawdown_key` is `reference_type:reference_id` for `ORDER_DRAWDOWN` rows and NULL for every other type, and it is unique.
  - A plain unique on (reference_type, reference_id, type) would also refuse a second `MANUAL_ADJUSTMENT` correction of one transfer, which is allowed.
  - `recordOrderDrawdown()` treats the index refusing a duplicate as "already recorded". Production, read-only on 2026-10-24: 35 drawdowns, no duplicate.
- **Delivered is refused on a compensated order at the seam, under the lock.**
  - The gap: a Paid-after-Failed order whose voucher was restored lands in NeedsReview. `ManualDeliveryService::confirmDelivered()` (Manual Queue, a supplier's `.done`) reached `finalizePendingDelivery()`, which had no compensation check. The `OrderController` check ran outside the lock.
  - Now `finalizePendingDelivery()` treats a Success on an `isAlreadyCompensated()` order like ADR-102's contradicting result. The answer is kept as evidence (`late_supplier_result`), the status doesn't change, and the caller gets `InvalidOrderTransitionException`, which every caller already handles. `markDeliveredManually()` re-checks under its own lock.
  - The combo leg path needs no guard. `OrderSettlementService::settle()` compensates only a Failed or PartiallyDelivered order. While any leg is Pending, `resolveComboOutcome()` keeps the order Pending, and after compensation `fulfillCombo()` refuses a resend. So a compensated combo never has a pending leg to finalize.
- **Joki NeedsReview is counted once:** on the Joki card's stale count, not the Dashboard's stuck count.
- **Proven:**
  - `FinancialScenario` adds a NeedsReview order the admin confirms at a price above catalog. Invariants 6 and 10 now cover that path. With the drawdown line removed, invariant 10 fails.
  - Service tests: real cost and one drawdown, the compensated refusal on both paths (red without the guard), and the index on sqlite and MySQL.

### 2026-10-24 addendum — PR E build notes (decision 12; two findings)

- **Joki through the scenario.** `FinancialScenario` drives joki through the real `/checkout`, `JokiAssignmentService` and `ManualDeliveryService` (`.done` / `.gagal`).
  - The CI month has four joki orders: delivered, failed, reassigned, and paid in September but finished in October.
  - A new invariant: a penjoki is owed only the payout of what they delivered; a failed or moved-away order owes them nothing; and there is no envelope posting.
- **`FinancialScenarioSeeder` replaces `ReportDemoSeeder`, which is deleted.**
  - It covers 1 July to yesterday, with about 1,500 orders across storefront, affiliate, Reseller API and joki. It also drives failures with compensation (and some vouchers spent again), late deliveries, NeedsReview confirms at a charged price, cost variance, supplier funding, penjoki payments, wallet top-ups and the September close.
  - It is deterministic (fixed seed) and additive.
  - It keeps the panel's own joki payout share. Only the CI test sets 80%.
  - Not driven yet: membership, CHIP wallet top-ups, withdrawals and resends.
- **The seeder needs the dev stack stopped.** Horizon on the same sqlite file locks it. Worse, the real scheduler would poll the scenario's Pending orders, whose supplier exists only in the seeding process. So the scenario uses the array cache, so rate limits don't carry over from an earlier run, and the seeder leaves no scenario API order Pending.
- **Founder decision: the local dev DB's 189 `DEMO-` orders were removed** (backed up first), together with their ledger rows and one voucher. The seeder then ran. On the dev DB:
  - 1,578 orders;
  - price identity and one drawdown per delivered order, no exception;
  - the profit bridge leaves 0 unexplained in every month;
  - the September close equals its live figure (RM1,009.15).
- **Browser pass**, 11 money pages: Dashboard, Reports, Customer Analytics, Orders, Joki, Vouchers, and the five Accounting pages. No API error.
- **Finding: a pay-after supplier payment was counted as a prepaid top-up.**
  - What happened: the Monthly Summary's "Supplier prepaid — top-up" summed every supplier transfer, including paying a penjoki or a manual supplier what is owed. In Bukku that is settling a payable, not buying a prepaid asset.
  - Now: a separate line, "Supplier payments — pay-after", by `funding_mode` (founder: keep the term pay-after). Neither line is P&L, and the bank fee line covers both.
  - Production, read-only: 3 prepaid transfers, no pay-after payment yet.
- **Finding: the Joki board's "Waiting"** showed a finished order's age since payment (e.g. "115d"). It now shows only for an open order.
- **Found restarting the dev stack:** admin and storefront both used Next's default port, so whichever started first took :3000. The admin's session cookie works only there. They are pinned to :3000 and :3001.

### 2026-10-25 addendum — PR E completed: the money around the orders, and two findings

- **The founder asked why six flows were not tested with the rest. They are now, in PR E.** Decision 12 had listed them. PR E's first cut drove only orders, and noted the rest as "not driven yet" without raising it.
  - `FinancialScenario` (via its `MoneyFlows` trait) now drives each through the real route, service or admin action:
    - share capital;
    - CHIP's own fee and paid time on every payment;
    - CHIP settlement files through the real `ingest()`;
    - membership through `/membership/subscribe`, and member-priced orders;
    - CHIP wallet top-ups;
    - affiliate withdrawals (request, approve, complete);
    - resends;
    - a second supplier in IDR, with daily FX rates and deposits at their own rates.
  - The seeder drives all of them daily or weekly.
- **The company's bank is modelled, so the cash equation is a test.**
  - New invariant: the September close's gap is 0 to the sen, with CHIP payments still unsettled at month end. CHIP's RM1-per-payment estimate offsets the channel fee charged to the customer. The real fee's difference is recognised at settlement.
  - It goes red if a single bank flow is left out.
  - On the dev data the close's gap is exactly July–August's unallocated profit (closes start in September) plus the IDR residual below.
- **Finding 1, fixed: the membership gate judged the wrong brand.**
  - What happened: ADR-080 pinned `EnsureBrandMembershipEnabled` ahead of the throttle, which also put it ahead of `ResolveStorefrontBrand`. On an affiliate's domain, OTP, subscribe-options and subscribe were gated by the platform brand's switch.
  - Production, read-only: PekanGame has membership on and the "FixFast Demo" affiliate has it off, so FixFast's domain left the surface open. All 3 subscription attempts were on PekanGame.
  - Now the resolver is pinned ahead of the gate. A test proves both directions. One older test passed only because of the bug; it now sets its brand's switch.
- **Finding 2, decision pending: IDR COGS at a cumulative average leaks between months.**
  - `FundedCostOfSales::weightedAverageRate()` averages every deposit since inception. A month's COGS is costed at that month-end rate and never revisited, while the remaining prepaid balance is valued at the latest rate. So COGS plus the balance differs from the ringgit actually sent as soon as deposit rates change between months.
  - On the dev data: RM4.01 over three months. It affects Digiflazz in production.
  - The founder chose a moving (perpetual) weighted average, after a grill and the money-critical code-trace (PRD §16 item 132). It is not built in PR E.
- **Scenario fixes found on the way:**
  - Membership attempts and top-ups have no `paid_at`; F5 dates them by their ledger fact, so the scenario does too. On sqlite, a missing column in a `where` silently compared a string.
  - The scenario never settles a payment "in the future" when days are scripted out of order.

