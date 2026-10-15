# PekanGame — Build Log

> The running chronological record of what shipped, when, and why — every
> session, the bugs hit along the way, and the live-verification notes.
> Moved out of `prd.md` §14 on 2026-09-11 once it outgrew the spec doc.
>
> **This file is append-only history.** Current per-feature status lives in
> `prd.md` §15; the live backlog is `prd.md` §16; decision rationale is the
> ADR log (`adr.md` index → `adr/`). **Everything before 2026-10-11** lives verbatim in
> [`build-log-archive.md`](./build-log-archive.md): the foundation build, the
> old §14 table and PrimeReact tracker, and the production-era entries
> 2026-09-01 → 10-10 (moved in six passes, the last on 2026-10-13) to keep
> this file quick to read at session start.

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
