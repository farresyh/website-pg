# ADR-003 (D3): Tenant-aware schema, platform-owner-only MVP features

> **Standing (2026-10-09):** Partly superseded by ADR-057 (tenant scoping). Build and live status: [`prd.md` §15](../prd.md). The text below is a dated record; a later addendum in this file overrides earlier text, including the **Status** line.

**Status:** Accepted — 2026-07-23. **Partially superseded by ADR-057 (2026-08-30):** the "tenant scoping enforced via ORM global scopes" clause was aspirational, never built during MVP — `orders.reseller_id` shipped as a plain nullable, unconstrained column with no scope, trait, or middleware. ADR-057 is the actual retrofit (`BelongsToReseller` trait + `ResellerScope` global scope + `orders.reseller_id` FK index + platform-owner backfill).

**Decision:** Schema and query layer are built multi-tenant-aware from day 1 (`reseller_id` as a first-class column, tenant scoping enforced via ORM global scopes, not per-query convention). MVP *feature* scope, however, is platform-owner-only — no reseller self-service dashboard, no custom domains, no reseller onboarding flow.

**Rationale:** Retrofitting tenant isolation onto a single-tenant schema after real data exists is expensive and risky — a single missed `WHERE` clause becomes a cross-tenant data leak. Architecture readiness is cheap now; reseller-facing *features* are expensive and can be deferred without penalty. This separates the cheap decision (schema shape) from the expensive one (build reseller UX).
