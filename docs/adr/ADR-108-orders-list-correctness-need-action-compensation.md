# ADR-108: Orders list correctness (Need Action + compensation-tag decluttering) + toolbar overhaul (Source/Game/date-range/Columns/Export)

> **Standing (2026-10-09):** In force. Build and live status: [`prd.md` §15](../prd.md). The text below is a dated record; a later addendum in this file overrides earlier text, including the **Status** line.

**Status:** Accepted — grilled (`/mattpocock-skills:grilling`) 2026-09-18, over 2 rounds, against the founder's own live-verification of production and a layout mockup he provided. **Built 2026-09-18.**

**Context:** While confirming a prior review-display fix (`docs/prd.md` §16 item 3) worked live, the founder walked `/admin/orders` on production and asked two questions: (1) an order he'd already resolved (Issue Voucher/Refund) was still showing under "Need Action" — shouldn't that be gone? (2) the Delivery column shows several stacked statuses (Voucher Paid/Voucher Issued/Restored) at once — could it show just the current one instead, decluttering the table? Both were confirmed as real, not perception, by reading the code and then querying production directly (read-only, via SSH):

- `OrderController::index()`'s `need_action` filter and `summary()`'s KPI count both did `payment_status=Paid AND delivery_status=Failed`, full stop — neither called `Order::isAlreadyCompensated()` (voucher issued OR wallet-refunded OR voucher-restored), the exact guard ADR-102 decision 1 already uses to block further action on a settled order. Querying prod: all 4 orders in that bucket were already compensated (`PG-CGDZOLEHAIR8` wallet-refunded; `PG-JLOMUJ1H23NE`/`PG-LXC90WQ8KQFC` voucher-issued; `PG-HHHRXLG1AR0N` voucher-issued AND restored). Real actionable count was 0, not the 4 both the KPI card and tab showed.
- `admin/src/app/admin/orders/page.tsx`'s Delivery cell renders `delivery_status` plus up to 4 independent compensation Tag pills (`has_used_voucher`/`has_compensation_voucher`/`has_wallet_refund`/`has_voucher_restored`) — ADR-102 decision 12's own explicit design ("compensation is an orthogonal axis... can carry more than one badge at once"). `PG-HHHRXLG1AR0N` showed 3 simultaneously on prod.

The founder then shared a layout mockup (a Design-canvas artifact, not a live screenshot — confirmed by grepping the codebase for its caption strings, none of which existed) showing a restyled Orders page with two additional rows of controls beyond what's live today: a Source filter, a Game filter, a date-range picker, a Columns toggle, and an Export CSV button. Checking the current code found only the search box among these already built — the rest, including ORD-5 (order export), were genuinely unbuilt, not just unstyled. `docs/prd.md` §16 item 6 already carried ORD-5 as known backlog.

**Decision:**

1. **`need_action` excludes an already-compensated order, in both the tab filter and the KPI count.** A new `Order::scopeNeedsAction()` — `payment_status=Paid AND delivery_status=Failed AND NOT (voucher exists OR wallet-refund ledger entry exists OR voucher-redemption restored)` — is the single predicate both `OrderController::index()`'s `'need_action'` branch and `summary()`'s KPI count call, replacing the old inline `where()` pair and the old grouped-`SUM(CASE...)` slot respectively. No new bucket/tab for a now-excluded order — it simply falls out of "Need Action" and remains visible under "All" and its own Order Detail page, same as any other order.

2. **Delivery column shows at most one compensation Tag, priority-picked** — Restored > Wallet Refunded > Voucher Issued > Voucher Paid (most-final-state wins; Voucher Paid is the least informative, just the payment method, not a compensation outcome). `delivery_status`'s own Tag is untouched. Order Detail's `RefundInformationCards` (4 independent cards) is explicitly unchanged — this decision only declutters the list row, nothing is lost.

3. **Search widens to game name**, alongside the existing order#/email match (`orWhereHas('game', ...)`).

4. **Source filter** — single-select: `reseller` (`wallet_reseller_id` set), `affiliate` (a genuine partner Affiliate — `affiliate_id` set AND that affiliate's `is_primary` is not true), `direct` (no affiliate, or the affiliate is the primary brand itself). The primary brand's own storefront order and a genuine partner-affiliate order both carry a non-null `affiliate_id` (ADR-060's uniform brand modeling) — only `is_primary` tells them apart, so the list's own eager-load widened to `affiliate:id,business_name,is_primary` and the Source *column* itself was corrected to read "Direct" for the primary brand's own orders (previously an unlabeled "—", inherited from before this distinction existed in the UI).

5. **Game filter** — single-select, `game_id` exact match, options from the existing `listGames()` admin endpoint (no new backend list endpoint needed).

6. **Date-range filter** — reuses `admin/src/lib/date-range.ts` verbatim (the Reports page's own preset shape: `all`/`this_month`/`7d`/`14d`/`30d`/`90d`/`custom`), filtering `created_at` via new `date_from`/`date_to` query params. Deliberately orthogonal to (not replacing) the existing "Today" status tab — the two read as different things to an admin (a tab picks *what kind* of order, the date range picks *when*), and can combine (e.g. Need Action + Last 7 days).

7. **Columns toggle** — every column hideable except Order # and Actions, persisted to `localStorage` (a per-viewer screen preference, not data — same tier of state the existing `ThemeContext.tsx` already persists this way, not an Artifact-runtime concern since this is the admin webapp itself).

8. **Export CSV (ORD-5, finally built)** — a new `GET /api/orders/export`, `StreamedResponse`+`fputcsv` (the same convention `CustomerAnalyticsController`/`ReportController`/etc. already use), respecting every filter above via a `applyFilters()` method shared with `index()` so the two can never disagree about "the current view" — streams every matching row via `chunk(500)`, not just the current page. Columns: everything visible on screen, plus a money-audit breakdown the founder explicitly asked for beyond the visible table ("order table ni akan jadi source of truth kita... kira mcm bank statement kita") — Pricing Basis, Cost Price, Standard/Normal Selling Price, Member/Affiliate/Wholesale Markup % (all three raw stored fields, not one collapsed "the" markup — a combo of `pricing_basis`-conditional logic risked picking the wrong one silently), Transaction Fee, Platform Profit, Affiliate Profit. Deliberately CSV-only, never added to the Columns toggle (decision 7) — Order Detail's `OrderDetailCards` already shows every one of these fields in full, and the founder agreed the table itself shouldn't get more crowded when a deep dive is one click away.

9. **Combo order cost in the export is a live sum, not a frozen figure.** ADR-107 deliberately dropped `order_delivery_legs.cost_price_sen` (no per-leg frozen cost column exists) — a combo order's CSV "Cost Price" cell sums each leg's `componentPackage->cost_price` **live**, same precedent ADR-107 itself established for combo cost reconciliation, `~`-prefixed in the cell so it's never mistaken for the non-combo column's genuinely-frozen figure.

10. **Layout** — KPI card placement, header Refresh/"Updated at"/Export CSV positioning, and the two filter-control rows follow the founder's own mockup positioning. No functional change beyond what's specified above.

**Rationale:** Both correctness fixes are the same underlying lesson ADR-102/ADR-105/ADR-106 have each already applied once to a different corner of the Orders system — a screen showing stale/redundant state because a check that exists elsewhere (`isAlreadyCompensated()`) wasn't threaded through to this particular query, and a UI surface treating independent facts as if they all deserved equal permanent visibility rather than picking the one that matters most right now. The toolbar buildout reuses every seam this codebase already has for the same job elsewhere — `@/lib/date-range` (Reports), `StreamedResponse`+`fputcsv` (Customer Analytics et al.), `listGames()` (already fetched elsewhere in `admin/`) — rather than inventing a parallel pattern, per this project's "deep modules, stable seams" practice.

**Consequence to track:**

- ~~Live-browser verification owed~~ — **founder verified live, 2026-09-18** (no admin session was available to this Claude session itself — Chrome extension requires the founder's own logged-in profile, passwords are never typed by the agent — so this was always going to be his own click-through, not a gap left unaddressed).
- `docs/prd.md` §16 item 6's "ORD-5 (order export)" line is now resolved — remove/update it alongside this ADR landing.
- The Columns toggle's `localStorage` key (`pekangame-admin-orders-columns`) is per-browser, not per-admin-account — an admin switching machines/browsers starts with every column visible again. Acceptable (same limitation `ThemeContext.tsx`'s own theme preference already has), not treated as a gap.
- Export is unpaginated by design (streams every matching row) — no row-count cap was requested or built. Revisit only if a real export turns out large enough to matter (no evidence of that today — total order count is still in the low hundreds).

**Addendum, same day (2026-09-18) — two founder-found fixes before merge:**

1. **Source/Game Select showed no placeholder text at all** when unfiltered — this component treats an empty-string value as "nothing selected" and renders no label (unlike a native `<select>`'s placeholder attribute), so the original `""` "no filter" sentinel silently rendered blank. Fixed by switching both to an explicit `"all"` sentinel value (matching the date-range Select's own `"all"`/"All time" convention, which already worked correctly for exactly this reason) — `orderFilters` converts `"all"` back to `undefined` before it ever reaches the backend.

2. **`RefundInformationCards` redesigned into one generic table-row shell**, per the founder's own reference mockup (a "Voucher Used" example and a "Refund Information" example) and his explicit ask to generalize rather than hand-roll five near-identical prose cards — a future layout change is now one edit to `CompensationCard`, not five. Two tone families: `tone="used"` (green, Voucher Used to Pay) and `tone="refund"` (neutral shell + a small `Tag` badge naming the sub-type — Voucher/Wallet/Restored) for the three "customer gets money back" facts, which now share one shell distinguished only by badge and field set. Field mapping per card, confirmed with the founder before coding: Voucher Used shows Voucher Code/Discount Applied (`order.voucher_discount`, this order's own frozen amount, not the voucher's total)/Payment Method (derived: "Voucher (Full)" when `final_amount===0`, else "Voucher (Partial)")/Voucher Remaining; the Voucher-badged Refund Information card shows Code/Original Amount/Remaining/Status/Created; Wallet-badged shows Amount/Reseller/Created; Restored-badged shows Voucher Code/Amount Restored/Status. A "View/Edit" action button (present in the founder's own mockup, linking to the voucher's own record) was explicitly descoped — `/admin/vouchers` has no deep-link-by-code support yet, and adding it was judged separate scope. Combo Profit Adjusted stays its own warning-tone prose card, deliberately outside this family — an internal profit-reconciliation flag, not a customer-facing compensation fact. Confirmed the compound real-world case (a partial-voucher order that fails: original voucher restored AND a new compensation voucher issued for the cash portion — the exact shape of prod order `PG-HHHRXLG1AR0N`) needs no special-casing: each fact is still an independent boolean, so it renders as 2 separate "refund" card instances side-by-side, same as before this pass. No purple token exists in this project's design system (`tag.tsx`'s `tagVariants` — default/secondary/info/success/warn/danger/review/contrast only) — the mockup's purple was approximated with the existing neutral card shell plus a `secondary`-severity badge rather than inventing a new color token without its own ADR.

### Addendum (2026-10-04) — profit is read from the ledger everywhere; the export becomes a 2-sheet Excel workbook; date filters use KL days

**Context.** After the #346 release the founder saw "Reseller Markup" around 100% on PG-B7RB8MON6Q9I and asked for a full check of Order Detail rather than another one-line fix. A read-only check of all 31 production orders against the ledger found three problems.

1. **Profit shown where none was earned.** Order Detail, this ADR's CSV export (decision 8) and the affiliate portal's "Your margin" read `orders.platform_profit` / `affiliate_profit`. Those columns are the *profit plan*: computed at checkout, updated by resend, real-cost reconciliation or settlement, and the input `LedgerService::creditOrderProfit()` credits from on delivery. They are not what was earned:
   - 10 failed or unpaid orders showed a profit. PG-B7RB8MON6Q9I, refunded in full, showed +RM 10.01.
   - Order 15's manual ledger correction (−10 sen, ADR-105) never reached the column, so it shows 0.37 against a ledger of 0.27.
   - Summing the CSV gave RM 19.37 platform profit; the ledger says RM 7.05.
   - Reports, Dashboard, Accounting, Transaction Register, Customer Analytics and the LLM views already read the ledger (ADR-086's rule). These three surfaces had been missed.
2. **"Reseller Markup" was re-derived** from `(selling − cost) / cost` instead of reading the tier frozen on the order (`wholesale_markup_pct`). Results:
   - 4.35% on a delivered combo whose tier is 3.00%;
   - 3.09% on another 3.00% order;
   - −100% on a fully refunded order — the #345 patch.
3. **UTC days.** The Orders page date filter, its "today" tab/KPI, and the export cut days at UTC midnight, not Kuala Lumpur.

**Decisions** (founder, 2026-10-04):
1. **Two meanings, two names.**
   - The order columns stay the *expected* profit (the plan; the legitimate input for crediting, for resend comparisons, and for `costReconciliation()`'s basis check).
   - *Earned* profit is the sum of that order's `order_profit` ledger entries, corrections included.
   - Order Detail, the export and the affiliate portal show earned. An order that earned nothing shows "—", with the expected figure as a labelled note where useful.
   - Sandbox orders (never on the ledger, ADR-018) show expected only.
2. **One helper.** `Order::earnedProfit()` and the batch `Order::earnedProfitsFor($orders)` (one query per page/chunk) mirror the existing `walletRefundLedgerEntry()` / `walletRefundEntriesFor()` pair. Every surface that shows per-order profit uses them. The existing ledger readers (Reports etc.) keep their own aggregate queries — they are correct, and consolidating them is not needed now.
3. **Reseller Markup reads `wholesale_markup_pct`**, the same field the affiliate "Wholesale Tier Markup" row and the export already use; hidden when null. No derivation.
4. **The export becomes a 2-sheet Excel workbook (`.xlsx`).** CSV cannot hold two sheets; the founder replaced the CSV outright. Written with the already-installed OpenSpout (no new dependency).
   - **"Summary" sheet.** Every figure is an **Excel formula** over the Orders sheet, so an accountant can click a cell and see exactly what is added and subtracted, and no backend logic is duplicated. Figures:
     - Gross Sales;
     − Refunded to Wallet;
     = Net Sales (= Reports' Total Sales, which also nets only wallet refunds);
     - Platform / Affiliate Profit Earned (= Reports);
     - Margin %;
     - informational lines: CHIP fees collected, vouchers issued as compensation, profit expected-but-not-earned on **paid** orders (an unpaid order is an abandoned checkout, not lost profit), paid via CHIP vs Reseller wallet (wallet spend is not new cash).
   - **Balance checks** that must read OK:
     - per-row Selling − Voucher Discount + Fee = Final Amount;
     - earned profit only on delivered or settled-partial orders;
     - a written instruction to compare Net Sales and Earned profit with Reports for the same dates.
   - **A column dictionary.**
   - **"Orders" sheet:** one row per order, money as numeric cells. Adds Selling Price, Voucher Discount, Funding Source, Wallet Refund, Voucher Issued, Voucher Restored, Platform/Affiliate Profit Earned and Expected.
   - **Unpaid orders** are listed but excluded from every Summary total (as Reports does).
5. **Dates in KL.**
   - The Orders date filter, "today" tab/KPI and the export select by **order created date in Kuala Lumpur time**. The founder chose created over paid: the workbook records every order, paid or not.
   - Day bounds come from `ReportService::dateRangeFromDates()`, the same helper Reports uses.
   - Reports groups by paid date, so a Summary can differ from Reports only for an order created before KL midnight and paid after it (CHIP's payment window is at most 30 minutes). The Summary states this.

**Rejected:**
- Re-deriving markup — it drifts from the tier.
- Reading the columns and "keeping them in sync" with every ledger correction — two writers for one fact.
- Paid-date filtering — it would drop unpaid orders from a record the founder wants complete.
- Keeping CSV and Excel both — two formats to keep in step.

**Consequence to track:**
- The workbook's column set is a format change for anyone with saved formulas on the old CSV (today: only the founder).
- The affiliate portal's `affiliate_profit` field now means earned — null when nothing was credited.

### 2026-10-09 addendum — a "Failed" status filter

**Context.** ADR-104 R6 sends Reports' "View failed orders" to Orders' Failed filter, but Orders had none. Need action (decision 1) is a different list: it excludes compensated orders, so on prod it shows 0 while 7 orders failed.

**Decision.** `?status=failed` lists every order with `delivery_status = failed`, compensated or not, under the same `is_test = false` scope as the other filters. It is a **pill only**, after Need Action, with no KPI card (ADR-092's six cards are unchanged, the same treatment as Awaiting Payment). Orders reads `?status=` from the URL on load, so Reports can deep-link to any pill. The founder chose this over pointing the button at Need action or dropping it (2026-10-09).

**Consequence to track.** The link opens all dates; Reports' failed count is range-scoped by `paid_at`. Orders' date filter is by `created_at` (decision 6), so passing the Reports range through would not match exactly either.
