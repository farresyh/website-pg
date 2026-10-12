# ADR-043: `llms.txt` — auto-generated AI/LLM-context route (built 2026-08-22)

> **Standing (2026-10-09):** In force. Build and live status: [`prd.md` §15](../prd.md). The text below is a dated record; a later addendum in this file overrides earlier text, including the **Status** line.

**Status:** Accepted, built — 2026-08-22. Split out from ADR-029's Addendum 4 (2026-08-27 docs refactor) for readability — see ADR-029's cross-reference.

**Context:** Founder asked directly, in chat, whether llms.txt (llmstxt.org's proposed AI/LLM-context convention) belongs alongside robots.txt/sitemap.ts, since it's another crawler-facing file. Scoped down to two quick decisions rather than a full grilling pass — genuinely small surface, not a multi-table feature.

**Decision (verbatim from ADR-029's original Addendum 4):**

**Addendum 4, 2026-08-22 (same day, later) — founder asked directly, in chat, whether llms.txt (llmstxt.org's proposed AI/LLM-context convention) belongs here too, since it's another crawler-facing file like robots.txt/sitemap.ts. Scoped down to two quick decisions rather than a full grilling pass — genuinely small surface, not a multi-table feature.**

22. **`storefront/src/app/llms.txt/route.ts` — fully auto-generated from existing catalog/branding data, no new admin-authored field or table.** Same choice `app/sitemap.ts` already made for the same reason: nothing to drift out of sync with the real catalog, no second place for the founder to remember to update. Summary line falls back `reseller_seo_settings.default_meta_description` → `reseller_branding.description` → a generic hardcoded sentence; game list and static-page links pulled from the same `listGames()`/`SITE_URL` this app's `sitemap.ts` already uses.
23. **Only `/llms.txt` (the concise root convention) — `/llms-full.txt` deliberately not built.** llms.txt itself is a young, not-yet-officially-adopted proposal (unlike robots.txt, which every real crawler already respects); building a second, less-established variant on top of an already-unproven one isn't justified yet. Revisit only if real adoption signal shows up.
