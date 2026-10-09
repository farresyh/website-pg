# ADR-081: Affiliate storefront theme presets — a curated fixed set, NOT the THM-1..4 custom theme system

> **Standing (2026-10-09):** In force. Build and live status: [`prd.md` §15](../prd.md). The text below is a dated record; a later addendum in this file overrides earlier text, including the **Status** line.

**Status:** Accepted — retroactively documented + grilled 2026-09-09; **shipped 2026-09-09 (PR #150) ahead of this ADR (process slip, noted below).** BUILT + on `main` (live).

**Context:**

PRD §6.15 (THM-1..4) and ADR-059/ADR-060 both parked a "reseller theme system" as explicitly out of scope — "its own future ADR". THM-1..4 as written meant a fairly heavy feature: a custom colour + typography picker per brand, a per-reseller theme-permissions table (THM-3), and admin bulk theme-assignment (THM-4).

PR #150 (commit `2278d9e`, 2026-09-09) shipped a much smaller thing, without an ADR: `affiliate_branding.theme_preset` (string column, default `'default'`), a fixed library of five presets (`default` / `bumblebee` / `redgiants` / `emerald` / `cobalt`) defined in `theme-presets.ts` (one copy in `reseller/src/lib/`, one in `storefront/src/lib/`), a portal "Theme" tab (`reseller/src/components/storefront/ThemeTab.tsx`) with a live preview, and a `<style id="pg-theme-preset">:root{ --color-*: … }</style>` block injected server-side in `storefront/src/app/layout.tsx` that overrides only the Material-3 **colour** tokens (skipped entirely for `default`). Structure — the neo-brutalist 2px ink strokes, hard shadows, type scale (ADR-063) — is untouched by a preset. The write path validates `theme_preset` against `in:default,bumblebee,redgiants,emerald,cobalt` in `UpdateBrandingRequest`.

This ADR documents that shipped design, and the grill confirmed its scope ceiling.

**Decision:**

1. **The curated fixed-preset picker IS the answer to "reseller theme system", and it is the permanent shape — not an interim step toward full custom theming.** An affiliate picks one of a small, hand-designed set. The preset overrides colour tokens only; every brand keeps the same structural design language.

2. **THM-1's custom colour/typography picker, THM-3's per-reseller theme-permissions table, and THM-4's admin bulk theme-assignment are dropped, not deferred.** They can be re-argued if a real, sized demand appears — but "we might want it" is not that. Rationale: a fixed set keeps every whitelabel storefront on-brand and legible, carries zero design-QA/support burden per tenant, and needs no colour-contrast validation tooling or per-tenant preview infrastructure.

3. **The platform's own primary / `is_owned` brand may select a non-`default` preset.** The column exists on its `affiliate_branding` row and the injection path already handles it; a seasonal/campaign re-skin of the main storefront with no deploy is a feature, not a risk (the token values are a fixed server-controlled map keyed by a validated enum — no injection surface).

4. **Adding a preset is a deliberate, code-reviewed change** (new entry in the preset map + a design pass on the palette), not a runtime/admin operation. There is no preset CRUD.

**Rationale:**

- The shipped feature is genuinely useful and genuinely small — it gives a real affiliate a sense of ownership over their storefront without opening the door to unreadable colour combinations, broken contrast, or a support queue of "my store looks wrong".
- Keeping structure fixed and only swapping colour tokens means a preset can never break layout — the failure modes of a full theme system don't exist here.
- The process slip (code shipped before the ADR) is logged honestly; the grill after the fact reached the same place the design would have, so nothing is being retrofitted to match code.

**Consequence to track:**

- **The preset definition is duplicated in three places:** `reseller/src/lib/theme-presets.ts`, `storefront/src/lib/theme-presets.ts` (full token maps), and the `in:` rule in `UpdateBrandingRequest` (the ID list). A 6th preset means editing three files with no compile-time link between them. **Follow-up (`fix/storefront-review-scoping` batch or its own):** derive the `in:` rule from a single PHP array of keys (kills the 3rd copy) and add a test asserting the two TS files' keys match that array. The real fix — a backend `GET /api/catalog/theme-presets` endpoint both frontends consume — is only worth it at preset #6+.
- **Dark mode is still a separate future ADR** (per §6.15 and ADR-063's "light-only v1" note). Presets today define light `:root` tokens only. **When that ADR is opened it MUST add a `tokensDark` variant per preset** — dark mode is not allowed to collapse every brand back onto one identity. Pin this as a hard requirement on that future ADR, not a nice-to-have.
- **No contrast/accessibility gate on preset palettes** — they are hand-checked at authoring time. If presets ever become numerous or externally contributed, add an automated contrast check.
- Keep `docs/prd.md` §6.15 (THM rows) and §15 current — this ADR closes THM-1/3/4 as "dropped".
