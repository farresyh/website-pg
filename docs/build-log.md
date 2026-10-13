# PekanGame — Build Log

> The running chronological record of what shipped, when, and why — every
> session, the bugs hit along the way, and the live-verification notes.
> Moved out of `prd.md` §14 on 2026-09-11 once it outgrew the spec doc.
>
> **This file is append-only history.** Current per-feature status lives in
> `prd.md` §15; the live backlog is `prd.md` §16; decision rationale is the
> ADR log (`adr.md` index → `adr/`). **Everything before 2026-10-09** lives verbatim in
> [`build-log-archive.md`](./build-log-archive.md): the foundation build, the
> old §14 table and PrimeReact tracker, and the production-era entries
> 2026-09-01 → 10-08 (moved in five passes, the last on 2026-10-12) to keep
> this file quick to read at session start.

---

## 2026-10-09 — Reports redesign PR-C: the 7 tabs (item 65, ADR-104 R2, R4–R10, R13, R15, R18)

On `feature/2026-10-09-reports-pr-c`. Staging-only until a release.

- **Tabs (R2):** Overview, Sales, Profit, Orders, Games, Partners,
  Membership. Payment Methods merged into Sales (with Avg order); Affiliates
  renamed Partners. Each tab rebuilt on `ReportKit` in its mockup's shape.
- **Overview:** collapsible Bridge to Accounting (native `<details>`,
  hidden under an Affiliate filter), top-3 games with "All games →", and
  the breakdown table following the chart's Daily/Weekly/Monthly bucket
  (R18) with a Total row and 31-row paging.
- **Sales:** KPIs, payment-method and game bar lists, payment method detail
  table, Sales by channel (R15). The mockup's "Paid orders per day" is
  dropped (R6).
- **Profit:** KPIs incl. margin in pts, the two trend charts, Profit by
  game. "From this period's data" dropped (R4).
- **Orders:** delivery health + performance, **Failed & compensated**
  (R8–R10, with the `paid_at` vs voucher `created_at` note) and outstanding
  store credit, Delivery by game (lowest rate first).
- **Games:** KPIs, game icons, filter box (Total row hidden while filtered).
- **Partners:** three channel cards with the API vs Bot split (R16).
- **Compare (R13 Phase 1)** on every tab's KPIs. Backend: membership and
  channel endpoints take `?compare=`; one `ReportService::compareWith()`
  seam, `summaryComparison()` routed through it. Game rows carry
  `image_url`. Both test-first (`ReportControllerTest`,
  `ReportChannelAndDeliveryTest`).
- One `useReport()` hook replaces each tab's hand-copied stale-response
  guard; failed fetches now show an error instead of "Loading…" forever.
  Nine superseded components deleted.
- **Default range stays All time** (founder decision 2026-10-09).
- **Verified:** backend 2669/2669; admin tsc, eslint, `npm test`, build.
  Local production build against the dev DB (Playwright): all 7 tabs in
  light, dark, 390px and Last 90 days + Compare; no console errors, 0px
  horizontal overflow, each tab compared against its mockup. Bridge on the
  dev DB walks RM 5,240.18 → RM 4,774.38 with RM 0.00 unexplained.
- **R6 "View failed orders"** (founder chose option 1 the same day):
  Orders gains a **Failed** pill, `?status=failed`, every failed delivery
  compensated or not (ADR-108 2026-10-09 addendum, test-first in
  `OrderControllerTest`). Orders reads `?status=` from the URL. Verified:
  the button lands on the Failed pill with 16 rows (= Reports' 16 failed),
  both themes. The PrimeReact `bg-primary` pair measured 4.23:1 in light
  (below 4.5 for 13px), so light uses the artifact's `cyan-600` + `on-cyan`
  (5.59:1) and dark keeps `primary` (5.53:1).
- **Gotchas.**
  - Local backend CORS allows only `localhost:3000/3001/3002`. A built
    admin on another port (or on `127.0.0.1`) logs in and then bounces
    back to `/login`.
  - `./scripts/dev.sh` has no backend URL of its own: the frontends call
    `NEXT_PUBLIC_API_URL` (`https://kedairuncit-backend.test`, Herd), not
    the `php artisan serve` it starts on :8000. With Herd stopped, admin
    login fails with `ConnectTimeoutError ... kedairuncit-backend.test:443`.
    Start Herd first.
  - Dev seed wallet orders carry `pricing_basis = standard` (prod uses
    `reseller-wallet`), so locally Membership's member + standard sales
    exceed Paid sales by the wallet refunds. Prod is unaffected (checked
    read-only 2026-10-09: member 1, standard 19, reseller-wallet 7,
    affiliate 4).

## 2026-10-09 — Reports pre-release money audit + R8 revision (item 65, ADR-104 addendum)

On `fix/2026-10-09-reports-money-audit`. Before releasing the redesign,
the founder asked whether every Reports figure matches the money-critical
ledger from checkout to delivery.

- **Audit:** three read-only forks (checkout and pricing; ledger,
  fulfillment and compensation; downstream readers) plus a read-only prod
  reconciliation via `php artisan tinker` (SELECTs only). Prod matched:
  - Paid sales RM 184.00 = RM 528.43 collected − RM 344.43 wallet refunds.
    That is FPX RM 66.98 + wallet net RM 117.02.
  - Bridge → RM 160.90 = Monthly Summary, RM 0.00 unexplained.
  - Owner profit RM 7.38 and affiliate RM 0.15, all on delivered orders.
    Order 15's ADR-105 manual correction (+37, −10 sen) nets as intended.
  - The 7 failed orders' compensation RM 362.83 equals their `selling_price`.
  - No order breaks `final = selling − discount + fee`.
- **Fixed (test-first):**
  - Reports CSV/PDF now carry Paid / Wallet refund / Paid sales, from the
    `netSalesSql()` seam. This was live on `main` before the redesign.
  - "Failed & compensated" split into Failed / Partially delivered /
    Other blocks, each with "awaiting compensation" from
    `scopeNeedsAction()`.
  - Partial is its own bucket in the funnel, the performance card and
    Delivery by game.
  - Stale texts: the Dashboard `sales_today` definition, and the Orders
    workbook "Total Sales" → "Paid sales".
- **Gotcha:** `Eloquent\Collection::only()` filters by model primary key,
  not by collection key. A `keyBy('delivery_status')` result needs
  `->toBase()` first, or every block silently reads 0.
- **An audit claim that didn't hold:** "the Bridge test has no partial
  combo" — it has both. Verify fork findings before acting.
- **Verified:** backend 2674/2674 (sqlite); report and dashboard tests
  on docker MySQL; admin tsc/eslint/build; Orders tab screenshot, light
  and dark, against the dev DB.

## 2026-10-09 — Release #385 (staging → main): #377–#384

Founder-requested release, merged 2026-10-09 (`6db0470`). Ships the Reports
redesign (#380–#384, item 65), the combo component SKU (#379), the e2e
`next start` boot (#378) and docs (#377). CI on `main` is all green,
`playwright` included (it failed on the #376 release; #378 fixed that).
The deploy job succeeded.

- **Deploy window:** `add_placed_via_to_orders_table` runs before the new
  release activates, so old code inserting an order in those seconds would
  fail on NOT NULL (no money moves). Released at ~03:30 KL, low traffic.
- **Verified on prod (read-only tinker):**
  - The box serves `6db0470`, the migration is `Ran`, and `/up` returns 200.
  - `placed_via`: 28 storefront, 7 reseller_bot, 0 null — exactly the
    backfill.
  - Summary: Paid sales RM 184.00, 31 orders, owner RM 7.38, affiliate
    RM 0.15.
  - Bridge: RM 184.00 → RM 160.90 (= Monthly Summary), RM 0.00
    unexplained.
  - Failed block: 7 orders, voucher RM 13.18 + wallet RM 344.43 +
    restored RM 5.22, 0 awaiting. Partial and other: 0.
  - Outstanding store credit RM 3.10. Export net sum RM 184.00.
  - Channels sum to RM 184.00 (58.79 + 117.02 + 8.19). Profit 3.68 + 3.35
    + 0.35 = RM 7.38.

## 2026-10-09 — Docs restructure + full markdown sync (docs only)

The founder asked for every `.md` file to agree with each other and with the
code, so future sessions follow correct instructions.

- **ADR log split.** `docs/adr.md` was 2 MB, with a 45k-character index whose
  rows had grown into build-status summaries.
  - Each of the 119 ADRs is now its own file, `docs/adr/ADR-NNN-slug.md`,
    copied verbatim: a check confirmed all 7,174 body lines match the old
    sections exactly.
  - Each file adds a **Standing** banner and turns its heading into `#`.
  - All 48 cross-ADR anchors and the `adr.md#…` links in other docs now point
    at the new files. 503 relative links were checked; none are broken.
  - `adr.md` is now the index only (25 KB): rules for adding an ADR or
    addendum, then one row per ADR with title, Standing and last addendum
    date.
  - Build/live status was removed from the index. It lives only in `prd.md`
    §15, which is how the old index rows and the PRD had contradicted each
    other.
  - Standing marks the superseded or revised decisions: 001, 003, 008, 013,
    014, 015, 017, 019, 020, 022, 029, 030, 053, 086, 098 and 102; 070 is
    reserved; 115 is parked; 118 is design only.
- **Audit.** Three read-only forks covered the agent/readme files,
  `prd.md`, and the public docs-site plus the supporting docs. Every
  finding acted on was verified against the code first. Fixed:
  - `prd.md`:
    - §15 said Reports PR-3 was "not yet built".
    - MFA was listed as MVP/required (it is descoped).
    - §7.1 described a Xendit payment flow.
    - Old `reseller_profit` names; Phase-2 "in build" wording.
    - The Order/LedgerEntry rows lacked `partially_delivered`,
      `placed_via` and the wallet ledger types; `KRS-` → `PG-`.
    - Three long §15 rows compacted, and three archive pointers fixed.
  - `AGENTS.md`:
    - The ADR workflow now points to per-file ADRs.
    - New "one fact, one home" rule and a build-log archiving rule.
    - Herd note on `dev.sh`.
  - `README.md`: broken setup commands (`reseller/.env.local.example`
    doesn't exist; storefront copy step missing), Node 22, ADR and
    build-log pointers.
  - `backend/AGENTS.md`: `orders-combo` is chosen by `FulfillOrderJob`, not
    `orderLane()`; added the Digiflazz and CHIP-webhook smoke tests; gotcha
    1 now covers `.env.example`'s `QUEUE_CONNECTION=database`. The config
    itself is unchanged, because CI copies it.
  - `reseller/AGENTS.md`: the third Next.js app; API docs at
    `docs.pekangame.space`.
  - `storefront/DESIGN.md`: four affiliate presets, all with dark tokens
    (ADR-113). It said one.
  - Public API docs:
    - Product codes are not case-sensitive.
    - Wallet top-up is FPX only (no card), and the Bot can top up too.
    - Price sync runs about hourly, not every 30 minutes.
    - Auth failures are limited to 20/min per IP.
    - Webhooks time out at 10 s and don't follow redirects.
    - `/v1/balance` path style.
  - `foundation-security.md`: the compensation forms (wallet refund,
    restore, partial settlement); stale pointers.
  - `legacy-reference-notes.md`: "supplier stays Gamevion / reseller scope
    stays Phase 2".
  - `build-log-archive.md`: history-only banner and header range.
- **Not changed, noted:** comment at `ci.yml:228` still names the old
  `build-and-push` job; `storefront/src/lib/theme-presets.ts:23` comment
  still says only `default` has dark tokens. Both are code comments, not
  docs.
- **Incidents.**
  - One audit fork ran a read-only SSH `grep -c PRICE_SYNC_INTERVAL` on
    the prod `.env`, against its no-production instruction. It counted the
    key only; no value was read and nothing was written.
  - A fork reported `docs-site/CLAUDE.md` as a duplicate of `AGENTS.md`. It
    is a symlink to it. Writing `@AGENTS.md` into it overwrote
    `docs-site/AGENTS.md`; restored from git at once, and no change was
    committed.
  - Lesson: check `ls -l` before writing to a file a fork calls a copy.

## 2026-10-09 — Admin `/admin/games` package order: cost price breaks denomination ties

- **Why.** Founder saw three "60 UC" supplier variants (RM 3.63 / 3.72 /
  3.64) in the wrong order. `GameController::packages()` ordered by
  denomination then name only; same-name variants fell back to row order.
- **What.** One ORDER BY: denomination ↑ → `cost_price` ↑ → name. The
  no-denomination tail (passes/bundles, with or without `catalog_code`) keeps
  name first, then cost, so "Weekly Pass 1..5" don't interleave by price.
  Inactive packages sort by cost like active ones.
- **Scope.** Admin list only. `CatalogController` and
  `Package::dedupeActivePerGame()` show one package per denomination, so there
  is no tie to break; storefront, reseller and bot output are unchanged.
- **Prod scale (read-only).** 588 (game, denomination) groups have more than
  one package; 556 of them differ in cost.
- **Verified.** New test in `GameControllerTest` (red, then green); full
  `php artisan test` 2675 passed; pint clean. No migration, no cache key change
  (the cached closure is the same; entries refresh within the cache TTL or on
  the next package change).

## 2026-10-10 — Monthly Summary: a CHIP settlement that spans a month end now counts

- **Why.** Found by the code-trace pass for the Envelope Ledger reshape
  (ADR-083 addendum, being grilled). `paymentProcessingGainLoss()` kept a
  settlement batch only when both `date_from` and `date_to` fell inside the
  month. A weekly batch such as 28 Sep–4 Oct failed both months, so its fee
  gain/(loss) counted nowhere.
- **What.** The batch belongs to the month of its `date_to`, once (founder's
  call, grill Q22). This is the only reader of settlement windows for a
  monthly figure. `paidButNotSettled()` takes one settlement's own window and
  is unaffected.
- **Prod (read-only).** All 4 settlements (3–19 Sep, 20–21 Sep, 22–26 Sep,
  6 Oct) sit inside one month, so no past month's figure changes.
- **Verified.** New test in `MonthlyAccountingSummaryServiceTest` (red: Oct
  showed 0 instead of 50, then green); full `php artisan test` 2676 passed;
  pint clean. No migration.

## 2026-10-10 — Envelope Ledger reshape PR-1: postings, director loans, transfer date (item 78, ADR-083 addendum)

- **What.** ADR-083 2026-10-10 addendum, decisions 1–8, 15 and 16.
  - **Postings.** One `budget_envelope_postings` header per action over
    signed lines in `budget_envelope_entries`. Types: funding (loan /
    share capital), transfer, expense, director-paid expense, repayment,
    distribution, and profit allocation (still the manual monthly action
    until PR-2).
  - **The service is the only writer** (`BudgetEnvelopeService`). It
    enforces each type's line shape and required fields, caps a repayment
    at the director's computed loan balance, and voids a whole posting by
    reversal (unique `reverses_posting_id`). It refuses a void that would
    leave a loan below zero. All writes go under one envelope lock
    (`ponytail:` comment, founder-only volume).
  - **Archiving** an envelope needs a zero balance.
  - **`supplier_transfers.transferred_on`** is required, correctable via
    Edit Details, and drives the Monthly Summary top-up/fee lines, the
    Transaction Register row date and range, and the Funding History
    filter. Existing rows were backfilled from `created_at` as a KL date.
  - **`PaidFrom`:** Luqman is renamed Lokman.
  - **`LedgerEntry`** is append-only at the model layer.
  - **Admin:** one Record form, an "Owed to directors" card, red
    over-budget balances, and labelled director-paid-expense lines. The
    envelopes page is split into PostingForm / EntriesTable /
    AllocateProfitForm (it was 622 lines).
- **Migration guard.** The reshape migration throws if
  `budget_envelope_entries` holds pre-posting rows. It will not guess
  whether an old "capital injection" was a loan or share capital.
  Production had 0 entries and 0 `luqman` rows (re-checked read-only
  2026-10-10). **Don't record Envelope Ledger entries on production before
  this releases**, or the deploy's migration will stop. The local dev DB
  held a 2-row tinker test pair (net RM0) from an older session; it was
  cleared so the migration could run there.
- **Verified.**
  - 21 new service invariant tests (every decision-3 row, void,
    loan-balance edges).
  - The controller test file was rewritten for the new endpoints, plus new
    `transferred_on` / Lokman / register / summary tests.
  - New tests were red first (the `transferred_on` set: 4 failed, 2
    errored; the envelope set: 30 of 32), then green.
  - Full `php artisan test`: 2691 passed.
  - On real MySQL: the concurrency suite passed 30/30 (it migrates up and
    down each test, so it covers both new migrations and their `down()`).
    112 accounting tests passed via a temp config. `SHOW INDEX` confirms a
    standalone index on every new FK.
  - Admin `tsc`, `eslint` and `next build` are clean.
  - Live browser (built admin + `artisan serve`, local dev DB): RM500
    Lokman loan split Rolling 300 / Ops 200 → owed RM500. Tune Talk RM20
    expense. Repaying RM600 rejected with "only owes … RM500.00".
    Farres-paid RM17.52 → owed RM517.52, envelope net 0. Voiding the RM20
    expense put Ops back to RM200, with the original struck through.
    Supplier Funding modal shows the transfer date. Test data cleared
    afterwards.
- **Gotcha hit.** A test helper named `post()` clashed with Laravel's HTTP
  `post()` and fataled the suite; it was renamed `record()`.

## 2026-10-10 — Envelope Ledger reshape PR-2: month close and cash equation (item 78, ADR-083 addendum)

- **What.** ADR-083 2026-10-10 addendum, decisions 9–14 and 17.
  - **Month close** (`MonthCloseService`, `accounting_period_closes`), on
    the Monthly Summary page. It closes one KL month at a time, in order,
    from September 2026, once the month has ended in KL time. It allocates
    exactly operating profit + prior-month adjustment, as one
    `profit_allocation` posting dated the month's last day. The founder
    picks the split per envelope; a loss allocates negatively; a zero month
    writes no posting. It is now the only writer of `profit_allocation`. A
    direct void of one is refused; reopening the latest close voids it.
  - **Prior-month adjustment** = Σ earlier closed months' live operating
    profit − Σ what was allocated for them, so a closed row is never
    rewritten and the allocations always add up to the live total.
  - **Operating profit** (`MonthlyAccountingSummaryService::operatingProfit()`)
    plus new Summary lines: voucher breakage, goodwill vouchers (Path A,
    not a merge target), supplier balance corrections (non-void
    `MANUAL_ADJUSTMENT` in MYR), envelope manual expenses (information
    only). The FX true-up's blended rate now stops at the month's last KL
    day (Q24), so the existing FX line no longer moves with later transfers.
  - **Cash equation** (`CashPositionService`): supplier prepaid at the
    month-end rate, CHIP paid-not-settled less RM1 each (new
    `SettlementReconciliationService::paidButNotSettledBefore()`), against
    envelopes, reseller wallets, affiliate earnings, approved-unpaid
    affiliate withdrawals, vouchers outstanding (rebuilt from redemptions)
    and orders paid but not delivered or compensated. A gap above RM1
    needs a note and never blocks. The envelope identity check runs beside
    it. The close snapshots the equation and the cash balances.
  - **Cash accounts** (`cash_accounts`, name only), seeded with "Held by
    Farres (mixed)"; add/rename/archive from the close form.
  - **Removed:** the Envelope Ledger's "Allocate Monthly Profit" form,
    route and service method, and the rough P&L estimate. The platform
    "Request Withdrawal" button, "Available balance" header, `POST
    /api/withdrawals` and `CreateWithdrawalRequest` (decision 17).
- **Choices the ADR left open.**
  - An undelivered order is a claim at its `selling_price`: that is what
    the customer is owed in goods. A voucher or wallet balance spent on it
    moved into this claim when spent.
  - **Known residual gap:** revenue is recognised by `paid_at`, but the
    supplier drawdown is costed when recorded. An order paid on the last
    day and drawn the next leaves a month-end gap of about its margin. That
    is the timing gap decision 13 expects a note for.
- **Verified.**
  - New tests red first, then green: 6 in `MonthlyAccountingSummaryServiceTest`,
    10 in `MonthCloseServiceTest` (incl. a whole month across every module
    whose equation closes at exactly 0 and whose identity check agrees),
    3 in `MonthCloseControllerTest`. Withdrawal and envelope controller
    tests rewritten for the removals.
  - Full `php artisan test`: 2707 passed; pint clean. The new tests also
    pass on real MySQL (34/34 via `phpunit.concurrency.xml`).
  - Admin `tsc`, `eslint`, `next build` clean.
  - Live browser (built admin + `artisan serve` on a throwaway sqlite DB,
    not the dev DB): September scenario showed operating profit RM8.00,
    gap RM0.00 with RM495 cash; closing it showed the snapshot and the
    Reopen form. Gap box contrast 5.80 (light) / 8.19 (dark). No Allocate
    button on Envelope Ledger; no Request button on Withdrawals.
  - Local dev DB migrated (plain `migrate`).
- **Gotcha hit.** `text-success-700`/`-400` and the other `-700`/`-400`
  status shades are not defined in `globals.css`; they silently render
  inherited colour. The new components use the theme-aware
  `*-surface`/`*-ink` tokens instead.

## 2026-10-10 — Docs sync after the Envelope Ledger reshape (item 78)

- **Why.** Before releasing #390/#391, every doc was checked for text the
  reshape made untrue.
- **Changed.**
  - ADR-083 banner and addendum status no longer say "design only"; the
    banner and index row record that decision 17 retires the platform
    withdrawal. ADR-061's banner and index row mark its decision 7
    (`('platform', null)` withdrawn from `/admin`) as retired by it.
  - PRD §6.10 / §7.4 describe withdrawals as Affiliate requests; §15
    Withdrawals row notes the removal (staging); §14 lists what is on
    `staging` but not `main` (it still said `staging` = `main` apart from
    #386); item 78 records both PRs merged and what is left after the
    release; items 79/80 say why neither changes an envelope figure; the
    glossary pins Envelope, Posting, Director loan balance, Operating
    profit, Month close, Prior-month adjustment, Cash equation / gap.
  - The Monthly Summary's FX true-up tooltip said "not a cash gain or
    loss"; it is now part of operating profit, so the copy says so.
- **Checked, no change needed:** no backend code reads the platform ledger
  balance any more (every remaining reader reads `order_profit` only);
  earlier ADR-083 decisions (FX rate over the whole history, manual
  allocation) are overridden by the addendum under the file's own
  "later addendum overrides" rule.

## 2026-10-10 — Release #393 (staging → main): #386–#392

Founder-requested release, merged 2026-10-10 (`218f0d8`). Ships the Envelope
Ledger reshape (item 78: #390 postings and director loans, #391 month close,
cash equation and removal of the platform withdrawal), the settlement
month-span fix #388, the admin package-order tiebreak #387 and docs
#386/#389/#392. CI on `main` all green, `playwright` included; the deploy job
succeeded.

- **Pre-release check (prod, read-only):** `budget_envelope_entries` 0 rows
  (the reshape migration's guard would pass), 5 `supplier_transfers` to
  backfill, 0 withdrawals, server on `6db0470`.
- **Verified on prod after the deploy (read-only tinker):**
  - The box serves `218f0d8`; `/up` returns 200.
  - All three 2026-10-10 migrations are `Ran`.
  - `transferred_on` on all 5 transfers equals their KL recording date
    (14, 15, 15, 19, 21 Sep); 0 null.
  - `cash_accounts` holds "Held by Farres (mixed)"; 0 postings, 0 closes.
  - September 2026 preview: closable, operating profit RM 4.64. Position
    before any posting: supplier prepaid RM 570.05, reseller wallets
    RM 347.96, affiliate earnings RM 0.15, vouchers outstanding RM 0.97,
    CHIP unsettled 0, undelivered orders 0; envelope identity agrees.
- **Next (founder):** record the real September postings, then close
  September 2026 (§16 item 78, list C).

---

## 2026-10-10 — §16 list C: item 59 decided (Baileys stays), item 78 verified (docs only)

- **Item 59.** Prod read-only: OpenWA `ENGINE_TYPE=baileys`; box 3.9 GB RAM
  (2.1 GB used, 1.8 GB available), OpenWA about 230 MB. The founder chose to
  keep Baileys until the droplet is upgraded. ADR-075 2026-10-10 addendum
  records the reasons and the triggers; item 59 now waits with item 4.
- **Item 78, read-only check of the September close (prod).** Operating profit
  recomputed live equals the closed RM 4.64 (drift 0, every `profit_lines` entry
  the same). The cash equation recomputes to the stored gap of -RM 10.94, which
  the founder's note explains (two pre-15-Sep CHIP payouts, RM 0.94 + RM 10.00).
  Envelope identity (headers) equals the line sum, RM 312.34; hand check
  RM 500 − RM 192.30 expenses + RM 4.64 = RM 312.34. 0 expense postings
  without a receipt. The RM 17.52 expense dated 5 Oct sits outside September.
  Expect the October close to show the same -RM 10.94 unless explained again.

## 2026-10-10 — §16 item 9 done: DNSSEC on both domains, DB connection on TLS

- **DNSSEC.** Enabled in Cloudflare for `pekangame.space` and `pekangame.com`.
  `.space`: DS (key tag 2371, algorithm 13, SHA-256) added at the Vercel registrar
  (team JW Brothers); visible at the registry about 5 minutes later. `.com`: Cloudflare
  Registrar added the DS itself. After: `ad` flag on 1.1.1.1, 8.8.8.8, 9.9.9.9 for both
  domains; API health 200, storefront 200, docs 200, bot 200, admin and reseller 307.
  One resolver answered SERVFAIL for a moment when the `.space` DS first showed up.
- **DB TLS, before.** Prod `.env` had no `MYSQL_ATTR_SSL_CA`. From the app, `SHOW SESSION
  STATUS LIKE 'Ssl_cipher'` was empty, so the link to the managed MySQL (VPC host
  `private-…`) was unencrypted. PRD's "VPC-private + TLS" was wrong.
- **What ran on prod.** The CA (`ca-certificate.crt`, 1 certificate, no private key,
  checksum equal to the founder's download) was copied to
  `~/api.pekangame.space/`. A standalone PHP test first: without the CA `tls=NONE`, with
  CA + server-certificate check TLSv1.3. Then the founder ran: `.env` copy to
  `.env.bak-before-dbtls` (mode 600), one appended line `MYSQL_ATTR_SSL_CA=…`,
  `config:cache`, `horizon:terminate`, `pulse:restart`.
- **After (read-only, from the app).** config has the CA path; `Ssl_version` TLSv1.3,
  `Ssl_cipher` TLS_AES_256_GCM_SHA384; 36 orders readable; `/api/health` 200 with
  database, queue and horizon true; Horizon running. `.env` differs from the backup by
  that one line only.
- **Left (founder).** `.env.bak-before-dbtls` holds the DB password: delete it once the
  change has run clean for a few days.

## 2026-10-10 — §16 item 71: FAQ click-through done (docs only)

- Founder checked SEO › FAQ in the admin: editing works, `{store_name}` works.
- Read-only check of the live storefront: `pekangame.com` carries `FAQPage`
  JSON-LD with all 5 FAQs and no literal `{store_name}`.
- Left: the GSC canonical/duplicate recheck around 2026-10-22 (founder).

## 2026-10-10 — §16 item 11: external uptime monitor added (founder-reported)

- The founder added an UptimeRobot HTTP monitor on
  `https://api.pekangame.space/api/health` (5-minute interval, email alert). The
  endpoint answered 200 with database, queue and horizon true when checked.
- Not verified from here: the UptimeRobot dashboard was not opened. Error tracking
  (Sentry) is still not set up.

## 2026-10-10 — §16 item 77: `next` 16.3.8 security bump (admin, storefront, reseller)

- `npm install next@^16.3.8` pulled 16.4.0 (a minor), so it was re-pinned to
  16.3.8; `package.json` keeps `^16.3.8`, the lockfiles pin the exact version.
  `npm audit fix` (no `--force`) then moved `sharp` to 0.35.5 and
  `source-map-js` in the three apps.
- `npm audit --omit=dev`: 0 vulnerabilities in all three. The remaining
  `npm audit` highs are dev-only (eslint's `braces`).
- docs-site: `sharp` pinned to 0.35.5, plus a plain `audit fix`. The 4 highs are
  gone; 11 moderates remain (`postcss-selector-parser` under Starlight).
  `npm audit fix --force` would downgrade `@astrojs/starlight` to 0.21.5 and
  `starlight-openapi`, so it was not run.
- Verified: `tsc`, `lint`, `build` clean in admin, storefront, reseller;
  docs-site builds (18 pages); e2e 6/6; the built storefront serves `/icon`
  as a 512x512 PNG and `/_next/image` answers 400 for a missing file.
## 2026-10-10 — §16 item 76: debounced, stale-guarded server-side search (admin)

- Two shared hooks in `admin/src/hooks/`: `useDebouncedValue` (300 ms) and
  `useLatestRequest` (a per-list guard; `begin()` returns an `isLatest()`
  check). The input binds to the raw state; every fetch and every
  "back to page 1" reset binds to the debounced value.
- Why a guard on top of the debounce: a list is reloaded from several paths,
  not just the filter effect. `/admin/orders` has four (filter effect, manual
  Refresh, Reverb push, resume hook), and Games, Gallery and Product Manager
  also reload after a mutation. All of them now go through one guarded loader
  per list, so the newest request wins whichever path fired it. The Orders
  push listener's deps still exclude the filters (2026-09-28 addendum).
- Pages: orders, games, seo/games, gallery, sandbox, product-manager.
  Reports' custom from/to are debounced before they reach `resolveDateRange`
  (`useReport()` already had its own guard). Product Manager's two inline
  re-fetches after link/checkout-input now reuse `refreshCategories()`.
- Verified on a local production build (Playwright, real API): typing
  "PUBG" fast sends 1 request, not 4; typing 9 characters in Orders sends 1;
  a slow in-flight `search=zzz` response that lands after the box was cleared
  no longer replaces the full list (5 rows stay 5). `tsc`, `lint`, `build`,
  admin `npm test` clean.
- Reports custom dates, typed digit by digit into the from box: the old code
  sent 4 rounds of the six report requests (`0002-…`, `0020-…`, `0202-…`,
  `2026-…`); the new code sends one round, `2026-01-01`.
- Stale response, same slow `search=zzz` test on the old code (`staging`
  Games page, rebuilt): after the slow response landed the list showed 0 rows
  instead of 5. The new code keeps 5. Both bugs reproduce on the old code and
  are gone on the new.

## 2026-10-10 — §16 item 6 (part): gallery picker in the image URL forms

- New `admin/src/components/gallery/ImageUrlInput.tsx`: the existing URL text
  field plus a "Choose from gallery" button that opens a dialog with the
  gallery grid (debounced search, paging, current image highlighted). Picking
  writes the image's `url` into the field. No FK was added (ADR-095), so the
  delete-references check keeps working and a pasted external URL still works.
- Wired into Edit Game, Hero Slide, SEO › Settings (default OG image) and
  SEO › Game (OG image). Frontend only; reuses `listGalleryImages`.
- Verified on a local production build (Playwright, real API): in Edit Game the
  picker opens over the open modal, the outer modal stays open, picking fills
  the field; search for a non-matching term empties the grid; Hero Slide and
  SEO › Settings fill the field too. `tsc`, `lint`, `build` clean.
- Not verified: thumbnails render. Locally the production CSP (`img-src`) blocks
  the dev image host, so images are broken on the existing Gallery page too; it
  is the same `<img>`. SEO › Game was not clicked through (same component).
  Picker-closes-after-pick was only checked on Edit Game by screenshot.
- The two test images uploaded for this were deleted again from the dev gallery.
## 2026-10-10 — §16 item 6 (part): Telegram settings removed (SET-9 dropped)

- Founder decision: no Telegram sender. Removed the `telegram_notifications_enabled`,
  `telegram_bot_token` and `telegram_chat_id` columns (migration
  `2026_10_10_210000_drop_telegram_columns_from_platform_settings_table`), the
  model fillable/cast, the `UpdatePlatformSettingsRequest` rules, the admin
  "Telegram ops notifications" card and the TypeScript types. `telegram_contact_link`
  (the customer-facing contact link) is untouched. ADR-028 addendum, same day.
- Read-only production check first: 2 `platform_settings` rows, no token, no chat
  id, none enabled, so the drop loses nothing. (The table has 2 rows although the
  model is read as a singleton; not looked into here.)
- Verified: local dev DB migrated, rolled back and migrated again; backend
  suite 2707/2707; `tsc` and `lint` clean; on a local production build the
  Platform Settings tab has no Telegram card and Save Platform Settings returns
  200 with no telegram fields in the request.
- Deploy note: for the minutes between the Vercel deploy and the Forge deploy, the
  new admin's Save Platform Settings can 422 against the old backend (the old
  request rule requires `telegram_notifications_enabled`). Nothing else is affected.

---

## 2026-10-11 — §16 item 75: the two "issued" voucher lines split (and half of item 80)

**What shipped (branch `fix/voucher-issued-lines`).** The Monthly Summary's
"Voucher liability issued" summed every voucher. It is now two lines, split by
how the voucher was issued: **Admin-issued vouchers (no order)** (was "Goodwill
vouchers issued"; still a profit cost) and **Failed-order store credit issued**
(information only). Keys renamed `admin_issued_vouchers_sen` and
`failed_order_credit_issued_sen`; readers were the service, the admin summary
page, `accounting-summary.ts` and two test files (grepped, no export or API
consumer elsewhere).

**Why.** The external reviewer's answer to item 75 asked for the split. The
founder asked to drop the word "goodwill" (a Campaign is a `PromoCode`, ADR-118,
costed per order, so "marketing" would collide with it).

**Found on the way.** `voucherLiabilityIssued()` had no merge exclusion while
the goodwill line did, so a merge counted the merged amount again. The new
failed-order line counts `order_id IS NOT NULL`, which a merge result never has,
so it is closed by construction. Red first: the merge test failed 700 vs 500
before the query changed. Item 80's revive-an-expired-voucher half is still open.

**Checked, not changed.** No `is_test` filter on the failed-order line: no path
creates a voucher from a sandbox order (`settle()` is reachable only through
`Admin\OrderController`, which 404s on `is_test`).

**Verified.** Full backend suite 2708/2708; admin `tsc` and eslint clean.
Not verified in a browser: the admin page change is label and tooltip text only.
Prod effect: none expected (5 vouchers, RM13.18, all failed-order).

---

## 2026-10-11 — InfoTooltip clipped inside `overflow-hidden` cards (admin)

**What was wrong.** The founder's screenshots: on Monthly Summary the last rows'
(i) popovers were cut off. `InfoTooltip` rendered `absolute` inside the table
card, which has `overflow-hidden`, and the last rows have no room below. The
longer tooltips added by the item 75 PR made it worse.

**Fix (`fix/info-tooltip-portal`).** `admin/src/components/dashboard/InfoTooltip.tsx`
now renders the popover in a portal with `position: fixed`, placed from the
icon's rect: below the icon, flipped above when the viewport has no room,
clamped to the left and right edges, closed on scroll or resize. One component,
so Dashboard KPI cards, the funnel chart and Monthly Summary are all fixed.
No new dependency; first `createPortal` in `admin/`.

**Verified live** (local production build, Playwright, `getComputedStyle` +
bounding boxes): Monthly Summary rows "Admin-issued vouchers", "Manual expenses
(Envelope)" and "Operating profit" at 1280px light and dark, and at 390px
(Operating profit flips above, Manual expenses now shows its full text past the
card edge); Dashboard's first three KPI tooltips. Every box inside the
viewport; popover colours match the old classes (dark: bg rgb(34,31,27)).
`tsc` and eslint clean. Not tested: the funnel chart's own (i) icon.

---

## 2026-10-11 — Docs sync after #401 / #402 (docs only)

Swept PRD, ADR index and ADRs for claims the session made untrue.
- PRD §14: "`staging` = `main` after this release" was only true at the #393
  release; it now points at `git log origin/main..origin/staging` (31 commits
  ahead on 2026-10-11, not live).
- PRD §16 "Next up": re-check note (77, 76, 75, gallery picker and Telegram
  closed); item 66's history view is the only item left in A.
- ADR-083: pointers on the two older lines that name the old "Voucher liability
  issued" and the merge double count, to the 2026-10-11 addendum.
- ADR-045: addendum for the InfoTooltip portal; index row date set.
- Remaining "goodwill" in code is free-text voucher reasons in tests only.
Not changed: item 80's open half (revive an expired voucher), item 75's
accountant question (expense at issue vs at redemption).

---

## 2026-10-12 — Combo Order Detail: per-leg attempt history (item 66, admin only)

**Bug proved first (red test):** a combo's legs each write an `initial` row to
`order_resend_attempts` (ADR-106 addendum, 2026-09-21). `DeliveryLogsTable`
took the first `initial` row as the order's "Initial Delivery" and filtered
every other `initial` row out, so with 3 legs two attempts vanished and the
third was mislabelled as the order's own.

**Fix (frontend only, no backend change):** the log-building logic moved
verbatim into `admin/src/lib/delivery-log-entries.ts` so `npm test` can reach
it. The order-level log now ignores rows with an `order_delivery_leg_id`;
`groupLegAttempts()` groups them per leg and `ComboLegBreakdown` shows them in
an "Attempt history per leg" section (failed / needs_review legs open by
default). `retry` rows are labelled Retry (they showed as "Resend Delivery").

**Production check (read-only):** only 2 combo orders exist (ids 7 and 28,
5 legs) and 0 leg attempt rows — both predate `cf44991` (2026-09-21), so the
section says "no attempts recorded" for them. `PG-B2BL0G1YDMVS` is order 28.

**Admin actions for a stuck combo (read from code):** all at order level, none
per leg. Retry Delivery retries failed legs only (ADR-094 decision 29), Confirm
Failed flips unresolved legs (decision 31), Mark as Delivered is hidden for
combos, compensation is by formula (decision 33). `NeedsReviewBanner` still
reads `order.supplier`, which a combo has none of. Founder's call 2026-10-12:
no combo-aware banner or per-leg "what to do" line; not tracked, revisit only if
a real stuck combo order confuses an admin.

**Verified live:** local production build, Playwright, API response patched
with a 3-leg combo (no DB writes): leg 2 opened with 2 attempts, others
collapsed, logs show only order-level rows. 6 `npm test` pass, `tsc` and eslint
clean. Not checked: dark mode, 390px width, a real combo order with leg
attempts (none exist yet).


## 2026-10-12 — Release #406 (`staging` → `main`, #394–#405) verified live, then a docs tidy

**Release.** PR #406 merged 2026-10-12 06:18 UTC as `aa0a4d6` (merge commit).
Carried #397 (Next 16.3.8, four frontends), #398, #399, #400 (Telegram
settings removed), #401 (Monthly Summary voucher lines), #402, #404, plus docs
PRs #394–#396, #403, #405. Items 6 (part), 66, 75, 76, 77 are live.

**Pre-release checks (read-only).** Production `platform_settings` held
`telegram_notifications_enabled=0` and a NULL token and chat id in both rows
before the `drop_telegram_columns` migration ran. #401 renames
`goodwill_vouchers_issued_sen` to `admin_issued_vouchers_sen` and
`voucher_liability_issued_sen` to `failed_order_credit_issued_sen`; no stale
reader in any app, and nothing reads `accounting_period_closes.profit_lines`,
so older closed months keeping the old keys is harmless. Operating profit
unchanged; `failed_order_credit_issued_sen` now also requires `order_id`, so a
merged voucher no longer counts as new credit.

**After release.** Forge release `79945846` = `aa0a4d6`; `/up` 200; migration
`2026_10_10_210000_drop_telegram_columns_from_platform_settings_table` Ran and
the columns are gone; no `production.ERROR` in the last 200 log lines. All CI
jobs and the four Vercel builds green. Founder confirmed the issued-voucher
lines on the Monthly Summary and the per-leg history on an order log in the
admin.

**Gotcha.** CI runs only on pull requests, so a docs-only merge to `staging`
leaves its head commit with no branch run; the last green run on `staging` was
`d3b5b10` while its head was `23dc997`. The release PR is what ran CI over it.

**Docs tidy (docs only, branch `docs/2026-10-12-release-406-docs-tidy`).**
Three read-only audits (PRD, ADR index vs files, everything else), then applied:
- `build-log.md` entries 2026-10-06 → 10-08 moved verbatim to the archive
  ("Fifth archive move"); the PRD's dated narrative (§6.7, §6.15, §7.3, release
  history) and §16 closed items 52–63 moved verbatim to "PRD narrative moved
  2026-10-12" in the archive.
- PRD: stale Reseller/Affiliate wording, backup retention (7/4/6), `merged`
  voucher status, ORD-9 function names, "ADR-060 backend built" now live; §14
  and §15 carry #406; §16 is one "Next up" index plus one "Details" section,
  numbers unchanged.
- ADR index: six wrong last-addendum dates; `ADR-043-llms-txt.md` renamed;
  ADR-085 reserved row and file; ADR-118 and ADR-115 index wording lost "not
  built"; ADR-057 and ADR-061 terminology banners.
- Other: RES-4 ticked in `foundation-security.md` (Affiliate impersonation is
  built); Digiflazz funding date; `composer run dev` also starts reverb and
  pulse; `reseller/AGENTS.md` Herd URL is `https`; the stock `admin/README.md`
  and `backend/README.md` deleted.

## 2026-10-12 — Logo/favicon preview kept showing the old image after a re-upload (fix, staging only)

**Symptom (founder).** In admin Settings → Store Branding and the partner
portal's Branding tab, replacing a saved logo or favicon left the preview on
the old image.

**Cause.** Both upload paths write a fixed object path
(`affiliate-logos/{id}.webp`, `affiliate-favicons/{id}.webp`), so a re-upload
overwrites the file but `logo_url`/`favicon_url` stay byte-identical. The CDN
(`cdn.pekangame.space`, checked read-only) sends no `Cache-Control` and
Cloudflare reports `DYNAMIC`, so each browser keeps its own copy by heuristic
freshness. The storefront has the same flaw: its data refreshes at once
(`forgetCache` → `NextRevalidation::purge()`), but `Logo.tsx` is a plain `<img>`
on the same URL and `/icon` proxies the same favicon URL. A second trap:
re-saving the same path is not a dirty attribute, so Eloquent skips the UPDATE
and `updated_at` never moves.

**Fix (one seam, backend only).** `AffiliateBranding::replaceImage()` is now
the only writer of an uploaded `logo_path`/`favicon_path` (four callers: admin
and partner-portal controllers, logo and favicon) and sets `updated_at`
explicitly; the `logo_url`/`favicon_url` accessors append `?v={updated_at}`.
The CDN serves `?v=` URLs (200 `image/webp`). Any other branding save also bumps
the version, which costs one refetch. No migration, no storage change.

**Tests.** Two new feature tests (admin logo + favicon, partner-portal logo)
re-upload two minutes apart and assert the URL changed; both fail without the
fix. Full fast suite 2710 green, Pint clean. Not checked in a browser: the
cache behaviour itself, which needs a real second upload on a deployed build.
After release, verify on staging/prod by re-uploading a logo and looking at the
admin preview, the storefront header and the tab icon (favicons are cached
hardest by browsers, so allow for that).

## 2026-10-12 — WhatsApp order-number messages are always answered (item 69, staging only)

On `fix/whatsapp-status-card-reply-every-time`. Not released.

**Problem.** The status-card throttle (30 min) and the not-found throttle
(10 min) in `CustomerNotificationService` were `Cache::add` calls that returned
before any row was written: no reply, no trace for admin. Prod showed it on
2026-10-05 (three order-number messages in four minutes, one card). The
throttle was also set before the row write, so a failed write plus an OpenWA
webhook retry would be swallowed (read from code, not reproduced).

**Investigation (read-only).** Prod `customer_notifications`: 11 rows, 2 numbers,
all founder tests, no failed jobs, nothing stuck. OpenWA `customer-support`
session: 21 direct messages from 5 chats (two unknown numbers wrote on
2026-10-07 and nothing records it in Laravel), ~360 group messages ignored by
design. The founder's chatbot project, same checks: busiest 10-minute window for
one chat was 3 order-number messages across six months.

**Fix.** Both `Cache::add` throttles and their two constants removed; every
order-number message gets a card or a not-found reply. Retry safety already came
from the `dedupe_key` (inbound message id). A `ponytail:` comment on
`orderStatusCard()` records the ceiling. Decision record: ADR-116 2026-10-12
addendum.

**Tests.** The two tests that asserted silence were rewritten (three messages
give three cards; two typos give two replies) and failed first. New test: the
same message id delivered twice gives one row. Notification suite 28 green, full
fast suite 2711 green, Pint clean.

**Gotchas.**
- The admin label map in `RefundInformationCards.tsx` has a dead `optin_reply`
  key, but `start_reply`, `stop_reply` and `order_not_found` have no order id,
  so they never show on the order page. Nothing to fix; left alone.
- Horizon `LongWait` cannot see a send flood, because delayed jobs do not count
  as waiting.

**Not verified.** The real behaviour on WhatsApp. It needs a deployed build:
after release, send the same order number from a test phone to the CS number
several times and expect a card each time.

---

## 2026-10-12 — §16 item 80: a voucher merge rejects an expired source

**What shipped (branch `fix/voucher-merge-expired`).** `VoucherService::merge()`
now refuses the whole merge (422 on `voucher_ids`) when any source voucher's
`expires_at` is in the past. ADR-036 2026-10-12 addendum.

**Why.** Nothing ever sets a voucher's `status` to `expired`; expiry is
`expires_at` alone, so an expired voucher still reads `active`. `merge()`
checked status only, so expired credit, possibly already booked as breakage by
a closed month, came back under a new expiry. The double-count half of item 80
was closed with item 75 (2026-10-11).

**Red first.** `test_rejects_merging_an_expired_voucher` got 201 before the
fix. Full backend suite 2712/2712 after. Concurrency suite not run: the lock
and the order of checks inside it are unchanged. No migration, no frontend
change. The admin merge modal already shows the server's error message (the
same path as a non-active source). No browser check.

**Noticed, not changed.** The admin voucher index's "Expired" stat counts
`status = 'expired'`, which nothing writes, so it always shows 0. The merge
checkbox still offers an expired-but-`active` voucher; the server rejects it.
Production effect: none expected (0 merges on 2026-10-10).

---

## 2026-10-12 — §16 item 79: compensation vouchers stop debiting the platform ledger

**What shipped (branch `fix/compensation-voucher-ledger`).** `VoucherService::issue()`
writes the platform `voucher_issued` debit only for an admin-issued voucher (no
`order_id`). A compensation voucher is the customer's own cash already held, the
same definition as `Voucher::scopeCompensation()`. Migration
`2026_10_12_000000_reverse_compensation_voucher_platform_debits` appends one
reasoned reversal per old compensation debit (`idempotency_key
item79-reversal:voucher:{id}`). ADR-024 2026-10-12 addendum.

**Why.** The redeeming order already credits its full `order_profit`, so the debit
was a cost the platform never bore. On a partial delivery it was a double count,
because `platform_profit` already nets out the cash share. Platform ledger in
production was RM15.51; it should be RM28.69. No screen read it (#391 removed the
only reader), so no figure on screen was wrong.

**Grill + stress test (founder, this session).** S1: reversal via a migration on
release. S2: `order_id` decides. (a): revoked/expired admin vouchers keep their
debit for now, now §16 item 83. Stress test: nine scenarios traced (full failure,
paid-with-voucher failure, full-cover failure, partial, affiliate brand, expiry,
merge A+B, wallet refund, admin voucher). Every writer and reader of
`voucher_issued` grepped. Four alternatives rejected (ADR addendum).

**Red first.** The new invariant test (admin issue + full failure + partial + merge)
failed with 3 debit rows instead of 1. The migration test failed before the file
existed. Three tests that asserted the old debit were changed (settlement service,
combo, settlement concurrency).

**Verified.** Fast suite 2714/2714. Concurrency suite 30/30 on MySQL, after dropping
the stale `llm_report_*` views (`backend/AGENTS.md` gotcha 8). The migration and
settlement tests on MySQL: 9/9. Local dev DB migrated: 1 compensation debit
reversed, net 0. Production dry run of the migration's SELECT (read-only): exactly
vouchers 1, 2, 4 and 5, −1318 sen; voucher 3 is RM0 and skipped; no `item79` key
yet. Nothing written to production.

**After the release.** Re-run that SELECT read-only. It must show 4 reversals and
no unreversed compensation debit. Forge migrates before the new code is live, so an
admin settlement in those seconds could leave one more debit.

---

## 2026-10-13 — Docs sync after #412 / #413 (docs only)

Swept PRD and ADRs for claims items 79 and 80 made untrue.
- PRD glossary, Voucher Merge: the "liability already booked by each source"
  reason now says an admin-issued source's cost was booked at issuance and a
  compensation source never debits; an expired source cannot be merged.
- ADR-024 decision 2: inline pointer to its 2026-10-12 addendum (only an
  admin-issued voucher is debited at issuance).
- ADR-036: cross-reference that decision 5's "sources' original debits"
  premise now covers admin-issued sources only; its conclusion is unchanged.
- ADR-083: the item 80 "revive half stays open" line now points to the closing
  addendum.
Checked, still true: ADR-094 decision 34 and ADR-104 R8 (they reason about the
voucher liability, not the ledger row), ADR-002's dedupe list, `backend/AGENTS.md`.
`main` is still release #406; #412 and #413 are on `staging` only.
Added §16 item 84 (founder, 2026-10-13): the admin voucher list's "Expired"
stat, "Active" stat, pill and merge checkbox ignore `expires_at`. Display only;
no production voucher has an expiry today.

---

## 2026-10-13 — §16 item 64: membership renewal-reminder emails

On `feature/membership-renewal-reminder`. Staging only until a release.

Grilled with the founder (15 questions), then stress-tested against the code;
recorded as the ADR-068 2026-10-13 addendum. Production at the time: 2 members
(1 active, ending 24 Oct MYT), 0 opted-in WhatsApp numbers for them. A
membership has no phone number, so phase 1 is email; WhatsApp is designed and
parked as item 85.

- **Reminders:** `app:send-membership-reminders`, daily 10:00 MYT.
  `MembershipReminderService` picks `before` (from H-3) and `after` (H+1 to
  H+7) by Malaysia-day windows, so a missed day is caught next day. Eligible:
  the brand is `active`, not deleted, and `membershipEnabledEffective()`.
  `membership_reminders` has a unique (membership, kind, expires_at): one per
  period, and a renewal (which always moves `expires_at`) starts a new pair.
  `SendMembershipReminderJob` re-checks before sending (skips if renewed),
  retries 3 times, then records `failed`. A row still `queued` an hour later
  is re-dispatched. `--dry-run` lists without sending.
- **Admin:** a "Renewal Reminders" table on `/admin/membership/{id}`.
- **Fixed on the way (stress test):**
  - The email header logo was 75×50 for every brand: the theme's fixed
    75×75 merged with the header's inline `max-height: 50px`. Now sized by
    height. Checked in a rendered email (50×50 from a 512×512 source).
  - The membership receipt printed `expires_at` in UTC, a day early for any
    expiry after 16:00 UTC (the founder's own member showed 23 Oct on the
    receipt, 24 Oct in the admin). `Membership::expiresOn()` gives the MYT day;
    the receipt and the reminder use it.
  - The email header linked to `config('app.url')`, the API host. Email and
    WhatsApp now share `BrandIdentity` (name, logo, canonical origin), so the
    header and the receipt's new "Manage membership" button link to the
    brand's storefront. Name fallback is now store name, then business name
    (as the storefront header); production's two brands both have a store
    name, so nothing customer-visible changes.
- **Tests:** 11 for the command and job (incl. the UTC-vs-MYT H-3 boundary),
  BrandIdentity, the receipt date and link, the admin reminders list, Horizon
  queue coverage. Backend 2731/2731. Admin `tsc`, `lint`, `build` clean; the
  reminders table checked on a built admin in light and dark.
- **Not run locally:** the MySQL concurrency suite (Docker was not running);
  CI's `backend-concurrency` job runs the new migration on MySQL 8.4.
- **After the release:** run the dry-run read-only on production.
