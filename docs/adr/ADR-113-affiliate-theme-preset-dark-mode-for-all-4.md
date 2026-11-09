# ADR-113: Affiliate theme-preset dark mode for all 4 affiliate-selectable presets, `storefront/DESIGN.md`, Digital Architect retired from the affiliate picker

> **Standing (2026-10-09):** In force. Build and live status: [`prd.md` §15](../prd.md). The text below is a dated record; a later addendum in this file overrides earlier text, including the **Status** line.

**Status:** Accepted & built — grilled with the founder 2026-09-24 (`/mattpocock-skills:grilling`), all 4 dark palettes hand-authored and live-tested the same session. **Merged to `staging` 2026-09-25** (PR #288, CI green). A `staging`→`main` release is a separate founder decision, not yet made.

**Context:**

The founder asked for a full audit of the 5 affiliate theme presets (ADR-081/090), comparing dark-mode Digital Architect against several competitor top-up sites (warungvamos.games, acidgameshop.com) using the `antislop`/`impeccable` skills rather than waiting for a founder-supplied Stitch reference — a deliberate departure from the workflow set after ADR-090's dark mode was tried live and disliked (see the `project_adr090_darkmode_redo_pending` memory). The audit surfaced a real, separate bug along the way: Cyber Bumblebee's raw `#FFC700` primary is ~1.6:1 contrast on white — unreadable as text — used directly via a shared `text-primary` Tailwind class across 41 call sites (checkout total, order tracking, footer, every price tag on the storefront), so every affiliate on that preset had an effectively unreadable checkout total. That fix (a new `--color-primary-on-surface` token, equal to `--color-primary` on the 4 presets that already clear 4.5:1, overridden only for Bumblebee) shipped in its own commit before this ADR's dark-mode work began, and revealed the pattern this ADR's own build follows throughout: a token that works for saturated hues can silently fail for a near-neutral one, so every new token needs its contrast checked per preset, not assumed from one working example.

Direct comparison against the two competitor dark themes (both real, currently-live top-up sites) found the actual cause of "feels like AI slop": not the brand hues themselves, but (1) a full-brightness near-white 2px border (`--color-ink: #e9e5f5`) on every single card/button/badge simultaneously, reading as busy/harsh where competitors keep borders barely visible; and (2) large-fill components (the Membership promo card) using a secondary-container token whose color family (raw teal, `#00687a`) sits near-complementary to the page's own primary hue (purple), reading as a mismatched inserted block rather than a family member — the same failure class Cyber Bumblebee's own `secondary-container`/`secondary-fixed-dim` had already shown earlier the same session (a washed grey instead of "ink black," fixed then, in the light-mode commit).

Mid-build, the founder made a separate policy call: Digital Architect is PekanGame's own primary-brand identity and should never have been offered to affiliates as a pickable preset at all — it stays exclusive to the primary/owned brand, which itself only ever renders in light mode (ADR-090 decision 5 already established the primary gets no Site Mode toggle). A production check (`AffiliateBranding::where('theme_preset','default')`, read-only, founder-authorized) confirmed zero non-primary affiliates were on Digital Architect, so retiring it as an affiliate option had no live-customer impact.

**Decision:**

1. **`storefront/DESIGN.md` written** (new — this project had none before), following the DESIGN.md format spec: frontmatter token schema plus the 8 canonical sections. Documents the existing light-mode system for all 5 presets (Digital Architect included, as the primary brand's own reference) and — the new part — a **Dark Mode Rules** subsection with 5 named rules (Tinted-Surface, Every-Surface-Retints, Light-Ink, No-Diluted-Container, Glow-Needs-a-Reason), each citing the specific bug it exists to prevent, so a future dark-mode build (this preset's own redo, or a 6th preset) has a written contract instead of re-deriving taste from scratch.
2. **Every dark palette is a hue-preserving inversion, hand-authored per preset** (continuing ADR-081's "no automated invert/contrast tool" principle) — not a shared neutral dark template with the brand hue swapped in. Each preset's surface/container ramp is tinted toward its own primary hue family (deep purple-black for Digital Architect, warm amber-black for Cyber Bumblebee, deep maroon-black for Red Giants, deep green-black for Cyber Emerald, deep blue-black for Hyper Cobalt) — never a shared neutral `#0a0a0a`.
3. **The structural border (`--color-ink`) is muted, not blazing, in every dark palette** — a warm/cool-family mid-tone (e.g. Digital Architect's `#5f5a78`, Cyber Bumblebee's `#8f8360`) rather than a near-white lavender at full brightness. Still visibly present (the neo-brutalist 2px stamped border is not optional), just not competing with the accent colors for attention.
4. **A large-fill "big block" component (the Membership promo card, via `secondary-container`) never uses the preset's raw, saturated secondary hue in dark mode.** Each preset's `secondary-container`/`secondary-fixed-dim` dark values are a deep, muted, in-family tone instead (Digital Architect: slate-purple `#2c3a52`, not raw teal; Cyber Bumblebee: deep brown `#332500`; Red Giants: deep gold-brown `#4d3800`; Cyber Emerald: deep rust `#4d1f10`; Hyper Cobalt: deep orange-brown `#522200`) — small accents (icon badges, step indicators, discount badges) keep using the bright saturated hue, since a *small* saturated pop reads as an accent, the same size fill reads as a clashing insert.
5. **Fixed a related dark-mode regression found while muting `--color-ink`:** 6 component call sites paired `text-ink` directly with `bg-surface-container-lowest` (a near-white card in light mode, near-black in dark mode) for actual legible content — icon fallback letters, a status badge, the `outline` Button variant — not just decorative borders. Muting `--color-ink` for borders would have made that text unreadable in dark mode. Fixed by routing those 6 spots through `text-on-surface`/`text-on-surface-variant` (the token already designed to stay legible against the main surface in both modes) instead of the raw structural ink color.
6. **Digital Architect (`default`) is removed from every affiliate-facing preset list** — the reseller portal's `ThemeTab.tsx` picker now renders only the 4 affiliate-selectable presets (a `SELECTABLE_PRESETS` filter, with the picker's internal fallback state now defaulting to the first selectable preset instead of `"default"`), and the backend `UpdateBrandingRequest`'s `theme_preset` validation rule is narrowed from `in:default,bumblebee,redgiants,emerald,cobalt` to `in:bumblebee,redgiants,emerald,cobalt` — an affiliate cannot select or API-set Digital Architect at all. `default` stays in `THEME_PRESETS` itself (the primary brand's own definition, and `getThemePreset()`'s internal fallback for an unrecognised id) — only the *offering* of it to affiliates is removed, not the definition.
7. **Digital Architect's `tokensDark` is deleted, not kept as dead code.** A dark palette was hand-authored and live-tested for it earlier in the same session (before decision 6 was made), but since no affiliate can select this preset and the primary brand stays permanently on light (ADR-090 decision 5), the dark tokens would be unreachable by any real code path. Removed with a comment pointing at the other 4 presets' pattern if it's ever needed again, rather than left in the file as something a future session might mistake for live/reachable.

**Rationale:**

- Comparing against real, currently-live competitor dark themes (rather than iterating on taste alone) turned a vague "feels like AI slop" complaint into two specific, fixable causes (border brightness, large-fill hue clash) — this is the antislop/impeccable workflow the founder explicitly asked to try in place of waiting on a Stitch reference, and it worked without one.
- Muting structural contrast while preserving the border itself (rather than removing it, which the competitor references do) keeps the neo-brutalist identity the founder explicitly did not want to lose — the fix borrows the *restraint principle* from softer competitor designs without adopting their soft/glow/gradient material language, which would have contradicted this system's own "hard offset shadow, no blur" signature (see `DESIGN.md`'s Elevation & Depth section).
- Retiring Digital Architect as an affiliate option, discovered mid-build rather than planned from the start, is exactly the kind of policy call `AGENTS.md`'s task lifecycle expects to surface during a grill/build rather than being bolted on after — resolved immediately (removed the now-dead dark tokens, narrowed both the frontend picker and backend validation) rather than left as a follow-up item.

**Consequence to track:**

- `docs/prd.md` §15/§16 should reflect: dark mode now ships for all 4 affiliate-selectable presets (was: Digital Architect only, and Digital Architect itself is no longer affiliate-selectable).
- The reseller `ThemeTab.tsx` live preview panel still hardcodes a few cosmetic details outside the injected tokens (the "Official Partners" strip background, noted as a known preview-fidelity gap in ADR-090's own Consequence-to-track) — unchanged by this ADR, still tracked, not a storefront-accuracy issue.
- This ADR's branch (`fix/affiliate-theme-contrast-bugs`) had not been pushed or opened as a PR as of session end — still owed: push, PR into `staging`, CI green, founder-driven Preview-deploy spot check before merge.
- If Digital Architect is ever made affiliate-selectable again (not currently planned), re-derive its dark palette from the other 4 presets' now-established pattern in `theme-presets.ts` rather than resurrecting the deleted values, which predate the border-mute/no-diluted-container fixes this ADR made to the other 4.

### 2026-11-10 addendum — Cyber Bumblebee dark takes the "Bumblebee Dark v3" palette

**Context.** The founder redesigned Bumblebee dark in Figma ("Bumblebee Dark v3", page 12583): charcoal layers instead of amber-brown, text never pure white, and yellow kept for action and product. They asked for the colours only, not the v3 layout, hairline borders or shadow changes. The preset is used by PajoStore and FixFast Demo. PekanGame itself stays on Digital Architect light.

**Decision.**
1. **Bumblebee `tokensDark` takes the v3 hexes:**
   - page `#111113`, card `#18181B`, band `#16161A`, raised `#202024`, image tile `#2A2A2F`, footer `#0B0B0D`;
   - text `#F2F0EA`, muted `#A6A39B`;
   - yellow `#FFC700` with ink `#111113` on it, a `#B38B00` container, and the 14% yellow tint flattened to `#383017`;
   - green `#5BD08A` for success.
2. **Three deviations from the v3 hexes, each for contrast:**
   - `ink` is `#8C8983`, not v3's 8% white line. It is both the 2px border and the bottom-nav pill under `surface` labels, which need 4.5:1 (5.4:1 here; the border reads 5.1:1 on a card).
   - `outline` is `#8C8983`, because it is used as text (v3's dim `#77746E` is 3.8:1).
   - Warning and success text take the yellow and the green, because the light defaults are unreadable on charcoal.
3. **The Tinted-Surface Rule** (`storefront/DESIGN.md`) no longer applies to Cyber Bumblebee dark. Its identity is yellow on ink black, so a neutral charcoal is its own hue family. The rule's guard against a generic palette is met by the yellow budget instead. It still applies to the other presets.

4. **The bottom nav and the More drawer's backdrop get their own roles.** The founder reviewed the screens and approved this.
   - The roles are `--color-nav`, `--color-on-nav`, `--color-on-nav-muted` and `--color-scrim`, declared in `storefront/src/app/globals.css`.
   - Their defaults reproduce the old look: ink pills, `surface` labels, an ink scrim.
   - Bumblebee dark sets:
     - a charcoal pill `#18181B`;
     - the active label in yellow, 11.3:1 (v3's "yellow = active nav");
     - the inactive labels `#A6A39B`, 7.0:1 (was 2.9:1);
     - a black scrim, so the drawer dims the page instead of lightening it.
   - Measured live with `getComputedStyle`, the other presets are unchanged:
     - Digital Architect light: pill `#19192f`, 6.32 / 15.64;
     - Bumblebee light: 6.52 / 16.18, the ADR-090 values.

**Consequences to track.**
- Red Giants, Emerald and Cobalt dark still use the default nav: a light `ink` pill with inactive labels at about 2.7:1 (Red Giants measured: 2.69 / 4.55), and a scrim that lightens. Setting the four roles in each dark set fixes it, with no component change.
- `text-danger` (`#ba1a1a`, global) is 2.9:1 on charcoal, as it was on the old brown. No dark preset overrides it.
