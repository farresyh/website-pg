# ADR-008 (D8): Split frontend stack — Vue for Admin, Next.js for Storefront

> **Standing (2026-10-09):** Superseded by ADR-009. Build and live status: [`prd.md` §15](../prd.md). The text below is a dated record; a later addendum in this file overrides earlier text, including the **Status** line.

**Status:** ~~Accepted~~ **Superseded by ADR-009** — 2026-07-23 (same-day reversal, before any code was written)

**Decision:** Admin Panel is a Vue 3 + Vite SPA. Storefront is Next.js (React). This is a deliberate two-ecosystem split, not a single unified frontend framework.

**Rationale:** Admin Panel has no SEO/SSR requirement (it's behind auth, never indexed) — a plain Vue 3 SPA matches the legacy system's precedent and keeps things simple. Storefront has a genuine SEO/SSR requirement (see PRD §6.16 — meta tags, OG images, per-game SEO exist specifically to drive organic traffic), which a plain client-rendered SPA serves poorly. The team chose Next.js over the Vue-ecosystem alternative (Nuxt 3) specifically for React's larger ecosystem and Next.js's SSR maturity, consciously accepting the cost of maintaining two frontend stacks instead of one.

**Why superseded:** the "matches legacy precedent" argument only carries weight if the team is actually going to reference/maintain the legacy Vue codebase directly — for a greenfield build by a team that already prefers React (as evidenced by choosing Next.js over Nuxt for the Storefront in this same decision), keeping Vue for Admin only adds a second framework to learn and maintain, with no offsetting benefit. See ADR-009.
