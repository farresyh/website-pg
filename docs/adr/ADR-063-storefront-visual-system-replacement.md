# ADR-063: Storefront visual system replacement — light neo-brutalist "Digital Architect" world

> **Standing (2026-10-09):** In force. Build and live status: [`prd.md` §15](../prd.md). The text below is a dated record; a later addendum in this file overrides earlier text, including the **Status** line.

**Status:** Accepted (design) — 2026-09-01, grilled with the founder (`grilling` + `impeccable` skills). Replaces the storefront's original visual world (the palette / fonts / logo established in PRD §15's storefront scaffold row and the `globals.css` header comment — never an ADR of its own). ADR-028's branding *pipeline* is untouched. Ships on `feature/pekangame-storefront-redesign`.

**Context:**
- The storefront ships **one hardcoded visual world**: `storefront/src/app/globals.css` `@theme` tokens (dark forest-green — `--color-bg: #04140e`, `--color-brand: #007400`), Bebas Neue + Source Sans 3 fonts (`layout.tsx`, `next/font/google`), a CSS-approximated diamond logo. This palette was "extracted from the real Kedai Runcit Soloz brand assets" (ADR-028) — it is now anti-reference.
- The founder produced a Stitch design set (`~/Downloads/stitch_fixfast_brand_evolution/`, 6 desktop screens + `DESIGN.md`) and a neo-brutalist design-system poster as the direction. Both are **reference, not spec** — the founder wants the card layouts, colours, and structure "as close as possible" to the Stitch screens, with craft latitude to improve.
- The founder's stated intent (grilling Q13/Q14): the Stitch "Digital Architect" palette is the poster's hard neo-brutalism **deliberately softened** ("nampak terlalu sharp, jadikan lebih smooth"). Ship **light** for PekanGame v1 (the original brief; all 6 Stitch screens are light), but the founder also wants dark available later per-tenant.
- Per-tenant theming (PRD §6.15 THM-1..4) is explicitly a **separate future ADR**, not this one. The reseller "theme system" stays out (ADR-059 / ADR-060 notes). **→ [ADR-081](./ADR-081-affiliate-storefront-theme-presets.md) (2026-09-09): curated fixed-preset picker shipped; full custom theming (THM-1/3/4) dropped.**
- Stack: Next.js 16 / React 19 / Tailwind **v4** (CSS `@theme`, no `tailwind.config.js`). Icons: `@phosphor-icons/react` already a dependency. `next/image` already used (`ProductCard`, `HeroSlider`).

**Decision:**

1. **Full redesign, not a refinement.** `globals.css`'s token world is replaced wholesale. Product truth, copy meaning, routes, flows, the guest-checkout constraint (ADR-011), and realtime wiring (ADR-047) are all preserved. The dark-green world + Bebas Neue + diamond logo are treated as evidence of the old identity and discarded, not polished.

2. **Light-first, dual-theme token structure.** v1 ships light only and exposes no dark toggle. But tokens are defined as **semantic roles** (`--color-surface`, `--color-on-surface`, `--color-primary`, …) with a light palette now and the structure ready for a dark palette to be added later without renaming anything. The future THM ADR builds on this; it does not retrofit it. `dark:` variant classes are **not** scattered through components in v1 — theme switching, when it comes, is a token-set swap at the root, not per-element overrides.

3. **Palette — Stitch "Digital Architect" M3 base, two poster-driven adjustments.**
   - Primary (brand / primary CTA / structural highlight): **`#6b38d4`** purple. `on-primary` `#ffffff`, `primary-container` `#8455ef`.
   - Secondary (interactive states, focus, technical detail): **`#57dffe`** cyan (`secondary` `#00687a`, `secondary-container` `#57dffe`).
   - Tertiary (promotions, discounts, urgency **only**): **`#b10e6b`** magenta.
   - **Adjustment 1 — background:** shift off Stitch's lavender-white `#fcf8ff` to a **warm paper** `#F7F4EC` (the poster's paper-beige feel, less yellow than `#F5F5DC`). The surface-container ramp is re-derived warm to match.
   - **Adjustment 2 — a fourth accent:** the poster's **yellow `#FFD700`** enters as a `warning` / high-attention accent only (stock warnings, "verify your ID" nudges). Never a CTA fill, never under body text.
   - Ink (all type + structural borders): **`#19192f`** navy, never pure black. Functional: success emerald, error `#ba1a1a` crimson.
   - The full M3 tonal set from `DESIGN.md` is adopted as the token values (containers, `on-*` pairs, `-fixed` variants) — a complete system, not hand-picked hues.

4. **Typography — the Stitch trio, Stitch scale.**
   - **Space Grotesk** (400/600/700) — display, all headings, CTA labels (CTAs uppercase, `+0.05em` tracking).
   - **Inter** (400/500/600) — body, form fields, everything readable.
   - **JetBrains Mono** (700) — prices, order numbers, player IDs, transaction IDs.
   - Loaded via `next/font/google` in `layout.tsx`; Bebas Neue + Source Sans 3 removed.
   - Scale: `display` 64 / 40 mobile, `headline-lg` 40, `headline-md` 28, `headline-sm` 20, `body-lg` 18, `body-md` 16, `caption` 12, `price` 24. **Hero display is the one showpiece exception** — it may run 72–96px on desktop, tuned to the banner, 40–48px mobile.

5. **Neo-brutalism, two token tiers.** The poster's discipline — thick ink strokes, squared corners with limited radii, solid accent blocks for status, strict grid alignment, **focus always visible** — is adopted. Intensity is split by surface role:
   - **Display tier** (home, catalog, product-hero card, product cards, marketing sections): 2px ink border; hard shadow, 4px offset, ink at `~0.9` alpha; radius 8px (containers / cards) / 6px (buttons); hover shifts the shadow to cyan or purple + `translate(-2px,-2px)`.
   - **Utility tier** (checkout form fields, review modal, OTP input, track-order form, order-summary rows): 1.5px border; restrained shadow (2px offset) or none; radius 6px; **focus = a 2px cyan ring / hard-shadow that is always rendered**, never suppressed. Money screens stay legible and calm — loud framing does not touch payment inputs.
   - Promo chips are the one pill-shaped (`rounded-full`) exception, magenta fill.
   - Elevation tokens: `shadow-0` none, `shadow-1` 4px (display default), `shadow-2` 8–12px (sticky summary, modal).

6. **Icons stay Phosphor.** Stitch's Material Symbols glyphs are mapped to `@phosphor-icons/react` equivalents (`bolt`→`Lightning`, `local_fire_department`→`Fire`, `timer`→`Timer`, `security`→`ShieldCheck`, `support_agent`→`Headset`, `verified`→`SealCheck`, `expand_more`→`CaretDown`, …). No new icon font is added.

7. **Imagery.** Real artwork renders through `next/image` wherever the data has it (game thumbnails, hero `imageUrl`). The fallback for a missing image is a **branded neo-brutalist placeholder tile** (bordered, `surface-variant` fill, centred mark) — never a broken `<img>`, never a lone floating icon. The hero's no-image state is a designed split-card treatment (ADR-064), not a generic gradient.

8. **Logo.** A PekanGame placeholder mark is authored as an inline SVG component (same pattern as the current `Logo.tsx`): a bolt inside a hard-bordered square, paired with the Space Grotesk wordmark. Explicitly a placeholder — a real exported asset replaces it in `storefront/public/` later.

9. **Motion.** Purposeful and minimal: the hard-shadow hover shift, a purple→cyan gradient on progress indicators (the "speed / digital flow" cue from `DESIGN.md`), accordion / disclosure transitions. All motion respects `prefers-reduced-motion` (already the storefront's standard — `HeroSlider`).

10. **Verification.** After the token world and ADR-064's surfaces are built, run `node .claude/skills/impeccable/scripts/detect.mjs --json` over the changed targets once, fix findings in one batch, and do one bounded browser pass (desktop + mobile together) — not an open-ended polish loop.

**Rationale:** The founder's own framing settles the palette question — the Stitch "Digital Architect" set *is* their softened neo-brutalism, so adopting it verbatim (with a warmer ground and the poster's yellow as a warning accent) honours the brief rather than splitting the difference. A light neo-brutalist storefront is also a genuine differentiator: the SEA top-up category (Codashop, UniPin, SEAGM) is uniformly dark-neon. The two-tier intensity is not a compromise — the Stitch screens already modulate this way (1px inputs, 2px cards), and it is the right call for a money-critical surface: trust and legibility on the payment path outrank expressive framing. Keeping the dual-theme token *structure* while shipping light-only means the future THM ADR is an addition, not a rewrite.

**Consequence to track:**
- **Accepted debt:** this hardcodes exactly one visual world (PekanGame's). When ADR-060's `Host` multi-tenancy lands, a second `is_owned` brand renders in PekanGame's skin until the THM ADR ships a real per-tenant theme layer. The founder has accepted this explicitly (grilling Q11). THM stays its own ADR (PRD §6.15).
- Replaces the "palette extracted from the real Kedai Runcit Soloz logo/banner assets" world described in PRD §15's storefront scaffold row and stated in the old `globals.css` header comment (that comment is rewritten). This was never captured in an ADR — ADR-028 is the *branding pipeline* (DB-driven `store_name`, footer, legal) and is unchanged.
- Bebas Neue / Source Sans 3 removal touches every component that names `font-display` expecting Bebas metrics — Space Grotesk is wider; heading line-heights and `tracking` need a re-check in the browser pass, not just a find-replace.
- Tailwind v4: all tokens live in `globals.css @theme`; there is no `tailwind.config.js` to edit. Custom utilities (`neo-shadow`, `neo-shadow-hover`) are defined with `@utility`.
- The warm-paper ground (`#F7F4EC`) must be checked for AA contrast against `on-surface-variant` body text in the audit pass — adjust the ground or the text token, not the primary hue.
- PRD §14 build-log + §15 note owed on ship.
