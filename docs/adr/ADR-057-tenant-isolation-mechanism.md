# ADR-057: Tenant isolation mechanism — `BelongsToReseller` trait + Eloquent global scope, retrofitted before any reseller-scoped endpoint

> **Standing (2026-10-09):** In force. Build and live status: [`prd.md` §15](../prd.md). The text below is a dated record; a later addendum in this file overrides earlier text, including the **Status** line.

**Status:** Accepted — built + merged 2026-08-30 (design grilled with the founder via `/mattpocock-skills:grilling`, same session as ADR-056).

**Built:** `App\Support\CurrentReseller` (`scoped` binding — reset per request/job, populated by the ADR-058 middleware, inert everywhere else); `App\Models\Scopes\ResellerScope` (not-active → unconstrained; active + id → `where reseller_id`; active + no id → `where 1=0` fail-closed; `runWithout()` bypass); `App\Models\Concerns\BelongsToReseller` trait (boots the scope, provides `reseller()` + `withoutResellerScope()`). Trait applied to `Order`, `ResellerBranding`, `ResellerFooterSettings`, `ResellerSeoSettings`, `Redirect` (their duplicate `reseller()` methods removed). `SeoScript` excluded — its `null reseller_id` means "global", which a `= <id>` scope would wrongly hide, and it is admin-only. Migration `2026_08_30_110000_add_reseller_id_index_and_backfill_orders`: standalone `orders.reseller_id` index (guarded — SQLite never auto-indexes the FK; MySQL's `orders_reseller_id_foreign` already covers it) + NULL→`platformOwner` backfill (only resolved when rows actually need it). `tests/Feature/Database/ResellerScopeTest.php` proves (a)/(b)/(c)/(d) across all five models + both escape hatches. 1085/1085 fast + 10/10 concurrency.

**Deviation from decision 2 (resolved):** `orders.reseller_id` shipped **nullable** here — flipping it in this PR would have turned a focused isolation change into a suite-wide fixture refactor. The scope fails closed on reads regardless, so a tenant-less row is invisible under the reseller guard. **Resolved in [ADR-061](./ADR-061-every-storefront-is-a-reseller.md) PR-B (2026-08-31):** `OrderFactory` added, column flipped to NOT NULL, FK `nullOnDelete()` → `restrictOnDelete()` (SET NULL is illegal on a NOT NULL column in MySQL).

**Context:**
- `ADR-003` (2026-07-23) decided "tenant scoping enforced via ORM global scopes, not per-query convention" and "`reseller_id` as a first-class column, tenant scoping enforced via ORM global scopes." Confirmed against code this session: **neither was actually built.** `orders.reseller_id` is a plain nullable, unconstrained column; only `orders`, `reseller_branding`, `reseller_footer_settings`, `reseller_seo_settings`, `redirects`, `seo_scripts` carry `reseller_id` at all; there is no global scope, no trait, no tenant middleware anywhere in `app/`.
- Every reseller-scoped read in the coming portal (ADR-059) — "my orders", "my earnings", "my customers" — is a cross-tenant data-leak vector if scoping is left to per-query discipline, which is exactly the risk `ADR-003`'s own rationale named ("a single missed `WHERE` clause becomes a cross-tenant data leak").
- This must land **before** any reseller-guard endpoint that returns tenant data — it is the hard prerequisite for ADR-059.

**Decision:**

1. **`BelongsToReseller` trait** — adds a `reseller()` `BelongsTo` and boots an Eloquent **global scope** that constrains queries to the current tenant when a reseller-guard session is active (`auth('reseller')->check()`), and applies **no constraint** for the platform/admin guards, the console, and queue jobs (which legitimately operate across all tenants). The "current reseller" is resolved from the authenticated `reseller_user`'s `reseller_id` (ADR-058) held in a request-scoped resolver — **never from a request parameter**.

2. **Add a real `reseller_id` FK + standalone index to `orders`** (currently unconstrained per that migration's own note — `foreignId()->constrained()` has been found once in this codebase to not reliably leave a standalone index, so verify via `SHOW INDEX`), backfilled to the `Reseller::platformOwner()` row for every existing row (`ADR-013` — every existing order genuinely is the platform owner's). `ledger_entries` / `withdrawals` already key on polymorphic `owner_type` / `owner_id`, so an earnings account is already reseller-owned with no column change (see the consequence note). Any new reseller-owned table (ADR-058/059/060) uses the trait from creation.

3. **The scope is deny-by-default for the reseller guard** — a model using the trait with no resolvable current reseller under the reseller guard returns **zero rows, never all rows**. A missing tenant context is a bug that fails closed.

4. **Admin / platform code paths are explicitly unscoped** — `/admin/orders` showing every reseller's orders labelled per-reseller (founder, this session) works because the admin guard applies no constraint. Where an admin needs to act *as* one reseller (impersonation, ADR-058 RES-4), that runs under a real reseller-guard token, so the same scope applies automatically.

5. **A `withoutResellerScope()` escape hatch** exists for the rare legitimate cross-tenant query under a reseller context (there are none planned; it exists so a future need doesn't tempt a raw query that bypasses the trait entirely).

6. **The retrofit is its own PR, with a test per newly-scoped model** proving: (a) a reseller session sees only its own rows, (b) the admin session sees all, (c) a queue job sees all, (d) no tenant context under the reseller guard sees none.

**Rationale:** A global scope keyed off the *guard* (not a parameter, not a per-query `where`) is the only approach that makes the safe path the default and the unsafe path something you have to write on purpose — which is what `ADR-003` was aiming at and didn't reach. Deny-by-default (decision 3) matters because the failure mode of "fail open" here is a silent cross-tenant financial-data leak, the worst outcome this system can produce short of losing money.

**Consequence to track:**
- Hard prerequisite for ADR-059 — done, sequenced before it. No dependency on production deployment.
- `ADR-003`'s status note updated (2026-08-30) — its "schema tenant-aware from day 1" claim is now marked partially superseded.
- `orders.reseller_id` NOT NULL — **done in ADR-061 PR-B (2026-08-31)**, along with the `OrderFactory` it was waiting on.
- `CurrentReseller` lifecycle is owned by the ADR-058 reseller-guard middleware: it `activate()`s on the way in and the `scoped` binding handles teardown. Shipped in ADR-058 58a.
- Global scopes are trivially bypassed with `DB::table()` / raw queries — a grep for `DB::table(` in reseller-context code belongs on the ADR-059 review checklist.
- The polymorphic `owner_type` / `owner_id` ledger design means `ledger_entries` itself does **not** use the trait (it has no `reseller_id`). Reseller-scoped ledger reads must go through a service that filters `owner_type = 'reseller', owner_id = <current>` explicitly (`ResellerEarningsService`, ADR-059). Document that seam so it's not mistaken for an unscoped gap. **Note (2026-08-30 review):** `owner_type` is the literal string `'reseller'` in code (`LedgerService`, `OrderFulfillmentService::creditProfit()`, `ResellerTierFeeService`) — **not** `Reseller::class`. `ResellerEarningsService` must filter on `'reseller'`. A `LedgerOwnerType` enum to replace these string literals at a money seam is recommended before ADR-059 writes that service (see the post-ADR-057 review addendum on ADR-060).
