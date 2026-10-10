# ADR-064: Storefront per-surface redesign + component rebuild

> **Standing (2026-10-09):** In force. Build and live status: [`prd.md` §15](../prd.md). The text below is a dated record; a later addendum in this file overrides earlier text, including the **Status** line.

**Status:** Accepted (design) — 2026-09-01, grilled with the founder. Consumes ADR-063's token world. Ships on `feature/pekangame-storefront-redesign` (one PR with ADR-062/063, or a follow-up PR).

**Context:**
- ADR-063 establishes the visual language. This ADR is the surface-by-surface application: which storefront screens change, what changes structurally vs. what is only reskinned, and the shared primitives that get rebuilt.
- The Stitch set covers 6 screens (home, ML top-up, checkout review modal, order status, track-order, membership history). The storefront has more: `membership` page, `about-us` / `privacy` / `terms` legal pages, the not-found page, the mobile `BottomNav`, `AnnouncementBar`, `HeroSlider`. Grilling Q7: **all** surfaces are redesigned — the 6 Stitch screens set the language and it is extended consistently to the rest.
- Hero content (eyebrow, title, description, image, CTA labels / hrefs, price) is **DB-driven and admin-editable** via `HeroSlideController` (`hero-slides.ts`). This ADR restyles the hero *container / treatment* only; copy stays admin-authored. No change to `HeroSlideController`, its model, or the admin screen.
- All component props, behaviour, routes, the guest-checkout model, voucher / membership logic, and realtime order-status wiring are preserved — this is reskin + layout restructure, not a functional change.

**Decision:**

1. **Shared primitives, rebuilt in the ADR-063 language** (`storefront/src/components/ui/`): `Button` (variants primary / outline / text / destructive; display-tier framing), and new `Card`, `Badge`, `Chip` (promo pill), `StepIndicator`, `PlaceholderTile`. Existing components consume these rather than re-implementing borders / shadows inline.

2. **Home (`page.tsx` + `components/home/*`)** — sections in Stitch order and treatment:
   - **Hero** — `HeroSection` becomes a 12-col split: a large bordered content card (eyebrow chip, display headline, description, CTA row) beside the `QuickCounterCard` restyled as Stitch's "Quick Top-Up" widget (game-picker grid + primary CTA). With-image slides render the image in a bordered frame + hard shadow (scrim only where text overlaps); the no-image state is the designed split-card with a decorative offset block behind (`secondary-fixed-dim`), replacing the current diagonal-cut dark gradient.
   - **Popular Picks / New Arrivals** — `ProductCard` grid: bordered card, image-or-`PlaceholderTile` header, title, subtitle, dashed-rule divider, "Starting from" + mono price, hard-shadow hover.
   - **Why Choose Us** — 3 icon cards in a bordered container, `secondary-fixed-dim` icon chips.
   - **Payment Methods / Testimonials / FAQ / SEO blurb / promotions** — reskinned to the card / border / shadow language; FAQ is a bordered accordion with a rotating `CaretDown`.
   - Hardcoded `TRUST_ITEMS` / feature copy is unchanged (matches Stitch: "3-Minute Delivery", "Xendit-Secured", "24/7 WhatsApp").

3. **Order flow (`order/[slug]/page.tsx` + `components/order/*`)** — Stitch's "Mobile Legends Top-Up" layout:
   - Breadcrumbs; **product hero card** (bordered, game mark, title, "Instant Delivery" chip, avg-delivery caption).
   - Two-column: left = the 3 steps with a `StepIndicator` (Enter ID → Package → Payment) and per-section numbered headers; right = **sticky `OrderSummarySidebar`** (bordered, `shadow-2`, uppercase "Order Summary", line items, mono totals, primary "Review & Pay" CTA, security caption) with the `MembershipPromoCard` below it (ADR-055 — kept, restyled).
   - `Step1AccountInfo` — utility-tier inputs, "Verify Account" outline button, success / error banners with 1px functional-colour borders.
   - `PackageGrid` — package cards showing the standard price and, when membership is active, a highlighted member-price row + "Save RMx" tag; selected card = primary border + check badge + coloured hard shadow.
   - Payment-method picker — grouped (e-wallet / FPX / card), utility-tier selectable tiles.

4. **`ReviewModal`** — utility-tier: 2px ink modal border, `shadow-2`, header + close, order-summary block, email / name / phone inputs, voucher input + Apply, total breakdown (package / fee / voucher deduction / total), T&C checkbox (custom neo checkbox), uppercase "Confirm & Pay" primary CTA. Backdrop blur + dim.

5. **Order status (`order/status/[orderNumber]` + `OrderStatusTracker`)** — reference + status + stage-tracker card, then a Stitch **bento grid** (Game & Package / Customer Info / Payment Details) + a "Need help?" sidebar card (`primary-fixed` fill, Contact Support + Buy Again). The bento's Customer Info + payment breakdown are their own decision — **[ADR-065](./ADR-065-guest-order-status-detail.md)** widens the guest response (masked contact + `payment_method`/`selling_price`/`voucher_discount`/`transaction_fee`) and the ADR-047 broadcast payload in step with it.

6. **Track-order (`track-order/page.tsx` + `TrackOrderClient`)** — bordered lookup form (utility tier) + a refined results table: ink header row, uppercase mono column labels, hover-highlighted rows, status pills (`neo-border` + container colour by state).

7. **Membership page (`membership/page.tsx` + `MembershipClient`)** — email → OTP → dashboard flow reskinned to the language; OTP input is utility-tier with always-visible focus. The verified-member dashboard becomes the Stitch `membership_history` layout — see the build addendum.

8. **Legal pages** (`about-us`, `privacy`, `terms` via `LegalPageContent`) — reskinned to a reading surface: paper ground, bordered content frame, Space Grotesk headings, Inter body. Content is unchanged (DB-driven / sanitized server-side). (No custom `not-found` page exists — see the build addendum.)

9. **Layout chrome** — `SiteHeader` (bordered, wordmark + search + nav + Track Order CTA, the mobile search row kept), `SiteFooter` (`surface-container-highest`, 2px top border, columns + payment chips), mobile `BottomNav` — all to the new language, all existing behaviour preserved. (`AnnouncementBar` was removed entirely — see the build addendum.)

10. **Critique + polish** — after the surfaces exist, run `/impeccable critique` on the home + order flow, fold the findings in, then the single bounded verification pass from ADR-063 decision 10. Not an open loop.

**Rationale:** Doing every surface in one pass is the right call (grilling Q7) — a half-redesigned storefront reads as broken, and the shared primitives (decision 1) make the long tail cheap once they exist. Restyling the hero container while leaving its copy in the admin-editable data path keeps the campaign-management feature (ADR-029 backlog) intact. The order flow and review modal follow Stitch's structure closely because that is what the founder asked for ("layout card… semua ikut mcm ni"); the craft latitude is spent on the two-tier intensity, motion, focus states, and the mobile layouts Stitch never drew.

**Consequence to track:**
- Stitch is desktop-only. Every surface needs a mobile layout designed here, not inferred — verify both widths in the browser pass.
- `ProductCard` / `HeroSlider` already use `next/image` with real remote URLs — the `next.config.ts` `images.remotePatterns` allowlist is unchanged; `PlaceholderTile` is pure CSS / SVG, no new remote host.
- `MembershipPromoCard` (ADR-055) and the member-pricing rows in `PackageGrid` depend on membership being enabled for the brand (ADR-061 `membershipEnabledEffective()`) — the redesign must keep the "hidden when kill-switch off" behaviour, not just restyle the visible state.
- No automated FE tests exist for the storefront (ADR-023 E2E golden paths are the only coverage). The checkout golden path must stay green — re-run `cd e2e && npm test` (needs `XENDIT_SECRET_KEY`).
- PRD §14 build-log + §15 storefront-status note owed on ship.

**Build addendum — founder feedback, 2026-09-01:**
- **`AnnouncementBar` deleted.** The purple top strip is removed from every page and the component file dropped (it was hardcoded marketing copy, not DB-driven — no feature lost).
- **`QuickCounterCard` is an icon-tile grid, not a `<select>`** (matches the Stitch "Quick Top-Up" widget): the pinned `QUICK_COUNTER_SLUGS` games render as picker tiles (real thumbnail or a Phosphor icon + name), padded with the top catalog games to fill up to 6; no "browse all" link (the header search + Popular Picks cover the long tail).
- **Nav "Promotions" → "Membership"** (`/membership`), in both `SiteHeader` and `BottomNav` (Crown icon), **rendered only when membership is enabled for the brand** — `listPlans()` returns `[]` under ADR-061's dual kill-switch, so the link is absent until the founder turns membership on, which keeps PRD §15's "no customer-facing membership link until launch" decision self-enforcing rather than a manual nav edit later. The homepage `PromotionsSection` + `#promotions` anchor stay.
- **Membership dashboard → Stitch layout** (`fixfast_membership_history`): the verified-member view becomes a two-column status card (tier + Active pill | quota + renew date) plus the Stitch order-history **table** (Date / Game·Package / Order # / Price / Status), horizontally scrollable on narrow screens. Frontend-only — `created_at` and `order_number` were already in the `GET /api/membership/me` response, just not rendered. **Not built:** the Stitch "Account Links" sidebar (Profile Settings / Linked Wallets / Security) — those pages don't exist and won't (ADR-027's "lighter than an account" identity); Sign Out is the only real action.
- **Order-status detail → Stitch bento layout** — its own decision, [ADR-065](./ADR-065-guest-order-status-detail.md).
- **`not-found` left as the Next.js default.** Decision 8 listed a custom not-found reskin, but `storefront/src/app/not-found.tsx` does not exist — Next renders its built-in page. No custom 404 was in scope; not created here.

**Build addendum — hero sizing + arrow removal, 2026-09-19 (see ADR-060 PR-6's own addendum of the same date for the paired content-field change):** decision 2's hero container is re-cut from a fixed `min-h-[360px]`/`h-[420px]` box to a single `aspect-ratio: 2/1` (image slide and no-image "paper card" fallback both), so the container shape always matches the documented 1600×800 upload spec regardless of viewport width — the prior fixed-height box cropped a spec-correct upload on mobile just as badly as an off-ratio one. The prev/next arrow buttons (desktop-only, `hidden lg:flex`) are removed outright — they sat in the same zone as the left-anchored headline and collided with it; dot indicators + swipe + auto-rotate are the only navigation now, matching what mobile already had.

Two findings from live-verifying the shorter mobile box (seeded real slides, checked in an actual browser at 390px per AGENTS.md's build convention, not just `tsc`/lint):
- **Description hidden below `sm`.** A text-heavy slide (title + description + CTA) at `aspect-[2/1]`'s mobile height (~195px) had no room left for the description once title + button were laid out — rather than let it clip against `overflow-hidden`, `<p>{slide.description}</p>` gained `hidden sm:block`. Title also drops from `text-[32px]` to `text-[22px]` on the smallest breakpoint for the same reason (`sm:text-[32px] lg:text-[56px]` unchanged).
- **Dot indicators collided with a bottom-anchored CTA row.** The text block is `justify-end`-anchored to the container's bottom, same as the `absolute bottom-3.5` dots — at the new shorter height the "Find Games"/"Track Order" buttons sat flush against the dots. Fixed with a `pb-8 lg:pb-10` reserve on the text wrapper, applied only when `slides.length > 1` (a single-slide hero has no dots to clear).
