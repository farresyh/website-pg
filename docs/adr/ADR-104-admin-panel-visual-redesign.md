# ADR-104: Admin panel visual redesign — retire TailAdmin token layer, adopt "PekanGame Admin" design system

> **Standing (2026-10-09):** In force. Build and live status: [`prd.md` §15](../prd.md). The text below is a dated record; a later addendum in this file overrides earlier text, including the **Status** line.

**Status:** Accepted — grilled (`/mattpocock-skills:grilling`) 2026-09-17, against the founder's own Design-canvas artifact ("PekanGame Admin", `https://claude.ai/artifact/Jr2dGDUAFasPMedVayHPHe`). **PR-1 (foundation) merged 2026-09-17 (PR #233). PR-2 (Orders) built (PR #234), then corrected the same session** — the Artifact tool's own read/read_db/list_assets paths return only an empty shell for this artifact type; re-opening it directly in a browser (works fine) surfaced two real mistakes: purple had been wrongly generalized from "Orders' urgency accent" to "profit, everywhere" (reverted), and the artifact's own `border`/`border-strong`/`border-control` tokens were missing from PR-1's extraction (added). **PR-2b (header action-bar + compact summary strip) built (PR #235)**, grilled with the founder to a strict layout-only scope (no rename/reorganize/functionality change) before any code. **The founder then logged into the local dev server and drove a real live-browser audit (light+dark) against the artifact's own screenshots — correctly rejecting an initial "no contrast issue"/"confirmed match" claim.** Found and fixed 2 real dark-mode token bugs verified via `getComputedStyle`: every unstyled `<dd>` in `OrderDetailCards.tsx` silently rendered `rgb(0,0,0)` (pure black) on a dark card — fixed with explicit `text-ink`; `info-surface`/`info-ink` had no `.dark` override at all (PR-1 wrongly filed it under "inherits light") and rendered as a bright light-cyan island — fixed by mirroring cyan's own dark pair. Re-grilled scope under a strict rule (current implementation = functional source of truth, mockup = visual source of truth only) and built, same session: card-merge into "Game & fulfillment"/"Payment & supplier" + structured "Latest supplier result", card-heading icons, `RefundInformationCards` emoji→icon + responsive grid, info-strip's 5th "Channel" column, uniform header-button styling, KpiCards icon consistency, and — the app shell explicitly brought into ADR-104 scope for the first time — `AppSidebar.tsx` regrouped into 6 titled sections matching the artifact exactly (zero route/permission change, pure JS-array reshuffle) plus a shared-component retint and logo-box treatment (also benefits the Middleware Panel, confirmed unaffected live). PR #233–#235 merged and live 2026-09-17. **PR-3 (Reports) was re-scoped by the 2026-10-08 addendum below and built as PR-A/B/C, live via #385 (2026-10-09).** The lesson stands: re-open the artifact live and audit by screenshot before declaring a match.

**Context:** The founder found the current admin UI reads as generic/"AI slop" — not a vague taste complaint, but a real, verifiable pattern: `admin/src/app/globals.css`'s Tailwind v4 `@theme` block still carries TailAdmin's own token naming and values (`brand-*` 12-stop scale, `gray-*`, `shadow-theme-*`, `text-theme-*`, `2xsm`/`xsm` breakpoints) even though `admin/`'s JSX primitives were fully migrated off TailAdmin onto PrimeReact-Tailwind back on 2026-08-29 (ADR-038). A single `brand-500` blue does five unrelated jobs at once today (active filter, border, hover, link, primary button — e.g. `OrderSummaryCards.tsx:38-42`) with zero restraint or hierarchy — the textbook tell of a scaffolded-not-designed UI, not something ADR-038's component migration ever touched. The founder had separately built a complete, disciplined replacement design system in a Claude Design-canvas artifact — full color/typography/spacing/radius/shadow/size token set plus real page mockups (Orders list+detail+states, all 8 Reports tabs) already drawn against the app's actual IA and real domain data (Digiflazz raw responses, member/reseller pricing math) — verified in full across all 9 of the artifact's sections (Overview/Colors/Typography/Spacing/Corner radius/Shadows/Size/Components/Assets) before this grill started.

**Facts gathered before deciding anything** (sub-agent investigation, not assumed):

- Token layer lives entirely in a Tailwind v4 `@theme` block (`globals.css:78-161`, ~60 tokens: colors, breakpoints, shadow, z-index) plus a `:root`/`.dark` block (`:18-76`) that wires PrimeReact's own `--p-primary-*`/`--p-surface-*` semantic vars onto the `brand-*`/`gray-*` scale — already documented in-file as "ADR-038: PrimeReact-Tailwind design tokens."
- PrimeReact is themed via `@primereact/core`'s `PrimeReactProvider` (`admin/src/components/prime-provider.tsx:33-50`), not `usePassThrough` and not a separate themes package — `darkModeSelector: ".dark"` is already configured there.
- Icons: `@primeicons/react` (358 icons) is already a `package.json` dependency but used in only 2 files (`DeliveryLogsTable.tsx`, `datatable.tsx`); the other 53 files (including the shared Sidebar/TopBar that appear on every page) use a hand-rolled SVG set at `admin/src/icons/index.tsx`.
- Font: `next/font/google` Outfit, applied twice (`layout.tsx:22`'s className + `globals.css:181`'s `font-outfit` utility on `body`). No second webfont loaded — `font-mono` (system fallback, not a real monospace webfont) covers 19 existing code/ID/ref displays.
- Migration surface: `brand-` referenced 229 times across 50 files, `rounded-2xl` 139 times across 61 files, `shadow-` utilities 54 times across 26 files.
- Admin already ships a working dark mode today (`.dark` class, `dark:bg-gray-900` on `body`) — unrelated to the storefront's separately-tracked, still-pending ADR-090 dark mode redo; conflating the two was an error corrected mid-grill.

**Decisions:**

1. **Full visual-identity replace, not a partial/hybrid reskin.** No coexistence of old `brand-*` and new token families anywhere in the codebase once a surface is migrated — mixing them reproduces the exact "too many colors competing" problem this ADR exists to fix.
2. **Retire the TailAdmin-origin visual tokens entirely**, replace with the artifact's own named system: colors (`bg-canvas/bg-surface/bg-subtle/bg-overlay`, `ink/ink-muted`, `cyan-50/600/700/ink`, `purple-50/200/600/ink`, `nude-100/ink`, `success/warning/danger/review/neutral/info` each as `-bg`/`-ink` pairs, `chart-negative`, `focus-ring`), typography (Display: `page-title` 26/`metric-lg` 30/`section-title` 15; Text: `body`/`body-strong` 14, `caption` 12, `overline` 11; Data: `code-id` 13 in JetBrains Mono), radius (`sm` 6/`md` 8/`lg` 12/`full` 999 — down from today's `rounded-2xl` 16px), shadow (`xs`/`md` only, 5–8% light / 40–50% dark opacity — deliberately more restrained than today's 4-level `shadow-theme-*` scale), spacing (`space-1..8` = 4/8/12/16/20/24/32px), size (`sidebar-width` 248px, `topbar-height` 60px, `control-height` 36px, `row-height` 60px). **Structural, non-visual tokens stay untouched** (breakpoints `2xsm..3xl`, z-index scale `99/999/9999/99999`) — they were never part of the "generic template" complaint.
3. **Color semantics, one meaning per accent:** `cyan` = "act here" (active nav, primary button, link, focus ring) — reuses the existing `--p-primary-*` bridge, just repointed from `brand-*`. `purple` = "this needs you" (urgent status; in charts, profit specifically — e.g. `ChartCard`'s revenue bar is cyan, owner-profit bar is purple). Every status badge pairs colour + label + dot, never colour alone (`StatusBadge`'s full enumerated set: Paid/Pending/Failed/Refunded, Delivered/Processing/Pending supplier/Needs review/Failed/Not started).
4. **Font: Outfit → Figtree (UI) + JetBrains Mono (`code-id` token only, for order refs/SKUs/JSON payloads)** — both must be added as `next/font` loads (Figtree replacing the existing Outfit setup 1:1 in `layout.tsx`; JetBrains Mono new, scoped to the `code-id` token's usages, not a body-wide font swap).
5. **Ship light + dark together**, using the artifact's already-fully-specified dark pairs (every color group logged with a contrast ratio, 5.5:1–15.1:1) — admin's dark mode is a live, currently-used feature; shipping light-only and leaving dark half-migrated would be a regression, not a deferral. Any pre-existing dark-mode contrast/wording defect found on a surface this redesign already touches gets fixed in place (no extra cost, same file being edited); a defect found outside this redesign's touched surfaces gets logged to `docs/prd.md` §16, not chased proactively.
6. **Icons: swap all 53 hand-rolled-SVG files to `@primeicons/react`** (Option A over a recolor-only pass) — a mechanical import-path/prop change, not a visual restyle, so it doesn't expand the *visual* redesign's scope into the ~13 not-yet-mocked sections even though the Sidebar/TopBar the icons live in is shared app-wide. This closes the "PrimeReact + artifact tokens only" principle decision 1/2 establish — no third, parallel icon system left running alongside a component library that already ships one.
7. **Component-visual scope for this ADR is Orders (list, detail, all states) + Reports (all 8 tabs)** — the only two areas the artifact fully mocked and the only two this grill verified end-to-end. The remaining ~13 admin sections (games, vouchers, withdrawals, accounting, affiliates, resellers, users, settings, membership, gallery, seo, blacklist, reviews, customer-analytics) are explicitly **out of scope** until the founder produces their own mockups for them in the same artifact — this ADR's token layer (decision 2) still lands app-wide as part of the foundation PR, so those pages inherit the new colors/font/radius/shadow immediately even before their own layouts get attention, but no page-specific reskin work happens there yet.
8. **Text wordmark only** — "PekanGame" as plain text (matching the artifact, whose Assets tab is empty) stands in for a real logo; not a blocker, tracked on the existing pending real-logo-asset backlog item.
9. **Three separate PRs, in order:** (1) foundation — token layer (decision 2), font (decision 4), icon swap (decision 6); ships and is verified alone, since it's the lowest-risk, easiest-to-revert slice and touches literally every page. (2) Orders family. (3) Reports family. Matches this repo's standing precedent (the original TailAdmin→PrimeReact migration was also screen-by-screen per `docs/build-log.md`) and keeps the money-critical Orders surface reviewable independently of Reports.

**Rationale:** The founder's own artifact is not a wishlist — it is a coherent, contrast-verified, IA-faithful token system with real page mockups already drawn against this app's actual routes and domain data, so adopting it wholesale (rather than cherry-picking pieces) is lower-risk than it looks: nothing about the app's information architecture changes, only its token values and a subset of its pages' visual layer. Retiring TailAdmin's token *naming*, not just its component *primitives*, closes the actual root cause the founder correctly diagnosed mid-conversation — component migration (ADR-038) alone was never going to fix a look that lives one layer down, in the tokens. Scoping the visual reskin to Orders+Reports (decision 7) while still letting the token foundation (decision 2) land everywhere accepts a deliberate, temporary "new colors on old layout" look on unmigrated pages in exchange for not blocking any ship on a founder-side design backlog of ~13 more pages.

**Consequence to track:**

- PrimeReact's `--p-primary-*`/`--p-surface-*` bridges need 11/12 numeric stops each; the artifact's Colors tab defines a smaller *semantic* set (4 cyan stops, ~6 neutral/bg values) rather than a full numeric ramp — the build will need to derive the missing intermediate stops algorithmically from the defined ones. Low-stakes and reversible (a CSS-variable-only change), but worth a design-review pass once built rather than treating the generated ramp as final.
- `@primeicons/react`'s 358-icon set was spot-checked for coverage (wallet/ticket/receipt/users/shield/star/image/megaphone/percentage/calculator/chevrons all present) but not verified 1:1 against every one of the 53 files' actual icon names — decision 6's build will need sensible semantic substitutions for anything without an exact match (e.g. dashboard/game-controller/panel-collapse icons).
- Decision 7's "foundation lands everywhere, reskin stays scoped" means every one of the ~13 not-yet-mocked pages will visually look like "new colors, old TailAdmin-shaped layout" for an unknown stretch of time, gated entirely on the founder producing mockups for them — track this as a known, accepted interim state, not a bug, if raised later.
- Revisit decision 5's dark-mode contrast audit scope if a defect surfaces on a page decision 7 puts out of scope (games/vouchers/etc.) — this ADR's fix-in-place rule only covers surfaces the Orders/Reports PRs actually touch.

### 2026-10-08 addendum — PR-3 (Reports) re-scoped: content, money definitions, and build plan

**Status:** Accepted. Grilled with the founder (`/mattpocock-skills:grilling`, 3 rounds plus a closing round), then stress-tested and code-traced against real code and production before recording. **PR-A (backend) and PR-B (frontend foundation) built 2026-10-08, PR-C (the 7 tabs) built 2026-10-09 (see the build notes at the end).** PRD §16 item 65.

**Context.** The artifact's 8 Reports mockups were drawn on 2026-09-17. Since then the system gained reseller channels, combo, compensation settlement and real-cost capture, and the founder judged the live Reports page "not tidy, not professional". A side-by-side review (private artifact `https://claude.ai/artifact/F5gLCNTsLq4NydriLuvoRY`) compared every live tab with its mockup. It found four defects that are not visual:
1. "All time" makes the KPIs and tables span all history, while the trend chart silently shows the last 30 days (`ReportController` fallback). This is the same "two filters disagree" class that ADR-086 fixed, back again for "All time".
2. The Overview daily table has no row limit (4,269px tall on demo data).
3. Raw `payment_method` values, shown inconsistently: `fpx` in the bars, `Fpx` in the table.
4. RM rounding differs between a bar list (RM 1,934) and the table beside it (RM 1,934.45).

Every chart is hand-rolled SVG, with no chart library installed.

The code-trace also found that **Reports "Sales" and Accounting "Sales revenue" are different numbers by design.** Reports sums `final_amount` minus wallet refunds over every **paid** order, failed ones included (`Order::netSalesSql()`). The Monthly Summary sums `selling_price` over **delivered** orders, plus settled partial orders net of compensation (ADR-083 decision 8, ADR-094 decision 41). On prod (2026-10-08, 31 paid non-test orders): `final_amount` RM 528.43, wallet refunds RM 344.43, customer-paid transaction fees RM 20.00, checkout voucher discounts RM 15.30, delivered `selling_price` RM 160.90.

**Decisions.**

*Shape*
- **R1. Charts use Recharts.** It replaces `TrendChart.tsx` and `OrderStatusFunnelChart.tsx`. Series colours come from the artifact's chart tokens (`chart-1` cyan = sales/orders, `chart-2` purple = owner profit/margin, `chart-3` nude = affiliate profit, `chart-positive`/`chart-negative` for delivery health only). These are added to `admin/src/app/globals.css` with light and dark values.
  This **reverses ADR-086's PR-3 closure** ("decision 7 is retracted, not replaced", 2026-09-11). That closure kept hand-rolled visuals as the house style across three places: Dashboard Hourly Activity, Customer Analytics Monthly Spending, and Reports `TrendChart`. Recharts becomes the house style for new and reskinned charts. The Dashboard and Customer Analytics visuals stay as they are until their own pages are reskinned (ADR-104 decision 7 scope), so admin will carry two chart styles for that interim. This is accepted, not drift.
- **R2. 8 tabs become 7.** Payment Methods is merged into Sales: both rendered the same `paymentMethodBreakdown()`. The "avg per order" column moves into the Sales table. Affiliates is renamed **Partners**, matching the sidebar group, because it also holds resellers.
- **R3. Trend bucketing.** Daily, Weekly (Monday-start) and Monthly (KL calendar month, the same boundary as the Monthly Summary), aggregated client-side from the daily KL buckets. A bucket's margin is Σ profit ÷ Σ sales, never an average of daily margins.
- **R4. The mockup's generated "From this period's data" sentences are dropped.** A report shows figures, not interpretation. With few orders, such sentences mislead.
- **R5. Kept from the mockups:** KPI rows on Sales/Profit/Games, Profit by game, Delivery by game (one new grouped query), real game icons with a 2-letter fallback, Total rows, clickable KPI cards that open their tab, one Export menu (CSV/PDF, export content unchanged), Refresh with an "Updated X min ago" label, and drill-down links into `/admin/orders`.
- **R6. Dropped from the mockups:** Sales "Paid orders per day" (it duplicates the Overview chart's Orders toggle). The "View failed orders → Need action" link opens Orders' Failed filter instead. Need action (`Order::scopeNeedsAction()`) excludes compensated orders: prod has 7 failed and 0 needing action.

*Money definitions (code-traced)*
- **R7. "Sales" is renamed "Paid sales" and keeps its current definition.** It is money collected, the same seam used by Dashboard `sales_today` and the ADR-087 assistant. Below the Overview KPIs, a collapsible **Bridge to Accounting** walks Paid sales to recognised revenue:
  - Paid sales
  - − customer-paid transaction fees (Accounting books these under payment processing)
  - \+ checkout voucher discounts (Accounting counts pre-discount `selling_price`)
  - − orders not recognised: failed, in flight, or unsettled partial (their `selling_price` net of wallet refunds)
  - − compensation on settled partial orders
  - = **Recognised revenue**

  The identity holds per order (`final_amount = selling_price − voucher_discount + transaction_fee`, `CheckoutTotalService`). The revenue line comes from **one seam extracted from `MonthlyAccountingSummaryService::salesRevenue()`**, which the Monthly Summary then calls too, so the two pages cannot drift. The bridge carries an explicit **"Unexplained difference"** row that must be 0. An invariant test proves it, and a non-zero value is shown, never hidden. The bridge is hidden when the Affiliate filter is set, because the Monthly Summary is company-wide.
- **R8. Failed orders are a liability, not an expense.** A failed retail order's cash is held and owed back as store credit. Its profit is already 0, because `order_profit` is credited only on delivery. Profit on other orders is computed from markup and is independent of `voucher_discount` (ADR-024 context: `PricingService::calculate()`). Subtracting `voucher_issued` (prod: −RM 13.18) from owner profit (prod: RM 7.38) would double-count. So the Orders tab gets a **Failed & compensated** section: failed count and paid amount, then compensation split into voucher issued, wallet refund (already excluded from Paid sales) and checkout voucher restored. It is not netted against profit.
- **R9. Failure figures are scoped by the order's `paid_at`,** like every other Reports figure, with a visible note. The Monthly Summary scopes vouchers by voucher `created_at`, and the two can legitimately differ across a month boundary.
- **R10. Outstanding store credit** is shown on the Orders tab as a point-in-time "as of now" figure, like the Monthly Summary's reseller wallet balance. It is Σ `remaining` of active, unexpired **compensation** vouchers (`order_id` set, source order non-test). Marketing (Path A) vouchers are excluded: they are a discount promise, not customer cash held.
- **R11. One compensation-voucher definition.** Dashboard `vouchersIssued()` and `TransactionRegisterService::voucherRows()` already carry two hand-mirrored copies of "Path B voucher, non-test source order". The build extracts a `Voucher` query scope and routes Dashboard, Register and Reports through it. Reports' compensation aggregates are set-based SQL that mirrors `Order::cashCompensationSen()` / `compensationAmountSen()`, with an invariant test against the model methods over a fixture of every compensation form. This is the item-63 lesson about drifted copies.

*Compare period*
- **R12. "Previous period" by range type.** Last N days compares with the N days immediately before. This month compares month-to-date with the same days of last month, clamped to that month's end. A custom range compares with the same-length range immediately before. All time disables Compare and says why.
- **R13. Display.** Margin changes are shown in percentage points. When the previous period is empty, show "—" and "No data", never "+∞%". When the range includes today, the profit KPIs carry a short note that profit is recognised on delivery. Phase 1 puts deltas on every tab's KPI cards; Phase 2 adds a dashed previous-period line on trend charts.
- **R14. Reuse, don't copy.** `DashboardService::comparison()` already does period-over-period for the Dashboard. It is extracted into one shared helper (plus a points variant for margin) and used by both. Previous-period figures are a second call to the same `ReportService` methods. There is no new aggregation logic.

*Channels*
- **R15. Sales by channel uses three exclusive buckets, checked in this order:** Reseller wallet (`wallet_reseller_id` set; reseller orders also carry an `affiliate_id`), Own brand (affiliate `is_owned`), External affiliate. Prod: 7 / 20 / 4. The split is shown as Partners-tab cards and a Sales-tab bar list. It is not a global filter. It buckets by the affiliate's **current** `is_owned`, so flipping that flag re-buckets history. This is accepted and labelled.
- **R16. New `orders.placed_via` column**, `storefront | reseller_api | reseller_bot | sandbox`, backed by a PHP enum, `NOT NULL` with no DB default:
  - It is set through a **required** `OrderDraft` constructor argument, so every `OrderFactory` caller is type-forced to pass it.
  - `SandboxOrderController` creates orders directly and sets `sandbox`.
  - The reseller portal places no orders. Only `ResellerApi\OrderController` and `ResellerBotService` call `ResellerOrderPlacementService`.
  - Backfill: wallet orders with an idempotency key prefixed `wa:` become `reseller_bot` (all 7 on prod). Other wallet orders become `reseller_api` (0 on prod). Everything else becomes `storefront`, except `is_test` orders created by the sandbox, which become `sandbox`.
  - It is shown only as an API-vs-Bot split in Partners. Orders list/export are unchanged.
  - The name avoids `channel_code`, which is CHIP's payment channel.

*Fixes for the four defects*
- **R17. "All time" trend** spans the first paid order to today and picks a bucket automatically: ≤ 62 days daily, ≤ 1 year weekly, otherwise monthly. The toggle can override it. The 30-day fallback in `ReportController` is removed.
- **R18. The Overview breakdown table follows the chart bucket,** has a Total row, and pages at 31 rows.
- **R19. One payment-method label map** in `admin/src/lib` (`fpx` → FPX, `wallet` → Wallet, `voucher` → Voucher (full cover), `card`, `duitnow_qr` → DuitNow QR, Title Case fallback). Order Detail reuses it.
- **R20. RM always has two decimals** in every bar list and label.

*Build order*
- **R21.** Three PRs into `staging`:
  - **PR-A, backend, test-first:** R7 seam + bridge, R8/R10/R11, R12–R14, R15, R16 with backfill, R17, Delivery by game. It changes no UI and is reviewed alone.
  - **PR-B, frontend foundation:** Recharts, chart tokens, shared Report components, header/controls, R19.
  - **PR-C, the 7 tabs.**
  - Then a light and dark live-browser audit per tab (screenshot + `getComputedStyle`), then docs.

**Rejected.**
- Netting compensation against profit (R8, double-count).
- Making Reports "Sales" equal Accounting revenue (it would lose money collected, which Dashboard and the assistant share).
- A global channel filter (31 orders; the Affiliate filter already isolates Ohahastore).
- Inferring API vs Bot from the `wa:` prefix at query time (an API client may send any key).
- Building a combo sales split now (1 combo order ever).

**Stress-test findings folded into the decisions above:**
- ADR-086's deliberate hand-rolled chart house style, which R1 reverses on record
- the existing Dashboard comparison helper (R14)
- the two duplicated Path B voucher definitions (R11)
- the "not recognised" bucket also holding in-flight orders, not only failed ones (R7 wording)
- the Accounting revenue population not filtering `payment_status` (0 delivered-but-unpaid orders on prod; the R7 "Unexplained difference" row makes any future case visible instead of silently absorbed)
- `SandboxOrderController` bypassing `OrderFactory` (R16)
- weekly margin averaging (R3)

**Consequence to track.**
- `MonthlyAccountingSummaryService::voucherLiabilityIssued()` sums **all** vouchers, Path A included, while the Transaction Register and Dashboard count Path B only. Prod has no Path A voucher yet (5 vouchers, all compensation), so no figure is wrong today. Which definition the accounting line should use is a question for the external reviewer. PRD §16 item 75, not part of this build.
- R17 zero-fills every day from the first paid order. About 1,100 rows after 3 years is fine. Move bucketing server-side if that becomes slow.
- R15's current-attribute bucketing: if an affiliate's `is_owned` ever flips, history moves with it.
- Reseller portal ordering, if ever built, needs a `reseller_portal` `placed_via` value at its call site. The required `OrderDraft` argument forces that decision.
- `VoucherService::merge()` gives the merged voucher `order_id = null`, so merged compensation vouchers drop out of R10's outstanding store credit (and out of the Dashboard and Register Path B counts, which predate this). Prod has 0 merges (checked 2026-10-08). Fix needs a decision on mixed Path A + B merges.

**Build note, 2026-10-08 — PR-A (backend) built** on `feature/2026-10-08-reports-pr-a`, as designed, with two implementation choices:
- The Compare mode is an explicit `?compare=previous|month_to_date` on `/reports/summary`, chosen by the page from its preset (dates alone can't tell "This month" from a "Last 7 days" starting on the 1st).
- The Bridge's "not recognised" line nets the new `Order::walletRefundSql()` from `selling_price` (`netSalesSql()` now composes it). Computing `selling_price - final_amount` overflowed MySQL's unsigned columns, which sqlite did not catch.

See `docs/build-log.md`, 2026-10-08.

**Build note, 2026-10-08 — PR-B (frontend foundation) built** on `feature/2026-10-08-reports-pr-b`, as designed, with four implementation choices:
- `OrderStatusFunnelChart` is plain HTML, not Recharts: the mockup's form (a share bar and labelled bar rows) needs no axes or scales. The trend charts are Recharts, as stacked panels with one y-scale each.
- The chart tokens sit in a Tailwind `@theme static` block. Recharts reads them only as `var(--color-chart-*)`, which Tailwind's pruning can't see.
- `chart-negative` now aliases `danger-ink` (it has a dark value), matching the artifact's `tokens.json`. It was a fixed hex, filed as "inherits light", and had no reader yet.
- Compare is wired end to end on Overview's KPI cards only; the other tabs take it in PR-C with the rest of R13 Phase 1.

See `docs/build-log.md`, 2026-10-08 (PR-B).

**Build note, 2026-10-09 — PR-C (the 7 tabs) built** on `feature/2026-10-09-reports-pr-c`, as designed, with these choices and findings:
- **Default range stays All time** (founder, 2026-10-09: the whole-business view matters more than matching the mockup's Last 30 days). Compare stays disabled on All time per R12.
- **R13 Phase 1 reaches Partners and Membership through the backend.** Their KPIs are not summary figures, so `/reports/membership-breakdown` and `/reports/breakdown/channels` take the same `?compare=`. `ReportService::compareWith()` is the one seam; `summaryComparison()` now routes through it too. The frontend never computes a change.
- **R5 game icons:** game rows (`gameBreakdown`, `deliveryByGame`) now carry `image_url`.
- **R6 "View failed orders":** Orders had no Failed status filter (Need action excludes compensated orders, so it is a different list). The founder chose to add one; it is recorded as ADR-108's 2026-10-09 addendum, and the button opens `/admin/orders?status=failed`.
- **Membership share figures** are labelled "member + standard", not "paid orders": on prod, reseller-wallet (7) and affiliate (4) orders have their own `pricing_basis` and sit outside that tab.

See `docs/build-log.md`, 2026-10-09.

### 2026-10-09 addendum — R8 revised after a pre-release money audit

**Status:** Accepted. Grilled with the founder (`/mattpocock-skills:grilling`, 2 rounds, 8 questions) after a three-layer code audit (checkout and pricing; ledger, fulfillment and compensation; downstream readers) plus a read-only prod reconciliation. The prod figures matched: Paid sales RM 184.00 walks to recognised revenue RM 160.90 = Monthly Summary (Sep RM 157.81 + Oct RM 3.09), unexplained RM 0.00; profit only on delivered orders; the 7 failed orders' compensation (RM 362.83) equals their `selling_price`.

**Context.** The audit found no wrong figure on the page, but two places where the page's meaning was off, plus a stale export:
1. "Failed & compensated" summed compensation over **all** paid orders beside a count of failed ones, and its copy ("never taken off profit") is false for a settled partial delivery: `OrderSettlementService::creditDeliveredProfit()` deducts that compensation from the order's profit.
2. "In progress or review" included partially delivered orders, which can be final (settled).
3. The Reports CSV/PDF "Sales" column was gross `final_amount`, not Paid sales (prod: RM 528.43 vs RM 184.00). This predates the redesign; R5 kept export content unchanged.

**Decisions.**
- **R8a. One block per delivery status** (`ReportService::failedAndCompensated()`): `failed` (a liability, not netted from profit), `partially_delivered` (compensation already inside that order's profit), and `other`, compensation on any other status. `other` exists because a late payment on a compensated order turns it NeedsReview (`OrderPaymentOutcomeService`); without it that compensation would fall between blocks. The invariant test holds Σ of all blocks to `Order::cashCompensationSen()` / `compensationAmountSen()`.
- **R8b. "Awaiting compensation"** per block reuses `Order::scopeNeedsAction()`, the scope behind Orders' Need action pill, and links there. No second SQL copy of "compensated".
- **R8c. Partially delivered is its own status bucket:** a row in the funnel share bar and the Delivery performance card (settled · awaiting), and a "Partial" column in Delivery by game. "In progress or review" is now only orders not yet resolved.
- **R8d. Delivery rate stays strict:** delivered in full ÷ paid. A partial delivery is not a success.
- **R8e. Outstanding store credit is unchanged:** every live compensation voucher, partial ones included, as of now.
- **R8f. All figures keep R9's `paid_at` scoping.**
- **R5 revision — export.** CSV/PDF carry *Paid*, *Wallet refund* and *Paid sales* per order, selected from `Order::walletRefundSql()` / `netSalesSql()`. Matches the Orders workbook. A test holds Σ export Paid sales = `summary().total_sales`.

**Rejected.** Rewording the copy only (the sum beside it would still mix statuses). Showing partial compensation only in the Bridge (hides money from the Orders tab). Weighting a partial delivery in the success rate (a new formula that is hard to explain).

**Audit claim corrected.** One audit layer reported that the Bridge invariant test has no partial combo. It has both a settled and an unsettled one (`ReportAccountingBridgeTest::seedEveryShape`), so nothing changed there.
