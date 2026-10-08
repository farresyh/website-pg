# PekanGame — Build Log

> The running chronological record of what shipped, when, and why — every
> session, the bugs hit along the way, and the live-verification notes.
> Moved out of `prd.md` §14 on 2026-09-11 once it outgrew the spec doc.
>
> **This file is append-only history.** Current per-feature status lives in
> `prd.md` §15; the live backlog is `prd.md` §16; decision rationale is
> `adr.md`. **Everything before 2026-10-01** lives verbatim in
> [`build-log-archive.md`](./build-log-archive.md): the foundation build, the
> old §14 table and PrimeReact tracker, and the production-era entries
> 2026-09-01 → 09-30 (moved in three passes, the last on 2026-10-08) to keep
> this file quick to read at session start.

---

## 2026-10-01 — Accounting audit fixes released to `main`, live-verified, and the underlying data-entry mistake corrected

PR #329 + #331 released to `main` via PR #332 (bundled with the pending
WhatsApp order-card fix, #326, and docs #327/#328 — all already on
`staging`). CI on #332 caught one real failure first: `backend-tests` on
`CustomerAnalyticsServiceTest::test_monthly_trend_buckets_by_paid_month` —
unrelated pre-existing flake (built `paid_at` with UTC `now()`, but
`CustomerAnalyticsService::monthlyTrend()` buckets by Asia/Kuala_Lumpur;
near KL midnight the two disagree about which month "now" is). Fixed via
PR #333, merged to `staging`, #332 re-ran green, merged to `main`.

**Live-verified against real production data post-deploy** (read-only SSH
+ a real browser session, per `docs/prd.md` §16 item 51(e)):
`cost_sen` null for `PG-B7RB8MON6Q9I`, its `reseller_wallet_refund` row
present, CSV footer total computes without error, `bank_transfer_fees_sen`
correctly split from `supplier_prepaid_topup_sen` (still summing to the
same RM757.33 pre-correction total), `reseller_wallet_balance_sen` =
RM347.96 — matching the founder's own previously-verified Balance page
figure exactly, a strong real-world confirmation the new line is correct.

**The underlying data-entry mistake the external reviewer found (RM14.36
gap, see the first 2026-09-30 addendum above) was also corrected live**,
using the now-live "Edit Details" flow (driven via a real browser session
against the real admin panel, founder's own logged-in session): the 19 Sep
and 21 Sep Digiflazz transfers both had the gross Wise receipt total
entered into "Amount sent" with the Wise fee then added a second time —
corrected to the net figures (RM193.59 and RM336.13), and the 19 Sep
transfer's missing Wise reference (`#2380779917`) was added too. Verified
immediately after: `MonthlyAccountingSummaryService::forPeriod(2026, 9)`
now returns exactly RM 742.97 (RM 721.91 topup + RM 21.06 fee) — matching
the external reviewer's own from-the-receipts figure to the cent. Both
corrections are visible in Funding History with their full "Edited:
field old→new" audit trail and reason text, never a silent edit.

Every finding from the external accounting review has now either shipped
as a code fix, been corrected as data, or been grilled and deliberately
parked (`docs/prd.md` §16 item 55) — see the receipt-storage incident
below, surfaced by this same correction work, for the one loose end it
led to.

**Same-session incident, found while correcting the 19/21 Sep receipts
above: Supplier Funding receipt downloads were 500ing — a real droplet-
migration gap, not a new bug.** The founder's own receipt-download attempt
threw `League\Flysystem\UnableToRetrieveMetadata`. Traced to `config/
filesystems.php`'s `accounting_disk` being the `local` driver — a real
directory on whichever droplet is currently serving traffic — and ADR-114's
2026-09-25 droplet migration (decision 2) only `mysqldump`'d the database,
never rsynced `storage/app/private`. Every `supplier_transfers.receipt_path`
created before that cutover (5 rows, all 14–21 Sep) pointed at a file that
only ever existed on the old droplet — destroyed the same day as this
session, 2026-09-30, closing off any recovery path.

Scoped precisely before doing anything: exactly 5 affected, zero
`BudgetEnvelopeEntry` receipts (none existed yet at migration time). 2
belong to already-voided transfers (`id` 1, 2) — `recordCorrection()`'s
own `lockNotVoided()` guard means a voided transfer's receipt can't be
replaced even if wanted (confirmed live: the UI doesn't render "Correct…"
for one at all), and since a voided row is excluded from every real total
already, its lost receipt has zero effect on any figure. The other 3 (`id`
3/4/5 — the same active transfers the RM 742.97 correction above depends
on) were re-uploaded by the founder from his own kept Wise receipts via
the now-live "Edit Details" → Replace receipt flow — verified live not
just by a 200 on the download route but by `Storage::exists()`/`size()`
against the real file on the real persistent path
(`/home/forge/api.pekangame.space/storage/app/private`, confirmed
correctly symlinked outside the release folder so this specific class of
loss can't recur from a plain deploy — only from a future full-droplet
move without an explicit storage-rsync step).

**Underlying durability gap tracked, not fixed this session:**
`accounting_disk` staying on `local` will reproduce this exact incident on
any future droplet move. `Gallery` already solved the identical problem
for public images via R2 (ADR-095) — `docs/prd.md` §16 item 56, full
writeup `docs/adr.md`'s ADR-114 2026-10-01 addendum.

## 2026-10-02 — Bulk markup widened to every package + combo pricing-corruption bug fixed (ADR-028 addendum, cross-ref ADR-094)

Founder question ("does bulk markup skip inactive packages?") led to confirming
`SettingsController::bulkMarkup()` (ADR-028 decision 4) was scoped to
`is_active = true` with no recorded rationale, and — more seriously — had no
`is_combo` exclusion at all: every active combo Package was being flat-overwritten
with `cost × (1+markup%)`, bypassing ADR-094's `ComboPricingService::recompute()`
entirely. Checkout charges a combo's stored `standard_selling_price` verbatim, so
this was a real customer-facing pricing bug, not a cosmetic one. Full ADR-028
addendum has the complete decision record; this entry is the "what shipped" note.

**Production incident data (found via `PriceChangeLog`/`Order` queries before
fixing anything):** the last `bulkMarkup()` run was 2026-09-30 12:20:25 and
corrupted 47 combo packages. Zero real orders were placed against any of them in
the two days since — caught before a customer was actually overcharged/undercharged.

**Code fix** (`backend/app/Http/Controllers/Admin/SettingsController.php`):
`bulkMarkup()` now runs in two sequential passes inside the same transaction —
non-combo packages first (unchanged flat formula, now with no `is_active` filter
at all), then combos (`is_combo = true`) second, so a combo's default "Sum of
Components" price sees its components' already-updated prices before recomputing.
A combo with an active Custom Markup % or Custom Price override
(`combo_override_markup_percent`/`combo_override_price` not null) is left
untouched — only a combo still in the default Sum of Components mode is routed
through `ComboPricingService::recompute()`. Nesting a combo inside another combo
is already rejected at creation, so this two-pass ordering is always sufficient —
no topological-sort concern. 5 new tests in `SettingsControllerTest` (inactive
package now included, sum-of-components combo recomputed from updated
components, custom-markup combo skipped, custom-price combo skipped, inactive
combo also recomputed) + the existing active-package test flipped to assert the
new behavior. `PackageController::updateMarkup()` doc-comment drift (ADR-028's
own text claimed it writes a `PriceChangeLog`; it doesn't) corrected in the same
pass. `admin/src/components/settings/PlatformSettingsSection.tsx`'s confirm
dialog + help text updated to stop claiming "active package" scope. Full backend
suite 2461/2461 green, `tsc --noEmit` clean on `admin/`.

**Production remediation (one-time, run directly, no code deploy needed for
this part):** `ComboPricingService::recompute()` run against all 49 default-mode
combos on production. Only 4 were still actually wrong — most of the original 47
had already self-corrected via an unrelated component price-sync in the two days
since the incident: `id=1271` (2066 Diamonds) RM137.89→RM143.08, `id=1277` (2662
Diamonds) RM177.43→RM183.96, `id=1280` (5394 Diamonds, **inactive**)
RM352.63→RM351.08, `id=1284` (7800 Diamonds, **inactive**) RM532.36→RM530.03. The
two inactive ones are exactly the failure mode this whole session started from —
nothing touches an inactive combo's price until it's reactivated or a component
changes, so they'd have stayed wrong indefinitely otherwise. Each fix logged its
own `PriceChangeLog` row (`price_sync_run_id = null`, same convention as a manual
`bulkMarkup()` row). Verified idempotent — a second `recompute()` pass over the
same 49 combos changed 0.

## 2026-10-02 — Package delete guards (ADR-119)

A founder question about Product Manager's "Add to Catalog" flow (does deleting
a Package in `admin/games` leave Product Manager stuck thinking it's still
promoted?) led to tracing `PackageController::destroy()`. The Product Manager
side is fine — "already in catalog" is computed live by matching
`supplier_package_ref` against `packages`, not a cached flag, so a deleted
Package correctly becomes re-promotable. But `destroy()` itself had no guards at
all: a package with real order history could be hard-deleted, silently setting
`orders.package_id` to `NULL` (the FK is `nullOnDelete()`) with zero warning;
a package used as a combo component would instead throw a raw, unhandled DB
`QueryException` (`restrictOnDelete()`), a 500 with no admin-readable message.

**Fix** (`backend/app/Http/Controllers/PackageController.php`): `destroy()` now
blocks entirely (no force-delete) if the package has ever had any order
(any status, not just active ones), and blocks if it's a component of any combo
(active or inactive) — both via `ValidationException::withMessages()`, the
combo case mirroring `updateStatus()`'s existing cascade-guard message style
exactly. `Package` stays a hard delete (no `SoftDeletes` conversion — out of
scope for what was asked). 2 new tests in `PackageControllerTest`. Full backend
suite 2463/2463 green, Pint clean.

Checked, not assumed: an initial plan to also fix `admin/`'s generic error
display (on the theory that Laravel's 422 response buries the useful message
under `errors.field` while the top-level `message` is a generic "The given
data was invalid.") turned out to target a bug that doesn't exist in this
Laravel version — a real local HTTP request against the new guard showed
`ValidationException::summarize()` already promotes the first field error to
the top-level `message` (`vendor/laravel/framework`'s own behavior, not
something this app customized). The speculative frontend change was reverted
before committing; `admin/` has no code changes in this entry.

## 2026-10-02 — ADR-118 code-trace review addendum + `AGENTS.md` restructure (docs only)

**ADR-118 (Marketing Campaigns) re-reviewed against real code before any build.**
- The original design passed four grill rounds and a stress test, but its claims
  about existing code were made by analogy.
- Three parallel forks traced the design against code: checkout/pricing,
  ledger/fulfillment/compensation, and downstream readers. The model re-verified
  the top findings itself.
- Four silent-wrong-money gaps surfaced:
  1. Discount storage was unspecified, and four profit recomputes would have
     added the discount back.
  2. Resend recomputes `affiliateProfit` from scratch, erasing a 60/40 split.
  3. The "5% is always margin-safe" proof held only for the Standard basis.
     On an affiliate store, a low wholesale tier can make the platform lose
     money.
  4. `VoucherService::redeem()` actually runs after the CHIP purchase and
     logs-and-proceeds on a race. That is safe for a single-owner voucher, but
     unbounded for a public code.
- Four more grill rounds with the founder settled R1–R16 in ADR-118's review
  addendum (`docs/adr.md`). PRD §16 item 57 now points builders at it.
- Founder decisions:
  - Failed-but-uncompensated orders keep their budget reservation.
  - Once per player ID or email.
  - Contra-revenue for the discount, plus an optional KOL fee on `Campaign`
    for true ROI.
  - Negative affiliate profit allowed (withdrawal already blocked by the
    balance check).
  - Admin UI under Customers, with two sub-pages.

**`AGENTS.md` restructured.**
- Added a "which steps apply to which request" table, plus new **Release &
  Production State** and **Production Access** sections.
- Added the money-critical code-trace pass to lifecycle step 2. Step 7 now
  covers PRD §14 and design-only §16 lines; those two gaps caused this
  morning's docs-audit drift.
- Promoted several workflow rules that had lived only in one assistant's
  private memory, so other agents (e.g. Codex) now see them too.
- Removed duplicated rules.
- The five local-dev gotchas moved verbatim-in-substance to `backend/AGENTS.md`
  → "Local dev gotchas", with a pointer left in the root. Added a fifth: local
  `CACHE_STORE=database` breaks `Cache::tags()`.
- Removed the dead `Co-Authored-By` rule. It required an `attribution.commit`
  setting that never existed, while 74 of the last 100 commits carried the
  trailer anyway.
- Removed the `.claude/settings.json` model pin (founder's call).

## 2026-10-02: affiliate domain setup guide (PRD §16 item 49), theme preview tokens (item 6), `.env.example` (item 9)

**Domain setup guide.**
- New `reseller/src/components/domains/DomainSetupGuide.tsx` replaces the
  Domains screen's single English paragraph. It has an EN/BM toggle and 5
  numbered steps. Two of them, "find where your DNS is managed" and "add any TXT
  ownership record shown", were missing from the original design.
- Collapsible tips for Cloudflare, GoDaddy and other providers.
- Screenshots were captured from the founder's own Cloudflare (`fixfastapp.com`)
  through the Chrome extension. The model filled the Add record form with
  example values and **cancelled it every time**. The record count was
  unchanged at 12.
- All four of the founder's GoDaddy domains turned out to delegate DNS to
  Cloudflare. That is the most common real-world case, so the GoDaddy section
  teaches it with GoDaddy's own "DNS Provider: Cloudflare" screen; its add-record
  steps are text only.
- Images live next to the component and are statically imported, not served
  from `public/`. `proxy.ts` matches every non-`_next` path and redirects
  cookie-less requests, which would break `next/image`'s optimizer fetch of a
  `public/` file. Static imports are served from `/_next/static/media`, which the
  proxy excludes.
- Verified live: local backend + portal, a temporary local-only affiliate login
  (deleted afterwards), EN/BM and light/dark, and a `next build`.

**Theme preview.** `ThemeTab.tsx`'s preview had `#19192f` shadows, static portal
`border-ink`, gray text and a white payment strip. It also read
`--color-on-primary` / `--color-primary-fixed` / `--color-primary-on-surface`
from the *light* palette even in dark mode, so "RM 5.00" was low-contrast. All
of these now come from the selected preset and mode through CSS vars and a
`previewToken()` fallback helper.

**`.env.example`.** Added the 9 `VERCEL_*` vars. The other keys PRD item 9
listed were already there. The founder changed `.claude/settings.json`'s deny
rules from `./.env.*` (which blocked `.env.example`, and likely only covered
the repo root) to `**/.env`, `**/.env.local`, `**/.env.production` and
`**/.env.*.local`.

**Same day, follow-up.**
- The founder challenged the guide's subdomain-first framing: affiliates want
  their main domain. Reversed in the guide and recorded as an ADR-060 addendum.
- Checking it found that ADR-060 section E's `www` auto-registration was never
  built (`AffiliateDomainService` has no `www`/redirect logic). The guide
  therefore tells affiliates to add `www` as a second domain.
- The founder moved `domistore.co` back to GoDaddy nameservers so GoDaddy's
  real DNS screen could be captured. Its default `A @ Parked` and `CNAME www`
  rows are now the GoDaddy screenshot.
- Opening GoDaddy's edit form worked. Typing a value into it, and then even
  cropping it, was blocked by auto mode ("DNS / Domain / Cert Changes"). The
  model stopped there; the form was closed unchanged.
- The Add-domain placeholder is now `yourbrand.com`.

## 2026-10-04 — Monthly accounting summary dropped whole KL days (pre-launch money audit #2)

Found by the 2026-10-03 pre-launch money audit (fork D, read side).
`MonthlyAccountingSummaryService::forPeriod()` built the month start in KL time,
converted it to UTC, and only then added a month. The UTC start is the last day
of the previous month, so `addMonthNoOverflow()` overflowed against that shorter
month and the period ended one KL day early. That happened for every month whose
previous month is shorter: 29–31 Mar, 31 May, 31 Jul, **31 Oct** and 31 Dec
belonged to no month at all, on all 10 journal lines. September (the only month
the tests covered) happened to come out right.

Two more boundary bugs in the same file:
- **Upper bound counted twice.** `whereBetween` includes its upper bound, so an
  order paid at exactly 00:00:00 KL on the 1st counted in two months.
- **Settlement dates compared against UTC.** The CHIP settlement windows are KL
  calendar dates, but they were compared against the UTC instants' dates, so a
  batch ending on the last day of the month counted in no month.

Fix:
- The month is now added in KL time, before converting to UTC.
- Every period filter is `>= from AND < toExclusive`.
- Settlement windows are compared against KL dates.
- Two new tests: a last-day order lands in October and an exact-midnight order
  only in November; a 25–31 Oct settlement batch counts in October. Both fail
  on the old code.
- Full backend suite 2465/2465 green.

No production data to correct: the summary is computed fresh on every request,
nothing is stored. Not fixed here (same UTC-day root cause, logged by the same
audit, still open):
- `TransactionRegisterController` / `SupplierTransferController` date filters.
- Admin Orders "today".
- `SettlementReconciliationService::paidButNotSettled()`.

## 2026-10-04 — Partial combo delivery: `partially_delivered` + one settlement path (pre-launch money audit #1, ADR-094 addendum)

**Why.** The 2026-10-03 pre-launch money audit (4 forks, one per layer) found
that a Reseller-wallet combo with one leg delivered and one failed had **no
compensation path at all**:
- Issue Voucher rejected every wallet order.
- Refund to Wallet accepted only `failed`.
- Confirm Failed and Mark Delivered both refused a partial combo.

Root-causing it showed three gaps shared by every channel:
- **No terminal state.** A partial outcome was parked in `needs_review`
  ("unknown"), and every action gated on `failed`.
- **Duplicated rule.** The compensability/amount rule was hand-rolled in two
  controllers; the partial carve-out reached only the voucher one.
- **Ledger never closed.** A partial order never credited the delivered legs'
  profit.

The same trace confirmed a live retail over-compensation bug: a partial order
got its whole paid-with voucher back on top of the cash-share voucher.

**Design and grill.** Production at the time had 2 combo orders (both fully
delivered), 0 partial deliveries, and 0 registered reseller webhooks.
- The founder rejected "keep `needs_review`" and "flip to Delivered on
  settlement". The rule they set: a combo must behave like an ordinary
  package — status is supplier truth, compensation a separate fact.
- A 3-fork code-trace pass (write side / read side / frontends+docs+tests) then
  corrected the draft in three places:
  - one profit formula for both instruments, not two;
  - profit credited at settlement, not on reaching `partially_delivered`,
    because the `order_profit` dedupe key would collide with a later full
    delivery;
  - partial restore needs a `restored_amount` column.
- Decisions 28-41 (ADR-094 addendum, cross-refs on ADR-024 / ADR-073).

**What shipped** (branch `fix/2026-10-03-combo-partial-delivery-settlement`,
granular commits):
- **Status and transitions:**
  - `DeliveryStatus::PartiallyDelivered` + `isCompensable()`.
  - `resolveComboOutcome()` rolls a Delivered+Failed mix to it.
  - Retry re-enters from it.
  - Confirm Failed on a combo flips the ambiguous legs to Failed and rolls up,
    refusing while a leg is Pending.
- **`OrderSettlementService`:** one compensation path for Failed and
  PartiallyDelivered, instrument picked from the order.
  - Formula-locked amount: retail undelivered share of `final_amount − fee`,
    plus the same share of the paid-with voucher; wallet undelivered share of
    `final_amount`.
  - Member quota restored proportionally — also closes "Failed + voucher never
    restored quota".
  - Delivered legs' profit credited in the same transaction
    (`LedgerService::creditOrderProfit`, `OrderDeliveryLeg::costSen` extracted
    and shared with full delivery).
- **Endpoints:** `storeFromOrder` and `refundToWallet` are thin callers, and a
  typed `amount` is refused. Order detail exposes `compensable` +
  `compensation_preview` (replacing `partial_combo_delivery` /
  `suggested_voucher_amount`).
- **Migration** `2026_10_04_000000`: `voucher_redemptions.restored_amount`
  (backfilled for full restores) and `membership_quota_debits.restored_amount_sen`.
- **Messages:**
  - WhatsApp status card and voucher copy say "part of your order" and never
    "combo".
  - New reseller webhook event `order.partially_delivered`.
  - The Bot's final update says "Sebahagian Berjaya", and its refund notice
    states the amount actually credited (it used `selling_price`).
- **Readers:**
  - The monthly summary's sales/COGS (and so its FX variance), the transaction
    register's cost, and customer analytics count a settled partial order:
    kept revenue, delivered legs' cost.
  - `Order::comboCostReconciliation()` counts delivered legs only.
  - The LLM prompt lists the status.
- **Frontends:**
  - Admin: buttons gate on `compensable`, the voucher modal shows the server
    amount with no input, the Refund button carries the amount, plus status
    maps and the funnel key.
  - Storefront: track-order Zod enum, terminal state, stepper, copy, and
    `StatusBadge` — which also fixes the existing raw `NEEDS_REVIEW` label leak.
  - Reseller portal: types, severity and filter.
- **Docs-site:** status table, webhooks and versioning v1.2.0 (the policy now
  names new status values and events as additive), regenerated `openapi.json`.

**Verified:**
- **Suites and builds:**
  - Backend 2478/2478.
  - Concurrency suite 24/24 on real MySQL, including
    `RefundToWalletConcurrencyTest`, now through `settle()`.
  - `tsc` / `lint` / `build` clean on admin, storefront and reseller;
    docs-site build clean.
- **Real HTTP:** against a scratch copy of the dev DB (the dev DB itself was
  untouched):
  - A wallet partial combo previews and refunds RM20.00 of RM50.00, with
    platform profit 600.
  - A repeat refund returns 422.
  - A typed amount returns 422.
  - A retail partial order issues a RM20.00 voucher.
- **Browser:** the storefront track page renders "Partly Delivered", the
  stepper and the new copy (Chrome, localhost:3001).

**Gotchas:**
- `voucher_discount` is nullable on older rows, hence the `(int)` cast.
- The order detail must not 500 for a leg with no frozen price, so the preview
  returns null and settle refuses with a 422.
- A wallet order's fee is always 0 today, but its refund is based on
  `final_amount`, so a future fee could never be kept back from a wallet.
- Testing the storefront on a non-standard port fails CORS; use :3001.

**Added before merge (founder asked for proof first):**
- `OrderSettlementConcurrencyTest`: two processes settle the same partial
  retail order on real MySQL. Exactly one succeeds, giving one 1600 voucher,
  the paid-with voucher restored 400 once, and one `order_profit` pair.
- A whole-lifecycle test: real fulfilment lands on `partially_delivered`, it
  settles, and a later retry is refused without calling the supplier.
- The wallet endpoint test now also asserts Retry Delivery returns 422 after
  the refund.
- Backend suite 2479/2479; concurrency suite 25/25.

**Founder-requested admin UI check** (local admin against a scratch copy of the
dev DB; the founder logged in, the model drove the browser). Confirmed:
- the list shows `partially_delivered`;
- the wallet order shows "Refund RM 20.00 to Wallet", and refunding it shows
  RM 20.00, hides the buttons and keeps the status;
- the voucher modal shows RM 16.00 new + RM 4.00 returned, with no amount input;
- Confirm Failed on a needs-review combo lands on `partially_delivered` and
  offers Retry and Issue Voucher.

It found three admin display bugs, all fixed:
- **Restored card amount.** The "Amount Restored" card showed the full
  `voucher_discount` (RM 10.00), not the share actually restored (RM 4.00). It
  now reads `voucher_redemption.restored_amount`.
- **Stale values after Issue Voucher.** After Issue Voucher the page kept the
  pre-settlement profit and voucher balance until a reload. It now refetches
  the order.
- **Reseller Markup %.** A partial wallet order compared the full selling price
  with the delivered legs' cost (+108%). It now uses the price kept after the
  refund (+25%), and is hidden until the refund exists.

**CI e2e flake, fixed at the cause rather than re-run (ADR-023):**
- **Symptom.** The storefront checkout spec failed once in CI: the order
  landed on `needs_review`.
- **Cause, from the trace's own `supplier_response`.** `SQLSTATE[HY000]: 5
  database is locked` on the fulfilment's `update orders`. The e2e web server,
  queue worker (`QUEUE_CONNECTION=database`) and cache
  (`CACHE_STORE=database`) all write one sqlite file, and sqlite's
  `busy_timeout` was null, so a write that met another's lock failed
  immediately. The fulfilment catch correctly parked the order at
  `needs_review` with its reference kept (M-1).
- **Not caused by this PR.** The same backend code passed CI before, and the
  local e2e run passed 5/5.
- **Fix.**
  - `config/database.php` sqlite `busy_timeout` / `journal_mode` read
    `DB_BUSY_TIMEOUT` / `DB_JOURNAL_MODE`, still null by default, so dev and
    production (MySQL) are unchanged.
  - `e2e/scripts/boot-backend.sh` sets 5000 ms and WAL.
  - Verified the pragma applies (`PRAGMA busy_timeout` = 5000), e2e 5/5, and
    backend suite 2479/2479.

**Not live:** `main` is unchanged. Audit #3 (voucher/quota checkout race) is
PRD §16 item 60; the remaining audit P2s are item 63.

## 2026-10-04 — Earned profit everywhere, a 2-sheet Excel export, KL-day filters (ADR-108 addendum)

**Why.** After the #346 release the founder saw "Reseller Markup" around
100% on PG-B7RB8MON6Q9I and asked for a full check, not another patch. A
read-only check of all 31 production orders against the ledger found:
- **Profit shown where none was earned.** Order Detail, the CSV export and
  the affiliate portal read the order's *expected* profit columns, not the
  ledger.
  - 10 failed/unpaid orders showed a profit.
  - Order 15's ADR-105 ledger correction never reached the column (0.37 shown,
    0.27 earned).
  - The CSV profit column summed to RM 19.37 against a ledger of RM 7.05.
- **Reseller Markup re-derived from prices.** −100% on that refunded order,
  4.35% and 3.09% on two delivered 3.00% orders.
- **UTC day bounds** on the Orders date filter and "today".

The first fix attempt (subtracting the refund for every wallet order) was
discarded uncommitted once the full check showed it was a patch.

**What shipped** (`feature/2026-10-04-orders-export-xlsx-earned-profit`):
- **Earned profit.** `Order::earnedProfit()` / `earnedProfitsFor()` — the
  order's `order_profit` ledger entries, corrections included, null when
  nothing was credited. Mirrors the `walletRefund*` pair.
  - Order Detail shows earned with the expected figure as a note
    ("Expected RM 10.01 (not earned)"); sandbox shows expected only.
  - The affiliate portal's "Your margin" is earned ("—" when nothing).
  - The resend modal says "vs expected".
- **Reseller Markup** reads `wholesale_markup_pct`.
- **Export → Excel** (`OrdersWorkbook`, OpenSpout already installed).
  - **Summary sheet:** every figure is an Excel formula over the Orders sheet
    (Gross Sales, − wallet refunds, Net Sales, earned profit, margin, CHIP vs
    wallet, vouchers, expected-but-not-earned on paid orders), plus balance
    checks and a column dictionary.
  - **Orders sheet:** one row per order with earned/expected, compensation
    columns and a per-row Price Check formula.
  - Columns are defined once; formulas find columns by header and Summary
    cells by the row they were written to.
  - Customer text is always a StringCell — `Cell::fromValue()` turns a
    leading "=" into a live formula (formula injection; covered by a test).
- **Dates.** The Orders date filter, "today" and the KPI use KL days via
  `ReportService::dateRangeFromDates()`.

**Verified:**
- **Suites and builds:** backend 2489/2489 (new tests: earned helper,
  affiliate margin, admin payload, KL filters, workbook incl. injection);
  admin and reseller `tsc` / `lint` / `build` clean.
- **Formulas recalculated in Numbers, not just text-checked.** On a scratch
  copy of the dev DB (195 orders), Summary matched `ReportService::summary()`
  exactly: Net Sales RM 5,484.18, Platform Profit RM 573.05, Affiliate RM 16.58,
  Margin 10.45%. Both balance checks read OK.
- **Browser:** a replica of PG-B7RB8MON6Q9I shows Reseller Markup +3.00% and
  Platform Profit "—" / "Expected RM 10.01 (not earned)". The export endpoint
  returns a valid `.xlsx` through the browser session; the button reads
  "Export Excel".
- **Production expectation** (read-only, earlier): earned totals for the 31
  orders are RM 7.05 platform / RM 0.15 affiliate.

**Gotchas:**
- XLSX is a zip, so it is written to a temp file, then
  `download()->deleteFileAfterSend()`.
- Sanctum tokens in the scratch DB had expired; verification used a fresh
  test token.
- A partially applied tool command earlier left an uncommitted build-log
  entry; it was discarded with the superseded fix.

## 2026-10-04 — Docs status sync (docs only)

**Why.** A docs-vs-system audit (git `main..staging`, code, read-only prod
checks) found status claims that had drifted.

**Fixed.**
- PRD §14: added the 2026-10-03 release (#346, server on `908f16b`) and #347
  as staging-only; the smoke-test line now covers every release since 09-29.
- Release markers: items 61/62 and the Combo row now say live via #346; the
  Orders row, item 63 and the ADR-108 index say #347 is still on `staging`;
  stale "on `staging`" claims fixed for ADR-081/082/084/086/113/116.
- §15: Gemini key is set on prod; real logo uploaded (placeholder is only the
  fallback); membership renewal reminder's "vendor unpicked" reason replaced.
- §16: removed an orphaned fragment under item 55 (left from the closed
  Envelope Ledger item); added items 64 (renewal reminder), 65 (ADR-104 PR-3
  Reports), 66 (combo admin gaps), 67 (ADR-109 live verify), which were only
  mentioned inside §15.
- `AGENTS.md`: PHP 8.4 (composer, CI and prod all run 8.4).

## 2026-10-04 — Voucher / member-quota checkout race fails closed (pre-launch money audit #3, ADR-024 addendum)

**Why.** `CheckoutService` reserved the voucher and member quota after the
CHIP link existed and only logged a lost race, then handed the link out. One
customer racing N tabs got N discounted orders on one voucher or one quota.
ADR-024 had accepted this on two wrong premises (an accident only, and
"money already moved"). Grilled Q1–Q15 with a stress-test round and the
money-ADR code-trace pass; full design in the ADR-024 2026-10-04 addendum.

**Shipped.**
- `CheckoutService::reserveOrClose()` / `reserveAll()`: reserve under the
  Order row lock; on a loss restore the instrument that won, mark the order
  Failed, throw `CheckoutAttemptClosedException`. Full-cover reserves quota
  before Paid. `resume()` refuses a Failed order and reserves before any
  replayed link goes out.
- `CheckoutController`: coded 422 `{code: checkout_closed}`.
- Storefront: on `checkout_closed`, new idempotency key, voucher cleared,
  Review Modal remounted, totals re-fetched (a quota-only loss changes the
  price without changing any preview key input).
- Admin: `has_used_voucher` and the "Voucher Used to Pay" card read
  `voucher_redemptions`, not `orders.voucher_id`.

**Verified.**
- Backend 2498/2498. New service tests (voucher race, quota race restoring
  the voucher, full-cover quota race, replay Failed / Pending-without-
  reservation / late loss / Paid) and controller tests (coded 422 on race and
  on replay).
- New `CheckoutReservationRaceConcurrencyTest` (two real processes, MySQL),
  3/3 runs green with the other checkout/voucher/quota concurrency tests.
  Run once against the old `CheckoutService`: both tests failed exactly as
  the exploit describes (two links on one voucher; a member order linked with
  no quota debit).
- `tsc`/`eslint`/`build` clean on storefront and admin; e2e 5/5. A temporary
  Playwright check (never committed) mocked a `checkout_closed` response:
  the message shows, totals re-fetch, the next click carries a new key.

**Gotchas.**
- `Model::update()` on a stale instance writes nothing when the in-memory
  values already match; test fixtures that rewind DB state use a query
  update instead.
- Remounting the Review Modal unticks T&C, so the customer re-confirms the
  fresh attempt. Intended.

## 2026-10-04 — Item 63 no-grill batch: payment-outcome seam, replay hash, KL days, labels

**Why.** Pre-launch audit P2s (PRD §16 item 63). Before building, each
proposed fix was re-checked for root cause; four of six first proposals
were one-site patches and were widened.

**Shipped.**
- **Payment outcomes (bullet 1).** `OrderPaymentOutcomeService` is the one
  locked seam the CHIP webhook and `PaymentReconciliationService` share; the
  reconcile copy had drifted since 2026-09-29 (no M-4 / Paid-after-Failed).
  Non-terminal CHIP statuses are no longer written (they could move a Failed
  order back to Pending). `Order::setPaymentStatusUnlessPaid()` removed.
  Attempts: all 8 Failed/Expired writers use `LeavesPendingOnce`. ADR-110
  2026-10-04 addendum.
- **Replay hash (bullet 4).** Storefront replays must match a payload hash of
  game, package, channel, voucher, player and server (the Reseller API rule).
  Column renamed `reseller_api_idempotency_payload_hash` →
  `idempotency_payload_hash`. The storefront mints a new key on voucher change.
- **KL days (bullet 7).** Six accounting filters used UTC days; the PRD named
  three. All use `ReportService::dateRangeFromDates()` (exclusive upper bound).
  `settled_on` (a DATE) untouched. Budget Envelope filters on
  `transaction_date` (founder). Found on the way: a void's reversal had no
  `transaction_date` (a date filter dropped it; it now carries the cancelled
  entry's date), and the default date, `before_or_equal:today` and the form
  max were UTC "today".
- **Labels.** `PricingBasis::label()` for the Reports export (wallet/affiliate
  were "Standard"); combo cost basis counts real legs (none → `estimated`).

**Verified.** Backend 2516/2516; concurrency 28/28 on MySQL (incl. the new
Paid-vs-Failed race, both arrival orders seen, and the column rename);
storefront and admin build; e2e 5/5. The KL register test and the reservation
tests fail on the old code.

**Gotchas.**
- `date`-cast columns hold `Y-m-d 00:00:00` in sqlite, so a string `<=` on a
  bare date fails there; `whereDate()` is portable.
- An existing combo test asserted `mixed` for a combo with no real leg cost —
  it encoded the bug.
- Why bullet 1 was missed before: each 2026-09-29 fix was scoped to its
  finding, and the reconcile copy's "mirrors exactly" comment hid the drift.
  Nothing in prod was harmed (no Paid order with a restored voucher/quota
  other than a legitimate Issue Voucher).

## 2026-10-05 — Late Digiflazz result after NeedsReview; Confirm Failed asks the supplier first (item 63 bullet 1, ADR-102 addendum)

**Why.** Pre-launch audit P2 (PRD §16 item 63). Reproduced with a real test
through the real paths: a Digiflazz order Pending for 2 hours ages out to
NeedsReview; a later `Sukses` (webhook or poll) hit `finalizePendingSuccess()`
(Pending-only), answered `200 already finalized`, and was dropped — no SN, no
`order_profit`, only the supplier balance updated. Confirm Failed then accepted
the order without asking the supplier, unlocking Issue Voucher / Refund to
Wallet for goods the customer received. Digiflazz has no cancel endpoint, so
the fix is to never compensate before Digiflazz's own final answer. Prod
2026-10-05: 0 NeedsReview, the 2 orders ever Confirm-Failed were real Gagal
(rc 55, 02), slowest normal delivery ~19 min — never happened.

**Shipped** (`fix/2026-10-05-late-digiflazz-sukses`, PR #352, merged to
`staging` 2026-10-05 as `11bba23`, all PR checks green; not yet on `main`). Grilled Q1–Q15 +
money-ADR code-trace (profit per basis, voucher, quota, wallet, reseller
webhook, receipt, drawdown — all reuse the existing Success branch).
- `OrderStatusService::finalizePendingSuccess()/Failure()` accept NeedsReview
  — one seam for the webhook, the poll, Check supplier and combo legs. A
  confirmed `Gagal` on NeedsReview therefore also moves it to Failed.
- A result contradicting a terminal status (`Sukses` on Failed/Partially,
  `Gagal` on Delivered) is merged into `supplier_response.late_supplier_result`
  and logged at error level; status never changes (combo leg: log only).
- Combo: `finalizePendingDeliveryLeg()` locks the order then the leg and runs
  the roll-up in the same transaction; `resolveComboOutcome()` exits
  NeedsReview (all Delivered → Delivered, a leg still NeedsReview → no-op,
  mix → PartiallyDelivered). `checkComboLegs()` also asks NeedsReview legs
  that have a reference.
- `SupplierDeliveryCheckService::confirmFailed()`: Digiflazz is asked first
  (Sukses → Delivered, confirmed Gagal → Failed, else 422 and nothing
  changes; no override), under the Check-supplier cooldown. Exceptions: no
  `reference_number`, or past `max_reconcile_age_days` (a re-submit would be a
  new transaction) — confirmed without a check, reason recorded. Gamevion and
  sandbox unchanged.
- Check from Supplier also works on NeedsReview (`supplier_askable` on Order
  Detail); Confirm Failed modal copy explains the supplier decides.
- `Order::blocksPackageSwapTo()`: no package swap from NeedsReview on a
  replay-safe supplier (the ref_id is reused, so Digiflazz would replay the
  original SKU); checked in the controller and again in `OrderResendService`.

**Local browser check found a real bug (fixed in the same PR).** Seeded four
NeedsReview orders on Digiflazz's official test cases (`xld10`,
`0878000012xx`, `testing=true`) in the local dev DB; the founder clicked
through. The local IP is not whitelisted at Digiflazz, so every call answered
`status: Gagal, rc 45` ("IP Anda tidak kami kenali"), and Check supplier (A)
and Confirm Failed (C) **failed** the orders instead of leaving them — the
exact leak this PR closes. Cause: any `status: Gagal` counted as confirmed
(ADR-102 decision 5). Digiflazz's rc table marks 45 "Terbentuk Transaksi =
Tidak": the request was rejected, the original transaction never looked at.
Fix (ADR-102 2026-10-05 addendum, decision 9): on a re-submit (`checkStatus()`,
or `createOrder()` reusing a stored reference — new
`SupplierOrderRequest::$resubmit`), Gagal confirms only for a code in the
existing 20-code "Ya" table minus rate limits 85/86; otherwise the outcome is
unknown. A first submit is unchanged. The existing table was re-checked
against the live docs: identical, no sync needed. This also closes a
pre-existing path: the Pending poll getting `rc 45` (e.g. during an IP
change; prod logged one `rc 45` resend attempt) used to fail the order.
Test data deleted from the local DB afterwards (orders, voucher, ledger,
notifications, attempts, request logs, test game/packages).

**Verified.** Backend 2549/2549 (incl. end-to-end tests through the real
adapter with `Http::fake`: rc 45 → Confirm Failed refused, a NeedsReview
retry parked Pending; rc 02 → still Failed); concurrency 29/29 on MySQL (new:
two legs of one NeedsReview combo finalized at once → both succeed, order
Delivered, profit credited once); admin tsc, eslint, build. The Sukses/Gagal
browser paths could not be exercised locally (IP not whitelisted); they are
covered by the tests above.

**Gotchas.**
- A PHP arrow function captures by value, so a test's `&$array` capture lost
  every recorded call; an `ArrayObject` fixed the test, not the code.
- Pint realigned an unrelated docblock in `OrderResendService`; reverted to
  keep the diff on-topic.
- The money-ADR code-trace pass missed decision 9: it checked every writer of
  the state, but took the adapter's "confirmed Gagal" on trust. A local
  browser check against the real API caught it — worth doing for any change
  that relies on a supplier's answer.

## 2026-10-05 — Release #354 (`staging`→`main`): #347, #349, #350, #352

**What shipped.** Earned profit + Excel export + KL-day Orders filters
(#347), the voucher/quota checkout race fix (#349), item 63's no-grill batch
(#350), and the late Digiflazz result after NeedsReview (#352), plus docs
(#348, #351, #353). Released before grilling the remaining item 63 bullets:
those bullets touch none of the shipped code (only `OrderResendService`
overlapped, and only for #352's package-swap guard, not the member-profit
branch), and every PR in the batch was complete on its own.

**Pre-deploy (read-only, prod):** HEAD `908f16b`, last migration batch 30,
`orders.reseller_api_idempotency_payload_hash` present, 0 pending/processing
orders.

**Post-deploy (read-only, prod):** HEAD `1a446d5` (= `origin/main`, CI
`deploy` job success); `2026_10_04_100000_rename_...payload_hash` Ran in
batch 31; old column gone, `idempotency_payload_hash` present; Horizon
running; `/up` 200; `/api/health` ok (database, queue, horizon); 0 failed
jobs in the last 2 h. The only `production.ERROR` lines are Digiflazz
`rc 83` pricelist rate limits from `SyncSupplierPricesJob`, which predate the
release (2026-10-04 onward) and are unrelated.

**Gotcha.** Auto mode blocks the model from merging into `main` (it
deploys); the founder merged #354 themselves.

## 2026-10-05 — Per-game player-input contract (item 63, Bot multi-line bullet; ADR-097 addendum)

**Why.** The audit bullet was "several `.order` lines place only the first".
The trace found the real gap in the shared seam: `CheckoutInputValidator`
passed any `server_id` on a User-ID-only game (17 of 30 reseller games) and
`DigiflazzAdapter::normalizeCustomerNo()` appended it, so `.order X 123456 tq`
sent `customer_no = 123456tq` after the wallet debit, and nothing checked an
ID's format (`12345678 (2001)` went out as-is). Same path on the Reseller
API. Founder confirmed resellers send one order per message, so the
single-line cases were the real risk. Prod: 0 orders anywhere broke the new
rules — nothing to remediate.

**What shipped.** Grilled Q1–Q13, ADR-097 2026-10-05 addendum (decisions
27–35):
- One per-game contract in `CheckoutInputValidator`: User ID digits only
  (default) or Text (`[A-Za-z0-9#._-]`, a Riot ID), Server ID digits only,
  zone in the list, no `server_id` on a User-ID-only game, no whitespace,
  64-char cap. Returns `{field, reason, message}`.
- Used by storefront checkout, Reseller API, Bot `.order`/`.checkid`, admin
  Resend with an ID correction, and storefront Check ID (before the paid
  provider call). Sandbox/Developer Tool exempt.
- Bot: extra tokens rejected (covers multi-line); BM replies built from the
  game's `validation_rules` (`.order MLMY-14 {userId} {serverId}`, zone list);
  `.list` footer the same.
- `Game.validation_rules.player_id_format`, edited in Product Manager's
  checkout-input editor ("User ID format"); `linkCategory()` merges instead of
  replacing (LinkCategoryModal's re-link was wiping zone list + separator).
- Reseller API v1.3.0: `checkout_input.player_id_format`, same
  `VALIDATION_FAILED` envelope; `scramble.php` docs revision was stuck at
  1.1.0, now 1.3.0. Storefront: keyboard + typing block per field, paste kept
  with an inline error.

**Verified.** Backend 2580/2580 (new: validator contract, every channel,
Resend, Check ID before the provider, Bot extra tokens + BM replies, link
merge, editor payload); admin + storefront lint/build; docs-site build.
Local browser: typed `12a34(5)` → `12345` (numeric keypad); pasted
`12345678 (2001)` kept with "User ID must not contain spaces.", Continue
disabled, colour `rgb(186, 26, 26)`; a Text game shows the text keyboard,
accepts `JettMain#1234`, flags `JettMain#1234(x)`. Real HTTP (local test
token, deleted afterwards): editor save → 200, an extra-field-only re-link
keeps `player_id_format: text`, catalog shows it. The Bot and Reseller API
were not exercised against a live OpenWA/API key — covered by the tests.

**Gotchas.**
- The first storefront build blocked pastes too: React's `onBeforeInput`
  fires for a paste (Chrome `textInput`). Found only in the browser.
- The real HTTP check found a pre-existing 422: the editor sends
  `zone_options: null` for a non-zone game and the request read it as a
  list. Every editor Update on a non-zone game had failed since 2026-09-16.
- Port 3000 was another app (Remotion Studio), not admin, and no seeded
  admin password exists, so the editor UI itself was checked by tsc/lint and
  the endpoint by real HTTP. Later the same day, with the founder logged in
  (Remotion stopped to free :3000): the editor's User ID format control
  saved Text, and it persisted across a reload. The Text label was
  truncated in its select; shortened to "Text (e.g. Riot ID)" (#357).
- Seen, not fixed (pre-existing, cosmetic): the editor's "Checkout field"
  select shows blank for a User-ID-only game — PrimeReact treats the `""`
  option value as no selection. The saved data is correct.
- Local `CACHE_STORE` needed `redis` for the catalog list (gotcha 5).
- Local dev data touched for the check (games 2, 3) was restored.

## 2026-10-06 — Founder real-order smoke test on prod: §16 items 51 and 54 closed (docs only)

**What ran.** The founder placed real orders on production (`main` =
`1a446d5`, release #354) with their own money, voucher and WhatsApp number,
and reported each result; the model traced each result against the live
code (`origin/main`) and checked the money on the admin Order Detail
screenshots. #356/#357 (player-input contract) were still staging-only, so
they are not covered here.

| Test | Result |
| --- | --- |
| 51(a) storefront order (`orders` lane) | Delivered; Delivered receipt arrived on WhatsApp |
| 51(b) reseller bot `.order` (`orders-reseller` lane) | Delivered; wallet debited; bot replied |
| 51(c) full-voucher-cover order `PG-SJDWBIKMYC1Z` (MLBB MY 14 Diamonds) | Final RM 0.00, transaction fee 0, no CHIP payment ref, Delivered. Voucher `VC-WQQANL67` RM 3.19 → RM 2.13 (−RM 1.06). Platform profit RM 0.14 = 1.06 − 0.92 cost |
| 54(b) status card / not-found | Both reply; see "Throttle" below |
| 54(c) voucher over WhatsApp, `PG-12A9VRY5KLCE` (PUBG 60 UC) | Paid RM 4.19 FPX + RM 0.97 voucher `VC-GHCL5MZW`; delivery failed on a deliberately invalid Player ID (Digiflazz "Transaksi Gagal"). Issue Voucher restored RM 0.97 to `VC-GHCL5MZW` and issued `VC-WQQANL67` RM 3.19 (= RM 4.19 − RM 1.00 fee); the code arrived on WhatsApp (row `sent`) |
| 54(d) re-send the old skipped receipt | Skipped by choice (proves nothing new) |

**Money check (`PG-12A9VRY5KLCE`).** Compensation RM 0.97 + RM 3.19 =
RM 4.16 = selling price. The RM 1.00 FPX fee is not returned: by design
(`OrderSettlementService::amounts()`, "a retail voucher never refunds the
payment-gateway fee"; ADR-107's "a voucher reflects what the customer
paid"). The customer bears the fee even when the supplier, not the
customer, caused the failure; this is a policy to keep stated in the
terms, not a bug.

**Throttle (why two WhatsApp messages got no reply).**
- On a delivered order, "Contact Support" got a status card, then "Send
  Receipt to WhatsApp" right after got nothing. Both buttons prefill the
  same order number, and the card is not repeated to the same phone for
  the same order + status within 30 minutes (ADR-116 addendum decision 3,
  `CustomerNotificationService::orderStatusCard`).
- A mistyped order number got nothing the first time, then a reply on a
  later retry: the "not found" reply is limited to once per phone every
  10 minutes.
- Both throttles return before a `customer_notifications` row is written,
  so a throttled reply leaves no trace in admin, and the cause had to be
  inferred from code. New §16 item 69.

**Seen, not fixed.**
- The founder's number is opted out again (`STOP` was sent during the
  test), which is why `PG-SJDWBIKMYC1Z` got a status card but no Delivered
  receipt. `START` restores it.
- A full-voucher-cover order keeps `payment_method = fpx` (the method
  picked at checkout), so Order Detail shows "fpx" and the Reports
  Payment Methods tab counts it as an RM 0 FPX order, although no gateway
  was involved (`payment_ref` is null, as ADR-024 decision 5 intends). No
  money effect. New §16 item 70.
- A status card went to a second number (60143670787), not the order's
  phone. Expected: the card goes to whoever sends the order number
  (ADR-116 addendum decision 2).

**Not covered.** Order numbers for 51(a)/(b) were not recorded. Test
orders count as real sales revenue (item 55, no test flag).

## 2026-10-06 — One resend seam: residual profit on every basis (item 63, member-resend bullet; ADR-105 addendum)

**Why.** The audit bullet was "a member order resent to a dearer package
records a re-priced member profit and skips the loss prompt". The trace
(read-only prod check included) found it wider: the resend rules lived in
three copies that had drifted (service, controller guard — Standard basis
only — and a TypeScript copy in the modal, wrong for lapsed-affiliate
orders); a swap never wrote `orders.cost_price`, so orders 15 and 19 show
the old package's cost in Order Detail, the export, the Transaction
Register and COGS; a refused job vanished into `Log::info`; and two
overlapping resends wrote unlocked. Ledger was right throughout; no
customer money was affected.

**What shipped.** Grilled Q1–Q13, ADR-105 2026-10-06 addendum (decisions
9–20) plus its build-time corrections (cloud session, `f09cef8`):
- `OrderResendService::preflight()` → `ResendImpact`: every guard plus the
  impact. One rule for every basis: affiliate share kept at checkout,
  `platform_profit = selling_price − live cost − affiliate_profit`. The
  Member branch, `calculateForAffiliate` and the pricing catch are gone;
  the service no longer depends on `PricingService` /
  `MembershipPricingService`.
- A loss needs an override on every basis. The controller calls
  `preflight()` (its own copies of the status / compensated / same-game /
  swap-block checks and the Standard-only loss check are deleted; the
  reference-unsafe guard stays, it is shared with Retry).
- `resend()` re-runs `preflight()` and writes package, IDs, `cost_price`
  and `platform_profit` under `lockForUpdate()`; the attempt row records
  what `fulfill()` actually sent and `price_diff_sen` against the cost
  assigned before it.
- `ResendOrderDeliveryJob` records a refused attempt as `outcome =
  rejected` (reason in `note`), red in Delivery & Activity Logs.
- `GET /api/orders/{order}/resend-options` and the sandbox twin; the modal
  renders that, marks a blocked package "unavailable", and sends
  `override_reason` in sandbox too.
- Data migration `2026_10_06_000000_correct_cost_price_on_resent_orders`
  (two-part rule, ledger-guarded, idempotent, query builder so no
  broadcast).

**Deviations from the addendum (small).**
- The modal picks its endpoint from its existing `sandbox` flag, the way
  its submit already did, instead of a fetcher prop; the two pages are
  unchanged.
- Player-ID checks (contract on a correction, validation window) stay in
  `resend()` under the lock, not in `preflight()`: the preview has no
  correction to check.
- The sandbox resend request now accepts `override_reason`; before, a loss
  in the sandbox could never be submitted.
- Copy: "Original cost (snapshot)" → "Current cost on this order"
  (decision 13), and a package with no supplier ref shows "—", not "null".

**Verified.** Backend 2612/2612 (new: 5-basis × same/cheaper/dearer
invariant incl. ledger, member loss + residual, preflight impact and
blocked reason, rejected rows, second-resend diff, the exact race window
pinned with an `Order::updated` + `DB::afterCommit` hook, controller loss
on tier/wallet/member, preview endpoint admin + sandbox, migration incl.
ledger mismatch skip and a second run issuing no UPDATE). Concurrency
suite 30/30 on real MySQL, incl. the new two-process resend race. Admin
tsc / eslint / build clean. Local dev (`php artisan migrate`, no
`fresh`):
- curl with a local test token (revoked afterwards): preview for a member
  and a tier order (combo listed as blocked), 422 `override_reason` for a
  loss on both; a sandbox resend refused without a reason, delivered with
  one — `cost_price` 1650, profit 390 − 1650 = −1260, attempt row right.
- Browser: member order, same package +RM 0.13; 310 + 25 Bonds shows a
  RM 13.12 loss, override required, submit disabled (the old formula
  showed a profit here). A `rejected` row renders red, `rgb(180, 35, 24)`
  on `rgb(252, 235, 234)`. No real resend was submitted from the admin
  path (it queues a real supplier call). Local test orders deleted.

**CI e2e flake, fixed at the cause (second time).** The storefront
checkout spec landed on `needs_review` again; the CI trace's
`supplier_response` showed `SQLSTATE[HY000]: 5 database is locked` on the
fulfilment's `update orders` — not this PR's code path (a first
`fulfill()`, no resend). The 2026-10-04 fix (busy timeout 5000 ms + WAL)
does not cover it: a DEFERRED transaction that reads first and writes
after another connection committed gets `SQLITE_BUSY` at once in WAL, and
the busy timeout is never consulted. `config/database.php` sqlite
`transaction_mode` now reads `DB_TRANSACTION_MODE` (default still
`DEFERRED`, dev and MySQL prod unchanged); `e2e/scripts/boot-backend.sh`
sets `IMMEDIATE`, so BEGIN takes the write lock and waits. e2e 5/5
locally, backend 2612/2612.

**Still to do at release.** Run decision 18's check on prod and record
orders 15 / 19 before/after, plus the September accounting summary
before/after (decision 19: COGS and FX variance both +RM 0.18).

## 2026-10-06 — Release #362 (`staging`→`main`): #356, #357, #360

**Deploy.** CI on `main` green incl. `deploy`; the server runs `3d27145`;
`2026_10_06_000000_correct_cost_price_on_resent_orders` Ran in batch 32;
`/api/health` ok (database, queue, horizon); Horizon running.

**Data migration, read-only before/after on prod (decision 18–19).**

| | Before | After |
| --- | --- | --- |
| Order 15 (standard) | cost 356, profit 37 | cost 365, profit 27 (= ledger 37 − 10) |
| Order 19 (reseller-wallet) | cost 356, profit 2 | cost 365, profit 2 |
| Orders 18, 34 | 92 / 9, 92 / 9 | unchanged |
| September summary COGS | 15042 | 15060 |
| September FX variance true-up | −144 | −126 |
| September sales revenue | 15781 | 15781 |

Ledger untouched (order 15: 2 platform entries, sum 27; order 19: 1 entry,
2). Order Detail now balances: `selling − cost − affiliate = profit` for
both (27 and 2). No "correction skipped" log line. The RM 0.18 the FX
variance line had carried was swap cost, not FX; tell the external
reviewer if they kept September's old COGS / variance figures.

**Player-input contract.** Pre-deploy: 0 orders with a non-digit
`player_id`, 0 games with `player_id_format` set, so nothing is refused
by the new contract. Founder action stays: set a letters-ID game (e.g.
Valorant) to Text before selling it; add ZZZ's server field.


## 2026-10-08 — SEO/GEO overhaul (ADR-120): audit, Vercel 308s, PR #364–#367 + this PR

**Why.** A founder GEO question (claude.ai session) produced findings
worth checking; a live audit of `pekangame.com` + `fixfastapp.com`, the
code, and read-only prod data confirmed most of them and found worse:
- **All 38 game pages served literal tokens to Google** —
  `Instant {game_name} Top Up & Membership Rates | {store_name}`. Every
  active game's per-game `seo_title`/`seo_description` held the same
  template text (1 distinct value), but tokens rendered only in Meta
  Templates. GSC URL Inspection: game pages "unknown to Google" (sitemap
  first submitted 2026-10-07), so the fix lands before the first crawl.
- **Affiliate domains pointed at the platform.** On `fixfastapp.com` the
  sitemap (42 URLs), robots `Sitemap:`, `llms.txt` (41 links),
  Organization `url`, Breadcrumb all read `https://pekangame.com`. No
  `rel=canonical` anywhere — ADR-060 §E decided it, never built.
- 12 of 49 MLBB packages in the HTML; Product JSON-LD without
  `offers`/`aggregateRating`; store-unavailable answered 200.
- The claude.ai session put the meta/JSON-LD decisions under ADR-042;
  they're ADR-029's. Its robots.txt alarm was already resolved on prod
  (every AI bot allowed); its "soft 404" for a bad slug already carries
  `noindex` (left alone).

**Prod config change (founder-approved, browser-driven).** Vercel →
team `jw-brothers` → `pekangame-storefront` → Domains:
`www.pekangame.com`, `pekangame.space`, `www.pekangame.space` redirect
**307 → 308** to `pekangame.com`. Before: `curl -sI` → `HTTP/2 307`
on all three. After: `HTTP/2 308`, `location: https://pekangame.com/…`
(path preserved). `pekangame-storefront.vercel.app` left as is (serves
"Store unavailable", now a real 503).

**Shipped (all into `staging`).**
- #364 ADR-120 (grilled Q1–Q17) + addenda on ADR-029/042/060.
- #365 PR-1: `Affiliate::canonicalOrigin()` (platform = `STOREFRONT_URL`,
  affiliate = active primary domain, never the request host) →
  `canonical_origin` on `/api/catalog/branding`, busted on any domain
  change (`AffiliateDomainService::propagateChange`), reused by
  `CustomerNotificationService` (was its own copy). Storefront
  `resolveGameMeta`/`resolveSiteMeta` render tokens in every SEO field;
  `metadataBase` + canonical + `og:url`; per-brand sitemap/robots/llms/
  Organization (+`logo`/`sameAs`)/Breadcrumb; store-unavailable → 503.
  e2e `storefront-seo.spec.ts`.
- #366 PR-2: every package in the HTML (CSS-hidden past 12); Product
  `offers` (guest Standard price — traced: catalog display and the guest
  checkout charge call `calculateForAffiliate()` with the same brand
  args) and `aggregateRating` (approved, brand-scoped, ≥1); a generated
  per-game fact line. FPX's RM 1 transaction fee stays out of `offers`
  (founder call: match the price the cards show).
- #367 PR-3: `faqs` table (seeded with the five hardcoded items), admin
  SEO › FAQ, per-brand `{store_name}` list, homepage `FAQPage`;
  `placeholder-data.ts` deleted.
- This PR: `CrawlerRuleSeeder` defaults match the prod policy (all
  allowed) with correct labels — `ChatGPT-User`/`PerplexityBot` are
  answer-time fetchers, not training; adds OAI-SearchBot, Perplexity-User,
  Claude-SearchBot/-User. `firstOrCreate`, so prod rows are untouched.

**Verified.** Backend 2622/2622; e2e spec green; tsc/eslint clean.
Local storefront against prod data: PekanGame and FixFast titles render
with their own store name; MLBB `offers` RM1.06–RM2044.65 (49), rating
5.0 (4); 49/49 packages in HTML. Local backend with a test affiliate:
`www.acme.localhost` → canonical, sitemap, robots, llms, Organization
all on `https://acme.localhost`; unknown host → 503 + noindex. Fact
line screenshot, contrast 8.43:1 (computed). **Not verified:** the admin
FAQ screen in a browser (see gotchas) — founder click-through owed.

**Gotchas.**
- Local `next build` of the storefront fails on `staging` too
  (`Can't resolve '@vercel/turbopack-next/internal/font/google/font'`) —
  environment; Vercel Preview builds were the check.
- e2e reused a stale `storefront/.next/cache/fetch-cache` from an earlier
  dev run and served the pre-fix title; `rm -rf storefront/.next/cache/fetch-cache`
  fixed it. CI starts clean.
- A dev server restarted with different `NEXT_PUBLIC_*` values kept the old
  inlined ones until `.next` was removed.
- Local admin login failed in the browser (form did a native reload, no API call). First read as "didn't hydrate" — wrong: prod's login page looks identically plain. Cause not found; the API login itself returned 200 via curl.
- `pint app/Services` reformats dozens of unrelated files — pass files, not
  directories.

**Found, out of scope.** `payment_methods` has no active row since
2026-10-07 07:46 (FPX switched off) — storefront checkout has no payment
option. Founder confirmed it's intentional.

**Still to do at release.** `staging`→`main`; live curl + Rich Results
check; clear the 38 identical per-game SEO fields in admin (ADR-120 d5,
before/after recorded here); GSC Request Indexing. Founder-deferred: GA4,
default OG image, Bing Webmaster Tools.

## 2026-10-08 — Release #369 (`staging`→`main`): ADR-120 (#364–#368)

**Deploy.** Merged by the founder; CI on `main` green incl. `deploy`; the
server runs `acaecbd`; `2026_10_08_000000_create_faqs_table` Ran in batch
33 (5 FAQ rows); `/api/health` ok (database, queue, horizon).

**Live checks (curl, raw HTML).**

| | pekangame.com | fixfastapp.com |
| --- | --- | --- |
| MLBB title | own store name, no tokens | own store name, no tokens |
| canonical / og:url | `https://pekangame.com/order/…` | `https://fixfastapp.com/order/…` |
| Organization / Breadcrumb url | pekangame.com | fixfastapp.com |
| sitemap / robots `Sitemap:` / llms.txt | 44 / 1 / 42 on pekangame.com | 42 / 1 / 41 on fixfastapp.com |
| `offers` | RM1.06–RM2044.65, 49 | RM1.06–RM2053.54, 49 (affiliate markup) |
| `aggregateRating` | 5, 4 reviews | 5, 3 reviews (brand-scoped) |
| packages in HTML | 49 (37 CSS-hidden) | 49 |

Homepage carries `FAQPage`; `pekangame-storefront.vercel.app` answers
503. The first fixfastapp.com read right after deploy still showed the
pekangame.com canonical — a pre-deploy Data Cache entry inside its 60s
window; a fresh `MISS` was correct.

**ADR-120 decision 5 cleanup (founder, admin UI).** Before: 38/38 active
games had the same per-game `seo_title`/`seo_description`. Game 1 and 2
were cleared by Claude via the admin screen, the rest by the founder.
After: 0 active games with a per-game title/description; 38 OG images
kept; `seo_title_local` untouched. Live titles now come from the primary's
BM template on every brand, e.g. `Top Up PUBG Mobile Global Murah &
Instant Delivery | FixFast`.

**Search Console.** MLBB page: "unknown to Google" → Request Indexing →
"Indexing requested". Homepage (already indexed): re-index requested, the
confirmation wasn't seen. Other game pages left to the sitemap.

**Still open.** Founder click-through of SEO › FAQ in admin; GA4, default
OG image, Bing Webmaster Tools (deferred); re-check GSC coverage for
canonical/duplicate reports across both domains in ~2 weeks (ADR-120).

## 2026-10-08 — Reseller API `max_price_sen` (item 63, API bullet; ADR-074 addendum)

**What shipped.** `POST /v1/orders` takes an optional `max_price_sen`. If the
live wallet price is above it, the order is refused with `422 PRICE_CHANGED`
and `details.current_price_sen`, and nothing is charged. A lower live price is
charged as is. Omitted = the old behaviour. API docs v1.4.0.

**Why, and the corrected premise.** The backlog said "catalogue cached 60s".
The trace showed the charge was always computed live (`resolveByCode()` is
uncached) and both catalogue caches flush on every price write. The real gap
is read-then-order time on the integrator's side. Prod, 7 days: 11,683 cost
changes, 230 rises >2%, 12 >10%, max +37%. API orders ever: 0 (2 keys); Bot: 7.

**Design (grilled, then stress-tested at the founder's request).**
- Guard in `ResellerOrderPlacementService::placeOrder()`, after the replay
  check, comparing the same `$pricing` the debit uses. Only the service knows
  the final price, so a controller guard would have duplicated pricing.
- `PriceAboveMaxException` (channel-neutral) → `ResellerApiException::priceChanged()`.
- `max_price_sen` is **not** in the idempotency payload hash: a rejected order
  leaves no row, so the same key can retry; stored hashes stay valid.
- The stress test changed two grill answers: `details` is now documented as
  code-specific (no top-level field outside the envelope), and the planned
  one-off `Log::info` was dropped (no API rejection is logged today; a generic
  hook is §16 item 73).
- Bot unchanged; its `max=` token is §16 item 72.

**Gotcha.** Scramble turns a comment above a FormRequest rule into the
public field description. The first export published an internal ADR
reference; the comment is now integrator-facing.

**Verification.** +6 tests (3 service, 3 HTTP: rejection envelope with no
order or debit, same-key retry after rejection, validation of 0 / 63.5 /
"abc"). Fast suite 2628/2628 on the final code; Pint clean; `scramble:export` regenerated
`docs-site/public/openapi.json`; docs-site `check` + `build` clean. No curl
check: the local dev DB has no reseller and seeding one wasn't worth writing
to the unbacked dev DB; the HTTP tests run the real route, key middleware,
FormRequest and envelope render hook. No migration; concurrency suite not
affected (no lock change).

## 2026-10-08 — Item 63 closed: Report Assistant matches Reports (ADR-087) + tier-fee line (ADR-083)

**What shipped.**
- `llm_report_orders` gains `wallet_refund` and `net_sales` (= `final_amount`
  − wallet refunds, the Reports page's Net Sales). Migration
  `2026_10_08_120000`, drop + recreate like 2026_09_15_100000.
- The assistant prompt: sales = `SUM(net_sales)`, profit =
  `SUM(platform_profit)`, margin = profit ÷ net sales. The hand margin
  formula is gone. `pricing_basis` / `delivery_status` lists are generated
  from the enums.
- `Order::netSalesSql()` replaces two identical copies (`ReportService`,
  `CustomerAnalyticsService`); `LlmReportViewParityTest` holds the view to
  `ReportService::summary()`.
- Monthly Summary: neutral `affiliate_tier_fees_sen` line ("from earnings, no
  cash"), also added to the Envelope Ledger's rough P&L.

**Corrected premises.** "Failed orders counted as revenue" matches Reports'
own Net Sales (a failed retail order's money is kept as store credit), so
the fix was to mirror Reports, not drop failed rows. Prod tier-fee entries
are 1, not 0 (RM0.00; both tiers are RM0).

**Found by the reader trace.** `BudgetEnvelopeController`'s rough P&L sums the
summary lines; without the tier fee it would understate profit. Added under
either accounting treatment.

**Not done on purpose.** No reviewer question drafted: nothing to book while
tier fees are RM0 (§16 item 74 is the trigger). Customer email/phone stay in
the view (noted in the ADR-087 addendum).

**Verification.** +4 tests (tier-fee KL month bounds, rough P&L, view parity,
prompt definitions + enum lists). Fast suite 2632/2632; Pint clean; `admin/`
tsc, lint and build clean. Local dev DB migrated (`php artisan migrate`); the
view returns 183 rows there. Concurrency suite: see the PR.

## 2026-10-08 — Backlog hygiene: one sorted queue in §16 "Next up" (docs only)

The founder's rule from this session: clear the backlog before any new
feature. §16 "Next up" now sorts every open item into A (buildable now), B
(grill first), C (founder actions), D (waiting on a trigger) and E (new
features, after the backlog). Each "open" claim was re-checked on 2026-10-08:

- Still open on prod: #70 (4 RM 0 voucher orders show `fpx`), #56
  (`ACCOUNTING_DISK` unset), #59 (`ENGINE_TYPE=baileys`), #58 (OpenWA still
  deploys from upstream, no fork), A3 (one 60/min limit per key).
- Corrected: the Valorant/ZZZ follow-up from release #362 is done (5 Valorant
  games are Text, ZZZ has its zone field); `AggregateRating` (item 6) shipped
  with ADR-120; §14 said the old droplet was rollback-only, it was destroyed
  2026-09-30; ADR-110's index row still called the CHIP `.env` cutover owed,
  while §16 "Parked" records keeping them as a fallback on purpose.

## 2026-10-08 — §16 items 70, 68, 67 + one shared admin `Switch`

**#70, full-voucher-cover order showed "fpx".** `CheckoutService::initiate()`
now stores `payment_method = 'voucher'` when the voucher covers the whole
price (no gateway runs, ADR-024 decision 5). Every reader just prints the
string (Order Detail, orders list, Track Order, Affiliate/portal order,
WhatsApp status card, Reports Payment Methods tab, CSV export, LLM view), so
one write-side change fixes all of them; `payment_gateway` / `channel_code`
are left as picked (reconcile only reads pending orders; settlement recon
excludes a null `payment_ref`). A data migration relabels existing rows
(voucher, RM 0, no `payment_ref`): prod has exactly 4, all Paid, checked
read-only on 2026-10-08. It runs on the next `main` deploy.

**#68, blank "UID only" select — wider than reported.** PrimeReact's Select
treats `""` as no value (`isNotEmpty`), so every `""` option in admin rendered
blank: "UID only", "All suppliers", "— No tier —", "Inherit supplier default"
and 10 more. Fixed once in `SimpleSelect` (sentinel inside, callers keep
`""`); request-logs' own `FilterSelect` now uses `SimpleSelect`.

**Toggle overflow (founder report, same session).** Seven hand-rolled
toggles. The four `Switch` copies placed an absolute knob with no `left`
inside a centring `<button>`, so it started mid-track and slid out. Replaced
by one `components/ui/switch.tsx` on PrimeReact `ToggleSwitch` (ADR-038),
knob in a flex row. Measured live: knob inside the 44×24 track in both
states (Membership, Payment Methods, Games packages).

**#67, ADR-109 admin click-through — passed.** `/admin/games` → Edit Game →
Content: description, two notes, Manual Processing, custom subtext saved; the
modal reloads them; public `GET /api/catalog/games/{slug}` serves them (cache
flushed); clearing every field saves back to `null` / `instant`. Dev DB
restored to its original values afterwards.

**Found, not fixed (prod, read-only check).** Five
`storage/app/backup-temp/restore-extract-*` folders (2026-09-26..30, 34–60 MB
each) hold plaintext DB dumps with customer PII. `RunDatabaseBackupJob`
deletes them in `finally`; these are from runs killed before it ran. None
since 2026-09-30 though the job still runs daily. Removing them is a prod
write — founder's call.

**Verification.** +1 test (full cover → `voucher`, partial keeps the
channel); fast suite 2633/2633. `admin/` tsc + eslint clean. Live checks in a
local browser as above.

## 2026-10-08 — Release #376 (`staging`→`main`) + item 56 prod cutover + backup-temp cleanup

**Release #376** (#370–#375). Server runs `7ff58ae`; batch 34 Ran:
`add_net_sales_to_llm_report_orders_view` and
`relabel_full_voucher_cover_orders_payment_method`. Before: the 4 RM 0
full-cover orders read `fpx`; after: `[{"payment_method":"voucher","c":4}]`.
`/api/health` 200. The `main` push run's `playwright` job failed on the
known item 27 flake (Turbopack `next/font/google` crash booting admin
`next dev`, no test ran); the same tree passed playwright on PR #376.
`deploy` still ran: its `needs:` lists every job except `playwright`, so
AGENTS.md's "gated on all test jobs" was corrected.

**Item 56 cutover (founder go-ahead; founder set the `.env` values).**
- Founder created bucket `pekangame-accounting` (APAC, private: no custom
  domain, public dev URL off, only the default multipart-abort lifecycle
  rule; checked in the Cloudflare dashboard) and a token scoped to it.
- `.env`: `R2_ACCOUNTING_ACCESS_KEY_ID/SECRET/BUCKET`,
  `ACCOUNTING_DISK=r2_accounting`, `WALLET_RECEIPTS_DISK=r2_accounting`
  (set after the deploy, since the disk name didn't exist in the old release).
- Ran: `php artisan config:cache`; a put/get/delete round trip on
  `r2_accounting` (read `ok`, gone after delete).
- Ran: tinker copy of `local:accounting/**` → `r2_accounting`, skipping
  existing keys. First run copied 3 PDFs (22570/22569/22561 bytes, sizes match
  on both sides); second run skipped all 3.
- After: transfers 3, 4, 5 resolve on the new disk and stream 200 with a
  valid `%PDF` body through `SupplierFundingService::downloadReceipt()`;
  transfers 1, 2 (voided) stay missing, as recorded in ADR-114's 2026-10-01
  addendum. 0 budget receipts, 0 wallet receipts. The local copies were left
  in place (harmless, a fallback until the next droplet move).

**backup-temp cleanup (founder ran the command).** Before: five
`storage/app/backup-temp/restore-extract-*` folders (2026-09-26..30, 34–60 MB,
plaintext DB dumps with customer PII), left by restore tests killed before
`RunDatabaseBackupJob`'s `finally` ran. After: the folder is empty (4.0K).

## 2026-10-08 — Docs hygiene: archive pass + stale-claim sweep (docs only)

Founder asked for every finished backlog item to be recorded, old info moved
out of docs where it no longer belongs, and stale docs archived.

- **Archived (verbatim, checked line by line: nothing lost):** build-log
  entries 2026-09-23 → 09-30 (1880 lines) to `build-log-archive.md` ("Third
  archive move"); PRD §14's per-release history (#369 back to #320) to the
  same file. §14 now holds only the current state. Releases #342 and #320
  had no build-log entry of their own, so the archive is their only record.
- **Moved:** `research-payment-gateways-2026-07-30.md` → `docs/archive/`
  with a "historical" banner (Xendit-era research; CHIP-only since
  2026-09-01). ADR-022's link updated. `foundation-security.md` and
  `legacy-reference-notes.md` stay: code comments cite them in 20+ places.
- **Stale claims fixed, each re-checked against code or prod:**
  - README: PHP 8.4 (was 8.3), `composer run dev` runs Horizon/Reverb/Pulse
    and needs Redis (said `queue:listen`), no storefront `.env.local.example`,
    portal dev server added, 6 e2e specs (said 4), domain `pekangame.com`,
    and "no supplier account is funded" (Digiflazz funded since 2026-09-15).
  - `foundation-security.md`: PAY-2/PAY-3/§5 described Xendit controllers
    as the live mechanism; ADAPT-1 named Gamevion as the only adapter;
    DEV-2 said Developer Tools didn't exist (it does, and redacts through
    `SupplierRequestPayloadRedactor`); the route-enumeration line had gone
    stale again and is now a rule. Added lines for the restore-test plaintext
    dump risk and the private receipt bucket.
  - ADR index: ADR-059's payout-redirect fix said "Not yet built" (built,
    `72706b2`); ADR-105 said order 15's RM0.10 was still owed (corrected,
    release #362 entry).
  - PRD §15 ADR-109 "admin verification still owed" (done, item 67); §12
    backup local-disk gap (closed by R2); §16 items 6, 9, 27, 49 trimmed
    to what is still open (closed parts are in the build-log).

## 2026-10-08 — Item 27: e2e admin boots from a production build

`playwright` went red on the `main` push of release #376 (third time) because
Turbopack `next dev` crashed compiling admin's `next/font/google` on demand,
before any test ran. `e2e/playwright.config.ts` now boots admin with
`npx next build && npx next start --port 3000` (webServer timeout 60s → 300s
to cover the build). The build compiles once and fails loudly; `start` has no
on-demand compile. `--webpack` dev (tried 2026-09-28) broke two admin specs;
the production build passes all three. Storefront hit the same crash on its
Inter font the same day (PR #379's run), so it got the same change. Local run
with both on production builds: 6/6 passed.

## 2026-10-08 — Combo modal shows each component's supplier SKU (item 66, part)

Founder couldn't tell which supplier product a combo leg was (e.g. "60 UC ×3"
with no SKU). `GameController::packages()` now includes
`supplier_package_ref` in each combo component, and `ComboOverrideModal` shows
it under the name, the same SKU the Create Combo picker already shows. Admin
route only. Test: `ComboPackageControllerTest`. Item 66's edit-composition and
leg-history parts stay open.

## 2026-10-08 — Reports redesign designed (item 65, ADR-104 addendum R1–R21)

Design only, nothing built. The founder found the live Reports page untidy
and the 2026-09-17 mockups partly outdated, so every tab was compared side by
side (private artifact `https://claude.ai/artifact/F5gLCNTsLq4NydriLuvoRY`,
screenshots from the local demo DB + the artifact's own preview HTML rendered
with its `tokens.json`/`bundle.css`), then grilled (4 rounds), stress-tested
and code-traced.

- **Live defects found:** "All time" KPIs vs a silent 30-day trend chart; an
  unbounded daily table; raw, inconsistently cased payment-method labels; RM
  rounding that differs between a bar list and its table.
- **Money facts (prod, 31 paid non-test orders):** Reports "Sales" (paid,
  incl. failed, net wallet refunds) and the Monthly Summary's revenue
  (delivered `selling_price`) differ by design. Owner `order_profit` RM 7.38
  vs `voucher_issued` −RM 13.18: compensation is a liability and must not be
  netted from profit. 7 failed orders, 0 Need action (all compensated). All
  7 reseller orders came from the Bot (`wa:` keys); the reseller portal
  cannot place orders.
- **Stress test turned up reuse/drift points the build must route through:**
  `DashboardService::comparison()` (Compare period), two hand-mirrored
  Path B voucher definitions (Dashboard + Transaction Register),
  `SandboxOrderController` bypassing `OrderFactory`, and ADR-086's
  deliberate hand-rolled chart house style (reversed on record by R1).
- **Gotcha:** the artifact page itself reads as an empty shell through the
  Artifact tool, but `read` with `paths` returns its files (preview HTML,
  tokens, CSS, fonts). Headless Playwright login to local admin needs a
  click on "Sign in" (Enter didn't submit), and `goto('/admin/reports')`
  after login bounced to `/login`; navigating via the sidebar link worked.
- New §16 item 75 (voucher-liability definition, for the external reviewer).

## 2026-10-08 — Reports redesign PR-A: backend (item 65, ADR-104 R5, R7–R17)

Backend only; the Reports UI is unchanged until PR-B/PR-C. Built test-first
on `feature/2026-10-08-reports-pr-a`.

- **R7 one revenue seam.** `MonthlyAccountingSummaryService::salesRevenue()`
  and its settled-partial helper moved into `Accounting\RecognisedRevenue`;
  the Monthly Summary (revenue and COGS) and the new
  `ReportService::accountingBridge()` both call it. The bridge returns
  `unexplained_difference`; `ReportAccountingBridgeTest` proves it is 0 over
  every order shape (delivered, voucher discount, full cover, failed +
  voucher, wallet refund, in flight, settled and unsettled combo partial) and
  that recognised revenue equals the Monthly Summary figure. A delivered but
  unpaid order shows up as a non-zero difference, as designed.
- **R8–R11.** `failedAndCompensated()` is one SQL pass mirroring
  `Order::cashCompensationSen()` / `compensationAmountSen()`, held equal by
  `ReportCompensationTest`. `outstandingStoreCredit()` counts active,
  unexpired compensation vouchers. `Voucher::scopeCompensation()` is now the
  only Path B definition; Dashboard and the Transaction Register route
  through it.
- **R12–R14.** `DashboardService::comparison()` became
  `Support\PeriodComparison` (`change()`, plus `points()` for margin, and
  `previousRange()`). `GET /reports/summary?compare=previous|month_to_date`
  adds a `compare` block (null for All time). The page sends the mode
  because dates alone can't tell "This month" from a "Last 7 days" that
  starts on the 1st.
- **R15/R16.** `orders.placed_via` (`PlacedVia` enum), required on
  `OrderDraft` and `ResellerOrderPlacementRequest`; the sandbox and CHIP
  smoke-test direct creates set it. The backfill was run on the local dev DB
  (17 seeded wallet orders → `reseller_api`, 172 → `storefront`).
  `breakdown/channels` returns own brand / reseller wallet / external
  affiliate plus the wallet API-vs-Bot split.
- **R17.** The trend's "All time" runs from the first paid order to today;
  the 30-day fallback is gone.
- **New endpoints:** `breakdown/channels`, `breakdown/delivery-by-game`,
  `accounting-bridge` (null under an affiliate filter), `failed-compensated`.
- **Gotchas.**
  - The bridge first computed `selling_price - final_amount`. On MySQL
    that is unsigned minus unsigned and errors for any order with a fee;
    sqlite passed. It was caught only by re-running the report tests
    against the docker MySQL (a temporary phpunit config pointing
    `phpunit.concurrency.xml`'s env at `tests/Feature/Services/Report`).
    The fix nets the new `Order::walletRefundSql()` from `selling_price`
    directly.
  - sqlite's `->change()` can't rebuild `orders` under the
    `llm_report_*` views. The migration drops them and re-creates them from
    `sqlite_master`'s stored SQL, so there is no fourth hand copy of the view
    definition.
  - 80 test sites create orders directly. They now pass
    `'placed_via' => 'storefront'` (mechanical edit) instead of the column
    getting a default.
- **Gap found, not fixed (0 on prod):** `VoucherService::merge()` gives the
  merged voucher `order_id = null`. A merge of compensation vouchers
  therefore drops out of outstanding store credit (and out of the Dashboard
  and Register counts, which predate this). Prod has 0 merges.
