---
name: PekanGame Storefront
description: Guest-checkout neo-brutalist game top-up storefront, whitelabeled across affiliate brands via swappable theme presets
colors:
  primary: "#6b38d4"
  on-primary: "#ffffff"
  primary-container: "#8455ef"
  on-primary-container: "#fffbff"
  primary-fixed: "#e9ddff"
  on-primary-fixed: "#23005c"
  primary-on-surface: "#6b38d4"
  secondary: "#00687a"
  on-secondary: "#ffffff"
  secondary-container: "#57dffe"
  on-secondary-container: "#00363f"
  secondary-fixed-dim: "#4cd7f6"
  on-secondary-fixed-dim: "#19192f"
  tertiary: "#b10e6b"
  on-tertiary: "#ffffff"
  tertiary-container: "#d23284"
  warning: "#ffd700"
  ink: "#19192f"
  surface: "#f7f4ec"
  on-surface: "#19192f"
  on-surface-variant: "#4b4550"
  surface-container-lowest: "#ffffff"
  surface-container-low: "#fbf7ee"
  surface-container: "#f2ede1"
  surface-container-high: "#ece5d7"
  surface-container-highest: "#e5ded0"
  outline: "#7b7486"
  outline-variant: "#cbc3b7"
typography:
  display:
    fontFamily: "Space Grotesk, sans-serif"
    fontSize: "4rem"
    fontWeight: 700
    lineHeight: 1.05
    letterSpacing: "-0.02em"
  headline-lg:
    fontFamily: "Space Grotesk, sans-serif"
    fontSize: "2.5rem"
    fontWeight: 700
    lineHeight: 1.15
    letterSpacing: "-0.01em"
  headline-md:
    fontFamily: "Space Grotesk, sans-serif"
    fontSize: "1.75rem"
    fontWeight: 600
    lineHeight: 1.2
  body:
    fontFamily: "Inter, system-ui, sans-serif"
    fontSize: "1rem"
    fontWeight: 400
    lineHeight: 1.5
  price:
    fontFamily: "JetBrains Mono, ui-monospace, monospace"
    fontSize: "1.5rem"
    fontWeight: 700
    lineHeight: 1.33
rounded:
  sm: "0.25rem"
  md: "0.375rem"
  lg: "0.5rem"
  xl: "0.5rem"
components:
  button-primary:
    backgroundColor: "{colors.primary}"
    textColor: "{colors.on-primary}"
    rounded: "{rounded.md}"
    padding: "11px 20px"
  button-primary-hover:
    backgroundColor: "{colors.primary-container}"
  button-outline:
    backgroundColor: "{colors.surface-container-lowest}"
    textColor: "{colors.ink}"
    rounded: "{rounded.md}"
    padding: "11px 20px"
  button-text:
    backgroundColor: "transparent"
    textColor: "{colors.primary-on-surface}"
    rounded: "{rounded.md}"
    padding: "11px 20px"
---

# Design System: PekanGame Storefront

## Overview

**Creative North Star: "The Ink-Stamped Arcade Ledger"**

PekanGame's storefront reads like a pop-art receipt book crossed with an arcade cabinet: warm paper-cream surfaces, hard 2px ink-navy borders, and offset drop-shadows that never blur or glow. Every card looks physically stamped onto the page, not floated above it. The system is deliberately loud in structure (thick borders, blocky corners, bold uppercase display type) but restrained in palette — one electric primary hue carries almost every call-to-action, with a cyan secondary and a magenta tertiary appearing only as small, specific accents (a step indicator, a discount badge), never as competing focal points.

The storefront is **whitelabeled**: an affiliate brand picks one of five curated "Theme Presets" (see Colors → Theme Presets) that swaps the primary/secondary/tertiary/surface family, while the neo-brutalist structural language — hard borders, offset shadows, squared-leaning radius, Space Grotesk display type — stays fixed across every preset. A preset changes the world's *color*, never its *bones*.

Confirmed visual rejections: no gradients, no blur/glassmorphism, no soft ambient shadows, no rounded-pill everything, no purple-and-black "generic AI dark mode" default (see Colors → Dark Mode Rules).

**Key Characteristics:**
- Warm paper-cream base (light) with heavy 2px ink-navy structural borders
- Hard, non-blurred offset shadows (`4px 4px 0`) — elevation by displacement, not blur
- One dominant accent hue per brand; secondary/tertiary appear only as small, deliberate accents
- Space Grotesk display type for headings/CTAs, Inter for body, JetBrains Mono for prices/IDs
- Squared-leaning radius (6-8px) — never pill-shaped

## Colors

The palette is built on a Material-3-style role system (primary/secondary/tertiary/surface, each with an `on-*` legible-text pairing and a `container`/`fixed` tint variant) reused identically across all five theme presets — only the hex values change per preset.

### Primary
- **Electric Violet** (`#6b38d4`): every primary CTA (Track Order, Top Up Now, Join Membership), the header logo chip, focus caret. Carries roughly the same visual weight on every screen — this is the one hue a visitor should remember.

### Secondary
- **Deep Teal** (`#00687a`) / **Bright Cyan** (`#57dffe` container): small structural accents only — the step-indicator circle, `::selection` highlight, focus ring. Never a full-surface fill.

### Tertiary
- **Hot Magenta** (`#b10e6b`) / **Magenta Pop** (`#d23284` container): reserved for a single kind of moment — draws attention to a number the visitor should notice (the "MEMBER -13%" discount badge, review-star fill, hero "bestseller" flame icon). Not a general-purpose third color.

### Neutral
- **Ink Navy** (`#19192f`): every structural 2px border, body text, and (inverted) the dark-mode surface base. Deliberately not pure black (`#000`) — the navy undertone is the system's signature, not an accident.
- **Warm Paper** (`#f7f4ec`): the base page surface. Never stark white — white (`#ffffff`) is reserved for the lowest card container tier only.
- **Muted Plum-Grey** (`#4b4550`, on-surface-variant): secondary/caption text — labels, helper copy, dimmed prices.

### Named Rules
**The One Dominant Rule.** Primary carries every call-to-action on a screen. Secondary and tertiary are structural accents (a badge, an icon, an indicator) — if a redesign reaches for secondary/tertiary to fill a large surface, stop and ask whether that surface should be primary instead.

**The No-Pure-Black Rule.** Ink is `#19192f`, not `#000000`, in both light and dark mode. A pure neutral black paired with a saturated accent is the single most recognizable "AI default palette" tell (antislop Part 1) — the navy undertone is what keeps this system from reading as generic.

### Theme Presets

Five curated presets an affiliate can pick in the reseller portal (`storefront/src/lib/theme-presets.ts`, canonical; synced to `reseller/`'s copy). Same role structure (primary/secondary/tertiary/surface + `on-*`/`container`/`fixed` pairs) as above, different hex per preset. Full values live in the source file — do not restate them in DESIGN.md prose to avoid a second source of truth; describe identity and behavior instead.

- **Digital Architect** (`default`) — the platform's own brand, documented above. The only preset live on a real affiliate storefront today; the only preset with a dark counterpart so far.
- **Cyber Bumblebee** — "Ohaha Style", bold golden yellow (`#FFC700`) + solid ink black, white highlights, pop-art energy. *Audit correction, 2026-09-24:* the raw primary yellow is ~1.6:1 on white — unusable as running text — so a separate `--color-primary-on-surface` (deep amber `#8a6500`) carries price/link text instead of the raw primary. `secondary-container`/`secondary-fixed-dim` are solid ink black (`#19192f`), not a washed grey tint, with `--color-on-secondary-fixed-dim` set to the primary yellow so icon badges read as a yellow-on-black pop rather than grey-on-grey.
- **Red Giants Edition** — "Selangor Glory", crimson red (`#E51B24`) + golden yellow secondary, ink borders. Champion-esports energy.
- **Cyber Emerald** — "Soloz Classic", gamer green (`#00875a`) + neon coral secondary, clean dark outlines. High-contrast arcade energy.
- **Hyper Cobalt** — "Esports Cyber", electric blue (`#0052cc`) + arcade orange secondary, crisp typography.

**The Token-Not-Component Rule.** A preset is *only* a token swap (`THEME_PRESETS[x].tokens`) — never a component-level `if (preset === 'bumblebee')` branch in JSX. Every component must read exclusively through the semantic classes (`text-primary-on-surface`, `bg-secondary-fixed-dim`, `text-on-secondary-fixed-dim`, …), never a raw hex or a preset-conditional. This is what let one token fix (Cyber Bumblebee's audit, 2026-09-24) correct 41 call sites at once instead of 41 individual edits — and what will let every future preset (including its dark counterpart) reuse the exact same component tree.

### Dark Mode Rules

Per-affiliate **fixed** choice (ADR-090) — an affiliate picks Light or Dark for their whole storefront; every visitor sees the same thing. Not a viewer toggle, not `prefers-color-scheme`. Only Digital Architect has a `tokensDark` today; the other four presets hide the Dark option in the reseller portal entirely rather than silently falling back to light.

A dark counterpart is a **hue-preserving inversion**, not a fresh design: every rule below exists because Digital Architect's first dark build (built without these rules, shipped 2026-09-11) was tried live by the founder and rejected — it read as generic rather than as *this* brand's identity gone dark.

**The Tinted-Surface Rule.** Dark `--color-surface` and its `-container-*` ramp must carry the preset's own hue family (a purple-black for Digital Architect, an amber-black for Cyber Bumblebee), never a neutral `#0a0a0a`-style near-black. A neutral dark base plus one saturated accent is the "purple-and-black AI default palette" antislop names explicitly — the exact failure mode to avoid.

**The Every-Surface-Retints Rule.** Every component that reads a semantic surface/secondary/tertiary token retints automatically in dark mode — no component may keep an un-retinted neutral fallback. (Digital Architect's first dark build had exactly this bug: the "Official Partners" strip rendered as a flat mid-grey slab because it fell back to an untouched default instead of a dark-mode token — audited and named 2026-09-24.)

**The Light-Ink Rule.** `--color-ink` (the 2px structural border color) flips to a light stroke in dark mode — a dark-navy border is invisible against a near-black surface, and the hard border is load-bearing to the whole visual language.

**The No-Diluted-Container Rule.** When a preset's `secondary`/`tertiary` base hue is itself near-black or near-white (as Cyber Bumblebee's ink-black secondary is), its M3 "container"/"fixed-dim" tint must not collapse to a neutral grey — pick a value that still reads as a member of the preset's declared palette, verified against WCAG contrast before shipping, in **both** light and dark. (Found live 2026-09-24: Bumblebee's `secondary-fixed-dim` was `#8E8EA8`, a washed lavender-grey with no relation to "bold yellow + ink black.")

**The Glow-Needs-a-Reason Rule.** A soft glow under a button or card is allowed only with a stated purpose (elevation, focus) written down — never shipped as a default "because dark mode looks techy." (antislop R-13, Purpose-Gate.)

## Typography

**Display Font:** Space Grotesk (with sans-serif fallback)
**Body Font:** Inter (with system-ui, sans-serif fallback)
**Label/Mono Font:** JetBrains Mono (with ui-monospace, monospace fallback) — prices, order IDs, player IDs; anything the visitor might compare digit-by-digit

**Character:** Space Grotesk's blocky geometric letterforms carry the pop-art/arcade energy in headlines and CTAs; Inter stays fully neutral for body copy so the display type is the only place personality shows. JetBrains Mono's tabular figures keep price columns aligned.

### Hierarchy
- **Display** (700, 4rem, 1.05 line-height, -0.02em tracking): hero headline only.
- **Headline LG** (700, 2.5rem, 1.15 line-height, -0.01em tracking): section titles ("Popular Picks", "Why Choose PekanGame?").
- **Headline MD** (600, 1.75rem, 1.2 line-height): card/modal titles.
- **Headline SM** (600, 1.25rem, 1.4 line-height): subsection headers.
- **Price** (700, 1.5rem, 1.33 line-height, mono, tabular-nums): every RM amount.
- **Body** (400, 1rem, 1.5 line-height): descriptions, FAQ answers.

### Named Rules
**The One Voice Rule.** Only `h1`/`h2`/`h3`/`.font-display` switch to Space Grotesk. Everything else — including UI labels and buttons, which are uppercase via `tracking-wide` rather than a font swap — stays Inter. Two typefaces total, never three.

## Layout

Content max-width `1200px`, centered, `px-4` side gutter down to phone width. Cards and sections stack with no horizontal scroll below 400px (antislop R-03 responsiveness floor). Grids collapse from up to 4 columns (Popular Picks, Why Choose Us) to 2 columns at the `lg` breakpoint down, matching Tailwind's default breakpoint scale — no custom breakpoints defined.

## Elevation & Depth

Hybrid: flat surfaces at rest, depth conveyed entirely through **hard offset shadows** — never blur, never opacity gradients. This is the system's signature and the clearest tell that separates it from generic AI-slop "soft floating card" defaults.

### Shadow Vocabulary
- **`neo`** (`box-shadow: 4px 4px 0 0 rgb(25 25 47 / 0.9)`): display tier — product cards, hero, marketing surfaces.
- **`neo-sm`** (`box-shadow: 2px 2px 0 0 rgb(25 25 47 / 0.9)`): utility tier — form fields, summary rows, icon badges.
- **`neo-lg`** (`box-shadow: 8px 8px 0 0 rgb(25 25 47 / 0.9)`): sticky order summary, modals.
- **`neo-hover`** (`box-shadow: 6px 6px 0 0 var(--color-primary)`, `translate(-2px,-2px)`): hover lift, primary-colored shadow, gated on `(hover: hover)` so it never sticks after a mobile tap.
- **`neo-hover-cyan`**: same lift, secondary-container-colored shadow — the primary CTA button's hover state specifically.

### Named Rules
**The Flat-By-Default Rule.** Surfaces are flat at rest. Depth appears only as a hard offset shadow, and shifts (never grows blurrier) on hover/active.

## Shapes

Squared-leaning radius throughout: `0.25rem`/`0.375rem`/`0.5rem` (sm/md/lg — xl is the same as lg, no larger step exists). Buttons and inputs use `md` (6px); cards and containers use `lg` (8px). Never a pill (`rounded-full`) except true circular elements (avatar-style icon chips, the step-indicator dot). Every structural container carries a 2px solid ink border — the border is not optional decoration, it is how a block reads as "a block" in this system.

## Components

### Buttons
- **Shape:** `rounded-md` (6px), 2px ink border, min-height 44px (touch target floor).
- **Primary:** `bg-primary text-on-primary`, `neo` shadow, `neo-hover-cyan` on hover (shadow recolors to secondary-container + lifts). Exactly one filled primary button per screen (ADR-063/064).
- **Outline:** `bg-surface-container-lowest text-ink`, `neo` shadow, plain `neo-hover` (shadow recolors to primary) — secondary actions.
- **Text:** transparent background, `text-primary-on-surface` (not raw `text-primary` — see the Token-Not-Component Rule), fills to `bg-primary-fixed` on hover. Tertiary actions only.
- **Destructive:** `bg-danger text-on-danger`, `neo` shadow, brightness dip on hover.
- All variants: uppercase label, `0.05em` tracking, Space Grotesk (via the shared button base class).

### Badges / Chips
- **Discount badge** ("MEMBER -13%"): `bg-tertiary text-on-tertiary`, pill-free rounded-full corner (the one deliberate pill exception), positioned as a corner overlay on package cards.
- **Status badge** ("Active", "Instant Delivery"): `bg-primary-fixed text-on-primary-fixed` or `bg-warning`, small rounded-md pill, 1px border.

### Cards / Containers
- **Corner Style:** `rounded-lg` (8px).
- **Background:** `bg-surface-container-lowest` (white) on a `bg-surface` (warm paper) page; a themed accent card (Membership promo) uses `bg-secondary-container`/`bg-primary-container` instead.
- **Shadow Strategy:** `neo` at rest; see Elevation & Depth.
- **Border:** 2px solid `border-ink`, always.
- **Internal Padding:** `p-3.5` to `p-5` depending on density.

### Inputs / Fields
- **Style:** 2px `border-ink`, `rounded-md`, `bg-surface-container-lowest`.
- **Focus:** `outline-visible` 2px solid `secondary-container`, 2px offset — never `outline: none` without a replacement (antislop R-32).
- **Caret:** colored `var(--color-primary)` (a small branded detail, not a generic browser default).

### Navigation
- Header: `border-b-2 border-ink` on `bg-surface`, primary-colored logo chip, active link underlined in `border-primary`. Mobile: same structure, no separate treatment beyond responsive stacking.

## Do's and Don'ts

### Do:
- **Do** route every color through the semantic role classes (`text-primary-on-surface`, `bg-secondary-fixed-dim`, `border-ink`, …) — never a raw hex or an inline preset-conditional (the Token-Not-Component Rule).
- **Do** verify a new/changed token's contrast against every surface it can land on (white card, warm-paper page, dark surface) before shipping — the Cyber Bumblebee price-text bug (2026-09-24) shipped because this check was skipped for one preset.
- **Do** keep the ink border on every structural container, light or dark — it's the system's signature, not decoration.
- **Do** treat a theme preset as a pure token swap; component structure and shadow/radius/type rules never vary by preset.

### Don't:
- **Don't** use a raw M3 "container"/"fixed-dim" tint without checking what it renders as when the base hue is near-black or near-white — the automatic light/dark-mix math that works for a saturated hue (cyan, coral) collapses to a washed grey for a near-neutral one (the Bumblebee bug).
- **Don't** let dark mode collapse into "neutral near-black + one saturated accent + soft glow" — that is antislop's named "purple-and-black AI default palette," and it is exactly what got Digital Architect's first dark build rejected live.
- **Don't** add a glow, blur, or gradient anywhere in this system without a written reason — the entire elevation model is hard offset shadows, and a soft effect anywhere reads as a foreign design language bolted on.
- **Don't** invent a sixth or seventh accent color on any single screen. Primary dominates; secondary and tertiary are small, deliberate accents — never simultaneous large fills.
