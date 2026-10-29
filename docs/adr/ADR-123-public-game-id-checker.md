# ADR-123: Public Game ID Checker — a storefront page that checks a player ID per game family, on its own endpoint, budget and table so it can never cost checkout its provider

> **Standing (2026-10-28):** In force. Build and live status: [`prd.md` §15](../prd.md). The text below is a dated record; a later addendum in this file overrides earlier text, including the **Status** line.

**Status:** Accepted, 2026-10-16. Grilled with the founder in seven rounds (`/mattpocock-skills:grilling`), then stress-tested against the code and production (read-only) before the build (PRD §16 item 107).

**Context:** Customers and searchers want to check a game ID (nickname, region) without opening an order page, the way redxgame.com/tools does. The SEO and GEO value is why Redx has one: a search for "check mlbb id" lands on a page that offers a top-up. The validators exist since release #469 ([ADR-005](./ADR-005-player-id-pre-payment-validation-is-not.md)'s three 2026-10-16 addenda): MLBB = RedxGame → MooGold, Free Fire and Genshin/HSR/ZZZ = RedxGame alone.

Facts from the stress test:

- `POST /api/games/{game}/validate-player` is already public: no captcha, `throttle:30,1`, no result cache, every call reaches Redx.
- Checkout and resend (`CheckoutController::assertPlayerIdIsValidated`, `OrderResendService::assertPlayerIdIsValidatedIfRequired`) require a `valid` `player_validations` row from the last 30 minutes. A provider failure is `region_unknown`, which blocks checkout. Free Fire and the zone games have no fallback provider, so **if Redx blocks our server IP, their checkout stops** (AcidGameShop died this way, ADR-005 2026-10-16 addendum).
- Production scale on 2026-10-16: 13 `player_validations` rows in total, at most 7 in a day. 18 validator-enabled stores over 5 families (`player_validator_profiles`: `mlbb`, `freefire`, `genshin`, `hsr`, `zzz`), each family uniform in input shape (MLBB ID + Server ID; FF ID; zone games ID + zone).
- `player_validations.game_id` is NOT NULL.
- `$request->ip()` is the real client: only Cloudflare's ranges are trusted proxies (`bootstrap/app.php`), so per-IP limits hold. `api.` is behind Cloudflare.
- The brand-hidden-game filter (`affiliate_game.is_visible = false`) exists twice already (`CatalogController::hiddenGameIds`, `CheckoutController`).

**Decision:**

1. **Its own endpoint.** `POST /api/player-check` (and `GET /api/player-check/families`), separate from `validate-player`, which is unchanged. The public page's traffic can't spend checkout's capacity.
2. **A game family is a validator profile.** The visitor picks a family (Mobile Legends, Free Fire, …), enters the ID, and is answered with the **store we sell for that account**. The profile gains `public_name`, `public_slug` (unique) and `public_image_url`, set by the admin on the validator profile screen (`/middleware`). A family is listed only when it has a slug, a public validator binding (decision 6), at least one active validator-enabled store visible on the serving brand, and one input shape across those stores; a family whose stores disagree on shape is hidden and logged.
3. **Input shape** comes from the family's stores: the ID format, plus a Server ID when the stores take `server_id`. A zone game asks for the UID only; the zone comes back from the check.
4. **What the result shows**, the same for every family, in our own words: the nickname and the store ("This account tops up at **Mobile Legends Malaysia**"), never the provider's raw country or server label (a Malaysian Free Fire account reports "Singapore") and never provider text. A zone game names the zone with our label (America, not Redx's "Americas"). States: `found` (with stores), `unsupported_region` (nickname, we don't sell that region), `unavailable_here` (the store exists but this brand hides it), `not_found`, `busy` (provider down, cooldown, or budget spent).
   - Store resolution: country → `player_region_mappings` (as checkout does); zone game → the family's stores whose `zone_options` contain the zone (as `ZoneCheck` does). Both filtered by `is_active` and the serving brand's visibility.
5. **"Top up now"** links to `/order/<slug>?player_id=…&server_id=…` (zone prefilled for a zone game). The order page fills the fields and **does not validate on load**; the visitor presses Validate as today. A public check never counts toward checkout's 30-minute window, so only the checkout path, with its own limits, approves an ID for payment, and a crawler following links costs no provider call.
6. **RedxGame only, never a supplier.** The public path resolves `player-validator.public.{key}`, bound explicitly for all five keys to a standalone `RedxGameValidator` (`statusFalseMeansInvalid: true`). MLBB's MooGold fallback is not used: MooGold is our supplier, and an abuse flag there would hurt fulfilment. A key without a public binding hides its family, so a future provider can't reach the public page by accident.
7. **Protection, in the order a request meets it:**
   1. **Per IP:** 5/minute and 30/hour (`throttle:player-check`).
   2. **Cloudflare Turnstile**, verified server-side (`siteverify`) before any provider work. **Fails closed:** without `TURNSTILE_SECRET_KEY` the endpoint answers 503 and the page says the checker is unavailable.
   3. **Input format** checked (`CheckoutInputValidator`) before any provider call.
   4. **Result cache:** 10 minutes in Redis per profile + player_id + server_id, for found / not-found results only (a provider failure is never cached). A cache hit spends no budget. Redis deletes the key at its TTL, so the cache holds at most the last 10 minutes of IDs.
   5. **Cooldown:** a provider failure on this path pauses public checks for 2 minutes (`busy`), so we stop calling Redx while it is struggling. Checkout is unaffected.
   6. **Global budget:** 200 provider calls per clock hour across every brand (`PLAYER_CHECK_HOURLY_BUDGET`). When spent, `busy`. The first time in a Malaysia day the budget is spent, every admin gets one email through `BackupFailureAlerter` (area "Game ID Checker"): either the page is popular (raise the budget) or it is being abused.
8. **Its own table, `public_player_checks`**, never `player_validations`. Columns: `player_validator_profile_id`, `player_id`, `server_id`, `status`, `nickname`, `matched_game_id` (nullable), `cache_hit`, `created_at`. One row per answered check (cache hits included, Turnstile/limit rejections not). It is for usage and abuse visibility only and no approval reads it, so a public check **can't** approve a payment by construction, not by a filter every reader must remember. Pruned on the same retention window as `player_validations` by the existing prune command.
9. **One brand-visibility helper.** `AffiliateGame::hiddenGameIdsFor(int $affiliateId)` replaces the two copies in `CatalogController` and `CheckoutController`; the checker uses it too.
10. **Pages and SEO.** `/check-id` (family picker) and `/check-id/<public_slug>` (family preselected), English like the rest of the storefront. Per family: title, meta description, "how to find your ID" steps and a small FAQ with `FAQPage` JSON-LD, written by us (redxgame.com/tools used only as a reference for which topics to cover). On the primary brand (`affiliates.is_primary`) the pages are in `sitemap.xml` and `llms.txt`. On an affiliate brand they work but carry `noindex` and are left out of its sitemap and `llms.txt`.
11. **Navigation.** Mobile bottom bar: [Home, Membership] · raised centre **More** · [Track Order, Support] (the centre slot was kept for re-targeting, per `BottomNav`'s own comment). More opens a bottom drawer with two groups: **Menu** (Game ID Checker, Price List when the brand has one) and **Info** (About Us, Terms, Privacy). Icon `SquaresFour`. Desktop header gains a "Game ID Checker" link; the footer links it too.

**Rationale:** The risk is traffic, not discovery. Every public check is a request from our server's IP to an unofficial provider that checkout depends on. A per-IP limit alone doesn't bound a bot that rotates IPs, so the hard bound is the global budget, Turnstile keeps that budget for humans, and the cache and cooldown keep the call count low. A separate endpoint, a Redx-only binding and a separate table make "the public page can't harm checkout or a supplier, and can't approve a payment" true by structure instead of by a filter or a flag every future change must remember. The page is cheap to run at today's scale (13 checks ever), so the protection is sized for the abuse case, not the expected one.

**Consequence to track:**

- The budget (200/hour) and cooldown (2 minutes) are first guesses. Read `public_player_checks` (count per hour, cache-hit share) after two weeks and tune.
- Redx can still block our IP for reasons outside this page. If it does, checkout for FF and the zone games stops (ADR-005's open risk, unchanged by this ADR).
- Production setup the founder does: create a Turnstile widget in Cloudflare for the storefront hostnames (every affiliate custom domain too), set `NEXT_PUBLIC_TURNSTILE_SITE_KEY` on the storefront Vercel project and `TURNSTILE_SECRET_KEY` in the Forge `.env`, and fill `public_name`/`public_slug`/image on each validator profile.
- An affiliate custom domain added later must be added to the Turnstile widget's hostnames, or its checker shows the challenge error.

### 2026-10-28 addendum — MLBB's public check falls back to UniPlay

Decision 6 ("RedxGame only") changes for MLBB: `player-validator.public.mlbb` is the same Redx → UniPlay chain as checkout ([ADR-005](./ADR-005-player-id-pre-payment-validation-is-not.md) 2026-10-28 addendum). Why: Redx's MLBB checker was down (503), so the public page showed "busy" for MLBB. The reason for "never a supplier" was MooGold, which is our supplier; UniPlay is not, so an abuse flag there can't hurt fulfilment. The other four families stay Redx-only. The cooldown and budget are unchanged and now count the chain as one call.

