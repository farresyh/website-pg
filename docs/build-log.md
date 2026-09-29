# PekanGame — Build Log

> The running chronological record of what shipped, when, and why — every
> session, the bugs hit along the way, and the live-verification notes.
> Moved out of `prd.md` §14 on 2026-09-11 once it outgrew the spec doc.
>
> **This file is append-only history.** Current per-feature status lives in
> `prd.md` §15; the live backlog is `prd.md` §16; decision rationale is
> `adr.md`. **Everything before 2026-09-23** (the old §14 file-level table,
> the PrimeReact migration tracker, and the 2026-09-01 → 09-22 production-era
> entries) lives verbatim in [`build-log-archive.md`](./build-log-archive.md),
> moved there 2026-09-29 to keep this file quick to read at session start.

---

## 2026-09-23 — Partner invite, compensation visibility, Track Order throttle, docs-site audit (fix branch; not deployed)

- Cleaned up the already-merged local `feature/html-email-templates` branch, fast-forwarded local `staging` to `origin/staging` at `cba7e42`, and cut `fix/partner-portal-refund-status-and-tracking`. No production release is implied by this entry.
- **Portal invite/session:** after successful one-use set-password, close any previous partner session and clear the presence cookie before sending the invitee to manual login. A failed cleanup can be retried without resubmitting the consumed invite token. A cookie without a matching client session now renders neither the Affiliate nav nor protected page content while redirecting to login. See ADR-058/059 addenda.
- **Compensation visibility:** a Reseller wallet credit is shown separately from `payment_status=paid` and `delivery_status=failed`, using the actual `wallet_refund` ledger entry for amount/time. Reseller portal list/detail/dashboard and every Reseller API order shape (list, GET, placement/replay, webhook) share that fact; list endpoints batch-read ledger entries. Affiliate portal orders likewise distinguish a compensation voucher or restored voucher from delivery status. The public API guides no longer promise an automatic refund; generated OpenAPI/docs revision is 1.1.0 while the API URL stays `/v1`. See ADR-059/084 addenda.
- **Track Order:** first-minute polling changed from 2s (up to 30/min) to 4s against the unchanged 20/min backend limit. Residual 429s from shared-IP/multi-tab traffic use the exposed `Retry-After` header for automatic recovery without a page refresh. See ADR-071 addendum and PRD §16 item 20.
- **Docs-site dependency advisory:** the three high findings share one transitive `form-data` chain through `httpsnippet`/`starlight-openapi`; a scoped override to patched `form-data@4.0.6` avoids the breaking `npm audit fix --force` path. `npm ci`, `npm audit --audit-level=high` (zero vulnerabilities), `npm run check`, and the 18-page static build pass. See ADR-084 addendum and PRD §16 item 22.
- Verification so far: focused backend tests 37/37; the full backend suite 2233/2233, 5967 assertions (run outside the network-restricted sandbox after three unrelated supplier/FX tests could not reach external services inside it); `reseller/` and `storefront/` typecheck/lint and production Webpack builds pass; `docs-site/` check/build pass. Track Order tests pass, targeted Pint passes, and `git diff --check` is clean. Local Playwright E2E could not start because the founder's existing server already occupies `localhost:3000`; no process was stopped. Real browser/Preview smoke tests remain before merge; there is no claim that these fixes are live.

## 2026-09-23 — ADR-112 PR 1: partner portal shell (PR #275 merged to staging)

- Cut `feature/adr-112-portal-shell` from `origin/staging` after PR #274 merged. Implemented the frontend-only shell slice: role-aware mobile header, Dashboard/Orders/Wallet-or-Earnings/More bottom navigation, remaining role-specific destinations and account actions in a More drawer, matching desktop sidebar, and safe-area-aware content spacing. The drawer now closes on navigation/Escape, restores focus when dismissed, and uses the `lg` breakpoint consistently with the layout; nested order-detail routes highlight Orders.
- Kept all existing backend, auth, API, wallet, top-up, and order-status behavior unchanged. Dashboard and Orders presentation landed in PR2; there is no claim that this staging build is live.
- `reseller/` TypeScript and ESLint pass; production `next build --webpack` passes. Default Turbopack build failed before compilation because its CSS worker could not bind a local port in this environment, including on a retried elevated run. Role-by-role browser verification was completed with PR2 and CI was the merge gate.

## 2026-09-24 — ADR-112 PR 2: dashboards and responsive Orders (PR #276 merged to staging)

- Cut `feature/adr-112-portal-dashboards-orders` from `origin/staging` after PR #275 merged. Added bounded, honest recent-order panels to Affiliate and Reseller dashboards; retained the existing metric meanings and role-specific task hierarchy.
- Orders keeps the desktop table and now renders tappable mobile cards with separate Payment, Delivery, and compensation facts. Affiliate search remains server-backed; Reseller does not get a misleading current-page search. No backend, ledger, delivery, payment, API, or top-up contract changed.
- New filter/input/button/status presentation primitives use the portal's PrimeReact setup, matching the admin panel convention; the role-aware shell and navigation remain the existing portal-specific layout layer.
- Browser-verified against isolated SQLite fixtures for both roles at 390px and 776px, plus desktop table rendering; no horizontal overflow, explicit empty/loading states, clickable order links, and the mobile bottom navigation visible. `reseller/` typecheck, lint, Webpack production build, and `git diff --check` pass. PR #276 merged this slice to `staging`; production is not claimed live.

## 2026-09-24 — Release: `staging` → `main` (PR #278), Gamevion launch gate retired

- **Pre-merge audit + verify session (separate from the build sessions themselves):** confirmed PRs #273–#277 (partner-portal fixes + ADR-112) were built without this assistant (no `Co-Authored-By: Claude Sonnet 5` trailer on any of their commits), then audited them against this repo's own SOP — branch-off-`staging`/PR-into-`staging` discipline, `docs/adr.md`/`docs/prd.md`/`docs/build-log.md` updates, test coverage for the money-adjacent wallet-refund/voucher-compensation exposure (batch-query N+1 guard explicitly asserted in a test), no client-trusted money, no migrations. No gaps found.
- **ADR-112 browser-verified live** (not just read from docs): the Vercel Preview URL for the reseller app returned "Could not load orders" — traced to the preview subdomain simply not being in production's `cors.allowed_origins` (browser-side CORS block, not a real API failure; confirmed via `fetch()` in-page throwing `Failed to fetch`, and via curl showing the endpoint itself answers normally for allowed origins). Verified instead against `localhost:3002` + local backend: both Affiliate and Reseller roles, at 390/768/1440px — role-aware shell, dashboards, mobile Orders cards with separate Payment/Delivery/Compensation facts (wallet-refund amount+time; voucher-issued with no code leak), all correct. Found and fixed 2 stale **local dev DB fixtures** in the process (an `AffiliateUser.owner_id` pointing at a deleted `Reseller` row, and no Affiliate-role portal login existing locally) — local-only, not a code bug.
- **CI:** PR #278's `playwright` job flaked twice on unrelated causes (an isolated login timeout, then a Turbopack Google-Fonts module-resolution crash during `admin`'s `next dev` boot) before passing clean on a full workflow rerun. Not a regression from this release — PR #270 (the `next@16.3.5` bump) and PR #276 (staging's own tip, identical code) both passed `playwright` cleanly on their own branch CI runs beforehand.
- **Merged and deployed 2026-09-24, founder-confirmed live:** `/track-order` throttle fix and the ADR-112 portal redesign both spot-checked in production by the founder — §16 items 20/22/23 closed.
- **Gamevion launch gate retired, founder decision 2026-09-24:** the platform runs Digiflazz-only for the foreseeable future rather than fund a second supplier; Gamevion (billed in **MYR**, not IDR/Rp) stays integrated but deliberately unfunded. Not a gap to chase — see `docs/prd.md` §14/§16. (Note: this assistant had mis-stated Gamevion's currency as "Rp" in conversation and in a stale memory note before the founder caught it — corrected here and in memory.)
- **CHIP credential migration (ADR-110 PR-C) confirmed fully settled:** founder has filled in the real production credentials via `/middleware/payment-gateways` and confirmed the connection probe passes. Keeping the `.env` `CHIP_SECRET_KEY`/`CHIP_BRAND_ID` vars on Forge as a fallback is a deliberate choice, not an owed cleanup — removed from the backlog's "founder-owed" framing.

## 2026-09-24 — ADR-100 addendum: drop the flap-count gate on Pending Reactivation auto-approve (fix branch; not yet deployed)

- Founder-requested audit of a recurring "70-100 packages pending" complaint. Cut `fix/pending-reactivation-drop-flap-gate` from `staging`. SSH'd into prod to pull real data: 599 deactivations / 307 auto-approvals in the trailing 7 days; 81% of the 162 currently `supplier_sync`-deactivated packages have an ambiguous `cutoff_start === cutoff_end` (mostly literal `"0:0"`), pushing them onto the stability gate instead of the immediate cutoff trigger. Direct proof of the bug: all 11 packages the live queue showed as supplier-confirmed-active (Valorant Singapore VP, package IDs 943-966) had a 19-sync (~19h) confirmed-active streak but were still skipped every cycle because their `deactivation_logs` flap count (4) sat over the old `reactivation_flap_limit_per_14_days` (2) — founder independently confirmed via Digiflazz's own dashboard these packages were genuinely online.
- **Fix:** `PendingReactivationAutoApprover::stabilityConfirmed()` no longer queries `deactivation_logs` or checks a flap limit — streak length (`consecutive_active_syncs >= reactivation_stability_syncs`) is now the sole non-cutoff signal, since a stale confirmation can't build a streak (it resets to 0 the instant a row goes inactive). `reactivation_stability_syncs` default raised 2 → 3 to keep a real safety margin now that the flap-count layer is gone; founder chose 3 (~3 hours) from a menu of 3/6/12. `reactivation_flap_limit_per_14_days`/`reactivation_flap_window_days` config removed as dead. See ADR-100's 2026-09-24 addendum for the full evidence/rationale.
- Replaced the 2 flap-limit-specific tests with a regression test reproducing the exact live bug (long prior flap history no longer blocks approval once the streak is met). Full backend suite green: 2232/2232, 5965 assertions.
- **Founder-owed, deliberate deploy-adjacent step (not done as part of this fix):** prod `.env`'s `REACTIVATION_STABILITY_SYNCS=1` needs updating to `3` after this ships. The now-dead `REACTIVATION_FLAP_LIMIT_PER_14_DAYS`/`REACTIVATION_FLAP_WINDOW_DAYS` prod `.env` vars are harmless to leave, safe to remove whenever `.env` is next touched.
- The separate cutoff-ambiguity issue (81% of pending hitting the `"0:0"` fallback) is untouched by this fix — still open, not part of this session's scope.

## 2026-09-24 — ADR-094 addendum: combo reactivation cascade (fix branch; not yet deployed)

- Continuation of the same session's Pending Reactivation audit, PRD §16 item 21. Cut `feature/adr-094-combo-reactivation-cascade` from `staging` after the ADR-100 flap-gate fix (PR #280) merged. Confirmed the two-part gap by reading the actual code: `ComboPricingService::cascadeDeactivate()` sets a dependent combo's `deactivated_reason='combo_component_deactivated'`, but `PendingReactivationFinder` only ever queries `deactivated_reason='supplier_sync'` (no visibility), and neither `PendingReactivationController` nor `PendingReactivationAutoApprover` had any reverse-cascade logic (no action).
- **Design choice put to the founder directly** (auto-cascade vs. manual-but-visible): founder chose auto-cascade, consistent with the same session's ADR-100 preference for less manual toil once a signal is trustworthy.
- **Fix:** new `ComboPricingService::cascadeReactivate(Package $component, ?int $priceSyncRunId, ?int $adminUserId)` — for every combo still `combo_component_deactivated` referencing this component, reactivates it once **every** one of its components is active (not just the one that changed; a combo needs every leg to fulfill). Wired into every site a component's `is_active` can flip false→true: `PendingReactivationController::approve()`/`bulkApprove()`, `DismissedPackageController::restore()`, `PendingPriceChangeController::approve()`/`dismiss()`, `PackageController::updateStatus()`, and `PendingReactivationAutoApprover::approve()` — symmetric with `cascadeDeactivate()`'s own automated+manual call sites.
- **New `package_reactivation_logs.admin_user_id`** (nullable FK, migration `2026_09_24_051204`) — mirrors `deactivation_logs`' existing two-provenance shape (null for the auto-approver, set for a manual approve/dismiss/restore). Every cascade reactivation logs `trigger='combo_components_all_active'`.
- **Found and flagged, not fixed:** `SupplierController::updatePackagesStatus()`'s bulk supplier on/off toggle uses a raw mass `Package::query()->update()` and has never called `cascadeDeactivate()` either — a pre-existing, separate gap from before this session, out of scope here.
- Replaced 1 test asserting the old decision-22 behavior (`ComboPackageControllerTest::test_reactivating_a_component_does_not_auto_reactivate_a_dependent_combo`) with one asserting the new cascade. Added 3 `ComboPricingServiceTest` cases (single-component cascade, multi-component "waits for every leg," ignores non-cascade-deactivated combos) and 1 HTTP-level `PendingReactivationControllerTest` case. Full backend suite green: 2236/2236, 5988 assertions. Pint clean on every touched file.
- See ADR-094's 2026-09-24 addendum for full rationale; PRD §16 item 21 marked fixed (staging, not yet released to `main`).

## 2026-09-24 — ADR-046 addendum: supplier bulk toggle now cascades onto combos (fix branch; not yet deployed)

- Follow-up on the gap flagged (not fixed) in the same session's ADR-094 combo-reactivation-cascade PR: `SupplierController::updatePackagesStatus()`'s bulk "Deactivate All"/"Deactivate by Game"/"Reactivate" action does a raw mass `Package::query()->update()`, never the per-component `cascadeDeactivate()`/`cascadeReactivate()` every other deactivation/reactivation path already calls.
- **Checked scope before fixing** — worth-fix-vs-grill question raised directly, not decided unilaterally. Real usage: twice ever (both against Gamevion, which has zero combos — all 49 system-wide combos are Digiflazz-only). Zero currently-broken combos live. But Digiflazz has 1,088 active packages today, so a naive per-package loop calling the existing single-component cascade methods would turn 2 bulk SQL statements into 1,000+ synchronous queries in one admin request — a real timeout risk, not a safe drop-in. Decided: fix now (real money-adjacent correctness gap), skip a full grilling session (the one open call — silent cascade vs. an acknowledge-prompt — is already answered by decision 13's own existing precedent), founder agreed.
- **Fix:** new `ComboPricingService::cascadeDeactivateForComponents()`/`cascadeReactivateForComponents()`, batch-shaped — find affected combos with ONE query against `package_components`, then bulk-write only that small candidate set (bounded by total combo count, 49 today, never by the component count). Wired into both branches of `updatePackagesStatus()`, both now inside a `DB::transaction()` (only the deactivate branch was before). Response gains `updated_combos`.
- **Found and fixed a real bug before merge, not shipped**: the first cut used `whereHas('components', fn ($q) => $q->whereIn('packages.id', $ids))` — for this self-referencing `belongsToMany`, Laravel aliases the inner joined table to disambiguate the self-join, so filtering on the literal `packages.id` string silently matched the wrong (outer) side and found nothing. Caught by the new tests themselves (`updated_combos` came back `0` instead of `1`). Fixed by querying `package_components` directly instead of `whereHas`.
- **Found and fixed as a direct byproduct**: the reactivate branch had no cache-forgetting at all before this — only the deactivate branch called `GameController::forgetPackagesCache()`/`forgetIndexCache()`. Computing `$gameIds` correctly for the new cascade call meant this branch could no longer skip it.
- Added 4 `ComboPricingServiceTest` cases (batch deactivate across 2 combos, ignores unrelated component, batch reactivate only-when-every-component-active across a 3-component/2-combo scenario) and 2 `SupplierControllerTest` HTTP-level cases. Full backend suite green: 2241/2241, 6011 assertions. Pint clean.
- See ADR-046's 2026-09-24 addendum for full evidence/rationale.

## 2026-09-24 — Fix: affiliate checkout/membership payment redirected to the wrong domain (PR #284; not yet deployed)

- Founder-reported real bug (not from the backlog): two live orders on the `fixfastapp.com` affiliate storefront redirected, after CHIP payment, to `pekangame.space/order/status/...` instead of back to `fixfastapp.com` — which then 404'd with "No order found with that order number" because `/track-order` is itself brand-scoped (ADR-060). Founder also flagged the same risk for Membership subscribe, unprompted.
- **Root cause, confirmed against prod via SSH before fixing**: `CheckoutService::requestPayment()` and `MembershipSubscriptionService::requestPayment()` both built the CHIP `success_return_url`/`failure_return_url` from `config('services.storefront.url')` (`STOREFRONT_URL`, `https://pekangame.space` in prod) unconditionally — never from the request's already-resolved `StorefrontBrand` (set by the `storefront.brand` middleware from `X-Storefront-Host`, which both `/checkout` and `/membership/subscribe` already run behind). Confirmed live: `fixfastapp.com` is an active `is_primary` `affiliate_domains` row for affiliate 2 (Ohahastore), and the reported order `PG-BSBKZSIRQQLS` (`payment_status=paid`) belongs to that affiliate — the founder's read was exactly right, membership had the identical latent bug, just never yet hit in production.
- **Fix:** `StorefrontBrand::set()` now optionally carries the literal verified `X-Storefront-Host` alongside the resolved `Affiliate`; a new `StorefrontBrand::url()` returns that host (as `https://`) when one was resolved, else falls back to `STOREFRONT_URL` unchanged. `ResolveStorefrontBrand` passes the host only on the custom-domain branch — the primary brand's own hostnames stay on the config default, so `pekangame.space`'s own checkout is byte-identical to before. Both services now inject `StorefrontBrand` and call `->url()` instead of reading config directly.
- Added a feature-level regression test per flow — `AffiliateStorefrontCheckoutTest::test_checkout_return_url_lands_on_the_affiliates_own_domain` and `MembershipSubscriptionControllerTest::test_subscribe_return_url_lands_on_the_affiliates_own_domain` — each captures the real `PaymentRequest` sent to the (faked) CHIP gateway and asserts both return URLs use the affiliate's own domain. Neither flow had any test coverage of the return URL at all before this fix. Full backend suite green: 2243/2243, 6019 assertions.
- No ADR needed — this restores ADR-060's already-intended per-brand isolation, not a new decision. `/track-order`'s brand-scoping itself is correct as designed (an order shouldn't be look-up-able cross-brand); the bug was purely in where the customer got redirected to.

## 2026-09-24 — Membership renew/upgrade guided modal (PR #285)

- Direct follow-up in the same session as the affiliate redirect fix above: walking through how renew/upgrade actually work surfaced that neither mechanic is obvious from the button label — Renew extends `expires_at` by 30 days from the CURRENT expiry (not reset from today) and never touches `quota_remaining_sen`; Upgrade switches the tier's price immediately but the customer keeps using the OLD tier's remaining quota until the next 30-day cycle boundary (`MembershipFeeService::applyTransition()`, ADR-027 addendum Q2/Q5 — a deliberate anti-abuse call, not a bug). The storefront (`MembershipSubscribe.tsx`) had zero explanation of either — only "Downgrade" (disabled) carried an inline note.
- **Fix:** new `MembershipTransitionModal.tsx` — bilingual (English + Bahasa Melayu, this storefront has no locale switcher) explanation shown when tapping Renew or Upgrade, gated behind an "I Understand, Continue" acknowledgement before `pickPlan()` runs; Cancel dismisses with no selection made. First-time Subscribe and disabled Downgrade are unaffected.
- Browser-verified against a real flow, not just `tsc`/lint/build: seeded an active Tier 1 membership + issued a session token via `MembershipSessionTokenService::issue()` on local dev, set the `krs_membership_token` cookie, walked both Renew and Upgrade — correct EN+BM copy, Continue proceeds to plan selection, Cancel dismisses cleanly.

## 2026-09-24 — Release: `staging` → `main` (PR #286, 7 PRs #279–#285)

- Founder merged PR #286 same day; production auto-deployed per ADR-037/066. Ships: ADR-100's flap-gate retirement (PR #280), ADR-094's combo auto-reactivation cascade (PR #281), ADR-046's supplier-bulk-toggle combo cascade (PR #282), the affiliate checkout/membership redirect fix (PR #284), and the membership renew/upgrade guided modal (PR #285) — see their own entries above/in `docs/adr.md`. One additive migration (`package_reactivation_logs.admin_user_id`). All CI green on every merged PR, including a `playwright` job that hit the known recurring Turbopack Google-Fonts CI flake (`docs/prd.md` §16 item 27) on PR #285's first run and cleared on a plain rerun with zero code change — consistent with every prior occurrence.

## 2026-09-24 — Production infra: Vercel Function Region was `iad1` (US), not `sin1` (Singapore), on all 3 frontends

- Same session as the release above: founder asked whether a felt storefront slowdown was related to the recently-shipped `GameInfoModal` (ADR-109). Ruled that out by reading the code — it's a pure client component riding on an already-fetched payload, zero new network calls. Kept digging instead of stopping at "not this feature."
- **Live investigation found the real cause.** `curl` timing against `api.pekangame.space` directly: ~70-120ms (backend/DB fine). A Chrome DevTools network trace against production while clicking through the homepage caught `_rsc` prefetch requests for `/order/[slug]` taking 4.9s, 12.4s, 19.8s, and once **48.2s** — 2+ orders of magnitude slower than the backend alone. Vercel Observability confirmed this wasn't a one-off: `/order/[slug]`'s trailing-12h duration averaged 3.9s, P75 4.38s, P95 6s, 0% actual errors (just slow). `vercel inspect` on the latest production deployment showed the Function's build output tagged `[iad1]` — Washington DC, US East. `ipinfo.io` on the Forge box's IP confirmed the backend origin is physically in Singapore. Checked `pekangame-admin` and `pekangame-reseller` too — same `iad1` misconfiguration on both (the Vercel account default, never explicitly set on any of the 3 projects). `pekangame-docs` (Astro/Starlight) has zero Vercel Functions, unaffected.
- **Fix:** Project Settings → Functions → Function Region on all 3 projects — unchecked `iad1`, checked `sin1` (Singapore is offered under Vercel's Asia Pacific region list, though the collapsed UI only previews Hong Kong/Tokyo at a glance), Save, Redeploy. Confirmed Pro plan + Fluid Compute already enabled on all 3 — this was pure misconfiguration, not a capacity ceiling, so self-hosting off Vercel was considered and explicitly rejected as the wrong fix (a single fixed-capacity VPS would handle a real burst of concurrent users worse than Vercel's already-enabled auto-scaling, once pointed at the correct region).
- **Verified live immediately after redeploy, no rollout wait needed:** `/order/[slug]` steady-state dropped from 3.9-6s to 0.15-0.45s across 4 different games; `admin.pekangame.space`/`reseller.pekangame.space` login pages both settled at ~0.35-0.4s (down from the same `iad1` tax). One cold-start spike (16.9s) on the very first request per project, then immediately stable.
- **Found the same root cause behind a previously-unexplained error**, not a separate bug: `[catalog] listHeroSlides failed — serving fallback: ...(525)` on `fixfastapp.com`, first spotted in a quick `vercel logs` tail. Pulled full history via Vercel's dashboard Logs (Query) tool (the CLI's `logs` command is live-tail only, not historical) — only 2 occurrences in 7 days. The Sep 21 one is the smoking gun: the same request had `listGames` (522 Connection Timed Out) AND `listHeroSlides` (525 SSL Handshake Failed) fail together, with the external call to `api.pekangame.space` itself stalling 44.8s before Cloudflare gave up — same cross-region latency pathology as above, just manifesting as an outright connection failure under worse conditions instead of merely slow. No separate fix needed; covered by the region change. `safeRead()`'s graceful `[]` fallback meant the customer never saw a crash either time, just a page with no hero banner.
- Same session, founder also tuned `packages.php`'s Price Sync/reactivation config live in prod: raised `PRICE_SYNC_INTERVAL_MINUTES` 60→30 for fresher prices, then raised `REACTIVATION_STABILITY_SYNCS` straight to `6` (skipping the originally-planned `3` — ADR-100's own owed bump from `1`) once the interaction was flagged — halving the sync interval would have silently halved the real-world stability window (3 syncs × 60min ≈ 3h vs. 3 syncs × 30min ≈ 1.5h) had the streak requirement not moved with it. Confirmed via SSH: the first attempt's `.env` write hadn't actually landed (caught by checking the file's mtime before trusting it — still Sep 22's, two days stale); the second attempt did, `config:cache` regenerated automatically, `php artisan tinker` read back the live values (`30`/`6`).
- See `docs/adr.md`'s ADR-071 addendum for the full record (this ADR's own Context had explicitly ruled out backend throughput as the cause and never checked Function region — worth remembering that "not the backend" isn't the same as "not infra").

## 2026-09-24 — Affiliate theme-preset audit → Cyber Bumblebee contrast fix + dark mode for all 4 affiliate presets (ADR-113, branch `fix/affiliate-theme-contrast-bugs`, unpushed)

- Founder asked for a full visual audit of the 5 affiliate theme presets using `antislop`/`impeccable` rather than the previously-planned Stitch reference — see [ADR-113](./adr.md) for the full decision record. Two rounds of work, same session.
- **Round 1 — contrast bug, found via the reseller portal's Live Storefront Preview panel, confirmed on the real storefront.** Cyber Bumblebee's raw `#FFC700` primary is ~1.6:1 on white — used directly (`text-primary` Tailwind class) as running text across 41 call sites: checkout total, order tracking, footer headings, every price tag. Fixed with a new `--color-primary-on-surface` token (equal to `--color-primary` on the 4 presets already ≥4.5:1; overridden to a deep amber `#8a6500` only for Bumblebee) and a mechanical rename of all 41 `text-primary` usages to the new token-backed class. Same audit also found Bumblebee's `secondary-container`/`secondary-fixed-dim` were a washed navy-slate/lavender-grey (`#3A3A50`/`#8E8EA8`) instead of "ink black" — set both to solid ink black with a new `--color-on-secondary-fixed-dim` (ink on every other preset, yellow pop on Bumblebee) so icon badges read as yellow-on-black instead of grey-on-grey. Verified live on local dev by temporarily pointing the primary affiliate's own branding row at each preset (`AffiliateBranding::theme_preset`/`theme_mode`, reverted after each check) — the reseller preview mockup alone wasn't trusted as ground truth after it was found to hide real components (discount badges, step indicators) that do show the intended accent colors on the actual storefront.
- **Round 2 — dark mode, all 5 presets audited, 4 built.** Compared Digital Architect's existing (disliked) dark build against two real competitor dark themes (warungvamos.games, acidgameshop.com). Root cause wasn't the brand hues: (1) the structural 2px border was full-brightness near-white on every element at once, and (2) the Membership promo card's `secondary-container` fill used a raw hue near-complementary to the page's primary, reading as a mismatched inserted block. Fixed for Digital Architect (muted `--color-ink`, in-family `secondary-container`), then built fresh `tokensDark` for Cyber Bumblebee, Red Giants Edition, Cyber Emerald, and Hyper Cobalt following the same rules — each gets its own hue-tinted dark surface ramp (warm amber-black, maroon-black, green-black, blue-black respectively), a muted structural border, and a deep in-family tone for the Membership card instead of the raw saturated secondary hue. `storefront/DESIGN.md` written (new file) to carry the system forward, including a named "Dark Mode Rules" section citing each bug it exists to prevent.
- **Mid-build policy change:** founder decided Digital Architect (PekanGame's own primary-brand identity) should never have been an affiliate-pickable preset at all. Verified via read-only production query (SSH, founder-authorized) that zero non-primary affiliates were on it — zero customer impact. Removed it from the reseller portal's picker (`ThemeTab.tsx`'s `SELECTABLE_PRESETS` filter) and the backend's `UpdateBrandingRequest` validation (`in:bumblebee,redgiants,emerald,cobalt`, was `in:default,...`); deleted its freshly-built `tokensDark` as unreachable dead code rather than keeping it.
- Fixed a related regression while muting `--color-ink` for dark mode: 6 component call sites paired `text-ink` with `bg-surface-container-lowest` for real legible content (icon fallback letters, a status badge, the `outline` Button variant), not decoration — routed through `text-on-surface`/`text-on-surface-variant` instead so dark mode didn't lose real text contrast while structural borders got calmer.
- **Verification:** `tsc --noEmit`, `eslint`, and a full production `next build` clean on both `storefront/` and `reseller/`; full backend suite green (2243/2243); `theme-presets.ts` byte-identical between `storefront/` and `reseller/` after every `scripts/sync-theme-presets.mjs` run (CI's `theme-preset-drift` job would pass). Every preset/mode combination live-tested in the real browser against local dev (not just the reseller preview mockup), including reverting the primary affiliate's branding row back to Digital Architect/light after each check so local dev was left in its original state.
- `docs/prd.md` §15/§16 updated same session to reflect dark mode now shipping on 4 presets (was 1) and Digital Architect no longer being affiliate-selectable. Branch pushed and PR opened same session (#288) — see the 2026-09-25 entry below for its merge.

## 2026-09-25 — ADR-113 merged to `staging`

- PR #288 (branch `fix/affiliate-theme-contrast-bugs`) merged into `staging` — CI green, no follow-up fixes needed. Not yet released to `main`/production; that remains a separate founder decision.

## 2026-09-25 — ADR-114: production infra split into its own DigitalOcean team (retroactive doc backfill)

- **This entry was written a day after the migration itself shipped** — a doc-audit session (this one) found the migration had no `adr.md`/`build-log.md` record at all, only session memory. Backfilled here; see [ADR-114](./adr.md) for the full decision record.
- PekanGame's droplet + managed MySQL rebuilt (not transferred — DO has no cross-team transfer) into a new DO team, **LWF Group Sdn Bhd**, separating it from the DO account it was sharing with Nakhoda (a different, older business with real external customers). New droplet `pekangame-prod-lwf` (159.223.39.16), new `topup-prod-mysql` cluster (89 tables imported, `DEFINER` clauses stripped first). Cloudflare Authenticated Origin Pulls mTLS replicated byte-for-byte from the old server's nginx config; new Origin CA cert installed via Forge.
- **DNS cutover for `api.pekangame.space` verified via a real end-to-end order**, not just a health check: `PG-NMED1MMB4HKK` — CHIP webhook received, `payment_status: paid`; first Digiflazz delivery attempt failed (`error_code 45`, IP not yet whitelisted for the new droplet's IP); founder updated the Digiflazz IP whitelist, automatic retry succeeded ~2 min later (`delivery_status: delivered`).
- **OpenWA bot deliberately not migrated in this cutover** — its WhatsApp session is device-bound, can't be copied, needs a fresh QR re-link with unavoidable bot-channel downtime when it happens. Old droplet (`pekangame-prod`, 157.245.203.250, old DO team) kept running in parallel, serving both as API rollback and as the bot's still-live home (`bot.pekangame.space` + its OpenWA process, confirmed still running via SSH during this audit session).
- **Found during this audit session, not the migration session:** the new droplet provisions with Forge's zero-downtime deploy (`releases/<id>/` + `current` symlink) — the old droplet does not (flat `backend/` dir, Quick Deploy off). Not a deliberate choice re-made for this migration, just what Forge gave the new site; kept as a strict improvement (no half-deployed-release window on a money-critical checkout path, atomic rollback). This means `.env` now lives at `/home/forge/api.pekangame.space/.env` (site root, symlinked into `current/backend/.env`), not inside a flat `backend/` dir — an initial check in this same audit session looked at the wrong (old-server-shaped) path and wrongly concluded OpenWA env vars were missing entirely; re-checked against the correct path and found `OPENWA_WEBHOOK_SECRET` is actually present and correct (inbound bot webhook traffic verifies fine), while `OPENWA_BASE_URL` still resolves to `127.0.0.1:2785` with nothing listening there on the new box (outbound backend→bot calls fail) — a real but low-urgency gap, since this channel has zero external customers yet.
- **Memory updated same session:** the `reference_forge_box_ssh` memory described only the old server's flat layout — added a new-server addendum so a future session doesn't repeat the same wrong-path mistake. (No server-path reference exists in `AGENTS.md` itself — checked, none found.)
- **Still open, unscheduled:** OpenWA bot migration to the new droplet; old droplet/DB decommission (no date — wait for the new server to prove stable for a few days); a stray duplicate `schedule:run` cron entry accidentally left on the old server mid-migration (harmless, delete whenever convenient).

## 2026-09-25 — OpenWA bot channel migrated to the new droplet, closing out ADR-114

- Same-day follow-up to the migration audit above, same founder session. Provisioned `bot.pekangame.space` as a Custom Forge site on `pekangame-prod-lwf` (browser-driven: New Site → domain → SSL cloned from the old site's own certificate rather than waiting on Let's Encrypt HTTP validation, which needs DNS to already point here), cloned `github.com/rmyndharis/OpenWA` fresh, `npm install` clean, nginx proxy config hand-typed to match the old server's `proxy_pass http://127.0.0.1:2785` block, registered as a Forge background process (`npm run prod`). DNS cut over (`bot.pekangame.space` + `www.bot.pekangame.space` A records → `159.223.39.16`) once the new instance answered `200` locally — same careful "verify fully live, then cut DNS" order as the earlier `api.` cutover.
- **Three separate per-instance credentials in OpenWA's own excluded `data/` SQLite DB caused three rounds of 401s, each looking like the previous fix hadn't worked when actually a different credential was still wrong:** (1) the dashboard login key (auto-generated fresh, cosmetic only), (2) the webhook registration's `secret` column (the dashboard's "Create Webhook" form has no secret field at all, so a fresh registration ships with `secret = NULL` and every inbound delivery gets HMAC-rejected — fixed via a direct `sqlite3 UPDATE` reusing the value already in both servers' `.env`), (3) the outbound API key **and** the WhatsApp session's own ID (both per-instance; the session ID in particular is assigned fresh at QR-link time, not reused — fixing the API key alone still 401'd because the endpoint URL itself named a stale session ID). See ADR-114's 2026-09-25 addendum for the full mechanism of each and why `.env` alone doesn't carry them.
- **`horizon:terminate` was required after each `.env` fix** — a running Horizon doesn't pick up `config:cache` changes on its own; this is an already-documented project gotcha (`AGENTS.md`) that still cost real time here because the fix was applied out-of-band from a normal deploy.
- **Verified end-to-end for real, not just per-credential:** a WhatsApp message sent into the founder's own test group round-tripped — inbound `message.received` processed (`OpenWaSessionStatus` cache showed `ready`), outbound reply delivered (OpenWA's own `webhook_outbox_events` logged a matching `message.sent`), zero rows in `webhook_delivery_failures`.
- **Found and fixed as a byproduct, not part of the bot migration itself** — asked directly by the founder to scan for other problems before closing the session: the old droplet's Horizon had been left fully live since the earlier `api.` cutover (deliberate, as rollback safety), and because `Supplier.api_config` for Digiflazz was copied into both databases, **both droplets had been independently running price-sync against the same real Digiflazz account concurrently since that cutover**, tripping Digiflazz's own rate limiter (`[83] Anda telah mencapai limitasi pengecekan pricelist`) repeatedly through the day on both sides — visible in both servers' logs going back to the `api.` cutover, not something the bot migration introduced. **Fixed:** founder paused the old droplet's Horizon/Reverb/Pulse daemons and Forge scheduler, deliberately leaving the OpenWA daemon running (still the rollback target). Confirmed via log check: zero further Digiflazz rate-limit errors after the pause.
- `docs/adr.md`'s ADR-114 gained a full addendum with the per-credential mechanism, useful as a checklist if OpenWA is ever re-provisioned again. `docs/prd.md`'s OpenWA-resize backlog item corrected in a separate small PR (#291) after this session's own audit found its "nginx IP-restriction" half was already-stale wording, not a real gap.

## 2026-09-26 — Full money-critical branch audit (4 background agents) + 2 grilled fixes decided, none built yet

- Founder-requested comprehensive audit of every money-moving branch: Affiliate portal workflow (earnings ledger, withdrawal, tier fees, tenant isolation), Affiliate whitelabel storefront (custom-domain checkout/membership, brand isolation, theme rendering), Reseller WhatsApp Bot (command handling, webhook auth, wallet top-up safety), Reseller REST API + docs-site (code-vs-docs drift, external-developer usability). One background `general-purpose` agent per branch, each also running the `code-review` skill at high effort scoped to its own files, plus read-only production verification via `ssh pekangame-prod-lwf`/`tinker` (no mutating actions taken anywhere).
- **Overall verdict: foundation is solid** — server-side pricing, ledger accuracy (0 sen drift between `ledger_entries` and `orders.affiliate_profit` live), and the whitelabel brand-isolation model (the 2026-09-24 CHIP-redirect fix, PR #284) all held up under adversarial review. One **critical** and four **high** findings surfaced; full findings list (including mediums/lows not repeated here) lives in this session's own conversation — the two that needed a design decision were grilled and are recorded as ADR addenda (not built this session):
  - **[docs/adr.md ADR-074 addendum](./adr.md#adr-074-reseller-api-channel--per-tenant-api-keys-order-placementstatus-endpoints)** — Reseller API's `checkout_idempotency_key` lookup (`ResellerOrderPlacementService::placeOrder()`) had no per-reseller scope, letting one reseller's request match (and receive back) another reseller's real order data on a key collision — a real ADR-084 decision 2 invariant violation, not just a benign accidental-collision risk (a malicious caller could deliberately target a Bot-channel order's own `wa:...` key shape). Decided: a `STORED GENERATED` `idempotency_scope` column (`IFNULL(wallet_reseller_id, 0)`) + composite unique index, after two simpler approaches were grilled and rejected (a bare app-level `WHERE` filter leaves the race-recovery re-query's identical bug open; a naive composite index on the raw nullable column would have silently broken every guest/Affiliate-storefront order's own idempotency guarantee, since MySQL treats each `NULL` as distinct in a unique index).
  - **[docs/adr.md ADR-059 addendum](./adr.md#adr-059-reseller-portal--reseller-app-earnings-ledger-withdrawals-self-service-storefront-config--built--live-at-resellerpekangamespace-entity-later-renamed-resellerAffiliate-by-adr-072-the-app-now-also-serves-wallet-reseller-accounts)** — `Affiliate\WithdrawalController::store()` lets any `affiliate_user` override the saved profile's payout bank details per-request with zero cross-check, a real payout-redirect risk once an affiliate has multiple staff logins. First-draft fix (admin warning if the withdrawal differs from *current* profile) was self-corrected mid-grill — once the withdrawal always snapshots from profile, it can never differ from profile at approval time by construction, so that comparison would never fire. Decided instead: drop the per-request override fields entirely (withdrawal always reads profile) + an admin-approval warning comparing against the affiliate's *last admin-approved* withdrawal (a real "did the payout destination just change" signal). Accepted, tracked gap: the first payout after a malicious profile change still only warns, doesn't block — a cooling-off/notification mechanism (parked) would close that, not worth building yet for one real affiliate with one staff user.
- **Findings triaged into two buckets, not built this session (all fixes are mechanical/pattern-following, no further grilling needed for these):**
  - **Access control:** `SetAffiliateContext` never checks the parent `Affiliate.status` (a deactivated affiliate keeps full portal/withdrawal access); `EnsureAccountType` never checks `AffiliateUser.is_active` for `owner_type=reseller` either (same gap, reseller side).
  - **Concurrency:** `Admin\WithdrawalController::reject()`/`complete()` and the platform `store()` path have no lock, unlike `approve()` in the same file (mirror its existing pattern).
  - **`AffiliateTierFeeService::chargeCycle()`** — no due-date recheck inside its own lock (double-charge risk if invoked twice; zero live impact today since the one real affiliate's tier is RM0/month) + no `withTrashed()` on a soft-deleted tier relation (null-pointer crash risk).
  - **Validation:** withdrawal bank-detail fields accept empty string, bypassing the "must have bank details" guard; `maker_checker_threshold_sen` silently coerces a missing config to 0 instead of failing loud.
  - **Reseller Bot:** ordinary chat in an already-linked WhatsApp group triggers a full spam reply (confirmed live via real `ResellerBotCommandLog` rows) — missing the same `.`-prefix guard the unlinked-group path already has; `.list` shows cost-price (0% markup) for a reseller with no tier assigned yet, unlike `.order` which correctly blocks.
  - **Docs:** `checkout_input` (the ADR-097 zone-id discovery field, live in code since 2026-09-16) is missing from `docs-site`'s hand-written `first-order.md`/`product-codes.md` walkthrough, though it is present in the auto-generated API Reference.
  - **Low/cosmetic (not itemized further here):** CHIP payment description hardcodes "PekanGame" regardless of which affiliate storefront the customer paid on (needs a founder yes/no, not a grill — may be intentional single-merchant-of-record); a narrow `.topupbaki` notify-miss race (money safe, WhatsApp confirmation can be skipped); several stale docblocks/comments and one duplicated magic number.
- **Nothing built this session** — this was audit + grill only, per the founder's own framing ("fix grill semua perkara yang perlu grill, note down yang boleh fix terus, next sesi settlekan satu per satu"). Next session's job: implement the two addenda above (migration + service-layer filter for ADR-074; request/controller change + approval-screen warning for ADR-059) plus work through the mechanical punch list, one PR at a time, each on its own `fix/*` branch off `staging` per the usual branch workflow.

## 2026-09-26 — Item 28 built (Reseller API cross-reseller idempotency scope)

- **[ADR-074 addendum](./adr.md#adr-074-reseller-api-channel--per-tenant-api-keys-order-placementstatus-endpoints).**
  `ResellerOrderPlacementService::placeOrder()`'s two `checkout_idempotency_key`
  lookups (lines 63/133) now both filter by `wallet_reseller_id`, backed by a
  new generated `orders.idempotency_scope` column
  (`IFNULL(wallet_reseller_id, 0)`) and a composite
  `UNIQUE (idempotency_scope, checkout_idempotency_key)` index replacing the
  old bare-column unique constraint. **Build-time revision:** the column is
  `VIRTUAL`, not `STORED` as the addendum originally decided — sqlite (the
  fast suite's driver, and the real local dev DB) refuses to add a `STORED`
  generated column to a table that already has rows, confirmed empirically
  against both an in-memory table and the actual `database.sqlite`; `VIRTUAL`
  has no such restriction and indexes identically on both sqlite and MySQL
  InnoDB. Added a regression test asserting two different resellers sharing
  one idempotency key each get their own order, never a cross-tenant replay.
  Backend 2248/2248 fast + 17/17 concurrency (real MySQL), both green. Local
  dev DB migrated (plain `php artisan migrate`, confirmed via
  `migrate:status`). Not yet released to `main`. Built on its own
  `fix/adr074-reseller-idempotency-scope` branch off `staging`, its own PR,
  per the usual branch workflow.
- **Code-review follow-up, same PR, caught two real gaps in the first pass:**
  the migration's `down()` unconditionally re-added the bare global-unique
  constraint on `checkout_idempotency_key` — safe today (0 collisions
  exist) but a real footgun the moment two resellers actually do share a
  key post-deployment (the exact state this migration exists to allow):
  the final statement would throw mid-rollback, leaving `idempotency_scope`
  already dropped and the old constraint never restored either way. `down()`
  now checks for any cross-reseller duplicate `checkout_idempotency_key`
  first and throws a clear `RuntimeException` before touching schema at
  all. Added a regression test for both the refusal and the clean-rollback
  path. Separately, the two `wallet_reseller_id`-scoped idempotency lookups
  in `ResellerOrderPlacementService` (pre-check + race-recovery) were
  duplicated verbatim — extracted into one private `findByIdempotencyKey()`
  so a future edit to the scoping predicate can't update one call site and
  silently miss the other. Backend 2246/2246 fast + concurrency suite both
  green. 🟢 **MERGED TO `staging`** (PR #293, 2026-09-26) — not yet on `main`.

## 2026-09-26 — Item 29 built (Affiliate withdrawal payout-redirect fix)

- **[ADR-059 addendum](./adr.md#adr-059-reseller-portal--reseller-app-earnings-ledger-withdrawals-self-service-storefront-config--built--live-at-resellerpekangamespace-entity-later-renamed-resellerAffiliate-by-adr-072-the-app-now-also-serves-wallet-reseller-accounts).**
  `CreateAffiliateWithdrawalRequest` no longer accepts
  `bank_name`/`bank_account_no`/`bank_account_holder` at all — a withdrawal
  request always reads the affiliate's saved profile
  (`Affiliate\WithdrawalController::store()`), closing the payout-redirect
  gap where any `affiliate_user` could silently override the payout
  destination per request. Added a regression test asserting a bank-detail
  override sent in the request body is fully ignored. `Admin\WithdrawalController::index()`
  now also returns `bank_details_changed_since_last_approval` per withdrawal
  (`null` for a platform withdrawal or an affiliate's first-ever payout,
  otherwise a real diff against the affiliate's last admin-approved/completed
  withdrawal — never against current profile, since a withdrawal already
  always snapshots from profile at request time and so could never differ
  from it by construction) — surfaced in `/admin/withdrawals` as a red "Bank
  details changed since last payout" tag next to the row. Admin `tsc`/`eslint`
  clean. Backend 2247/2247 fast, all green. Not yet released to `main`. Built
  on its own `fix/adr059-withdrawal-payout-redirect` branch off `staging`,
  its own PR, per the usual branch workflow (item 28's Reseller API
  idempotency-scope fix landed the same session on its own separate branch/PR).
- **Code-review follow-up, same PR, caught two real gaps in the first pass:**
  the `reseller/` portal's own `/withdrawal` page still let an
  `affiliate_user` type a "one-off" bank override and submit it — the
  backend now silently ignores those fields, so the form was actively
  misleading (looked like it worked, payout still went to profile). Replaced
  the editable inputs with a read-only "Payout to" summary + a link to
  Profile, and disabled submission when no bank details exist yet (mirrors
  the backend's own 422 guard). Separately, `Admin\WithdrawalController::index()`
  ran one extra query per affiliate-owned row to compute
  `bank_details_changed_since_last_approval` — batched into a single query
  per request instead (fetch every relevant owner's approved/completed
  withdrawals once, group in PHP). A third, lower-severity finding — bank
  fields are read from the in-memory `$affiliate` model before
  `AffiliateWithdrawalService::request()`'s lock, so a concurrent Profile
  bank-detail edit isn't covered by that lock — is accepted, not fixed:
  the lock's declared purpose is serializing balance/open-request checks,
  not bank-detail consistency, no `Affiliate`-row locking exists anywhere
  else in the codebase (a Profile update doesn't lock either), and this is
  a narrower instance of the same already-accepted gap this ADR addendum's
  own Consequence-to-track already covers (first payout after a change only
  warns, doesn't block).

## 2026-09-26 — Items 30/31 resolved (deactivated-account portal access)

- From the 2026-09-26 money-critical branch audit punch list (`docs/prd.md`
  §16 items 30/31), originally filed as one mechanical fix mirrored across
  Affiliate + Reseller. Building it surfaced a real conflict: a blanket
  "deactivated account = no portal access" gate breaks an already-decided,
  already-tested business rule for the Affiliate side —
  [ADR-058 RES-5](./adr.md) deliberately keeps a deactivated Affiliate's
  portal **read-only** with **earnings still withdrawable** (only new
  orders + the branded storefront are blocked), confirmed live by the
  already-passing `BrandingControllerTest::test_a_deactivated_affiliate_is_read_only`.
  Founder confirmed: item 30 is **not a bug**, no code change.
- Item 31 (Reseller side) built as scoped: `EnsureAccountType` now blocks
  the entire `reseller-portal/*` route group when `resellers.is_active` is
  false, matching the full block the REST API (`EnsureResellerApiKey`) and
  the Bot (`ResellerBotService`/`ResellerOrderPlacementService`) already
  enforce on the same column — the portal was the one channel that stayed
  fully open for a deactivated Reseller.
- Added 1 new test (`EnsureAccountTypeTest::test_a_deactivated_reseller_cannot_reach_the_portal_even_with_an_active_login_row`),
  confirmed it fails against the pre-fix code first. Backend 2251/2251 fast
  suite green. Built on its own `fix/deactivated-account-portal-access`
  branch off `staging`, per the founder's plan this session to build a few
  more punch-list items and bundle them into one PR rather than one PR per
  item. Not yet merged.

## 2026-09-26 — Item 32 built (`WithdrawalController::reject()`/`complete()` missing lock)

- Mechanical fix from the 2026-09-26 money-critical branch audit punch list
  (`docs/prd.md` §16 item 32) — no grill needed. `reject()` and `complete()`
  each ran an unconditional `$withdrawal->update()` with no row lock, unlike
  `approve()` in the same controller. The real risk isn't two admins double-
  rejecting (harmless) — it's `approve()` racing `reject()` on the same
  Pending withdrawal: `approve()` debits the ledger and flips the row to
  `Approved`, then `reject()`'s unconditional write (reading the pre-race
  `Pending` status) overwrites it to `Rejected` — money already paid out,
  record says it wasn't, no compensating entry anywhere.
- Fix mirrors `approve()` exactly: both now wrap in `DB::transaction()` +
  `Withdrawal::query()->lockForUpdate()->findOrFail()`, re-checking status
  inside the lock before mutating.
- Test-first, red→green, per `AGENTS.md`'s money-critical-logic rule: added
  3 new subprocess concurrency tests (same pattern as the existing
  `WithdrawalApproveConcurrencyTest` — two genuinely separate PHP processes
  racing for real against Docker MySQL, via new test-only artisan commands
  `app:withdrawal-test-reject`/`app:withdrawal-test-complete`):
  `WithdrawalRejectConcurrencyTest` (double-reject), `WithdrawalCompleteConcurrencyTest`
  (double-complete), and `WithdrawalApproveRejectRaceConcurrencyTest` — the
  one that actually matters, asserting exactly one of approve/reject wins
  and the ledger entry count + balance stay consistent with whichever one
  did. Confirmed all 3 fail against the pre-fix code (both processes reported
  `success` every time) before applying the fix, then confirmed green after.
- Backend 2250/2250 fast suite green, 6/6 withdrawal concurrency tests green
  (old approve test + 3 new). Built on its own `fix/withdrawal-reject-
  complete-lock` branch off `staging`. Not yet merged.

## 2026-09-26 — Items 36/37 built (Reseller Bot `.list` unrecognized-chat spam + no-tier price leak)

- From the 2026-09-26 money-critical branch audit punch list (`docs/prd.md`
  §16 items 36/37). Founder revised item 36's scope mid-build: rather than
  just adding the unlinked-group's existing `.`-prefix guard to the
  linked-group path, an unlinked group now stays fully silent regardless of
  the message (no more "Group ini belum dikaitkan..." reply even for a
  dot-prefixed attempt) — only a linked group interacts at all, and only
  with `.`-prefixed messages. Ordinary chat ("ok tq", "haha") in a linked
  group parses as `Unrecognized` the same as a typo'd command, but is now
  silently dropped in `ResellerBotService::handle()` before it reaches
  either the command-list reply or the `unrecognized_command` failure log
  — a dot-prefixed but malformed/unknown command still gets the helpful
  reply, unchanged.
- Item 37: `handleListGamePackages()` (`.list {kod}`) now guards on
  `$reseller->tier === null` before computing a price, logging
  `no_tier_assigned` and replying with a plain "belum ditetapkan tier
  harga" message — mirrors `.order`'s existing
  `NoResellerTierAssignedException` rejection. Before this, a reseller with
  no `reseller_tier_id` got a 0%-markup price that silently equalled the
  real cost price.
- 4 new tests in `ResellerBotServiceTest`, all confirmed failing against
  the pre-fix code first (2 assertion failures on the silence guards, 1
  real `Attempt to read property "markup_percent" on null` reproducing the
  exact leak/crash risk item 37 described). Backend 2253/2253 fast suite
  green. Built on its own `fix/reseller-bot-list-unrecognized` branch off
  `staging`, per the founder's plan to build several punch-list items and
  bundle them into one PR. Not yet merged.

## 2026-09-26 — Item 38 built (docs-site missing `checkout_input`)

- Doc-only fix from the 2026-09-26 money-critical branch audit punch list
  (`docs/prd.md` §16 item 38). `first-order.md`'s hand-written catalog
  example was missing `checkout_input` (the ADR-097 zone-id discovery
  field, already live in the real API and its auto-generated Reference) —
  a developer following only the guide would get stuck placing an order
  for a zone-id game, since the guide only mentioned `server_id` in prose
  ("Mobile Legends does; many do not") with no way to check programmatically.
  Added `checkout_input` to the catalog JSON example (matching
  `CatalogController`'s own `#[Response]` example exactly) + a paragraph
  explaining it, and reworded the `server_id` bullet to point at
  `checkout_input` instead of a hardcoded game list.
  `product-codes.md` reuses the same catalog example for a different
  purpose (building `product_code`) — left untouched, not in scope for
  this fix. `npm run check` + `npm run build` both clean. Built on its own
  `fix/docs-checkout-input-example` branch off `staging`, per the
  founder's plan to build several punch-list items and bundle them into
  one PR. Not yet merged.

## 2026-09-26 — Item 33 built (`AffiliateTierFeeService` double-charge + `withTrashed()`)

- From the 2026-09-26 money-critical branch audit punch list (`docs/prd.md`
  §16 item 33). The punch list's own suggested fix — recheck
  `next_charge_at` inside the lock, mirroring `WithdrawalController::approve()`
  — turned out to break a real, tested feature once built: a plain "is
  `next_charge_at` in the future" check can't distinguish "already charged
  this cycle" from "freshly assigned, never charged yet" — `assignTier()`
  sets `next_charge_at` 30 days out on creation too, identically to a
  just-charged row, and `Admin\AffiliateController::chargeTierFee()`'s
  "Charge Now" deliberately has no due-date filter so it can force an
  early first charge. Two existing tests
  (`AffiliateControllerTest::test_charge_tier_fee_debits_earnings`/
  `test_charge_tier_fee_starts_grace_when_earnings_short`) caught this
  immediately.
- Correct fix requires both signals together: `next_charge_at` in the
  future **and** an `affiliate_tier_fee` ledger entry already exists for
  this subscription. That combination is only ever true right after a
  real completed charge — never on a freshly-assigned subscription (no
  entry yet) — and resets correctly once a cycle naturally elapses
  (`next_charge_at` back in the past, so the check is skipped regardless
  of how many old entries exist from prior cycles).
- Also fixed the `withTrashed()` gap: the eager-loaded `tier` relation
  now includes soft-deleted tiers, so a subscription whose tier was later
  deleted still charges its real fee instead of silently resolving to
  `null` (`(int) null` = 0).
- Found a second, related bug while building the fix: the pre-existing
  `AffiliateTierFeeConcurrencyTest` funded exactly one fee and expected
  `['active', 'grace']` as the outcome pair — the losing racer used to
  attempt a debit against an already-drained balance and fail into
  `grace`, even though this cycle's fee genuinely was already collected
  by the winner (a subscription that's fully paid up should never show
  `grace`). It now correctly recognizes the completed charge and no-ops
  as `active` instead — updated that test's expectation to match, and
  added a second concurrency test that funds *two* fees' worth (so a
  second debit is financially possible) to specifically prove
  `chargeCycle()` itself, not just the ledger balance check, is what
  prevents the double-charge.
- 4 new/updated tests total (2 feature, 2 concurrency), all confirmed
  failing against the pre-fix code first. Backend 2252/2252 fast + 2/2
  tier-fee concurrency green. Built on its own
  `fix/affiliate-tier-fee-double-charge` branch off `staging`, per the
  founder's plan to build several punch-list items and bundle them into
  one PR. Not yet merged.

## 2026-09-28 — Fix: CHIP payment description + missed Bot top-up notification (items 39/40, PR #298, merged to `staging`; not yet on `main`)

- **Item 39** — the CHIP purchase `description` (the line-item name shown
  on CHIP's own checkout page/receipt) was hardcoded `"PekanGame order
  {order_number}"` / `"PekanGame membership — {plan}"` regardless of which
  affiliate whitelabel storefront the customer actually bought on, and
  regardless of what was actually being bought — the order_number was
  already carried separately as `reference`, so the description was pure
  redundancy for orders and a wrong brand name for an affiliate's members.
  **Fix:** order checkout now sends the package/game name instead
  (`CheckoutService::requestPayment()`); membership now sends the
  request's own resolved affiliate store name instead of a hardcoded
  string. Added `StorefrontBrand::displayName()` (queries
  `AffiliateBranding.store_name`, falls back to `'PekanGame'` for the
  primary affiliate or a branding row with no store name yet) as the one
  seam both callers use, rather than duplicating the query — same
  `StorefrontBrand` class the 2026-09-24 return-url fix (PR #284) already
  uses for this exact "which affiliate is this request for" resolution.
  Reseller wallet top-up's own description (`ResellerWalletTopupService`)
  was left alone — its `reference` already names the actual thing being
  described adequately, and that channel isn't affiliate-storefront-scoped.
- **Item 40** — a `.topupbaki` (WhatsApp Bot wallet top-up) could be
  correctly charged and credited but never get its "top-up berjaya"
  WhatsApp reply: `ResellerBotWalletTopupNotifier::notifyPaid()` looks up
  the `ResellerBotWalletTopup` tracking row by `wallet_topup_attempt_id`
  and silently no-ops if it isn't found yet — and nothing ever re-checks
  once it later appears, since `ResellerBotService::handleTopupBaki()`
  only creates that row *after* `ResellerWalletTopupService::initiate()`
  returns. Considered closing the ordering gap directly (create the
  tracking row before calling CHIP) but rejected it: the attempt id the
  row is keyed on doesn't exist until `initiate()` returns, so that would
  need a nullable FK plus orphan-row cleanup on every one of
  `handleTopupBaki()`'s three failure paths, to fix a window that's
  otherwise unreachable in production anyway (CHIP can't report a
  purchase paid before the customer has even received its `checkout_url`)
  — and it would still only cover this one specific cause, not a worker
  crash or OpenWA being briefly down. **Fix instead:** extended the
  already-scheduled `ReconcilePendingWalletTopupsCommand` (built for the
  sibling "stuck-pending" backstop, ADR-076 PR-H's own "same backstop
  every CHIP-triggered flow already has" pattern) with a second sweep —
  any `ResellerBotWalletTopup` row with `notified_at` still null whose
  attempt is already `Paid` gets `notifyPaid()` called again. Self-healing
  against any cause of a dropped notification, not just this one, on the
  existing schedule, no schema change.
- No ADR needed for either — same precedent as PR #284: both extend an
  already-established seam (`StorefrontBrand`, the CHIP reconciliation
  backstop pattern) to a field/case that was missed, not a new decision or
  a reversal of one.
- New tests: `StorefrontBrandTest` (2), `CheckoutServiceTest::test_initiate_sends_the_package_name_as_the_payment_description`,
  3 new cases in `ReconcilePendingWalletTopupsCommandTest` covering the
  missed/already-notified/still-pending states. Full backend suite green:
  2262/2262, 6061 assertions. No migration — no schema touched.

## 2026-09-28 — Fix: items 26/34 (mechanical); item 27 attempted + reverted (`fix/2026-09-28-items-26-27-34`; not yet deployed)

- **Item 26** — an unauthenticated request to `/api/affiliate/*` or
  `/api/reseller-portal/*` without `Accept: application/json` (any plain
  `curl`, not a real frontend — every real frontend always sends it)
  crashed 500 instead of a clean 401. Re-derived the root cause from
  scratch with a live repro test rather than trusting the 2026-09-24
  finding's own description: it's not Sanctum's `Authenticate` middleware
  reading the `Accept` header wrong — Laravel's own
  `ApplicationBuilder::withMiddleware()` unconditionally wires a default
  `redirectGuestsTo(fn () => route('login'))` *before* this app's
  `bootstrap/app.php` closure runs, so building the redirect target for
  `AuthenticationException` calls `route('login')` on an API-only backend
  with no such route, throwing `RouteNotFoundException` — well before
  `shouldRenderJsonWhen()` (already configured for `api/*`) ever gets a
  chance to render a clean JSON response. **Fix:** one line,
  `$middleware->redirectGuestsTo(fn () => null)`, added to `bootstrap
  /app.php`'s existing `withMiddleware()` closure — correct everywhere in
  this app, nothing here has a login page. New
  `UnauthenticatedApiRequestTest` (2 cases) reproduces the exact
  no-Accept-header request against both affected route groups and asserts
  a clean 401 — both confirmed failing (500) against the pre-fix code
  first.
- **Item 27 — attempted, then reverted; back to unstarted.**
  `e2e/playwright.config.ts`'s `admin`/`storefront` webServer commands
  were pinned to `--webpack` (matching this repo's own existing `next
  build --webpack` workaround, 2026-09-24 ADR-112 PR2) to stop the
  Turbopack Google-Fonts-loader boot crash. Locally verified the flag
  itself forces webpack cleanly (`▲ Next.js 16.3.5 (webpack)`, ready in
  356ms) and the config still parsed/listed all 5 golden-path specs —
  but real CI told a different story: `admin-mark-delivered.spec.ts` and
  `admin-resend-delivery.spec.ts` both started timing out (60s, exact
  same locator — `openOrder()`'s search box — exact same ~1.0m each run)
  *every* run, not intermittently. Ran a clean A/B to be sure it was the
  bundler and not items 26/34: reverted `--webpack` only, kept the other
  two fixes, pushed — playwright passed clean. Re-added `--webpack`,
  pushed again — same 2 specs failed identically a second time. That's a
  deterministic regression, not a flake; `--webpack` made this measurably
  worse than the rare Turbopack crash it was meant to fix (which a rerun
  already always cleared). **Decision: not worth chasing further this
  session** — reverted `--webpack` on both webServer commands back to
  plain `next dev`, item 27 goes back to its original unstarted state. A
  real fix, if ever wanted, is `next build && next start` instead of
  `next dev` for e2e (deterministic, no dev-mode on-demand-compile timing
  at all — closes both this new failure mode and the original Turbopack
  one at the root), but that's CI workflow + config + `AGENTS.md` changes
  of its own, not a one-line flag swap — scoped out of this session.
- **Item 34** — `UpdateAffiliateProfileRequest`'s `bank_name`/
  `bank_account_no`/`bank_account_holder` were all `nullable`, which lets
  an empty string `""` through as a "valid" value — `Affiliate\
  WithdrawalController::store()`'s own `$affiliate->bank_name === null`
  guard doesn't catch that, so an affiliate could save blank bank details
  and still pass the "must have bank details" check before withdrawing.
  Confirmed this FormRequest is the only write-path for these three
  columns before touching it. **Fix:** `nullable` → `filled` on all
  three — allows omitting the field entirely (a partial update touching
  only other fields still works), rejects it outright if present and
  empty. 2 new cases in `AffiliateProfileTest`.
- No ADR needed for items 26/34 — both mechanical fixes using existing
  patterns (a framework config override, tightening an existing
  validation rule), not new decisions. Item 27 wasn't actually built —
  see above.
- Full backend suite green: 2266/2266, 6071 assertions. No migration — no
  schema touched. CI playwright: passes clean on this branch's final
  state (plain `next dev`, no `--webpack`) — verified via a real CI run,
  not just locally.

## 2026-09-28 — Fix: ADR-047 addendum — realtime reconcile-on-resume safety net + two resubscribe races + broadcast-queue reliability (`fix/2026-09-28-reverb-reconcile-gaps`)

- A founder question ("does everything really need a hard refresh?")
  turned into a full audit of every Reverb/Echo polling-and-push consumer
  built across ADR-047's own decisions and both prior addenda, not just
  the one reported symptom (a customer's track-order page stuck on
  "Failed" after an admin resend later delivered it). Grilled with the
  founder (`/mattpocock-skills:grilling`) before any code touched — full
  record in `docs/adr.md`'s new 2026-09-28 ADR-047 addendum.
- **Root cause, one thing, not four:** no consumer of a push channel ever
  had a designated moment to reconcile against REST truth once its first
  subscription was live — so a missed delivery (a resubscribe race, a
  backgrounded mobile tab whose WebSocket died silently, or the broadcast
  job itself failing server-side) had no recovery path short of a hard
  refresh.
- **New `useReconcileOnResume(callback)` hook**, duplicated as a small
  file in both `storefront/src/lib/` and `admin/src/lib/` (two separate
  Next.js apps, no shared package today) — fires `callback` on
  `visibilitychange` turning visible and on Echo's connection reaching
  `connected` again. Wired into all four converted screens (storefront
  `OrderStatusTracker`, admin `price-sync`/`backups`/`orders`) as a pure
  addition; no existing poll/push logic touched.
- **Two real resubscribe races found and fixed at the root**, not just
  narrowed: `admin/src/app/middleware/price-sync/page.tsx`'s run-status
  channel effect depended on `run?.status`, so it fully
  `leave()`+resubscribed the private channel on every status tick
  (including the tick the listener itself had just delivered) — a
  fast-failing sync could flip `running→failed` inside that resubscribe's
  own auth-round-trip window and land nowhere, sticking the card on
  "running" forever (polling was already dropped here, per decision 4).
  `admin/src/app/admin/orders/page.tsx`'s `admin-orders` channel effect
  had the same shape, keyed to `orderFilters` instead. Both now subscribe
  once per stable id (`run?.id`, or just `session`) and read the value
  they used to depend on via a `useRef`, matching `backups`'/the
  originally-shipped `admin-orders`' own "a subscription costs nothing
  while idle" pattern.
- **Storefront's terminal-state gap deliberately does NOT get its poll
  loop revived.** Considered reintroducing a client-side "is this order
  still resendable" check and rejected it — it would duplicate
  `Order::isAlreadyCompensated()`-style business logic the backend
  already owns, and resurrect the always-polling pattern ADR-047 decision
  4 moved away from for settled orders. The reconcile hook above is
  judged sufficient on its own.
- **Broadcast-queue reliability, backend:** `config/horizon.php`'s
  `supervisor-price-sync`/`supervisor-backups` both ran `tries: 1` —
  correct for the queue's own job (`SyncSupplierPricesJob`/
  `RunDatabaseBackupJob` each pin their own `public $tries = 1` at the
  class level, confirmed unaffected by this change), but the generic
  `Illuminate\Broadcasting\BroadcastEvent` job sharing that queue
  inherited the same one-shot limit with no override of its own — a
  transient Reverb hiccup at broadcast time was a silent, permanent drop,
  with zero log line anywhere. Bumped both to `tries: 3` (matching
  `supervisor-orders`) after confirming no other job shares either queue.
  New `App\Listeners\Broadcasting\LogFailedBroadcastJob` (registered on
  `Illuminate\Queue\Events\JobFailed` in `AppServiceProvider::boot()`)
  logs when a `BroadcastEvent` job exhausts its retries on
  `orders`/`price-sync`/`backups` — visibility only, Horizon's own
  failed-jobs UI already has the full payload.
- No new ADR number — this is an addendum to ADR-047 (a refinement of its
  own decisions, not a new decision axis), per this repo's own
  build-on-an-existing-ADR convention.
- New tests: `LogFailedBroadcastJobTest` (3 cases — logs a `BroadcastEvent`
  failure on a broadcast queue, ignores a non-broadcast job on the same
  queue, ignores a `BroadcastEvent` failure on an unrelated queue). Full
  backend suite green: 2269/2269, 6074 assertions. `tsc --noEmit`/
  `eslint`/`next build` clean on both `storefront/` and `admin/`. No
  migration — no schema touched.
- **Live verification still owed by the founder** — no admin/customer
  session available to this agent to watch a real WebSocket
  drop-and-recover in a browser. See the ADR-047 addendum's own
  Consequence-to-track for the three specific things to confirm on a real
  deploy (backgrounded-tab resume, a fast-finishing Price Sync run, the
  new failed-broadcast log line actually appearing).

## 2026-09-28 — ADR-083 2026-09-28 addendum built: `/admin/accounting` Funding History page, Transaction Register pagination + void/adjustment visibility fix, CHIP cross-window date view, Supplier Transfer "Edit Details" (`feature/2026-09-28-adr083-accounting-ui`)

Founder walkthrough of `/admin/accounting` surfaced four gaps, grilled together
(`/mattpocock-skills:grilling`, 3 rounds) at the founder's own request — full
design in `docs/adr.md`'s ADR-083 2026-09-28 addendum, this entry is the build.

- **Real bug found and fixed, not just a missing feature:** `voidTransfer()`
  reversed only a transfer's *original* net, never its *current cumulative*
  net (original + prior `MANUAL_ADJUSTMENT`s) — an Adjust-then-Void sequence
  left a silent residual permanently stuck in the supplier's ledger balance.
  Checked against real production data before deciding on a backfill
  (read-only, `pekangame-prod-lwf`): 2 voided transfers existed, neither had
  a prior adjustment — never actually manifested, so no backfill, code fix
  only. Live-verified post-fix in a real browser session: recorded a
  transfer, Adjusted +50,000 IDR, Voided it — ledger balance landed at
  exactly IDR 0.
- New `SupplierLedgerEntryType::VoidReversal` distinguishes a full-void
  reversal from a partial `MANUAL_ADJUSTMENT` in the ledger/register/CSV —
  zero-migration (`type` is a plain `string` column). Both
  `recordManualAdjustment()`/`voidTransfer()` now `lockForUpdate()` the
  transfer row inside their transaction (cheap race insurance, not a full
  subprocess-concurrency test — out of proportion for single-admin usage).
- **Transaction Register correctness fix:** a voided `SupplierTransfer` row
  used to show its full original outflow with no indication it was voided;
  `MANUAL_ADJUSTMENT`/`VOID_REVERSAL` entries were never projected into the
  register at all, so a correction was invisible to the CSV export the
  year-end professional works from. Fixed: a voided row keeps its real
  original figures (tagged VOIDED, never rewritten to zero — real
  double-entry practice reverses, it doesn't edit history), and every
  correction is now its own `supplier_adjustment` row dated at its own
  `created_at`.
- **Real backend pagination** on the Transaction Register (`rows()` used to
  pull every matching row with no `LIMIT`) — a documented, deliberate
  fetch-then-sort-in-PHP-then-slice approach (not a SQL `UNION`), verified
  against real production volume first (~36 total rows today); flagged in
  code to revisit once paid-order volume nears ~10k rows.
- **New Funding History page** (`/admin/accounting/suppliers/{id}/transfers`)
  — `SupplierFundingService::transfers()`'s pagination existed since PR-1 but
  was never wired to any UI; `SupplierTransferModal` narrowed to just the
  record-transfer form + balance card, linking out to the new page.
- **New "Edit Details" correction action**, metadata-only
  (`amount_myr_sent`/`fee_myr`/`source_channel`/`reference_no`/receipt) —
  confirmed these have zero computed relationship to the FX ledger side
  before building this (`amount_foreign_received` is independently
  hand-typed off the same receipt, never derived from `amount_myr_sent`).
  New append-only `supplier_transfer_corrections` table (model-enforced,
  same `booted()` pattern as `SupplierLedgerEntry`), one row per edit
  action with a JSON diff. `amount_foreign_received`/`supplier_fee` stay
  Adjust/Void-only — those two, and only those two, feed the ledger amount
  directly.
- **CHIP Settlements cross-window date view** — new "View by: Upload Window
  | Date Range" toggle queries `chip_settled_transactions.settled_on`
  directly across every `payment_settlement_id`; no new table, no
  double-count risk (ADR-110's unique `transaction_id` already covers it).
- Built via two sequential forked sessions (backend, then frontend), each
  independently verified by the coordinating session rather than trusted
  blind: backend — full suite **2291/2291** green (was 2270, +21 tests),
  `php artisan migrate` applied to local dev DB, route-collision ordering
  for `/settlements/transactions` vs `/settlements/{settlement}` confirmed
  correct. Frontend — `tsc --noEmit`/`eslint`/`next build` all clean
  (re-run and confirmed directly, not just trusted from the build report);
  real browser session against local dev servers additionally confirmed
  the narrowed modal, the new page's filters/inline corrections, the
  register's void tag + separate correction rows, and the CHIP date-range
  toggle against real local data.
- Not yet committed/pushed as of this entry — working tree on
  `feature/2026-09-28-adr083-accounting-ui` (branched off `staging`).

## 2026-09-28 — Envelope Ledger + Transaction Register/System Health completeness (re-grilling ADR-083 decision 11, `feature/2026-09-28-adr083-envelope-ledger-and-register-gaps`)

Founder re-challenged ADR-083's original "the platform does not model
equity, capital or drawings" call — not over cost, but because an
auditor-ready complete money trail (director capital, OPEX, marketing,
dividends) was the actual unmet need, and a hands-on Bukku free-trial
session showed most of that product's surface (inventory, fixed assets,
SST, 50+ reports) doesn't apply to this business. Grilled at length
(`/mattpocock-skills:grilling`) — see `docs/adr.md`'s second 2026-09-28
ADR-083 addendum for the full decision record. Three things shipped:

1. **Envelope Ledger** (`/admin/accounting/envelopes`, new sidebar item)
   — `budget_envelopes`/`budget_envelope_entries`, append-only (model-layer
   enforced, mirrors `SupplierLedgerEntry`), 4 starter envelopes seeded
   (Capital Rolling, Marketing Budget, Maintenance/Operations, Company
   Savings). Deliberately **not** a double-entry engine — no balance
   sheet, no trial balance. "Beginner friendly" by explicit founder
   request: entries take a positive magnitude, the category's own
   `typicalSign()` supplies the sign (`Adjustment` is the one category
   needing an explicit direction). Corrections are void-by-reversal only
   (a new negated entry, `reverses_entry_id` back-reference) — the
   original never edited/deleted, balance is a plain `SUM(amount_sen)`.
   "Allocate Monthly Profit" shows the real current-month Monthly Summary
   lines as reference context (reused from `MonthlyAccountingSummaryService`,
   never re-derived into a single "net profit" figure — that formula was
   never grilled/pinned) and lets the founder type a manual split per
   envelope. Receipts reuse the existing private-disk pattern; an
   AI/OCR "Digital Shoebox" was requested mid-grill then withdrawn once
   re-flagged as the exact prompt-injection risk ADR-083's original
   decision 11 already rejected once.
2. **Transaction Register — 3 missing row types.** `membershipRows()`,
   `walletTopupRows()` (`status=Paid` only), `withdrawalRows()`
   (`status=Completed` only, dated at `processed_at`) added to
   `TransactionRegisterService`, closing a real "nothing is ever lost"
   gap — membership fees and wallet top-ups were already matched by CHIP
   Settlements and summed in the Monthly Summary, but never had their own
   row in the one screen meant to be the complete, downloadable record.
   No backfill needed — the register computes live from a date-range
   query, so historical rows in `MembershipFeeRecord`/`WalletTopupAttempt`/
   `Withdrawal` (all pre-existing tables) show up correctly the first time
   a past period is viewed/exported.
3. **System Health — reseller-wallet-liability vs. supplier-balance.**
   `DashboardService::health()` gained `reseller_wallet_liability_sen`
   (summed across every reseller's wallet ledger) alongside the existing
   per-supplier balances, for a real treasury risk the founder flagged: a
   reseller's wallet top-up credits their spendable balance immediately,
   but the CHIP cash behind it settles T+1/T+2 — if spent immediately,
   the live supplier balance can be drawn down ahead of the cash meant to
   replenish it. Side-by-side numbers only, no alert threshold yet
   (agreed to wait for real reseller volume before guessing a buffer %).

**Also decided, not built:** a founder proposal to fully redesign the
existing 4 `/admin/accounting` screens (to "sync like Bukku") was
re-challenged before any code was touched — `AppSidebar.tsx` already
groups all 4 under one always-visible "Accounting" section, and the
Monthly Summary already derives from the same underlying ledger data the
other screens write. No real interconnection gap existed; the perceived
one was a surface comparison against a mature competitor product. The
Envelope Ledger was added as a 5th item in the same sidebar group
instead of touching the 4 already-tested screens. LHDN e-Invoicing/
MyInvois submission was deliberately kept out of scope — a real,
separate compliance requirement (already legally live for this
company's revenue tier as of Jan 2026, confirmed via research) gated on
actual commercial launch, still zero external customers as of this
session.

- Backend: full suite **2308/2308** green (was 2291, +17 tests — 3
  register-gap, 1 dashboard-liability, 13 Envelope Ledger),
  `php artisan migrate` applied to local dev DB.
- Frontend: `tsc --noEmit`/`eslint`/`next build` all clean.
- **Real-browser-verified** against the real local dev DB (Herd-served
  backend + `npm run dev` admin): recorded a Capital Injection entry,
  voided it (balance netted back to exactly RM0, original entry visible
  and struck through), ran a real Allocate Monthly Profit against real
  September 2026 Monthly Summary figures, downloaded and inspected a real
  CSV export. **One real bug caught by this live pass** (not the test
  suite): `handleAllocate()` refreshed envelope balances but not the
  selected envelope's own entries list — a freshly-allocated entry was
  invisible until a manual reload. Fixed and re-verified live in the
  same session. All test data cleaned up and the temporarily-reset local
  `test@example.com` admin password restored to the `password` default
  afterward.
- A first draft leaked the Malay working name ("Peruntukan Untung
  Bulanan") into the visible "Allocate Monthly Profit" button label and
  several doc comments — caught and fixed same session; the admin panel
  stays English throughout, matching the rest of `/admin`.
- **Merged same day** — PR #304 to `staging`, then released `staging`→`main`
  (PR #305, bundled with 4 other already-staging PRs: #298/#300/#302/#303)
  by the founder's own merge.

## 2026-09-28 — Envelope Ledger clarity fixes: rename/archive, Money In/Out badge, allocate safety warning, register type-label gap, CSV void-status column, Monthly Summary tooltips (`fix/2026-09-28-envelope-ledger-clarity-fixes`)

Five real gaps, every one surfaced by the founder actually using the
Envelope Ledger + Monthly Summary the day they went live in production
— not speculative polish. See `docs/adr.md`'s second 2026-09-28 ADR-083
addendum (decisions 2/4/7/10/11) for the full design record.

1. **Rename and archive an envelope** — there was no way to fix a
   mistakenly-named envelope or retire one; only creation existed. New
   `budget_envelopes.is_active` column (same convention `Reseller`/
   `Affiliate` already use) + `PATCH /accounting/envelopes/{id}`.
   Deliberately never a hard delete — `budget_envelope_entries
   .budget_envelope_id` is `restrictOnDelete()`, so a used envelope
   could never be deleted anyway; archiving hides it from the active
   grid while its full history stays visible/exportable.
2. **Money In / Money Out badge for every category** — previously only
   the "Adjustment" category (the one with an explicit direction
   selector) showed which way an entry would move the balance; every
   other category's sign was invisible until after submitting. Now
   every category shows a green "Money in" / red "Money out" badge
   right under the dropdown, computed from the same `typical_sign`
   the backend already returns.
3. **Allocate Monthly Profit had zero validation against real profit**
   — founder could type any amount into any envelope, no warning. New
   `current_month_rough_pl_estimate_sen` (backend, sums only the
   P&L-shaped Monthly Summary lines) + a running "total being
   allocated" display + a soft amber warning (never a hard block) when
   the total exceeds that rough, explicitly-unaudited estimate.
4. **Transaction Register frontend never learned the 3 new row types**
   — the backend's `membershipRows()`/`walletTopupRows()`/
   `withdrawalRows()` (built earlier the same day) each carry their own
   `type`, but the frontend's `typeLabel`/`typeSeverity` maps and the
   `TransactionRegisterRow["type"]` union were never updated. Live in
   production, these rows would have rendered a blank/undefined type
   badge and been unreachable from the type filter. Found by the
   founder asking "don't these need their own status too?" — fixed
   same session.
5. **Envelope Ledger CSV export had no explicit void-status column** —
   founder specifically asked whether this could repeat the old
   Supplier Funding bug (UI showed void, register export silently
   didn't). It structurally can't (a void is just another row in the
   same append-only table, never a flag the export could forget to
   check) — verified with a real download during this session showing
   both the original and its reversal — but added a `Status`
   (Active/Voided/Void reversal) column anyway for explicit per-row
   clarity, computed without a date-range restriction so it stays
   correct even when a void falls outside the export's own filter.
6. **Monthly Summary was unclear even to the founder** — couldn't tell
   what several lines meant, and couldn't find "profit" on the page at
   all (by design: the 8 lines mix real P&L with capital-movement/
   liability lines, so there was never a single profit figure there).
   Added a plain-language `InfoTooltip` per line (the same component
   ADR-045 established on the Dashboard) + a banner redirecting to
   `/admin/reports`'s "Profit Analysis" tab (confirmed by reading
   `ReportService` directly that this is where real `platform_profit`/
   margin actually live). **Found and fixed a real, separate bug along
   the way**: `InfoTooltip`'s popover was centered under its icon,
   which clipped its first few words off-screen for an icon near a
   container's left edge (the "COGS" row) — re-anchored to grow
   rightward from the icon instead, fixed for every page that shares
   the component, not just this one.
- Backend: full suite **2313/2313** green (was 2308, +5 tests: rename,
  duplicate-name rejection, archive/reactivate + history survival, the
  rough-P&L-estimate field, the CSV void-status regression test).
  `php artisan migrate` applied to local dev DB (`is_active` column).
- Frontend: `tsc --noEmit`/`eslint`/`next build` all clean.
- **Real-browser-verified twice** (Herd-served backend + `npm run dev`
  admin): renamed "Capital Rolling" to a test name and back, archived
  and reactivated it (confirmed it dropped out of/back into the active
  grid, "Show archived (N)" toggle, history/balance survived
  untouched), confirmed the Money In/red-green badges for both a
  negative (`OPEX — Rent`) and positive (`Capital Injection`) category,
  typed RM500 into an Allocate field against a real RM73.44 rough
  estimate and confirmed the amber warning text and figures matched
  exactly, confirmed the `InfoTooltip` fix on Monthly Summary renders
  the full un-clipped definition, and confirmed the "Reports →" link
  lands on a real "Owner Profit" figure. All test data cleaned up and
  the temporarily-reset local `test@example.com` admin password
  restored to the `password` default after verification.
- **Same-day addendum, same branch — category list trimmed after direct
  founder pushback** ("Rent tak perlu sebab topup store mana ada
  office"): removed `OpexRent`/`OpexBankCharges` (no confirmed real
  expense — generic textbook picks, cheap to re-add if either ever
  becomes real), kept `OpexSalary`/`OpexProfessionalFees` despite
  neither in use yet (founder's own call — both map to a real,
  already-planned future expense), and `Adjustment` no longer appears
  in the Record Entry picker at all (it's the void mechanism's own
  internal tag). Backend **2314/2314** green (+1 test). No migration —
  a plain PHP enum, never a DB-level constraint.
- Not yet committed/pushed as of this entry — working tree on
  `fix/2026-09-28-envelope-ledger-clarity-fixes` (branched off
  `staging`).

## 2026-09-28 — New `/admin/balance` page — supplier + Reseller wallet visibility (`feature/2026-09-28-balance-overview`)

Founder asked for a dedicated "Balance" screen after a prior session's
Reseller-wallet-vs-our-wallet liability comparison: current Digiflazz
(supplier) balance with a live refresh, plus every Reseller's prepaid
wallet balance (total + per-row). Framed as Part A of a two-part
request — Part B (a forecast/learned "comfortable buffer" amount,
factoring in bank/Airwallex top-up lag) is deliberately parked for its
own grilling session, not built here.

1. **Zero backend changes — pure composition of already-shipped
   endpoints.** Supplier balance + refresh reuse ADR-046 decision 8's
   `POST /middleware/suppliers/{id}/refresh-balance` (the same action
   `/middleware/suppliers`' own "Refresh Balance" button already calls
   — same DB row, so both screens stay in sync, just not live-pushed
   across open tabs). Reseller wallets reuse `listResellers()`
   (ADR-073, already unpaginated, ledger-derived via `LedgerService`)
   — the total is a client-side sum, and the per-reseller table
   deliberately has no pagination, matching `/admin/resellers`'s own
   existing (unpaginated) convention; Reseller accounts are a small
   B2B/wholesale set, not a consumer table expected to grow into the
   hundreds.
2. **Deliberately no cross-currency total.** Supplier balance is in
   its own currency (Digiflazz = IDR); Reseller wallets are MYR sen.
   Shown as two separate tables, never summed against each other —
   flagged during the build's own planning pass as a real bug shape to
   avoid, not discovered live.
3. **Real bug found + fixed during live verification**: the Refresh
   button correctly hit the real endpoint (confirmed via network tab,
   `last_tested_at` bumped), but a failed check (local dev's Digiflazz
   credentials — `[41] Signature Anda salah`) left the page showing no
   indication anything had failed; `balance` just stayed "—" exactly
   as before, indistinguishable from "never checked." Added
   `last_test_result` failure text (red, under "Last checked") —
   turns out this matches a pattern `/middleware/suppliers` already
   had, not a new one invented here.
4. **Nav placement corrected by the founder mid-build**: shipped first
   nested under Accounting (`/admin/accounting/balance`), then moved
   to a standalone Commerce-level item (`/admin/balance`, wallet icon)
   on the founder's own reasoning — Part B will grow this into a
   broader operational-monitoring surface, not a bookkeeping screen,
   so it shouldn't read as an Accounting sub-page. Route path moved to
   match, not just the nav entry.
5. **No new ADR** — this is UI composition over already-decided
   concepts (ADR-046 balance check, ADR-073 wallet ledger), not a new
   architectural decision.
6. **Found while answering the founder's own question, relevant to
   Part B's eventual grill**: `Supplier.balance` already auto-polls
   daily (`app:refresh-supplier-balances`, `routes/console.php`,
   ADR-069 decision 12) — not just on manual click. That same command
   already warns (`Log::warning`, no UI surface) when a balance drops
   below a per-supplier `api_config['low_balance_threshold']`, and
   separately checks supplier-funding-ledger drift (ADR-083 decision
   6). An order does **not** trigger a balance check — the local
   `balance` column is a polled snapshot, never locally decremented
   per order. Part B's starting point is therefore "surface an
   existing static threshold + silent log warning," not a blank slate.
- Frontend: `tsc --noEmit`/`eslint`/`next build` all clean (admin).
- **Real-browser-verified**: logged in as `test@example.com`, full
  round-trip on both tables, Refresh confirmed via
  `read_network_requests` (200 on `refresh-balance`), failure-surfacing
  fix confirmed live after the real local-dev credential failure above,
  nav placement + route move reconfirmed after the mid-build change.

## 2026-09-28 — Part B grilled: Supplier balance comfortable-buffer forecast, `ADR-115` (design only, build parked)

Same-day follow-on from the Balance page above (Part A). The founder's
original ask covered a broader "Part B": a mechanism that learns a
comfortable Digiflazz top-up buffer from real spending patterns across
all three order channels, factoring in the 1-2 day bank/Airwallex
top-up lag — explicitly split off Part A and grilled separately
(`/mattpocock-skills:grilling`, 3 rounds) rather than built inline.

- A fact-check fork ran in parallel with round 1, confirming: (a)
  `supplier_ledger_entries` (ADR-083) already gives a clean per-order
  drawdown data source in native currency, one join away from a daily
  time series (net `REFUND` against `ORDER_DRAWDOWN`); (b) Gamevion's
  launch gate is confirmed retired (`docs/prd.md` §16, 2026-09-24) —
  scope narrows to Digiflazz alone; (c) `Supplier::fundingDrift()` is a
  point-in-time check with no history to extend; (d)
  `DashboardService::rollingWindowUtc()` (DASH-3) is a reusable
  trailing-window pattern already in the codebase.
- Real domain input from the founder reshaped the formula mid-grill:
  MLBB in-game events cluster Friday afternoons at meaningfully higher
  volume than an ordinary day. A naive "average daily spend ×
  lead-time days" formula would underestimate whenever a top-up lands
  right after one of those days. Reframed to a **rolling
  lead-time-window-sum percentile** instead — sum spend inside every
  historical window the length of the lead time (2 days), take a high
  percentile (p90) of those window-sums — which captures the Friday
  pattern automatically (any historical 2-day window spanning a
  Friday is already inside the percentiled distribution) with no
  day-of-week special-casing or hardcoded event calendar.
- Full decision list (blended single buffer across all 3 channels, 2-day
  lead time as one global config value, IDR+MYR shown side by side
  rather than one converted figure, cold-start falls back to the
  founder's own manual threshold, info-only output surfaced on the
  Balance page + a Dashboard chip mirroring the existing funding-drift
  chip, no external push channel) recorded in full as **ADR-115**.
- **Deliberately parked, not built**: zero real external customers
  exist yet (every order to date is the founder's own testing) — a
  model trained on that data now would learn testing noise, not real
  demand. No automatic build trigger; the founder will say when,
  typically once real order volume exists. `docs/prd.md` §16 item 28
  added, pointing at the ADR.
- No code changes this entry — design/docs only. Branch
  `docs/2026-09-28-adr-balance-buffer-forecast` off `staging`.

## 2026-09-29 — Full system audit (money/security/burst, all channels) → Wave 1 of 5 built: 4 critical/high fulfillment bugs fixed (M-1 through M-4)

Founder asked for a fresh, from-scratch read-only audit of the whole
system — money flow, security, and burst stability, across every
channel (direct storefront, affiliate whitelabel, reseller portal/API/
bot). Five scopes ran as parallel background agents (checkout→
fulfillment, wallets/ledgers, security, burst stability, cross-channel
consistency), each explicitly told to check `docs/adr.md`/`docs/prd.md`
§16 before reporting anything as new and to avoid re-litigating
previously-fixed items. Every Critical/High finding was then
independently re-verified against the real code by hand (not just
trusted from the agent reports) before being written up. Full report
published as a private artifact
(`https://claude.ai/artifact/8WNoEKiD6A4BDpq7op6g7F`, in Malay) —
28 findings total, grouped into 5 fix waves ordered by severity.
Nothing has caused real loss so far (no external customers yet — see
`docs/prd.md`'s live status), so this is prevention, not incident
response.

**Wave 1 (this branch, `fix/2026-09-29-fulfillment-critical-audit` off
`staging`) — the 2 Critical + 2 High findings that can duplicate or
strand a delivery under real-world supplier hiccups, all root-cause
bugfixes restoring an already-documented invariant rather than new
design decisions:**

- **M-2 (Critical) — reseller wallet top-up could be credited twice.**
  `ResellerWalletService::completeTopup()` checked "already paid?" on
  an unlocked, possibly-stale copy of the attempt — the CHIP webhook
  and `ReconcilePendingWalletTopupsCommand`'s 15-minute backstop could
  both observe `pending` and both credit. Proven with a real two-
  process concurrency test (one RM5,000 top-up became RM10,000 against
  the old code). Fixed: re-read under `lockForUpdate()` inside the
  transaction, re-check there. 22/22 concurrency suite green.
- **M-1 (Critical) — a supplier timeout on the first delivery attempt
  could deliver an order 2-3 times.** `OrderFulfillmentService::fulfill()`'s
  single-package path generated and persisted `reference_number`
  inside the SAME transaction as the actual supplier `createOrder()`
  call, with no try/catch anywhere. A thrown `ConnectionException`
  rolled the reference back too, so a job retry minted a brand-new one
  via `resolve(null)` — if the supplier had actually processed the
  interrupted attempt, the retry could submit and deliver a second
  time under the new reference, undetected by the supplier's own
  dedup-by-reference (the two references never matched). Split into
  the same two-phase shape `attemptLeg()` (combo legs) already uses:
  phase 1 commits Processing + the reference before the supplier call;
  phase 2 wraps the call in try/catch, routing any Throwable to
  NeedsReview while keeping the already-persisted reference, so a
  resend correctly reuses it. Corrects the 2026-09-15 ADR-094 addendum,
  which had claimed the single-order path "doesn't have this gap" —
  the actual behavior was the inverse. New **ADR-094 addendum**
  records the correction.
- **M-3 (High) — an ambiguous supplier response (circuit breaker open,
  a real 5xx, an unparseable response) was finalizing orders as a
  CONFIRMED Failed**, both at `createOrder()` time and in
  `SupplierDeliveryCheckService`'s scheduled poll — exactly the
  condition a real supplier outage produces, meaning an outage could
  wrongly close out every in-flight order as Failed, needing a manual
  Retry/Issue-Voucher for each one. Fixed at the source
  (`CircuitBreakingSupplierAdapter`, `DigiflazzAdapter`,
  `GamevionAdapter` now set `resendUnsafeWithSameReference: true` on
  every genuinely-ambiguous failure) plus in the poll path itself
  (`SupplierDeliveryCheckService` now only finalizes a CONFIRMED
  failure — anything else is left exactly as Pending for the next
  scheduled poll to retry automatically, no admin action needed for a
  transient blip). Found and fixed two pre-existing
  `CheckSupplierDeliveryJobTest` fixtures whose names claimed
  "confirms failure" but never actually set the confirming flag — true
  before this fix only because the flag was irrelevant to reaching
  Failed, which was exactly the bug.
- **M-4 (High) — a paid order could be left permanently stuck with no
  sweep and no admin visibility**, two related gaps: (a) a paid order
  stranded at `delivery_status=not_started` (every `FulfillOrderJob`
  attempt exhausted, or the job never ran) had no reconcile sweep at
  all — `ReconcilePendingDeliveriesCommand` gains
  `retryStuckNotStarted()`, mirroring the existing stuck-Processing
  sweep, safe now that M-1 means a repeat failure correctly routes to
  NeedsReview/Failed instead of silently repeating; (b)
  `ChipWebhookController` used to dispatch `FulfillOrderJob`
  unconditionally on ANY Paid event, including a late one arriving
  after reconcile had already marked the order Failed and restored its
  voucher — `fulfill()`'s own `isAlreadyCompensated()` guard then threw
  on every job retry, stranding the order at `payment_status=Paid`
  with `delivery_status` still Failed forever (the customer genuinely
  paid, received nothing, zero visibility). Now checks
  `isAlreadyCompensated()` before dispatching and flags NeedsReview
  instead when true.

**Verification:** every fix proven with a new test that fails against
the pre-fix code and passes against the fix (TDD red→green, not
retrofitted). Full fast suite **2324/2324** green after all four
fixes. Full concurrency suite (real MySQL, real subprocesses) run
after each fix individually — 22/22 green three times over (M-1, M-3,
M-4), confirming the existing "exactly one of two simultaneous
fulfillment attempts succeeds" guarantee survives the `fulfill()`
restructure (just with a shorter lock hold, no longer spanning the
HTTP call, rather than a longer one).

**Waves 2-5** (compensation/checkout races, security hardening, burst-
traffic prep, remaining money hygiene + customer notifications) not
yet built — tracked in the published audit artifact, to be picked up
in separate sessions per the project's normal grill-then-build
workflow. Wave 4 (burst prep) explicitly needs `K-4` (combo job
`retry_after` vs timeout mismatch) fixed BEFORE any `maxProcesses`
increase, to avoid turning a currently-harmless mismatch into a real
concurrent double-run.

## 2026-09-29 — Wave 2 of 5 built: compensation/checkout races (M-5, M-7, M-8, M-9); M-6 deferred pending a grill

Branch `fix/2026-09-29-wave2-compensation-races` off `staging`, continuing
the 2026-09-29 full-system-audit backlog (`docs/prd.md` §16 item 45). Four
of the five Wave 2 findings are root-cause bugfixes restoring an
already-documented invariant (same category as Wave 1); **M-6 is
deliberately NOT built here** — it revisits ADR-024's accepted rationale,
which needs a grill, not a mechanical fix.

- **M-5 — `refundToWallet()`/`storeFromOrder()` didn't re-check
  compensation state inside their own lock.** Both already took
  `Order::lockForUpdate()`, but `refundToWallet()` only checked
  `isAlreadyRefundedToWallet()` (not the full `isAlreadyCompensated()`,
  so a concurrent voucher-issue landing first was invisible to it), and
  both re-checked `delivery_status` on the pre-lock `$order`, not the
  locked row. Fixed by re-deriving every check from `$locked`, mirroring
  `OrderFulfillmentService::fulfill()`'s own defense-in-depth pattern.
  One deliberate asymmetry: `storeFromOrder()`'s added check is
  `isAlreadyRefundedToWallet()` only, NOT the full `isAlreadyCompensated()`
  — the existing `test_restore_only_action_is_idempotent_on_a_repeated_click`
  test caught this immediately when the full check was tried first:
  `isVoucherRestored()` being true is that action's own expected
  idempotency marker on a repeat restore-only click (ADR-024's
  2026-09-17 addendum), not a race to block. Good example of a test
  surfacing a real design distinction rather than just a typo.
- **M-7 — `CheckoutService::resume()` had no lock, so two concurrent
  calls for the same Order could both call CHIP's `createPayment()`.**
  Whichever `payment_ref` `update()` landed last silently discarded the
  other's `payment_request_id`, orphaning a live, payable CHIP purchase
  that `ChipWebhookController`'s strict `payment_ref` match can never
  match back to an order. Fixed by wrapping `requestPayment()` (the
  method both `initiate()` and `resume()` funnel through) in
  `Order::lockForUpdate()`, checking `payment_ref !== null` before ever
  reaching the gateway. This deliberately holds the lock across the live
  gateway call — `initiate()`'s own doc comment argues against exactly
  that pattern, but for a different reason (never risk a rolled-back
  Order *INSERT* orphaning a CHIP purchase with zero matching Order
  row). Here the Order already exists and is already committed before
  `requestPayment()` is ever called, so a transaction failure mid-lock
  only reproduces the pre-existing "stuck at Pending, retryable" state,
  never a new failure mode — recorded as an inline comment rather than a
  full ADR addendum, same "root-cause bugfix" bar as Wave 1. Proven with
  a new `tests/Concurrency/CheckoutResumeConcurrencyTest.php` (two real
  OS processes racing `resume()` on the same order, a fake gateway
  counting its own call count into a shared file) — manually verified
  red (gateway called twice) against the unlocked code via `git stash`,
  green (called once) against the fix, 3x each to rule out scheduling
  luck.
- **M-8 — `ResellerCatalogService::resolveByCode()` had no `Game.is_active`
  check**, so a reseller (API/bot) could keep ordering a game the admin
  had deactivated even though it was already hidden everywhere else.
  One-line fix (`->where('is_active', true)`).
- **M-9 — `MembershipQuotaService` had no `restore()`.** Quota is spent
  at CHIP payment-link creation (`requestPayment()`/`settleWithVoucher()`),
  not at actual payment — so a failed or abandoned checkout never gave
  it back, permanently shorting a member's remaining quota for the rest
  of their cycle. Added `restore()`, mirroring `VoucherService::restore()`'s
  reserved/restored idempotency shape exactly: a new nullable
  `membership_quota_debits.restored_at` column (migration
  `2026_09_29_121358`, this table was previously insert-only) marks a
  debit settled so a webhook redelivery or the reconcile sweep catching
  the same abandoned order twice can't double-credit. Wired into both
  give-back triggers `VoucherService::restore()` already uses
  (`ChipWebhookController`'s terminal-Failed branch,
  `PaymentReconciliationService::markFailed()`'s stale-pending sweep).
  One extra guard beyond the voucher analogue, since vouchers have no
  equivalent mechanic: a debit from before the membership's current
  `cycle_started_at` (ADR-027's 30-day quota refill) is left alone,
  not credited — crediting a stale pre-cycle debit on top of an
  already-refilled balance would over-grant quota past the plan's cap.
  Covered by a dedicated test forcing that exact ordering.

**Verification:** every fix proven test-first (red→green). Full fast
suite **2332/2333** green at the time of the initial 4 commits — the one
failure (`SupplierControllerTest::test_update_merges_api_config_instead_of_replacing_it`)
was a pre-existing real-network timeout to `api.gamevion.com`, confirmed
present on unmodified `staging` too (not a regression from this Wave).
Full concurrency suite **23/23** green (22 from before Wave 1/2 + the new
`CheckoutResumeConcurrencyTest`). See the same-day addendum below — this
one CI failure was fixed once it started blocking the PR.

**Not built in this session:** M-6 (needs a grill, revisits ADR-024) and
Waves 3-5 (security hardening, burst-traffic prep, remaining money
hygiene) — still tracked in `docs/prd.md` §16 items 45-48.

### Same-day addendum: PR #311 CI fix + a backend-level wallet-order guard for Issue Voucher

Founder reviewed the Wave 2 summary and asked two things: (1) why PR
#311's `backend-tests` CI job was red, and (2) whether an order can even
reach `storeFromOrder()` (Issue Voucher) if it's reseller-wallet-owned,
since `refundToWallet()` is supposed to replace it entirely.

- **CI fix (unrelated to Wave 2's own scope, but blocking the PR):**
  `SupplierControllerTest::test_update_merges_api_config_instead_of_replacing_it`
  was the one test in that file that changes `api_config` without first
  binding a fake `supplier-adapter.gamevion` — every sibling test right
  below it does. `SupplierController::update()` probes the connection
  for real after any `api_config` change (ADR-069 decision 11), so this
  one test was quietly making a live HTTP call to `api.gamevion.com` on
  every run; it used to fail fast, now times out (10s) instead, both
  locally and on GitHub Actions' own runner — a genuine, deterministic
  test bug, not environment-specific flakiness as first assumed. Fixed
  by binding `$this->fakeAdapter(true)` like every sibling test.
- **New guard, M-5 follow-up:** `Admin\VoucherController::storeFromOrder()`
  had zero backend-level check against a wallet-owned order — ADR-073
  decision 7's "refundToWallet() replaces Issue Voucher entirely for a
  wallet order" was only enforced by the admin UI never showing the
  button, never by the endpoint. Added a `wallet_reseller_id !== null`
  guard rejecting the call outright. This made the in-lock
  `isAlreadyRefundedToWallet()` re-check added earlier in this same PR
  unreachable (that field is set once at order creation, never after,
  so any order still reaching the transaction is guaranteed non-wallet)
  — removed it rather than ship dead defense-in-depth code.
- Full fast suite **2334/2334** green after both fixes. Pint clean on
  every file touched.

## 2026-09-29 — M-6 built: full-voucher-cover checkout race (ADR-024 addendum), grilled with the founder

Last remaining Wave 2 finding (`docs/prd.md` §16 item 45). Grilled via
`/mattpocock-skills:grilling` before any code changed — 4 decisions,
founder deferred the technical two (reorder-vs-detect-after, scope lock)
to the model's judgment, confirmed the other two (visible Failed order,
quota-decrement timing) directly. Branch
`fix/2026-09-29-wave2-m6-full-cover-voucher-race` off `staging`.

The bug: a full-voucher-cover order (`voucher.remaining >= sellingPrice`)
used to be stamped `payment_status=Paid` and dispatched to
`FulfillOrderJob` at `Order::create()` time — *before*
`VoucherService::redeem()` (the real, row-locked serialization point)
ever ran. The 2026-08-13 addendum's "accepted residual race" (log and
proceed regardless) was written for the *partial*-cover path, where real
money had already moved by the time `redeem()` could lose — that
premise was silently also being relied on for full-cover, where it's
false (zero cash ever moves, `payment_ref` stays permanently null). N
concurrent checkout requests from the same customer citing one
exactly-covering voucher could each pass the unlocked `preview()`, each
get created `Paid`, and each get fulfilled — only the first `redeem()`
call actually spent the voucher; every other "winner" shipped real goods
for free, repeatably.

Fix: `CheckoutService::initiate()` no longer special-cases a full-cover
order's `payment_status`/`paid_at` at creation — always `Pending`/`null`,
same starting state as partial-cover. `settleWithVoucher()` is now the
real commit checkpoint: `redeem()` succeeds → order flips to
`Paid`/`paid_at=now()`, membership quota decrements (moved here from
running unconditionally regardless of outcome), `FulfillOrderJob`
dispatches. `redeem()` throws `InvalidVoucherException` (lost the race)
→ order flips to `Failed`, nothing dispatched, no quota touched, customer
gets a generic `CheckoutFailedException` message (reusing the same
exception class/handling the partial-cover gateway-failure path already
has). The losing order stays visible in the DB as `Failed` — same
audit-trail discipline as every other failed checkout in this codebase,
not deleted or silently absent.

Couldn't deterministically simulate the lost-race branch via a fake
`VoucherService` in a fast feature test — it's `final`, and this
project's own convention avoids introducing an interface for a single
implementation just to make it mockable. Proven instead with a new
`tests/Concurrency/CheckoutSettleWithVoucherConcurrencyTest.php`, same
shape as every other money-critical race test in this codebase: two real
OS processes (`app:checkout-test-initiate-full-cover`, new test-only
command) racing `CheckoutService::initiate()` against the same
exactly-covering voucher. Manually verified red (both sides settled
`Paid`, voucher spent twice) against the pre-fix code via `git stash`,
green (exactly one `Paid`, one `Failed`, voucher spent once) against the
fix, 3x each way to rule out scheduling luck. One fixture gotcha found
mid-build: the winning side's `FulfillOrderJob` dispatch actually *runs*
inline if `QUEUE_CONNECTION` isn't overridden away from `sync` in the
subprocess env (this test has no real supplier fixture, so it blew up on
a missing `supplier_product_ref`) — set to `database` instead, so the
job queues but never executes (no worker running in this test), matching
what this test actually needs to prove.

Full fast suite **2334/2334** green, full concurrency suite **24/24**
green (23 before this + the new test). Pint clean. New ADR-024 addendum
(2026-09-29) records the 4 grilled decisions. `docs/prd.md` §16 item 45
updated — all 5 Wave 2 findings now built (M-5/M-7/M-8/M-9 merged via
PR #311, M-6 on its own branch, not yet merged as of this writing).

## 2026-09-29 — Wave 3 PR-A built: S-1 JSON-LD XSS, S-2 SqlGuard bypasses, S-3 webhook SSRF

The three Medium security findings from the 2026-09-28 audit
(`docs/prd.md` §16 item 46). Branch `fix/2026-09-29-wave3-security` off
`staging`. No grill needed: all three restore an invariant the code
already claimed to have. The S-3 shape was model-decided with the
founder's go-ahead and is recorded as an ADR-084 addendum. S-2 gets an
ADR-087 addendum because that ADR's "hard backstop" claim isn't true in
prod yet. The Low items (OTP, login throttle, impersonation, API
throttle key, ULID comment) are left for PR-B.

- **S-1** — `storefront/src/lib/seo.ts` `jsonLdHtml()` escapes `<` as
  `<` for all three JSON-LD `<script>` tags (Organization in
  `layout.tsx`, Product + Breadcrumb in `order/[slug]/page.tsx`).
  Storefront has no test runner, so the check was a node assert
  (no raw `<` in the output, and `JSON.parse` round-trips the value)
  plus tsc/lint.
- **S-2** — the audit named the comma join. While writing the red
  tests, six more bypasses turned up, all proven red: a comma join after
  a derived table, a parenthesised table, `STRAIGHT_JOIN` (`\bJOIN`
  never matches after `_`), a backtick with no space before it,
  `/**/` as the separator, and MySQL 8's `TABLE t` inside a subquery.
  All of these are now rejected outright rather than parsed. Comma joins
  are detected per paren depth, not by regex, so a derived table's own
  inner FROM doesn't confuse the outer one. Also confirmed:
  `config/database.php`'s `report_assistant` connection falls back to
  the main DB user when `REPORT_ASSISTANT_DB_USERNAME` is unset, so in
  prod this regex was the *only* defense. Provisioning that user is
  still owed by the founder.
- **S-3** — new `App\Support\OutboundUrlGuard`. It uses
  `FILTER_FLAG_GLOBAL_RANGE` on every A/AAAA record, and an unresolvable
  host is rejected. The URL is checked at save (a closure rule shared by
  both `Store*WebhookRequest`s) and again at send; a failure at send is
  terminal, with no retry. The send is pinned via `CURLOPT_RESOLVE`, and
  redirects are no longer followed. Gotcha: the red test proved Laravel's
  `Http::fake` *does* follow a faked 302 through Guzzle's redirect
  middleware, so the redirect test is a genuine red→green, not a
  tautology. `Http::fake` can't prove the curl pin, so it was verified
  with a real request instead (`example.com` pinned to `127.0.0.1`
  landed on localhost's TLS cert). Tests that touch webhook URLs call
  the new `TestCase::fakeOutboundDns()`, because `*.test` never
  resolves. Four test files needed it; one of them (`DispatchResellerOrderWebhookTest`)
  was only caught by the full-suite run.

Full fast suite **2357/2357** green. Concurrency suite not re-run: no
locking changed.

Also parked, mid-session (not security): affiliate custom-domain
onboarding copy → §16 item 49. Found via `fixfastapp.com`'s Vercel
"Proxy Detected" warning (its Cloudflare record is orange-cloud).

**Addendum, same day: prod `report_assistant` user provisioned.** It was
created over SSH on `pekangame-prod-lwf` via tinker on the main
connection, with a password generated on the server that never left it.
First attempt failed harmlessly: `CREATE USER ... IDENTIFIED BY ?` is
rejected because MySQL doesn't allow a bound placeholder there, and the
QueryException message echoed the generated password into the terminal.
That password was never used (no user created, `.env` untouched), so it
was discarded and regenerated. The retry passed the password through
`PDO::quote` and only ever printed a result code. Verification
(`CURRENT_USER`, row counts on the 3 views, 1142 denials, `SHOW GRANTS`)
is recorded in the ADR-087 addendum. Also found: the app's main
connection is `doadmin`, the DO superuser. This was added to §16 item 46
as a new finding, not fixed here.

## 2026-09-29 — Wave 3 PR-B built: Low security hardening (OTP, login lockout, Reseller API auth flood)

Branch `fix/2026-09-29-wave3-low-security` off `staging` (after PR #314
merged). Every item was red first, then green. Decisions are in the
ADR-019 addendum; the numbers were proposed by the model and accepted by
the founder.

- **OTP send:** 10/hour per IP added alongside 3/hour per email. The
  named limiter now returns two `Limit`s, with an explicit `email:`/`ip:`
  prefix on each key.
- **OTP attempt race:** `verify()` claims an attempt slot with a
  conditional `increment()` and consumes the code with a conditional
  `update()`, checking the affected row count each time. The red test
  simulates the concurrent request deterministically: a `retrieved` model
  event bumps `attempts` right after this request reads the row. A real
  2-process concurrency test would be overkill for a counter.
- **Login lockout:** `App\Support\AccountLoginThrottle` is shared by the
  admin and affiliate logins. Tests spread failures across
  `REMOTE_ADDR`s so the existing per-IP 5/min bucket doesn't trip first.
- **Reseller API:** failed auth is counted per IP in
  `EnsureResellerApiKey`. On the limit it throws Laravel's own
  `ThrottleRequestsException`, not `ResellerApiException::rateLimited()`,
  because only the `bootstrap/app.php` mapping keeps the `Retry-After`
  header that the public docs promise. Throwing the envelope directly
  would have dropped it.
- The ULID comment in `routes/api.php` (Track Order) was corrected. The
  one in `OrderFulfillmentService` refers to the reference suffix and is
  accurate, so it was left alone.

Full fast suite **2363/2363** green. Concurrency suite not re-run: no
DB-lock code changed. The OTP fix is a conditional UPDATE, and the red
test covers it.

Not in this PR: impersonation scope/attribution (needs a grill,
ADR-058), MFA (item 10), and the `doadmin` main-connection finding.

**Addendum, same day: Wave 3 closed out.** After explanation, the founder
decided (a) RES-4 impersonation stays unrestricted: won't-fix, recorded
as an ADR-058 addendum with a re-open trigger (a second
`super_admin`/staff user); and (b) the `doadmin` main-connection fix is
deferred to be bundled with decommissioning the old `pekangame-prod`
droplet and old DB (§16 item 46). Wave 3 has nothing left to build
except MFA, which was already tracked separately as item 10. Next up:
Wave 4 (item 47), where K-4 must land before K-1.

## 2026-09-29 — Wave 4 PR-A built: burst timeouts (K-4, K-3, K-2, K-6)

Branch `fix/2026-09-29-wave4-burst-timeouts`. Test-first where there was logic
(K-4, K-3, K-2 each red → green); K-6 is config only. Fast suite 2366/2366.

- **K-4 — combo job could run twice once `maxProcesses` > 1.** `orders-combo`
  jobs may run 300s while Redis `retry_after` was 90s. Fix: a second queue
  connection `redis-long` (same Redis keys, retry_after 330s) used only by
  `supervisor-orders-combo`. **Gotcha worth knowing:** `retry_after` is applied
  by the *popping worker's* connection (`RedisQueue::retrieveNextJob`), so
  producers keep dispatching on `redis` — `FulfillOrderJob` is unchanged.
  Verified live via tinker (`Laravel\Horizon\RedisQueue retryAfter=330`). New
  `HorizonQueueCoverageTest` case guards every supervisor, not just combo.
  Chose this over raising the global `retry_after` to 330s, which would have
  slowed crash recovery for every other queue to 5.5 min.
- **K-3 — timeouts never tripped the breaker.** Adapters don't catch
  `ConnectionException` (M-1 relies on it propagating), so `guarded()` never
  saw it. Now caught, `recordFailure()`, rethrown unchanged. Only
  `ConnectionException` counts — not any `Throwable` — so a code bug can't
  masquerade as a supplier outage. **Same-day correction after pulling prod
  data:** `listProducts` is excluded too. The heavy catalog pull timed out 109×
  on Digiflazz, clustered by `SyncSupplierPricesJob`'s own retries, which would
  have opened the breaker 5 times on 2026-09-06 alone. Each opening pauses real
  `createOrder` calls for 60s and pushes those orders to NeedsReview (M-3), even
  though the order path was fine.
- **Prod findings for the K-1/K-7 grill** are recorded in `prd.md` §16 item 47:
  Redis runs `noeviction` / AOF off, the box has ~1.5 GB free RAM, and Digiflazz
  `createOrder` p95 is 6.3s.
- **K-2 — MLBB player validation could hold a php-fpm worker ~32s.** Added an
  8s chain deadline in `MlbbPlayerValidator` (no new fallback started past it)
  plus `connectTimeout(3)` per provider HTTP call. An Acid timeout (8s) now ends
  the chain; a fast Acid failure still falls back once (~17s realistic worst).
  No cache: burst traffic is distinct players, so a cache would only help retries.
- **K-6 — Reverb/storefront timeouts.** The audit said "no timeout", but Laravel's
  `BroadcastManager::pusher()` actually defaults to 10s connect / 30s total. That
  is still too long on the shared `orders` worker, so it's now 2s/5s. Storefront
  `apiFetch` now gets `AbortSignal.timeout(20s)` unless the caller passes its
  own signal. 20s is set above K-2's ~17s worst case.

Next: grill K-1/K-5 (ADR-048 addendum, 2-lane proposal), K-7 SSH check, Lows.

## 2026-09-29 — Wave 4 PR-B built: order lanes + worker scaling (K-1, K-5, K-7, Lows)

Grilled with the founder. Recorded as the ADR-048 2026-09-29 addendum. Branch
`fix/2026-09-29-wave4-order-lanes`. Fast suite 2370/2370; concurrency 24/24.

- **K-1 — lanes, not one worker per channel.** `Order::orderLane()` sends a
  wallet order (portal/API/bot) to `orders-reseller`; retail keeps `orders`,
  so jobs already queued at deploy time still drain. `FulfillOrderJob`
  (non-combo), `CheckSupplierDeliveryJob` and `ResendOrderDeliveryJob` all
  read the lane from the order, so webhook, reconcile and admin-retry
  dispatches route themselves. `supervisor-orders` now covers both queues with
  `balance: auto`, min 1 per lane and max 4 (prod). Combo goes to 2.
  **Verified live on local Horizon:** a job pushed to each lane was popped
  within 1s by its own worker.
- **Notifications off the order lanes.** Bot replies, the bot order
  notification listener, the reseller webhook dispatcher and the
  `OrderStatusUpdated` broadcast all move to `default`. During a burst they no
  longer wait behind supplier calls.
- **K-5:** CHIP/Digiflazz/OpenWA webhook throttles raised from 120 to 600/min.
- **K-7 (ops, live):** prod Redis on the ADR-114 droplet had AOF **off** and
  `noeviction`. ADR-048 decision 2's AOF prerequisite never made it onto the
  new box. It is now `maxmemory 256mb` / `volatile-lru` / `appendonly yes` /
  `everysec`, set via `CONFIG SET` + `CONFIG REWRITE` (no restart). Port 6379
  confirmed filtered from the internet.
- **Lows:**
  - LongWait alert. **Gotcha:** Horizon's own mail notification uses `Mail::`,
    which is `log` in prod, so it could never reach anyone. The alert now goes
    through `BackupFailureAlerter`'s Plunk path, which gained an `$area` param,
    throttled to one alert per queue per 15 min.
  - `horizon:snapshot` scheduled every 5 min.
  - Dashboard "queue pending" read the `jobs` table that ADR-048 made unused, so
    it always showed 0. It now reads `Queue::size()` across all 3 order lanes.
- **PR-A side effect caught here:** Horizon's `waits` key is connection-scoped,
  so after K-4 moved combo to `redis-long`, `redis:orders-combo` silently
  stopped matching. Renamed to `redis-long:orders-combo`.
- **Founder-owed before the staging→main release:** run
  `sudo systemctl disable --now mysql` on `pekangame-prod-lwf`. The local
  mysqld (409 MB, 0 connections) is unused; the app runs on DO Managed MySQL.
  This frees RAM for the extra workers.

## 2026-09-29 — Pre-release review of staging (#306–#317): PR-C bugfixes

Before the staging→main release, the founder asked for a review of every wave
against the original audit artifact (Part A) plus a `/code-review high` of
`origin/main...staging` (Part B). The model re-verified all 10 review findings
directly in the code. 8 were real, #8 had no effect (prod has 0
`budget_envelope_entries` rows, so the removed enum cases break nothing), and #10
was a design trade-off. This PR covers the ones that needed no new decision.
Fast suite 2377/2377; concurrency 24/24.

- **#4 (M-6 follow-up):** a replayed idempotency key on a full-cover-by-voucher
  order (Pending mid-redeem, or Failed after losing the race) went through
  `resume()` to CHIP as a RM0 purchase. `requestPayment()` now never calls the
  gateway when `final_amount === 0`.
- **#6 (M-7 follow-up):** the CHIP purchase and the voucher/quota reservation
  shared one transaction, so an unexpected failure in the voucher lock (for
  example a lock-wait timeout) rolled back `payment_ref` and orphaned a live
  purchase. `payment_ref` now commits first; `reserveVoucherAndQuota()` runs
  after it, still at most once per order. The founder confirmed the ADR-024
  trade-off stays as-is: the voucher is locked once the CHIP purchase exists,
  and the webhook/reconcile paths auto-restore it on failure.
- **#5 (M-5 follow-up):** Issue Voucher's locked re-check used the pre-lock
  `$isPartialComboDelivery`. It is now re-derived on `$locked`.
- **#7 (S-3 follow-up):** a DNS failure at send time was treated like a
  non-public address, so the delivery was marked terminal and silently dropped.
  Added `OutboundUrlGuard::isUnresolvable()`: an unresolved host now takes the
  normal retry/backoff path.
- **#10 (Wave 3 PR-B follow-up):** the per-IP auth-failure lockout ran before
  the token check, so a valid key from a locked-out IP (a reseller's one stale
  worker, or a shared NAT) was also refused. The lockout now applies only to
  requests that fail auth.
- **Part A gap — Horizon liveness:** `/api/health` only checked DB + Redis.
  With Horizon down nothing fulfils orders, and the new LongWait alert dies
  with it. It now reports `checks.horizon` via `MasterSupervisorRepository`
  (only when the queue runs on Redis). Verified live: Horizon running gives
  `ok`, stopped gives `degraded`/503. **Founder-owed:** confirm an external
  uptime monitor is actually watching `/api/health`.
- **Held for PR-D (grill first):** #1/#2/#3/#9 — stuck-order recovery (late
  Paid after compensation, uncapped NotStarted sweep, Processing stranded by a
  killed worker, quota on late Paid) — plus auto-retry instead of NeedsReview
  for CIRCUIT_OPEN / same-`ref_id` Digiflazz resends, and the checkout
  10/min/IP limit behind mobile NAT.

## 2026-09-29 — PR-D built: automatic order recovery (pre-release review #1/#2/#3/#9 + NeedsReview load + checkout CGNAT)

Grilled with the founder (Q1–Q8, every recommendation accepted), recorded as
the ADR-102 2026-09-29 addendum plus an ADR-014 addendum. Everything was built
test-first. Fast suite 2390/2390; concurrency 24/24.

- **Replay-safe rule** (`SupplierAdapterFactory::resubmitReplaysOutcome()`,
  Digiflazz only). For Digiflazz, an ambiguous outcome is parked at **Pending**
  instead of NeedsReview. That covers a thrown timeout, `CIRCUIT_OPEN`, a
  5xx-class unconfirmed failure, and a stale Processing order. It applies to
  plain orders and combo legs alike, and the reference is kept.
  `scheduleRecoveryPoll()` then dispatches a `CheckSupplierDeliveryJob` about
  2 minutes later. Digiflazz's `checkStatus` *is* a same-`ref_id` re-submit,
  so this is what an admin's Retry did by hand. Gamevion stays NeedsReview
  because a repeated ref only returns `duplicate_reference`.
- **Audit trail:** `supplier_response.auto_recovery` on the order, or on the
  leg's attempt row.
- **Combo:** `attemptLeg()` now returns whether it parked the leg, so
  `fulfillCombo()` schedules the poll only for parked legs. Genuine async
  Pending legs keep their old behaviour.
- **Pending window:** a Pending order whose `updated_at` is older than 2h goes
  to NeedsReview (`pending_max_hours`, overridable per supplier). This uses
  `updated_at`, not `created_at`: a still-Pending or ambiguous poll never writes
  to the order, and a resend of an old order must not age out on arrival.
- **#1/#9:** any CHIP Paid arriving after the payment was marked Failed now
  goes to NeedsReview. `markNeedsReview()` accepts `NotStarted`. **Gotcha:**
  the M-4 test had used `delivery_status=Failed`, a shape a payment-failed
  order never reaches, which is why the real bug slipped past it. The new
  test uses `NotStarted`.
- **#2:** `FulfillOrderJob::failed()` moves a paid `NotStarted` order to
  NeedsReview, and the NotStarted sweep skips `is_test` orders.
- **#3:** `retryStuckProcessing()` no longer re-dispatches, because
  `startDelivery()` rejects `Processing`. A plain order on Digiflazz goes to
  Pending plus a poll; anything else goes to NeedsReview.
- **Checkout:** a named `checkout` limiter allows 10/min per IP+email with a
  60/min ceiling per IP. Validate-player and voucher-preview go to 30/min/IP.

## 2026-09-29 — Wave 5 PR-A: M-10 + money-hygiene Lows (PRD §16 item 48)

Branch `fix/2026-09-29-wave5-money-hygiene`. Every finding was re-verified
against code first, and all were still real. Fast suite 2403/2403; concurrency
24/24 on MySQL. The ledger and wallet tests also ran against MySQL, to prove the
virtual-column unique index fires there and not just on sqlite.

- **M-10:** the `voided_at` check moved from the controller (before the lock)
  into `SupplierFundingService::lockNotVoided()`, which re-reads under
  `lockForUpdate()`. It covers Void, Adjust and Edit Details. A test holding a
  stale model instance went red on the old code.
- **Late non-Paid CHIP event over a Paid order:** new
  `Order::setPaymentStatusUnlessPaid()`, a single conditional UPDATE. The webhook's
  non-Paid branch and reconcile's `markFailed()` both use it, and skip the
  voucher/quota give-back when the row is already Paid. Reconcile was the more
  likely path, because its gateway lookup is the slow gap.
- **Hidden game buyable:** `CheckoutController` `store()`/`previewTotal()` now
  reject a game the brand set `is_visible=false`, using the same rule as
  `CatalogController`.
- **Combo override below cost:** the FormRequest rejects it against the *live*
  component cost (`ComboPricingService::componentCost()`, not the stored
  `cost_price`, which only moves on recompute). When a later cost rise
  overtakes the override, `recompute()` falls back to the default sum and logs
  a warning. The override stays stored. The old test comment "below cost, admin's
  own deliberate call" was never an ADR decision, and `PricingService` refused
  such a sale anyway, so it could only ever 500 (ADR-094 addendum).
- **FX stale fallback:** a fallback rate is now cached for 15 min, not the full
  day. A stored rate older than 48h alerts the admins through the
  `BackupFailureAlerter` Plunk path, at most once a day per pair (ADR-033
  addendum).
- **Ledger DB backstop:** new virtual column `ledger_entries.dedupe_key` with a
  unique index. It is non-null only for `order_profit`/`wallet_debit`/
  `wallet_refund`/`wallet_topup`/`voucher_issued`, and `owner_id` is COALESCEd
  because Platform rows have NULL. `membership_fee` and `affiliate_tier_fee`
  are deliberately excluded because they repeat per cycle. **Gotcha:** a plain
  5-column unique index would exceed InnoDB's 3072-byte key limit, since there
  are three utf8mb4 varchar(255) columns (ADR-002 addendum).
- **Manual wallet credit idempotency:** new `ledger_entries.idempotency_key`
  column (unique). The admin modal mints one key per credit and rotates it only
  after a success, because the form stays open. A replay returns the first entry.
- **Deploy risk:** if prod already holds a duplicate in the five dedupe types,
  the migration fails on deploy. Run the duplicate check before merging to `main`.

## 2026-09-29 — Docs hygiene pass at session close

At the founder's request, so the next session starts from accurate docs:
- **`prd.md` (1,740 → ~930 lines):**
  - §14 rewritten as a current snapshot. It had said "2026-09-24" and "#288/#293–#295 not on main".
  - §16 rewritten to hold open items only, with every closed item reduced to a one-liner. Item numbers stay stable.
  - New items: 51 (founder smoke test of #320), 52 (`doadmin` + old-droplet decommission), 53 (parallel scheduler reconcile).
  - §15 stale rows fixed: Affiliate/Reseller "production release not claimed", "Gamevion + Digiflazz both live", Balance Part B "not yet grilled".
  - §10 wrong facts fixed: Digiflazz "Rp 0, must be funded"; Plunk "sends order emails" (it doesn't, M-11); Telegram notifications (not built, setting fields only).
- **`build-log.md` (3,079 → ~1,490 lines):** the old §14 table, the PrimeReact tracker and the 2026-09-01→09-22 entries moved verbatim to `build-log-archive.md`.
- **`AGENTS.md`:** references now point to the archive. **`backend/AGENTS.md`:** added the queue-lane and ambiguous-outcome conventions.
- **`adr.md` index:** 2026-09-29 addenda flagged on ADR-014/048/102. Stale "PR open" / "merged to staging" / "phases not built" wording removed (ADR-098/102/112).
