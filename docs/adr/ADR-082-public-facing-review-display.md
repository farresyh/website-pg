# ADR-082: Public-facing review display — homepage marquee + per-game reviews on the product page

> **Standing (2026-10-09):** In force. Build and live status: [`prd.md` §15](../prd.md). The text below is a dated record; a later addendum in this file overrides earlier text, including the **Status** line.

**Status:** Accepted — retroactively documented + grilled 2026-09-09; **shipped 2026-09-09 (PRs #150/#151) ahead of this ADR (process slip, noted below).** BUILT + on `main` (live); see below for the two known gaps found at the time.

**Context:**

[ADR-053](./ADR-053-reviews-rev-1-5.md) decision 6 was explicit: "**No public display anywhere.** Approved reviews stay purely an internal admin moderation view … Extending to a public/Testimonials-replacing display is left to a future, dedicated ADR once real review volume exists".

PRs #150 and #151 (2026-09-09) shipped exactly that, without an ADR:
- **`GET /api/reviews`** (`ReviewCatalogController::index`) — the 12 latest approved reviews with `comment` non-null, customer name masked via `App\Support\ContactMask` (name → email → `"Verified Customer"`), 60s cache. Consumed by the homepage `TestimonialsSection` as an infinite marquee (PR #150).
- **`GET /api/catalog/games/{slug}/reviews`** (`ReviewCatalogController::gameReviews`) — for one game, tenant-scoped (`whereHas('order', affiliate_id = current brand`, `orWhereNull` for the primary): `average_rating`, `review_count`, and the 3 latest commented approved reviews. Consumed by `GameReviewsSection` on the `/order/[slug]` product page, replacing the old static `TrustStrip` (PR #151).
- Admin `ReviewController::approve/reject` now call `ReviewCatalogController::forgetCache(affiliateId, gameId)` so moderation changes propagate.

This ADR documents the shipped design, records the grill decisions, and lists the gaps the grill surfaced.

**Decision:**

1. **`approved` now means `public`.** There is no separate `is_public` / `is_featured` flag. The existing admin moderation (super_admin/admin approve/reject, ADR-053) is the sole gate — approval IS publication. At current volume a two-step "approve then feature" workflow is pure overhead.

2. **This reverses ADR-053 decision 6.** ADR-053's "no public display" and "its own future ADR" are superseded by this ADR. On the 2026-09-09 deploy, every already-approved review (moderated under a "will only ever be seen internally" expectation) became publicly visible. Accepted, given low pre-launch volume — but see the founder-owed corpus scan below.

3. **The homepage marquee (`GET /api/reviews`) must be tenant-scoped**, the same way the per-game endpoint already is — an affiliate's branded storefront shows reviews from *that brand's* customers (+ null-affiliate for the primary), never a cross-brand pool. **Gap: shipped un-scoped (global latest 12).** Fix in the follow-up branch.

4. **No placeholder fallback.** When a brand/game has no approved reviews, the section renders nothing (the per-game `GameReviewsSection` already does this). The homepage marquee must do the same. **Gap: PR #150 falls back to fake `TESTIMONIALS` placeholder data under a "Real feedback from verified buyers" heading** — misleading on a money-handling storefront. Fix in the follow-up branch: drop the fallback, delete `placeholder-data.ts`'s `TESTIMONIALS`, and delete the now-unused `TrustStrip` component (the founder's call: a generic trust strip is pointless noise now).

5. **Customer identity is masked** via `ContactMask` on every public surface, consistent with ADR-065. No raw name/email/phone, no `order_number`, no internal financial fields — the narrow-response discipline of `backend/AGENTS.md` applies.

6. **The public review endpoints share the same throttle/caching posture as the rest of the public catalog group** (`/api/catalog/*`) — 60s cache, no per-endpoint rate limit. The per-game cache key space is bounded (brands × games); the homepage key becomes bounded once decision 3 lands.

**Rationale:**

- Real, moderated customer reviews on the storefront are a straightforward conversion lever, and ADR-053 always anticipated this step — it just wanted volume first and an ADR. Volume is still thin but the founder judged the feature worth shipping now; this ADR back-fills the missing decision record.
- `approved == public` with no extra flag is the right call *at this scale* — the moderator already reads every review. The consequence list flags when to revisit.
- Per-brand scoping is not optional: a cross-brand marquee leaks one brand's sales proof to a competitor's storefront and shows a customer of brand X testimonials from brand Y they've never heard of.
- The placeholder-under-a-"verified"-heading gap is a genuine misrepresentation risk on a platform that moves real money — hence a scheduled fix, not a "maybe later".

**Consequence to track:**

- **Founder-owed, one-off:** scan the existing approved-review corpus once for anything not appropriate for public display (reviews approved under the old "internal only" assumption). Same shape as the `fixfastapp` orphan-row cleanup in ADR-080. Low effort at current volume.
- **Two shipped gaps → CLOSED on `fix/storefront-review-scoping` (2026-09-10):** (a) `GET /api/reviews` (`ReviewCatalogController::index`) now takes `StorefrontBrand`, scopes `whereHas('order', affiliate_id = brand)` + `orWhereNull` for the primary (same shape as `gameReviews`), and caches per-brand under `catalog.public.reviews.{affiliateId}`; `forgetCache()` normalises a null `affiliateId` → primary id and busts the per-brand homepage key (also fixing a latent miss where a primary-brand order's approve never busted its game-reviews key); `Admin\ReviewController::bulkApprove` collects the distinct affiliate ids of the pending set before the update and busts each. (b) `TestimonialsSection` returns `null` when the brand has zero approved reviews; `TESTIMONIALS` + `Testimonial` deleted from `placeholder-data.ts`; `TrustStrip.tsx` deleted (already unused since PR #151). `ReviewCatalogControllerTest` +1 (brand-scope: primary vs affiliate host). Full fast suite 1643/1643, storefront lint/tsc/build clean.
- **SEO `AggregateRating` / `Review` JSON-LD** on the product page (from the new `average_rating` / `review_count`) is **backlog**, not this ADR's scope — fold into the next SEO-module change (ADR-042). Star ratings in the SERP are high-value for a top-up storefront.
- **Revisit `is_public` as a separate flag** if review volume grows enough that a moderator can't reasonably keep every approved review public-worthy, or if a specific approved-but-not-public-appropriate case appears.
- **`GameReviewsSection` replaced `TrustStrip` on the product page** — a game with zero approved reviews now has nothing in that slot. Deliberate (decision 4): the founder considers a generic trust strip pointless. New games / new affiliate stores therefore have a bare product page until their first review lands.
- Keep `docs/prd.md` §14/§15 (Reviews row) and ADR-053's decision-6 pointer current with this reversal.
