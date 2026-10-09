# PekanGame — Build Log

> The running chronological record of what shipped, when, and why — every
> session, the bugs hit along the way, and the live-verification notes.
> Moved out of `prd.md` §14 on 2026-09-11 once it outgrew the spec doc.
>
> **This file is append-only history.** Current per-feature status lives in
> `prd.md` §15; the live backlog is `prd.md` §16; decision rationale is the
> ADR log (`adr.md` index → `adr/`). **Everything before 2026-10-06** lives verbatim in
> [`build-log-archive.md`](./build-log-archive.md): the foundation build, the
> old §14 table and PrimeReact tracker, and the production-era entries
> 2026-09-01 → 10-05 (moved in four passes, the last on 2026-10-09) to keep
> this file quick to read at session start.

---

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
  - That sweep grepped `Order::query()->create` only and missed
    `E2ESeeder`'s three `firstOrCreate` fixtures. CI's Playwright backend
    boot failed with a bare "webServer was not able to start". Grep every
    create form (`firstOrCreate`, `updateOrCreate`, `insert`) when a
    column becomes required.
  - A killed MySQL test run leaves the `llm_report_*` views behind in the
    docker `kerox` DB. `RefreshDatabase`'s `migrate:fresh` doesn't drop
    views, so every later run loops on `CREATE VIEW`. Fix: drop the three
    views (the founder ran it), then re-run: 122/122 report, accounting and
    dashboard tests green on MySQL.
- **Gap found, not fixed (0 on prod):** `VoucherService::merge()` gives the
  merged voucher `order_id = null`. A merge of compensation vouchers
  therefore drops out of outstanding store credit (and out of the Dashboard
  and Register counts, which predate this). Prod has 0 merges.

## 2026-10-08 — Reports redesign PR-B: frontend foundation (item 65, ADR-104 R1, R3, R5, R12–R13, R17–R20)

Frontend only, on `feature/2026-10-08-reports-pr-b`. The 7-tab layout is
PR-C; the current 8 tabs keep working on the new pieces.

- **R1 Recharts 3.10.1.** `TrendChart` takes stacked panels over one date
  axis, each with its own y-scale (mockup: revenue bars over owner-profit
  bars, so profit at ~3–10% of revenue isn't flattened; margin as a dotted
  line with point labels). `OrderStatusFunnelChart` follows the mockup's
  share bar + badge rows in plain HTML: no axes or scales to need Recharts. Chart tokens
  (`chart-1/2/3`, `chart-grid`, `chart-positive`, `chart-negative`) are
  aliases of the artifact's base tokens; `chart-negative` was a fixed hex,
  now `danger-ink` per the artifact's `tokens.json`. Profit charts use
  `chart-2` (owner) / `chart-3` (affiliate); the funnel uses the status
  tokens.
- **R3/R17 bucketing** in `lib/report-buckets.ts` (Monday weeks, KL months,
  Σ profit ÷ Σ sales, the All-time auto bucket). Admin had no unit-test
  runner: `npm test` runs `node --test` with type stripping, added to the
  admin CI job.
- **Shared pieces** (`components/reports/ReportKit.tsx`): `ReportCard`,
  `KpiCard` (Compare delta, pts for margin, "— No data" for an empty
  previous period, clickable), `ReportTable` (Total row, 31 rows a page),
  `GameIcon` (2-letter fallback). `HorizontalBarList` gained a Total line.
- **Header (R5/R12), in the mockup's shape:** "Updated X min ago" + icon
  Refresh, Ask assistant, Export menu; filter row with date range + its
  dates, inline-labelled Affiliate select, dashed "+ Compare period"
  (disabled with a reason on All time; "This month" sends
  `month_to_date`), and "Paid orders only · Malaysia time (GMT+8)".
  Compare is wired end to end on Overview's KPIs; other tabs pick it up in
  PR-C. Overview's trend card has the Revenue & profit / Orders and
  Daily / Weekly / Monthly toggles; Profit's charts share one bucket.
- **Backend (small):** the trend endpoint returns `orders_count` per day
  (Orders toggle) and `latest_order` carries `id` (the "Open →" link to
  `/admin/orders?order={id}`), test-first in `ReportServiceTest`.
- **Mockup comparison.** The first cut was checked for working colours and
  data only, not against the artifact's mockups; a side-by-side found a
  single-axis line chart (repeating review defect #06, profit flattened),
  margin points invisible between empty days, and funnel/header styling
  off. All fixed before merge, then re-screenshotted against the mockups.
  Overview and Profit tabs also ignore a slower stale response after a
  filter change (it could overwrite the newer one).
- **R19** `paymentMethodLabel()` in `lib/payment-methods.ts`, used by
  Reports and Order Detail/list. **R20** every Reports bar list shows sen.
- **Verified** in a local production build against the dev DB (Playwright,
  light + dark): line strokes compute to the token hex in both themes
  (light `#0b7285`/`#6b4fcf`, dark `#4cc3d4`/`#a592f0`), funnel counts match
  the DB (167 delivered / 16 failed), no console errors, no horizontal
  scroll at 390px.
- **Gotchas.**
  - Tailwind v4 drops `@theme` variables no class uses. `chart-2`/`chart-3`
    are only read as `var(--color-chart-*)` from Recharts props, so the
    owner-profit line rendered invisible. The chart tokens live in an
    `@theme static` block.
  - Recharts 3's `LabelList` index counts only drawn bars, so zero-count
    statuses shifted every label. The funnel puts its counts on a second,
    right-hand category axis instead.
  - The dataviz validator fails the brand palette on chroma (muted hues);
    CVD and normal-vision separation and contrast pass. Kept as the
    artifact defines it; every chart has a legend, end labels and a table
    view.
- **Not in this PR:** the old tabs' dark-mode
  `text-success-600` / `dark:text-success-400` profit cells are faint
  (`success-400` isn't defined) and go away with the PR-C reskin.

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
