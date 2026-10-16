# PekanGame — Build Log

> The running chronological record of what shipped, when, and why — every
> session, the bugs hit along the way, and the live-verification notes.
> Moved out of `prd.md` §14 on 2026-09-11 once it outgrew the spec doc.
>
> **This file is append-only history.** Current per-feature status lives in
> `prd.md` §15; the live backlog is `prd.md` §16; decision rationale is the
> ADR log (`adr.md` index → `adr/`). **Everything before 2026-10-13** lives verbatim in
> [`build-log-archive.md`](./build-log-archive.md): the foundation build, the
> old §14 table and PrimeReact tracker, and the production-era entries
> 2026-09-01 → 10-12 (moved in seven passes, the last on 2026-10-15) to keep
> this file quick to read at session start.

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

---

## 2026-10-13 — Release #416 (`staging`→`main`): #407–#415

Founder asked to merge #415 and release. Merged as `d7e5fd5`; CI (all jobs),
the Forge `deploy` job and the four Vercel production builds green. Prod
`current/` is at `d7e5fd5`; Horizon running.

**Production evidence (read-only, before → after):**
- Item 79 migration: compensation debits on vouchers 1, 2, 4, 5 (−1318 sen)
  with 0 `item79-reversal` keys and platform ledger 1551 sen → 4 keys,
  platform ledger 2869 sen (+1318, as predicted). The original debits stay
  (append-only); each now has its reversal.
- Item 64: `membership_reminders` did not exist → `2026_10_13_000000` Ran.
  `app:send-membership-reminders --dry-run` lists nobody today. Member #2 ends
  2026-10-24 MYT, so `kindDue()` is null until 21 Oct; member #1 (ended 2 Oct)
  is past the 7-day window. `schedule:list` shows `0 2 * * *` UTC (10:00 MYT).

**Still by hand:** #409 logo re-upload in a browser; #411 the same order
number sent several times from a real phone to the CS number. After 21 Oct:
member #2's `before` row should read `sent` on the admin member page.

---

## 2026-10-13 — §16 item 84 (expired voucher shown as expired) and a docs audit

On `fix/admin-voucher-list-expiry`. Staging only until a release.

**Founder checks after release #416.** #411 (a card on every repeat, real
phone), #409 (a re-uploaded logo shows at once) and the unsquashed email logo:
all confirmed by the founder.

**Item 84.** Expiry is `expires_at` alone; `status` stays `active`. Tracing
every reader found the "usable" rule inline in three places (checkout,
merge, and the admin list, which lacked the expiry half) and two more
display readers (the order page's Refund cards, and the Issue Voucher response
the order page merges into its state).
- `Voucher::isExpired()` is the one rule; `VoucherService` checkout and merge
  use it.
- `display_status` (`expired` for an active voucher past its expiry) is
  appended only on admin responses: the list (stats, pill, merge checkbox),
  the detail, the order detail's two vouchers and the Issue Voucher result.
  The Revoke button still follows the stored status (revoking an expired
  voucher stays allowed). `status` is not backfilled.
- Tests: list stats + `display_status` + stored status untouched; order detail
  `paid_with_voucher.display_status`; Issue Voucher response. Backend
  2733/2733; admin `tsc`/`lint`/`build`; a built admin showed an expired dev
  voucher with the `expired` pill, no merge checkbox, and Active 1 / Expired 1.

**Docs audit (current state and contradictions).**
- PRD §14 hand checks done; §15 Vouchers, Customer notifications and Settings
  rows no longer say "staging only" for #409/#411/#412/#413; §16 dates refreshed,
  the duplicate item 6 entry removed, item 84 closed.
- ADR-027's addendum decision 28 (renewal reminders on WhatsApp only, not
  email) contradicted ADR-068's 2026-10-13 addendum. ADR-027's Standing (file
  and index) and the ADR-068 addendum now cross-reference.
- `foundation-security.md` and `backend/AGENTS.md` said every voucher issuance
  writes a ledger row; since item 79 only an admin-issued voucher does.
- Glossary: Voucher Expiry, Membership Renewal Reminder.
- New §16 item 87 (founder): two `.env` backups on the prod box hold the DB
  password, one of them mode 664 (read-only `ls`, 2026-10-13).
- New §16 item 88: a typed voucher expiry date ends at 08:00 MYT that day
  (midnight UTC); no production voucher has an expiry yet.

---

## 2026-10-13 — §16 item 87 closed (founder), #418 merged

The founder merged #418 (item 84 + docs audit) into `staging` and deleted the
two `.env` backups on the prod box. Read-only `ls -la ~/api.pekangame.space/`
afterwards: only `.env` (mode 664, owner `forge`) remains. #418 is on `staging`
only until the next release.

## 2026-10-13 — §16 item 6 / A3: Reseller API rate limit per account, two buckets

On `fix/reseller-api-rate-limit-per-account`. Staging only until a release.
Design: ADR-074 2026-10-13 addendum (grilled, then stress-tested against the
code and Laravel's `SortedMiddleware`).

- **What changed.** The one `reseller-api` limiter (60/min per raw bearer
  token, run before auth) became two named limiters keyed on the Reseller:
  `reseller-api-orders` on `POST /v1/orders` (60/min) and
  `reseller-api-reads` on every `GET` (120/min). `EnsureResellerApiKey` is
  pinned ahead of `ThrottleRequests` in the priority list, next to ADR-080's
  pin. Bot untouched.
- **Gotcha proved, not assumed.** With the pin removed, all 5 new tests fail:
  the `api` group's `SubstituteBindings` makes Laravel hoist the throttle above
  the auth middleware, whatever order the route lists them in. `route:list`
  shows the declared order, not the sorted one, so it can't show this.
- **Tests.** New `ResellerApiRateLimitTest` (5): keys of one account share the
  order bucket; reads never block orders and the reverse; accounts are
  isolated; revoke-and-reissue keeps the bucket. Red first (4 of 5; isolation
  already held per key). The old 60/min limit had no test at all. Fast suite
  2738/2738.
- **Live check.** Local `php artisan serve` + `curl` with a temporary key
  (deleted afterwards): 120 reads then 429 with `X-RateLimit-Limit: 120` and
  `Retry-After`; 60 order POSTs still allowed after the read bucket was spent,
  then 429 `RATE_LIMITED`; a bogus key 401.
- **Docs site (v1.5.0).** `rate-limits.md` rewritten (per account, two
  buckets, the `X-RateLimit-*` headers, a 429 on an order means not placed).
  Full read of all 11 pages against the code, which corrected:
  `first-order.md` said the price "follows the supplier's cost… about hourly"
  (shows the cost structure; prod syncs every 30 min), now "Prices can change
  whenever PekanGame updates its catalogue"; the poll/webhook bullets there
  and the webhooks page description now include `partially_delivered`;
  `authentication.md` notes all keys share the limits; `wallet.md` dropped
  "If your pricing changes, PekanGame will tell you in advance" (it meant the
  tier, per `badfe1b`; the founder removed the promise since only admins set
  a tier) and points to the price-change guidance instead. No ADR/PRD/internal
  reference was found in the pages or the generated spec. `openapi.json`
  changes only in `info.version`.
- **Production read (read-only) for the grill:** 4 tiers, 2 resellers, 2 live
  API keys (reseller #1, last used 2026-09-09), 0 API orders ever, 8 Bot
  orders.

## 2026-10-13 — §16 item 88: a voucher's typed expiry date lasts the whole Malaysia day

On `fix/voucher-expiry-end-of-day-myt`. Staging only until a release.
Design: ADR-024 2026-10-13 addendum (grilled, stress-tested).

- **Bug.** `2026-11-01` was stored as midnight UTC (08:00 MYT), so checkout
  refused the voucher from 08:00 on the day WhatsApp called its last. Now it
  is stored as 23:59:59 MYT (`2026-11-01 15:59:59` UTC) by one helper,
  `VoucherService::endOfMalaysiaDay()`, used by issue and merge.
- **Gotcha (stress test).** Eloquent writes a Carbon's own wall-clock time:
  a Kuala Lumpur `23:59:59` saved as-is is read back as UTC, 8 hours late.
  The helper calls `->utc()`; test 1 pins the stored string.
- **Validation.** `date_format:Y-m-d` + `after_or_equal:` today in Malaysia,
  the supplier-transfer request's pattern. Laravel's `today` is UTC and would
  take yesterday's Malaysia date before 08:00 MYT; a test runs at 02:00 MYT.
- **Admin.** Create and Merge modals: native `<input type="date"
  min={todayInKL()}>`, label "Valid until". The calendar icon was nearly
  invisible in dark mode (all 17 admin date inputs); one scoped
  `.dark input[type="date"] { color-scheme: dark; }` in `globals.css` fixes
  them all, confirmed by screenshot + `getComputedStyle` (`colorScheme:
  dark`).
- **WhatsApp line** formats the date in Malaysia time explicitly (it was right
  only because 15:59 UTC falls on the same day).
- **Tests.** New `VoucherExpiryTest` (7): stored value, usable at 23:00 MYT and
  expired at 00:00 MYT next day, merge, today-in-Malaysia at 02:00 MYT, plain
  date only, WhatsApp "Valid until: 1 Nov 2026", breakage month. Red first
  (5 of 7; WhatsApp and breakage were already right by coincidence). Fast
  suite 2745/2745. Admin `tsc` + `eslint` clean.
- **Live check.** Built admin + local API, Playwright in the Kuala Lumpur
  timezone: the modal's input is `type=date`, `min=2026-10-13`; a voucher
  created with 1 Nov is stored `2026-11-01 15:59:59` and its View panel reads
  "Expires 1 Nov 2026, 11:59 pm". (The local dev DB keeps that RM 5 test
  voucher, reason "Item 88 local check".)
- **Found:** the Malaysia timezone string is copied in five services; logged
  as §16 item 90 (A).

## 2026-10-13 — #420 and #421 merged; docs consistency pass

The founder merged #420 (item 6/A3) and #421 (item 88) into `staging`. Not
live: `origin/main..origin/staging` holds #417–#421 (code: #418, #420, #421;
no migration).

Docs pass before closing the session:
- PRD §14 now records the unreleased `staging` set, and that all three
  payment methods are inactive since 2026-10-07 by the founder's choice
  (confirmed 2026-10-13; read-only prod check the same day: `fpx`,
  `fpx_b2b1`, `duitnow_qr` all `is_active = 0`, last wallet top-up attempt
  2026-09-20, one order in the past 7 days, paid from a wallet).
- §16 "Recently closed": the item 6 (part) line no longer says A3 remains.
- Checked for stale rate-limit or voucher-expiry statements across `docs/`,
  the ADR index, `AGENTS.md` files and the docs site: none left. ADR-074's
  original decision 4 and PR-E note still say "keyed on the API key"; per the
  ADR file rules, its 2026-10-13 addendum overrides them.

## 2026-10-13 — Item 90: one shared Malaysia timezone constant; timezone audit

On `fix/shared-malaysia-timezone`. Staging-only until a release.

- **What.** `App\Support\MalaysiaTime::TIMEZONE` is the one home for
  `'Asia/Kuala_Lumpur'`. The copies in `ReportService`, `DashboardService`,
  `CustomerAnalyticsService`, `AffiliateEarningsService` and
  `PeriodComparison` are gone, and every reader (vouchers, accounting,
  form requests, scheduler, the `transferred_on` migration, the demo
  seeder, tests) points at it. Admin: `KL_TIMEZONE` in `lib/date-range.ts`;
  the dashboard's own `kl_today()` folded into `todayInKL()`. No behaviour
  change. The SQL day buckets keep their `'+08:00'` offset (MySQL
  `CONVERT_TZ` needs an offset without the tz tables). Tests that pin
  `'Asia/Kuala_Lumpur'` as a literal were left as is, on purpose.
- **Verify.** Fast suite 2745/2745. Admin `tsc` + `eslint` clean.
- **Timezone audit (founder's ask, read-only).** Swept the backend
  (scheduler, date filters, emails, WhatsApp, CSV/PDF/Excel, SQL) and the
  four frontends for Malaysia-day logic still on UTC. Reports, Dashboard,
  Customer Analytics, Accounting, the Orders filter, vouchers, membership
  emails and reminders, exports and the affiliate dashboard already use
  Malaysia time. Found two real bugs, logged as §16 items 91 (hero-slide
  schedule shifts 8 hours per edit; no prod slide has a schedule) and 92
  (Request Logs filter on UTC days). Cosmetic: the stale-FX alert prints a
  UTC time unlabelled; the email footer year is the UTC year. Not bugs:
  `->daily()` jobs run 08:00 MYT and the backup 02:00 UTC (10:00 MYT);
  frontend dates render in the viewer's browser timezone; Digiflazz cutoffs
  are Asia/Jakarta on purpose.

## 2026-10-13 — Items 91 + 92: hero-slide schedule and Request Logs filter on Malaysia time; FX alert and email footer

On `fix/timezone-hero-slide-request-logs` (stacked on item 90's branch, for
`KL_TIMEZONE`). Staging-only until a release.

- **91.** `HeroSlideModal` loaded `starts_at`/`ends_at` by slicing the UTC
  ISO string, so 10:00 MYT showed as 02:00 and a re-save moved it 8 hours
  back. New `klInputToIso` / `isoToKlInput` in `admin/src/lib/date-range.ts`
  read the `datetime-local` input as Malaysia time (fixed +08:00) whatever
  the browser's timezone; labels say "Malaysia time". The list's Schedule
  column now formats in `KL_TIMEZONE` too.
- **92.** `RequestLogController` compared the raw `YYYY-MM-DD` with the UTC
  `created_at`. It now validates `date_format:Y-m-d` and uses
  `ReportService::dateRangeFromDates()`, the Orders filter's Malaysia-day
  bounds.
- **Cosmetic, from the same audit.** The stale-FX alert email (IDR->MYR,
  sent to every active admin when the stored rate is over 48 h old) now
  states the stored rate's time in Malaysia time ("13 Oct 2026, 11:30
  MYT"), not an unlabelled UTC time. It has never fired in production (0
  log lines; read-only check 2026-10-13). The email footer's © year is the
  Malaysia year (both markdown mail layouts).
- **Tests.** Red first: `CurrencyRateServiceTest` +1 (the MYT time in the
  alert). `RequestLogControllerTest` +2 (13 Oct boundary rows
  at 00:00 and 23:59:59 MYT; a non-date `from` is 422).
  `admin/src/lib/date-range.test.ts` (2), run under `TZ` New York, Kuala
  Lumpur and UTC. Fast suite 2748/2748. Admin `npm test` 8/8, `tsc` +
  `eslint` clean.
- **Live check.** Built admin + local API, Playwright with the browser in
  New York and then Kuala Lumpur: a slide stored 2026-10-20 02:00 UTC opens
  as `2026-10-20T10:00`, and Save leaves the DB at 02:00 UTC both times.
  The test slide was deleted afterwards.

## 2026-10-13 — Release #425 (`staging`→`main`) verified live; docs audit

**Release.** The founder merged #425 (#417–#424) into `main` as `cad575e`.
Verified: `origin/main` = `origin/staging`, CI green on `cad575e`, the prod
box's `current/` is `cad575e` and has `app/Support/MalaysiaTime.php`,
`/api/health` reports database, queue and Horizon ok, and the docs site's
`openapi.json` is v1.5.0. No new migration (last Ran is still
`create_membership_reminders_table`). Payment methods still all inactive
(read-only: `fpx`, `fpx_b2b1`, `duitnow_qr` `is_active = 0`), as intended.

**Docs audit (founder's ask: what is stale, contradictory, or worth keeping).**
Fixed on `docs/2026-10-13-release-425-docs-audit`:
- PRD §5, §10 and §15 said FPX is active; all three methods are off since
  2026-10-07 (§14 already said so). Now consistent.
- PRD §8: the Affiliate row listed `xendit_subaccount_id` (dropped
  2026-09-02); the Backup row described columns that never existed, now the
  real `backup_runs` (`BackupRun`) shape.
- PRD §11 listed "Automated Exchange Rate Feeds" as future; it shipped
  (ADR-033).
- PRD §14 now holds only the latest release (#425); the #416, #406 and #393
  narratives were already in the build-log / archive, so they are a pointer.
- PRD §15 / §16: every "staging only until a release" now says live via #425.
- Glossary: a ledger entry also belongs to a reseller wallet.
- `adr.md`: 7 index rows (ADR-022, 037, 039, 066, 073, 076, 111) had `||`,
  which adds an empty column; fixed.
- Build-log: the 2026-10-09 and 2026-10-10 entries moved verbatim to the
  archive ("Sixth archive move"); this file now starts at 2026-10-11.
  Pointers updated in `AGENTS.md`, `backend/AGENTS.md`, ADR-104 and PRD.
- `docs/anti-slop/audit-001-2026-09-18.md` (self-labelled historical) moved
  to `docs/archive/`.

Checked and kept as is: `foundation-security.md` (re-verified items, current),
`legacy-reference-notes.md` (correctly fenced as legacy-only), ADR banners vs
index Standing (wording differs, meaning matches), the root/backend AGENTS
facts (Laravel 13, PHP 8.4, Next 16.3.8, Astro 7, deploy gated on every CI
job except `playwright`).

PRD §9 said the admin is notified of stuck orders, low supplier balance and
a tripped circuit breaker. Today only backups, Horizon long-wait and a stale
FX rate email the admins; the rest is Dashboard-only. Founder's call: §9 now
states that, and a low-balance email joins §16 item 28's build scope as an
ADR-115 2026-10-13 addendum (revising decision 8, whose `MAIL_MAILER=log`
reason no longer holds since ops alerts go through Plunk). Found on prod
(read-only): Digiflazz has no `low_balance_threshold`, so today's
low-balance log warning never fires.

## 2026-10-13 — Item 93: combo prices stale after reactivation (fix)

On `fix/combo-price-stale-after-reactivation`. Staging-only until a release.

- **Found** during the item-66 audit (read-only prod): 8 active combos (plus
  1 inactive) had a stored `cost_price` / `standard_selling_price` that no
  longer matched their components. Combo 1494: RM626.18 stored vs RM623.43
  live. Its timeline: cascade-deactivated 2026-10-12 20:00 UTC, component
  costs moved in the 2026-10-13 03:30 sync, cascade-reactivated 08:30 with no
  recompute. 82 combo cascade-reactivations since 2026-09-24, so the cycle is
  routine. Today's direction overcharged slightly; a cost rise would sell
  below real cost, and the checkout below-cost guard compares with the same
  stale stored cost.
- **Every writer traced.** Component price writers: Price Sync propagation
  and `PendingPriceChangeController::approve()` (both called
  `recomputeForComponentChange()`, which skipped inactive combos),
  `PackageController::updateMarkup()` (no combo hook at all; it also accepted
  a markup on a combo itself), bulk markup (already recomputed every combo).
  Reactivation sites all route through `cascadeReactivate()` /
  `cascadeReactivateForComponents()`.
- **Fix** (ADR-094 2026-10-13 addendum): one `recomputeAndLog()`;
  `recomputeForComponentChange()` covers inactive combos; both cascades
  reprice before reactivating; `updateMarkup` reprices a component's combos
  and rejects a combo (422); bulk markup reuses `recomputeAndLog()`. Admin:
  the Markup cell is read-only on a combo row, and a component markup update
  refetches the list so its combos show their new price.
- **Data:** migration `2026_10_13_100000_recompute_stale_combo_prices`
  recomputes every combo once on deploy (idempotent, one `PriceChangeLog`
  row per repriced combo, catalog caches dropped). Expected on prod: 9
  combos repriced (the 8 active above plus inactive 1277), nothing else.
- **Tests.** Red first: `ComboPricingServiceTest` +3 (the old "ignores an
  inactive combo" test asserted the bug and was replaced),
  `PackageControllerTest` +2, `StaleComboPriceRecomputeMigrationTest` (run
  twice). Fast suite 2753/2753. Admin `tsc` + `eslint` clean.
- **Live check.** Local dev DB migrated (its one combo already current, no
  change; a second `migrate` ran nothing). Built admin + local API,
  Playwright: the combo row shows "20.00%" as text, and a component markup
  of 50% moved the combo to RM 9.76 (2 × 4.88) without a reload; restored to
  20% (RM 7.80). Gotcha: local Redis was down (Docker not running), so a
  cache flush made the PATCH 500 after its commit; re-run with
  `CACHE_STORE=array ... php artisan serve --no-reload` (backend/AGENTS.md
  gotchas 3 and 5).

## 2026-10-14 — §16 item 66 parked: a combo is never edited

**Decision (founder, docs only).** Item 66 (combo edit-composition UI) is
parked. On 2026-10-13 the founder rebuilt MLBB Malaysia's combos by hand, two
per denomination, and will do the same for PUBG Mobile, so the planned "edit
= replace version" design would only automate what that workflow already
does. A combo is never edited: create a new one and deactivate the old one.
Reopen only as a "Duplicate combo" button (ADR-094 2026-10-14 addendum, PRD
§16 Parked). PRD §16 group B now holds only item 81.

**Pre-release combo check (prod, read-only tinker, 2026-10-14).** Stored
`cost_price` / `standard_selling_price` against the sum of components, all
101 combos (none has an override):
- Mobile Legends Malaysia: 52 combos, all active, all current (the 2026-10-13
  rebuild; the older MLBB combos are gone).
- PUBG Mobile Global: 49 combos, 28 stale (8 active, 20 inactive). The 8
  active ones are the item-93 set and are priced *above* their live cost
  (stored cost higher than the component sum by RM0.23–RM2.39), so no sale
  is below cost. The 2026-10-13 entry expected 9 combos to be repriced;
  component costs have moved since, so the next release's migration should now
  reprice 28.

**Release #430 verified (2026-10-14, read-only).** `main` = `735e156`;
migration `2026_10_13_100000_recompute_stale_combo_prices` ran at 06:58:18
UTC and wrote 28 `PriceChangeLog` rows (no sync run), matching the
pre-release count. Same check after deploy: **0 stale of 101 combos**. The
active count moved from 81 to 100 (PUBG 28 → 48), but not from the
release: Price Sync run 2218 at 06:30:42 UTC, before the deploy,
cascade-reactivated the 20 inactive September PUBG combos (1366–1435,
`deactivated_reason = combo_component_deactivated`, `PackageReactivationLog`
trigger `combo_components_all_active`) once their components came back.
That ran on the old code, so they came back unrepriced, which is the item-93
bug; the migration repriced them 28 minutes later. PUBG now has two active
combos per denomination for those sizes (September and 2026-10-09 versions).

## 2026-10-14 — §16 group D reviewed item by item; OpenWA sessions auto-start

**Why.** The founder asked which "waiting on a trigger" items could be
decided now instead of sitting in the backlog. Each was checked against prod
(read-only) before deciding.

**Decisions (founder).**
- **28** Forecast plus low-balance email: build two weeks after launch
  (payments back on for real customers). The founder set Digiflazz's
  `low_balance_threshold` to IDR 10,000,000 the same day, so the Dashboard
  chip works now (ADR-115 2026-10-14 addendum). **55** follows 28.
- **10** Admin MFA: build (moved to group B, new ADR at the grill).
  Affiliate/reseller MFA dropped. Login 7 days max, 2 days idle, shared
  across tabs; a fresh code for CHIP credentials, wallet credit and voucher
  issue; recovery codes (ADR-019, ADR-058, ADR-059 2026-10-14 addenda).
- **73** Reseller API rejection log: build now (group A). Prod: 0 Reseller
  API orders ever, keys last used 2026-09-09.
- **New 95** WhatsApp session health endpoint for UptimeRobot (group A).
  **New 96** OpenWA v0.24.0 upgrade (group C). The founder added an
  UptimeRobot monitor on `https://bot.pekangame.space/api/health` (green).
- **Dropped:** 49 (proxied affiliate domain), 53 (parallel reconcile), 58
  (OpenWA chat-list rehydration: the founder answers from the
  `customer-support` phone; ADR-075 2026-10-14 addendum), 72 (Bot `max=`),
  82 (CS inbound log and CS-agent inputs: no CS agent is planned; the inputs
  are still in the founder's `/Project/chatbot`), 86 (email logo PNG), 89
  (per-reseller rate limit). Each was "build if someone reports it"; any
  design stays in its ADR.
- **Kept with their triggers:** 4, 59, 74, 83, 85.

**OpenWA restarts left the bots down (prod fix, founder-run).** The founder
often had to press Start on a disconnected session. The OpenWA audit log
showed a manual `session_started` after every process restart, with the bots
down 2 min to 28 h 39 min (3–4 Oct) between 30 Sep and 13 Oct. Cause: Ubuntu's
daily `unattended-upgrade` → `needrestart` → `supervisor` restart → every
Forge daemon restarts (13 Oct: apt 06:39:12, supervisor 06:39:19 UTC), and
`AUTO_START_SESSIONS` was unset, which OpenWA treats as off.
- Ran by the founder (the model's write was blocked by the permission
  classifier): `cp .env .env.bak-2026-10-14 && printf
  "\nAUTO_START_SESSIONS=true\n" >> .env` in `~/OpenWA`, then a daemon
  restart from Forge.
- Before: both sessions `disconnected` at 08:01:36 UTC after the restart.
  After: `Auto-started session` logged, `reseller-bot` ready 08:03:05,
  `customer-support` ready 08:03:08, no new manual `session_started` row.
  ADR-114 2026-10-14 addendum.
- Gotcha: the two `~/OpenWA/.env.bak-*` files hold the gateway's secrets;
  deleting them is part of item 96.

## 2026-10-14 — §16 items 73 and 95 built (Reseller API rejection log, WhatsApp health)

**What shipped (branch `feature/reseller-api-rejection-log-and-whatsapp-health`).**
- **73:** `ResellerApiRejectionLog::record()` runs inside the one Reseller
  API render hook, which now builds the rejection first and then logs and
  renders it (same responses as before, `Retry-After` kept on 429).
  `EnsureResellerApiKey` stores the resolved key on the request before its
  inactive and IP checks. Only known resellers are logged; `RATE_LIMITED`
  once a minute per reseller (ADR-074 2026-10-14 addendum).
- **95:** `OpenWaClient::sessionStatus()` (never throws: `not_configured`,
  `unreachable`, `http_<code>` or OpenWA's own status) and
  `HealthController::whatsapp()` on `GET /api/health/whatsapp`, 503 unless
  both sessions are `ready`, throttled 30/min. The response shape was read
  from prod first (`GET /api/sessions/{id}` returns `status: "ready"`).

**Gotcha.** Prod runs `LOG_LEVEL=warning`, so the rejection line had to be
`warning`; an `info` line would have passed every test and never reached
the prod log.

**Tests.** Red first: `RejectionLogTest` (6: business and middleware
rejections logged with reseller and key, validation details, no log without
a valid key, none on success, rate limit once a minute) and
`WhatsAppHealthTest` (4: ok, one disconnected, unreachable, not
configured). Full suite 2763/2763; Pint clean. The Scramble export covers
only `api/reseller`, so the docs-site spec is unchanged.

**Live check.** Local `php artisan serve`: `/api/health/whatsapp` answers
503 `not_configured` for both (no OpenWA locally); a keyless Reseller API
call still answers 401. The `ready` path is checked on prod after release.

## 2026-10-14 — §16 item 10 built: admin MFA and the cookie-session admin login (ADR-121)

**What shipped (branch `feature/admin-mfa`).**
- **Backend.**
  - The admin login is a Sanctum SPA cookie session, and admin bearer tokens are refused. A migration deletes the existing admin tokens and adds `mfa_required`, `mfa_recovery_codes` and `mfa_last_timestep`.
  - The login lasts 7 days at most (`EnsureAdminSessionFresh`) and ends after 2 days unused.
  - MFA is opt-in per account (`PATCH /admin-users/{id}/require-mfa`, one-way). Setup and the code are entered at `/api/login` → `/api/login/mfa`.
  - `mfa.stepup` guards CHIP credentials, supplier create/credentials, manual reseller wallet credit, withdrawal approve, admin create/edit/status, and recovery-code regeneration. `StepUpRoutesTest` pins the set.
  - The TOTP and recovery logic lives in one `AdminMfa` service. Wrong codes count toward `AccountLoginThrottle`.
  - Plunk security emails go out on recovery-code use, lockout and SSH reset. The SSH reset is `php artisan admin:mfa-reset {email}`.
  - CORS credentials are allowed for the admin origin only.
- **Admin.**
  - The login page has the setup (QR plus key), code and recovery-code screens.
  - A `StepUpDialog` appears on `mfa_step_up_required`, and the original request is retried.
  - Admin Users has an MFA column and a "Require MFA" action. The header menu has "New recovery codes".
  - The profile cache lives in `localStorage` and holds no credential.
  - All bearer-token plumbing is removed (~1,200 references, 124 files), and the eight download copies are one `apiDownload()`.

**Why.** PRD §16 item 10. An admin password alone could change the CHIP credentials, credit a reseller wallet or approve a withdrawal. Separately, every new admin tab asked for a login again.

**Gotchas.**
- `google2fa`'s `verifyKeyNewer()` returns `true` instead of the time-step when the previous one is `null`, so the service always passes an int.
- A global `supports_credentials = true` broke `CorsConfigTest`, which pins ADR-078's "never for a custom domain". The fix scopes credentials per request instead.
- The dashboard cleared the profile cache on any `/api/me` error. That included a fetch aborted by navigation, and with the cache now shared across tabs it would have logged every tab out. Found in the browser walkthrough.
- Local admin dev must call `http://localhost:8000`, because a `*.test` Herd site is cross-site and the cookie is dropped (backend `AGENTS.md` gotchas 6–7).
- e2e moved to `SESSION_DRIVER=file`.

**Tests.**
- `AdminMfaLoginTest` (12) and `AdminMfaStepUpTest` (7), plus a CORS test for the admin-only credentials. Existing step-up-route tests act as a stepped-up admin via `TestCase::actingAsSteppedUp()`. Three bearer-token tests were replaced.
- Full backend suite 2780/2780; Pint clean.
- Admin `tsc`, `lint` and `build` clean.
- e2e 6/6, which covers three UI admin logins and the checkout spec's admin API login over a session.

**Live check (local, real browser).** Playwright against `php artisan serve` plus `next dev`, with a throwaway local admin that was deleted afterwards:
- A password-only login stored no token, and a new tab opened `/admin/users` without a login.
- A dangerous action without MFA was refused with 403 `mfa_not_enabled`.
- After "Require MFA" the account showed "Awaiting setup". Setup worked: a wrong code was refused, then 8 recovery codes were shown and the MFA column read "On".
- Editing an admin opened the step-up dialog, and the save went through after the code.
- A recovery-code login worked, and `admin:mfa-reset` cleared the account between runs.
- Screenshots were checked, and the login buttons now use the shared `Button`.

**Release notes.**
- Production needs **no `.env` change**: stateful domain and CORS come from `ADMIN_URL`, `SESSION_DRIVER=database` and `SESSION_SECURE_COOKIE=true` are already set, and the lifetime is fixed in config.
- On release, every admin signs in again. The founder then sets "Require MFA" on their own account, signs out and enrols. Until then, the five step-up actions are refused.

## 2026-10-14 — Release #437 (`staging`→`main`) verified live; own-password logout fix (#438); backup restore test found failing (item 97)

**Release.** #437 merged as `4fefc43` (#431–#436: items 10, 73, 95 plus docs). CI and the Forge deploy were green. Read-only checks on prod:
- The `add_admin_mfa_columns` migration ran, and 0 admin bearer tokens remain.
- Config is as designed: stateful `admin.pekangame.space`, session lifetime 2880, `secure`, host-only, CORS credentials for `https://admin.pekangame.space` only. The storefront origin gets none, and a login from outside the admin app gets 400.
- `/api/health/whatsapp` answers 200 with both sessions `ready`. No errors in `laravel.log` since the deploy.

**Founder follow-up.**
- The founder set "Require MFA" on their account and enrolled (prod: `mfa_status = active`, 8 recovery codes).
- They added the third UptimeRobot monitor on `/api/health/whatsapp` and test-alerted it.
- They switched local `admin/.env.local` to `http://localhost:8000`.
- They changed the admin email from `admin@pekangame.space`, a dead mailbox, to a live `@pekangame.com` address. All Plunk alerts (backup and security) go to every active admin, so until now nobody received them.

**Bug found live → #438.** Editing your own account (name only) asked for the step-up and saved, then the next request bounced to `/login`.
- Cause: the edit form's "New Password" field had no `autocomplete`, so the browser autofilled the saved login and the save re-set the password. Sanctum's `AuthenticateSession` then still held the old password hash for the session.
- Fix: the guard gets the updated model on your own password change, so your session stays (other sessions still end, ADR-121). The field is now `autocomplete="new-password"`.
- Red-first test `test_changing_your_own_password_keeps_your_session`; suite 2781/2781. Staging only until a release.

**Found while scanning the prod log → §16 item 97.** `backup_runs` #31–#44 (every night 2026-10-01 → 10-14) are all `failed`. The archive uploads, but the restore test logs "could not create throwaway restore-test database". The first failure follows ADR-117's switch to the `defaultdb`-scoped `pekangame_app` user, which is the likely cause but is not verified yet. The alert reached only the dead admin mailbox above.

## 2026-10-14 — §16 item 97 fixed in code: backup restore path, job timeouts, one FX rate per sync run; Pulse/Horizon health check

**Why.** Every nightly backup run from 2026-10-01 was `failed` (item 97). The founder also asked for a Pulse and Horizon health check, because the last one ran before the system grew.

**Investigation (prod, read-only).**
- `SHOW GRANTS` for `pekangame_app` covers only `defaultdb.*`. Runs #41–#44 all say `ERROR 1044 Access denied … to database 'defaultdb_restore_test_…'`.
- Runs #26–#30 (2026-09-26..30) are still `running`, with no `path`. `failed_jobs` holds 5 `TimeoutExceededException` for `RunDatabaseBackupJob` on those exact dates. Successful runs #18–#25 had grown from 38s to 50s against the generic 60s timeout.
- Two views are owned by `doadmin@%` (`llm_report_catalog`, `llm_report_membership_fees`); `llm_report_orders` is owned by `pekangame_app@%`.
- The last restore-verified backup is #25 (2026-09-24).

**Pulse / Horizon / Redis / DB health (prod, 7-day window).**
- **Horizon:** running, 9/9 supervisors; no failed job in 14 days.
- **Redis:** 2.7 MB of 256 MB, 0 evictions. ADR-077 says 512 MB; prod is 256 MB, harmless at this size.
- **Host:** 1.8 GB RAM available, disk 12%, load 0.3.
- **DB is healthy.** `SELECT 1` takes 1.2–5 ms. The 507 ms `affiliates` slow query runs in 2.6 ms in isolation. The 70 slow queries cluster on scheduler minute boundaries and deploy migrations: most likely first-query connection setup (TLS), not a slow DB.
- **Slow requests are deploy bursts, not customers.** 652 of 769 fell in four windows lining up with releases (#416 merged 13:44 → burst 13:55; #437 18:38 → 18:39–19:05; #438/#439 19:22 → 19:24–19:30).
- **Catalog cache hit rate (~42%) is by design:** the TTL is 60s (ADR-077/078) and traffic is low.
- **One real anomaly:** 495k cache hits a week on one key, `currency_rate:IDR:MYR`, which is 86% of `pulse_entries` (123 MB, the largest table in the DB). Cause: Price Sync read the cached FX rate once per item (1,521 per run, every 30 min). It also hid a small pricing gap (below).
- **Price sync:** max 54.5s against the 60s timeout.

**What shipped (branch `fix/backup-restore-and-job-timeouts`).**
- `BackupRestorer` is the one restore path, used by the nightly test and a new `php artisan app:restore-backup {--run=|--path=|--file=} --database=`.
  - It restores only into an existing, empty database.
  - It drops `DEFINER` clauses (lines starting `INSERT ` are left untouched).
  - The DB password goes through a `0600` defaults file, not argv.
  - Verification requires every live table except `exclude_tables` plus every known migration; it no longer scrapes `migrate:status` text.
- `RunDatabaseBackupJob`:
  - `failed()` marks the row failed and alerts.
  - Each run clears `restore-extract-*` folders a killed run left behind.
  - Migration `2026_10_14_100000_fail_stuck_backup_runs` closes rows #26–#30.
- `supervisor-backups` and `supervisor-price-sync` moved to `redis-long` with a 300s timeout.
- The backup schedule moved from 02:00 to 02:07.
- `CurrencyRateService::convertToSenAt()`: Price Sync prices every item with the one rate it records in `fx_rates_used` (ADR-111 addendum).
- Docs: ADR-039, ADR-117, ADR-111 and ADR-077 2026-10-14 addenda; PRD §15 Backups row and §16 items 97 and 98 (new: scheduler liveness alert).

**Proofs (red first).**
- **FX:** a mid-run cache change priced items 2–3 at 320 sen instead of 73. Green after the fix.
- **MySQL 8.4 (concurrency suite, new `BackupRestoreMysqlTest`):**
  - A non-super `kerox` user restoring a real `mysqldump` with a root-owned view failed with `ERROR 1227 … SET_ANY_DEFINER`. Green after the fix, with the prod-equivalent pattern grant.
  - The throwaway DB is dropped afterwards.
  - A table added after the dump is reported in `missing_tables`.
- **Sqlite:** a missing live table fails; an excluded table is not counted as missing; a non-empty target is refused; the restore command is tested end to end; `failed()` and the sweep are tested; the stuck-runs migration is idempotent.
- Fast suite 2792/2792.

**Gotchas hit.**
- Symfony Process fed from a generator writes one chunk per poll, so a real dump crawled. The cleaned dump is now written to the private work dir and streamed as a file.
- Laravel's console test harness mocks nested `Artisan::call` output, which is why `migrate:status` text-scraping was replaced.
- **The new check caught a real local fault:** spatie's sqlite dumper passes every table to one `.dump` dot-command, and `sqlite3` stops at about 50 arguments, so local sqlite archives held 50 of ~98 tables. The old check would have passed them. Sqlite and dev only (MySQL uses `--ignore-table`); left as is and recorded in ADR-039.
- Local: no `mysqldump` on PATH. Used DBngin's 8.0.33 client for the run. `BackupRestoreTesterTest` had left 2,280 dump files in `storage/app/backup-temp`; it now cleans up after itself.

**Not live.** Next steps: release, then the founder's grant (ADR-117 addendum; after the release, not before), then "Backup Now" and a check for `restore_test_passed = 1`.

## 2026-10-15 — Release #441 live; item 97 verification run failed on `partial_revokes`; fixed-name follow-up

**Release #441 (`staging`→`main`, merged by the founder) verified live:**
- `main` = `bd80f27` on the box.
- Horizon is running; `supervisor-backups` and `supervisor-price-sync` are on `redis-long`.
- `fail_stuck_backup_runs` ran, and #26–#30 are now `failed`.
- `app:restore-backup` exists, the schedule is 02:07, and `/api/health` returns 200.
- The latest Price Sync run succeeded with `fx_rates_used` 0.000228. There were 31 `currency_rate` cache reads in the last 30 minutes, against 1,521 per run before.

**Grant + verification run.**
- The founder ran the 2026-10-14 wildcard grant. `SHOW GRANTS` showed it.
- A manual run (#45, `triggered_by` "Claude (item 97 verify)") still failed: `1044 Access denied … 'defaultdb_restore_test_1792001735'`. Its failure alert went to the admins.
- Cause: the cluster has `@@global.partial_revokes = 1`, while local Docker and CI have `0`. With it on, `_`/`%` in a database GRANT are literal, so the grant matched nothing.
- The 2026-10-14 stress test missed this because the MySQL test ran under different server settings from prod.

**Follow-up (branch `fix/restore-test-fixed-db-name`).**
- The restore test always uses `<db>_restore_test`, dropping any leftover from a killed run first.
- `BackupRestoreMysqlTest` now runs with `partial_revokes = ON` and an exact-name grant. Red first: it reproduced the prod `1044`. A new case covers a leftover half-restored database.
- ADR-039 and ADR-117 2026-10-15 addenda (the replacement SQL revokes the useless literal grant).

**Left to do:** release, the founder's replacement grant, then "Backup Now" and a check for `restore_test_passed = 1`.

**Later 2026-10-15.**
- PR #442 is merged to `staging`, with CI green (the `backend-concurrency` job now runs under `partial_revokes = ON`).
- The founder ran the ADR-117 2026-10-15 SQL. Verified read-only: `pekangame_app` holds the exact-name grant on `defaultdb_restore_test`, and the wildcard grant is gone.
- The founder chose to release #442 together with the next backlog items, so release PR #443 was closed unmerged.
- Until that release, each nightly run still fails with `1044`, because the live code uses a timestamped name. Each run alerts the admins, and archives still upload.

## 2026-10-15 — Item 98: scheduler liveness (ADR-122), built on `feature/scheduler-liveness`

**Why.** Nothing noticed if scheduled work stopped. Prod runs `schedule:run` from one `/etc/crontab` line whose log is overwritten every minute. Five silent ways for scheduled work to stop: cron stops, `schedule:run` crashes at boot, a `withoutOverlapping` lock left by a killed process (1440-minute default, so a whole day), a task throwing every run, and seven `Schedule::call(fn () => Artisan::call(...))` tasks that swallowed a `FAILURE` exit code. Grilled in three rounds, then stress-tested against Laravel v13.21.1's scheduler source.

**What shipped.**
- `App\Console\TaskSchedule` is now the one schedule definition. `routes/console.php` calls it, and so does the liveness check: an HTTP request never loads `routes/console.php`, so its `Schedule` is empty. The tiers (`CRITICAL` / `ROUTINE`) live in the same class.
- The seven closures are now `Schedule::command`, and every task uses `withoutOverlapping(30)`. `horizon:snapshot` got a name.
- `scheduled_task_heartbeats` holds one row per task: `last_ok_at`, runtime, last failure and error. It is written by `RecordScheduledTaskHeartbeat` on `ScheduledTaskFinished` / `ScheduledTaskFailed`.
- `SchedulerLiveness` judges each task from its own cron expression and timezone:
  - Grace is 30 minutes, or 60 for a daily task.
  - A sub-daily task may miss one due run; a daily task may not.
  - A task with no row is seeded on first sight.
  - Rows for removed tasks are ignored.
- `/api/health` gained `checks.scheduler`: 503 when a critical task is stale. The response shows no task names. The admin Dashboard System Health panel gained a "Scheduled tasks" row listing stale tasks with their last OK time and last error.

**Tests.**
- `TaskScheduleTest` (invariant: unique name, one tier, 30-minute lock; the real scheduler registers the same set).
- `RecordScheduledTaskHeartbeatTest` covers: OK, a non-zero exit, an overlap skip not counted as OK, a failure keeping the last OK, and a write failure never escaping.
- `SchedulerLivenessTest` covers: seeding, sub-daily vs daily, the task's own timezone, the creation baseline, a removed task, critical vs routine.
- Health and Dashboard cases.
- Fast suite 2808/2808.
- One real local `schedule:run` at :05 wrote `horizon-snapshot` OK (0.3s).
- Admin `tsc`/lint/build are clean. Browser check in light and dark with a staged stale row: the chip and list render, and the error text measures 5.68:1 in dark.

**Gotchas hit (both from the stress test, both would have hidden real failures).**
- Laravel fires `ScheduledTaskFinished` for a task skipped by its overlap lock, with a `null` exit code. `null != 0` is false, so a naive listener records success for exactly the stuck-lock case. The listener checks `skippedBecauseOverlapping` and `exitCode === 0`.
- `ScheduledTaskFailed` is dispatched inside `ScheduleRunCommand`'s `catch`, so a listener throw would abort every other task due that minute. The listener catches and logs.
- The app timezone is `UTC` (hardcoded in `config/app.php`; prod's `APP_TIMEZONE` env is unused), so daily tasks run at 00:00 UTC (08:00 MYT). The liveness check uses the same default.

**Found on the way, not fixed (new §16 items).**
- **99:** Digiflazz `[83]` pricelist rate limit, 8 times since 2026-10-11, although the old box is gone.
- **100:** the admin's `dark:text-error-400` (145 uses) points to an undefined token, so dark-mode error text falls back to `error-600` at 1.83:1.

**Not live.** It ships in the next release bundle with #442.

## 2026-10-15 — Item 99: Digiflazz price-list rate limit was self-inflicted; own 30s timeout, no retry

**Found.** From production `supplier_request_logs`, 2026-10-11 to 2026-10-15, read-only:
- Digiflazz `/v1/price-list` takes 4.7s median, 8.5s p90.
- 42 of 249 calls hit the shared 10s timeout. Each was retried at once (a read timeout is a `ConnectionException`, so ADR-014 retries it), and 7 retries got `rc 83`, because Digiflazz had counted the timed-out request.
- Gamevion's price-list never timed out.

**Shipped (ADR-069 2026-10-15 addendum, branch `fix/digiflazz-price-list-timeout`).**
- `DigiflazzAdapter::listProducts()` uses `DIGIFLAZZ_PRICE_LIST_TIMEOUT_SECONDS` (default 30) and no retry.
- Transactions and `checkBalance` keep 10s plus the retry.
- `client()` gained `timeoutSeconds`/`retry` parameters. The provider passes the new config.

**Tests.** Red first:
- The price-list call gets 30s while `cek-saldo` gets 10s.
- A price-list connection failure is sent once.
- `checkBalance` still retries (4 sends).
- Fast suite 2811/2811.

No live call: a price-list from a local machine shares the production account's rate limit.

**Not live.** It ships with the next release bundle. Afterwards, watch the Digiflazz `listProducts` `exception` count and `rc 83` in `laravel.log`.

## 2026-10-15 — Item 100: admin status colours had no tokens; aliased to the ADR-104 inks, plus a CI guard

**Found.** In the item 98 browser check, dark-mode error text measured 1.83:1.
- `globals.css` defines `error/success/warning` 50, 500 and 600 only, so the ~190 `dark:text-*-400` classes produced no rule: Tailwind v4 silently drops a utility whose token is missing. Dark mode kept the light `-600`.
- A scan of every colour class found 22 more, in light mode, naming shades that don't exist: `text-error-700/800`, `text-success-700`, `text-warning-700`, `border-warning-300`. Those rendered in the inherited colour.

**Shipped (branch `fix/admin-status-color-tokens`).**
- `-400` aliases to the ADR-104 `*-ink` tokens, in `@theme static` next to the chart aliases (`.dark` sits on `<html>`, so each alias follows its ink's dark value).
- The 22 light classes now use existing shades.
- A dead `hover:text-dark-900` is removed.
- `color-tokens.test.ts` is the guard: red without the `globals.css` change (it listed the three `-400` tokens), green with it. The first version also flagged `primary-*`/`surface-*`; those come from `tailwindcss-primeui`, so the test reads that plugin's `theme/colors.css` instead of hardcoding exceptions.

**Verified.**
- Admin `npm test` 9/9, `tsc`, eslint and build are clean.
- Local browser with `getComputedStyle`:
  - Dark resolves error #f59c93, success #7fd6a0, warning #f0bd6a.
  - Light is unchanged (the 600s).
  - Dashboard "(12 failed)" and the Balance page's failed line measure 7.86:1 dark (was 1.83) and 8.96:1 light.

**Seen, not fixed (asked the founder).** Two headings with no colour class, the Dashboard `h1` and the login `h1`, show near-black on the dark canvas, because `body` sets `bg-canvas` but no text colour.

**Not live.** It ships with the next release bundle.

## 2026-10-15 — Item 100 follow-up: `body` gets `text-ink` (founder-approved)

**Why.** Text with no colour class inherited the browser's black, because `body` set `bg-canvas` but no text colour. On the dark canvas that was 1.28:1 (the Dashboard `h1`) and 1.20:1 (Recharts axis ticks on Reports).

**Shipped (branch `fix/admin-body-text-ink`).** `body` now applies `text-ink`, which flips with `.dark`.

**Verified with a before/after sweep.** Every static admin page plus `/login` (41 × light and dark = 82 views) was swept for text under 3:1 against its first opaque background.
- 158 items before, 140 after.
- Fixed: the Dashboard `h1`, the Reports axis ticks, and a Reports `-30` label.
- New: none. The one apparent "new" row was the Monthly Summary's own timestamp changing between runs.
- In light mode, unstyled text moves from #000 to ink #1b1a17.

**Seen, not fixed (pre-existing, outside this change).**
- Light-mode `gray-400` (#a8a192) secondary text and info icons measure 2.3–2.6:1 (around 70 instances, e.g. package unit suffixes, "i" tooltips).
- Dark-mode "Delete" buttons in `error-500` (#b42318) measure 2.5:1 (23 instances).

## 2026-10-15 — Release #449 live and verified; the new scheduler monitor caught a real stuck lock on day one

**Release #449 (`staging`→`main`, merged by the founder; items 97–100) verified on the box.**
- `current` = `30687ac`. `create_scheduled_task_heartbeats_table` ran.
- `/api/health` returns `{"database":true,"queue":true,"horizon":true,"scheduler":true}`.

**Item 97 closed.** Claude triggered "Backup Now" (`php artisan app:run-backup --triggered-by="Claude (release #449 verify)"`). Run #47: `success`, `restore_test_passed = 1`, 3.19 MB, 72s (under the 300s timeout). This is the first restore-verified backup since #25 (2026-09-24).

**Item 98: first real catch.** Within minutes, `wallet-topup-reconciliation` had no heartbeat while the other 15-minute tasks did. Read-only trace:
- At 06:44 UTC, unattended-upgrade installed `libsystemd` (among others), which restarted Redis (Redis uptime started 06:44:54). `laravel.log` shows `Predis ConnectionException`s at 06:45:03–06.
- The wallet task, starting at 06:45, took its `withoutOverlapping` lock but could not release it. The lock was the old code's 1440-minute one, with about 21h left, so the task would have been skipped silently every 15 minutes until about 06:45 the next day. No reconcile process was running (`ps`). No wallet top-up was pending, so there was no customer impact.
- With ADR-122 live, health would have gone 503 at about 10:15 UTC.
- **Remediation (prod write, run by the founder after Claude gave the exact command):** `Cache::forget('framework/schedule-5a0dc6393f179e512444bcf24d7381da6e3bd869')`.
  - Before: lock held, ttl 77,085s, heartbeat empty.
  - After: lock gone. The 09:30 run recorded `last_ok_at 09:30:06` (0.98s), and health stayed 200.
- New locks are 30 minutes (ADR-122), so this class now clears itself.

**Item 99:** too early to judge (the 09:00 price-list took 7.0s and succeeded). Re-check the `exception` count after a day.

## 2026-10-15 — Item 101: admin low-contrast leftovers fixed with design-system tokens; Dashboard redesign brief agreed

**Why.** The item-100 sweep left two groups under 3:1: light `text-gray-400` secondary text (2.3–2.6:1) and dark-mode `text-error-500` Delete links (2.5:1). The founder pointed out that the "PekanGame Admin" artifact already defines the right colours. So this is a token swap, not a mockup wait (ADR-104 2026-10-15 addendum).

**Shipped (branch `fix/admin-low-contrast-leftovers`).**
- New token `--color-ink-subtle`, using the artifact's values: light #716b62, dark #9a948a.
- All 135 base `text-gray-400` classes (66 files) now use `text-ink-subtle`.
  - Where a `dark:text-gray-500/600` partner sat next to it, that partner was dropped so the token's own dark value applies.
  - Two non-adjacent stale partners (InfoTooltip, TierCard) were removed by hand after the browser check caught the tooltip "i" at 3.96:1.
- The Assistant "Thinking…" bubble sits on `gray-800`, so it uses `ink-muted` instead.
- 5 base `text-error-500` classes now use `text-danger-ink`, which flips in dark. Two `dark:text-{error,success}-500` classes are now `-400` (same dark bug as item 100).
- `color-tokens.test.ts` now fails on a base `text-gray-400` / `text-error-500`. It was proven red by restoring two old files.

**Verified.**
- Admin `npm test` 10/10, `tsc`, eslint and build are clean.
- Local built admin + Playwright, 10 pages × light/dark, `getComputedStyle` on every changed element:
  - Minimum 4.8:1 light, 5.45:1 dark.
  - SEO FAQ Delete: 2.5 → 7.86:1 dark.
  - Screenshots of Affiliates in both themes look right.

**Dashboard check (read-only prod).** The founder suspected the KPI and Hourly data was broken.
- `DashboardService` outputs match raw `orders` rows exactly: summary 0 today; hourly 3 at 23:00 on 10-05 and 1 at 18:00 on 10-10; funnel 1/1/1; top game PUBG 1 order.
- Nothing is broken. The last order was 2026-10-10 10:00 UTC; payments have been off since 10-07.
- The redesign brief is recorded as ADR-104 2026-10-15 addendum and PRD §16 item 102.

**Not live.** It ships with the next release bundle.

## 2026-10-15 — Admin dead-code sweep (PR-0 of the Dashboard redesign, item 102)

**Why.** The founder asked for an inventory of unused TailAdmin-era leftovers before the Dashboard grill, so they could decide what to delete. This PR removes only things with zero references.

**Removed.**
- `zod` from `admin/package.json`. It had 0 imports in `admin/src`. It stays in the lockfile as `eslint-plugin-react-hooks`'s own dependency.
- The 5 create-next-app SVGs in `admin/public/` (`file`, `globe`, `next`, `vercel`, `window`), with 0 references.
- Unused `globals.css` tokens, all with 0 class uses and 0 `var()` uses:
  - `--color-brand-25`, `--color-blue-light-50/500`, `--color-gray-25`, `--color-gray-dark`
  - `--text-theme-xl`
  - `--breakpoint-xsm` and `--breakpoint-3xl`. This revises ADR-104 decision 2's "structural tokens untouched" for these two; `2xsm` is still used once and stays.

**Kept on purpose.** These TailAdmin-era classes are still live on the screens that haven't been reskinned yet: `gray-*` (~3,000 uses), `text-theme-*` (~1,000), `brand-*` (216), `rounded-2xl` (138), `bg-white/[0.03]` (144), `shadow-theme` (35), and the `menu-item` utilities in `PanelSidebar`. They go as each screen is reskinned, not by deletion. Dashboard-only components and endpoints are deleted in the Dashboard build PR itself.

**Verified.** Admin `npm test` 10/10, `tsc`, eslint and build are clean.

## 2026-10-15 — Dashboard redesign, backend PR (item 102)

**Why.** This is the backend half of the redesigned Dashboard (ADR-104 2026-10-15 grill-outcome addendum). The founder approved the mockup in the design-system artifact first: one Sales trend card, not a sparkline per KPI.

**Shipped (branch `feature/dashboard-backend`, test-first).**
- **`PeriodComparison::previousRange`, which Reports uses too.** A range still running is compared up to the same elapsed time: today to 10:30 vs yesterday to 10:30, and likewise 7/30 days and month to date. `previous_range` gains `until` (`"10:30"`, else null), and `to` names the clipped day. Reports' Compare figures for ranges ending today change accordingly.
- **`ReportService::busyHours()` on `/reports/busy-hours`.** Paid orders per KL hour of `paid_at`, 24 rows, zero-filled. It uses the same population as Sales (`scopedOrders`) and a new `hourBucketExpr` (sqlite + MySQL).
- **`ReportService::highlights()` on `/reports/highlights`.**
  - `best_day`, with `is_all_time_record`. It is null for a one-day range, and ties go to the earlier day.
  - `top_reseller`, from `resellerBreakdown()`.
  - `biggest_order`, using net sales like Sales.
  - Each fact is null without data.
- **`attention` inside `/dashboard/health`**, so the status strip and the health panel come from the same poll and the same numbers. Items are `{key, level, count, subject}`, plus `needs_action_count` (red and amber items only) and `last_paid_at`. Choices found while building:
  - `stuck_orders` in health already includes NeedsReview, so the attention `stuck_orders` item counts only stale processing/pending orders. `needs_review` is its own item, and no order is counted twice.
  - A null OpenWA session only means no event in the last hour, so it is never flagged. Only an explicit non-connected status is.
  - "Withdrawals to process" counts Pending and Approved: an approved one still waits for the admin's transfer.
  - "Payments off" is neutral, and only when at least one method exists and none is active.
  - "Backup failed" means the latest finished run failed.
- The old `/dashboard/summary`, `/funnel`, `/top-games` and `/hourly-activity` stay until the frontend PR replaces their caller.

**Verified.**
- Full backend suite: 2,835 passed.
- The 71 Report/Dashboard tests also pass on real MySQL, which exercises `HOUR(CONVERT_TZ())` and the aliased `ORDER BY`.

**Not live.** It ships with the frontend PR in the next release.

## 2026-10-15 — Dashboard redesign, frontend PR (item 102)

**Why.** This is the frontend half of the Dashboard redesign. It follows the mockup the founder approved in the design-system artifact (ADR-104 2026-10-15 addenda).

**Shipped (branch `feature/dashboard-frontend`).**
- **`/admin` rebuilt as a daily briefing.**
  - The header subtitle is the summary sentence. It is built from data in `lib/dashboard-briefing.ts` and tested.
  - The period switch offers Today, 7 days, 30 days and Custom, and always opens on Today.
  - The status strip and its System details panel are both read from `/dashboard/health`, polled every 60s.
  - KPIs (Sales, Owner profit, Orders, Margin) show compare labels like "vs 14 Oct, to 8:48 PM".
  - Cards: Sales trend (`TrendChart`), Channels, Top games (with change), Busy hours (peak and quietest 3-hour windows) and Highlights.
  - Every business figure comes from `/reports/*`.
- **Shared with Reports.**
  - A **Today** preset in `date-range.ts`, which Reports and Orders also get.
  - `previousLabelOf` shows the backend's `until` ("…, to 10:30 AM").
  - `formatShortRange` prints one date for a one-day range.
- **Reviews** opens on `?status=pending`, the Dashboard's "reviews pending" link.
- **Backend.** `/reports/top-games?compare=` (`ReportService::gameComparison`, tested).
- **Removed.**
  - The old `/dashboard/summary|funnel|top-games|hourly-activity` endpoints, service methods and tests.
  - `DashboardKpiCard`, `StatCard`, `ComparisonBadge`, `ConversionFunnelChart` and `HourlyActivityChart`.
  - `lib/dashboard.ts`'s dead types and fetchers.

**Verified.**
- Admin: `tsc`, eslint and build are clean, and `npm test` passes 14/14.
- Backend: the full suite passes 2,811 (down from 2,835 because of the removed old-endpoint tests).
- In a live browser (built admin + local API, light, dark and 390px):
  - Today empty: the sentence names the last order, empty cards explain themselves, and Highlights is hidden.
  - A Custom range with data matches the mockup's layout.
  - Every text element is ≥ 4.5:1, with no horizontal scroll.

**Not live.** It ships with the next `staging`→`main` release.

## 2026-10-15 — Docs sync for the session (items 100–103)

Swept the docs this session touched for claims it made untrue (docs only).
- **PRD §14:** "No code is waiting on `staging`" was untrue. It now names #450–#455 (item 101, the dead-code sweep, the Dashboard redesign) and the Reports Compare change they carry.
- **PRD §15:** the Reports row records the same-time Compare, the Today preset and the three new `/reports/*` endpoints as on `staging`.
- **PRD §16:**
  - Item 100 closed (released and verified in #449).
  - Items 101 and 102 moved to a new "On `staging`, waiting for release" group.
  - Item 103 moved to "Buildable now" as the next session's first item.
- **ADRs:**
  - ADR-045's Standing records that ADR-104's 2026-10-15 addenda replace DASH-1, 3, 4 and 5. DASH-2 health stays.
  - ADR-045 gets a 2026-10-15 addendum saying the same.
  - ADR-104's banner and index row name the revision.

## 2026-10-15 — Customer Analytics reads that scale (item 103)

**Why.** Audit of item 103: every Customer Analytics request loaded every
customer (and, for names, every order of them, any payment status) into
PHP; the list was unpaginated and rendered in full; the detail page loaded
all of a customer's orders. Synthetic benchmark on local MySQL 8.4 at 10k
orders / ~2.7k customers: 0.4–0.5 s and 46 MB per call, against 63–130 ms
for the Reports aggregates. Also found: two copies of net sales (SQL list,
PHP detail), a segment that changed with the Affiliate/Reseller filter, and
`MAX(wallet_reseller_id)` labelling `faris9745@` (4 wallet + 3 Affiliate
orders, prod) "Reseller" for all 7.

**What.** Grilled in four rounds, stress-tested, ADR-049/050 2026-10-15
addendum. Backend PR: one per-customer SQL aggregate for stats, list,
export and detail stats; segment as a SQL `CASE` with cut-offs bound from
PHP (same SQL on sqlite and MySQL; Carbon 3's fractional `diffInDays()`
makes `< now − 60 days` exact); segment, repeat rate and Source from every
paid order whatever the filters; paged/sorted/searched list; export via
`cursor()`; paged detail order history; `CustomerAnalyticsFilterRequest`
(bad value = 422); Source `Reseller: A + Retail`; `from`/`to` dates, and
`ReportService::dateRangeForYearMonth()` removed (last caller);
`MalaysiaTime::dateSql()` now holds the day-bucket SQL Reports had.
Profit Analysis keeps the per-order `Order` methods (the only copy of the
ADR-094/105/111 cost rules), read in chunks. Frontend PR: paginator, sort,
debounced email search, Reports date presets, Source tag, KL dates, and a
filter change aborts the request it replaces.

**Verified.** Parity test pins the old implementation's figures for a fixed
data set (every segment boundary, a wallet refund, a mixed email, excluded
orders). Old vs new on the whole local dev DB (183 orders, 40 customers):
0 differences in list, range list, stats, row order and every detail page.
Fast suite 2828/2828; the 51 Customer Analytics tests also on MySQL; new
`tests/Concurrency/CustomerAnalyticsMysqlTest` proves letter-case variants
are one customer on MySQL. Benchmark at 10k orders, old → new: stats
409 → 137 ms, customers 502 → 124 ms, month filter 455–480 → 84–89 ms,
46 MB → 0.1 MB, list JSON 620 KB → 8 KB. Browser (local, light/dark/phone):
40 customers over 2 pages, one request per typed search, stat cards not
refetched on search, no page-level horizontal scroll, no API errors.

**Gotchas.**
- `addcslashes()` escapes with a backslash, not the `!` the `LIKE … ESCAPE '!'`
  expects; `strtr()` does it. sqlite has no default LIKE escape, hence `!`.
- The first local MySQL run errored all 112 tests from the first one on:
  `llm_report_*` views left in docker `kerox` by an earlier run (backend/AGENTS.md
  gotcha 8). Dropping them fixed it; the new `DatabaseMigrations` test was
  checked afterwards and leaves no view behind.
- Admin lint rejects `setState` inside an effect: loading is derived from
  which request an answer belongs to, and page reset happens in handlers.
- Left as is, with a `ponytail:` note: Profit Analysis costs one query per
  realised order, from `Order::cashCompensationSen()` (shared with
  OrderController and Accounting). The "All time" list is 124–137 ms at 10k,
  just over the 100 ms aim, from two scans per call (count + page, totals +
  top spender) and `netSalesSql()`'s correlated subquery.

## 2026-10-15 — Docs sync for the item 103 session (docs only)

- PRD §16 gained item 104 (reseller wallet top-up KPIs, group D with item 28;
  not part of ADR-115, which forecasts supplier drain, #457) and item 105
  (Profit Analysis one query per order via `Order::cashCompensationSen()`,
  Pulse trigger, #460).
- PRD §14: the staging list now runs #450–#460 and names item 103's API change.
- ADR-088 decision 4 ("`dateRangeForYearMonth()` is kept for Customer
  Analytics") retired by ADR-049's 2026-10-15 addendum: dated addendum added,
  index date updated.

## 2026-10-15 — Release #462 (`staging`→`main`): items 101–103, verified

Founder merged #462 (`9b5ff74`, #450–#461). CI deploy green; the prod box's
`current` is release 80314022 at `9b5ff74`; `/api/health` 200.

Checks (read-only tinker over SSH, and the founder's logged-in Chrome):
- Item 103: 3 customers; list total 22070 sen equals Reports' `total_sales`,
  4 + 21 + 7 = 32 orders equals Reports; `faris9745@` shows
  `Reseller: FixFast + Retail`; search `FA`, the October filter and the
  `faris9745@` detail page (7 orders, Top Sources FixFast and Ohahastore) work.
  The admin page renders it, names from paid orders included.
- Item 102: Last 30 days on the Dashboard (RM210.10, 27 orders) equals
  `/api/reports/summary` for 16 Sept–15 Oct (21010 sen, 27). Dark and narrow
  (606 px, Chrome's minimum) render with no horizontal scroll.
- Item 101: `getComputedStyle` contrast on prod: `text-ink-subtle` 4.80:1
  light / 5.45:1 dark, `text-ink-muted` 7.86 / 8.82, `text-danger-ink`
  5.70 / 8.42.
- The Dashboard's "7 failed jobs" are old rows, not this release: reseller-bot
  OpenWA 401s on 2026-09-25 (box migration) and backup timeouts 26–30 Sept
  (fixed by item 97). Clearing them is a prod write, left to the founder.

Items 101, 102 and 103 closed (PRD §16).

## 2026-10-16 — Items 98 and 99 closed after a day of prod data (docs only)

Read-only checks over SSH (tinker, `laravel.log`, `/api/health`) at
05:05 UTC, about 20 hours after #449 went live (release 80273185,
2026-10-15 09:08 UTC).

- **Item 98 (ADR-122).** `scheduled_task_heartbeats` holds 19 rows, one per
  task in `TaskSchedule` (11 `CRITICAL`, 8 `ROUTINE`). Every row has a
  `last_ok_at` on 2026-10-16 and `last_failed_at` null. The daily tasks ran at
  00:00, then `membership-renewal-reminders` at 02:00, `database-backup` at
  02:07 (its `BackupRun` is `success`) and `database-backup-cleanup` at 03:00.
  `/api/health` returns 200 with `checks.scheduler: true`. Longest runtime
  12.29 s (`database-backup-cleanup`), so the 60-minute daily grace has a lot
  of room; revisit it after a week of runtimes, as ADR-122 says.
- **Item 99 (ADR-069 2026-10-15 addendum).** Digiflazz `supplier_request_logs`,
  before vs after the deploy: `listProducts` 212 success / 42 exception (max
  10,008 ms, the old 10 s timeout) from 2026-10-11, against 40 success / 0
  exception (max 19,530 ms) since. That 19.5 s call would have timed out and
  been retried under the old setting. `laravel.log` has 7 rc 83 lines, the last
  at 2026-10-15 03:00:23, before the deploy. `PriceSyncRun`: 48 a day, every
  one `success`.

Seen during the check, not part of either item:
- Gamevion's `listProducts` timed out twice (2026-10-16 02:00 and 02:30 UTC,
  `cURL error 28` at 10 s, 0 bytes received). The next run succeeded. Gamevion
  still uses the shared 10 s timeout. Its `PriceSyncRun`s were still recorded
  `success`, because one supplier failing doesn't fail the run, so the failure
  shows only in `laravel.log`.
- A Redis connection blip on 2026-10-15 06:45 (about 30 Predis errors over
  5 s, on the release before #449). It recovered by itself, and nothing
  followed it.

Items 98 and 99 closed (PRD §16).
