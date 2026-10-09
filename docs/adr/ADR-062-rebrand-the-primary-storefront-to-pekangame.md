# ADR-062: Rebrand the primary storefront to "PekanGame"

> **Standing (2026-10-09):** In force. Build and live status: [`prd.md` §15](../prd.md). The text below is a dated record; a later addendum in this file overrides earlier text, including the **Status** line.

**Status:** Accepted (design) — 2026-09-01, grilled with the founder (`grilling` + `impeccable`, same session as ADR-063/064). Ships on `feature/pekangame-storefront-redesign`; may split to its own PR ahead of the visual work.

**Context:**
- The storefront's public brand is still "Kedai Runcit Soloz" — a name the founder is moving off. New canonical name: **PekanGame** (one word).
- ADR-061 abolished the platform-owner special-case: the storefront's brand is the `is_primary` `Reseller` row (`business_name = 'Platform Owner'`), and every customer-facing brand string flows from `reseller_branding.store_name` (ADR-028) with `{store_name}` substitution — *except* a set of hardcoded fallbacks in `storefront/` that render when the branding API is unreachable or a field is empty: `layout.tsx` meta title, the per-route `<title>` strings in `order/[slug]`, `order/status/[orderNumber]`, `privacy`, `about-us`, `terms`, `track-order`, `membership`; `SeoBlurb`, `WhyChooseUsSection`, the `SiteHeader` wordmark, `OrderStatusTracker`'s "Contact Soloz Support", a `placeholder-data` testimonial quote, and the `globals.css` header comment.
- One backend string is customer-visible outside the app: `CheckoutService` sends `"KedaiRuncitSoloz order {order_number}"` as the CHIP payment description — it appears on the customer's bank / e-wallet statement.
- `Reseller::platformOwner()` → `Reseller::primary()` is already done (ADR-061 PR-A); no live code path keys on the literal `business_name` value any more. Three historical migrations (`2026_08_30_110000`, `2026_08_31_010000`, `2026_09_01_000200`) still resolve the primary row by `where('business_name', 'Platform Owner')` inline and run before `is_primary` exists on a `migrate:fresh` — they must keep working.

**Decision:**

1. **Canonical name is "PekanGame"** (one word, capital P + G). Rendered as an all-caps wordmark in the header/footer (Space Grotesk, per ADR-063); written "PekanGame" everywhere in copy, meta, and JSON-LD.

2. **Storefront hardcoded fallbacks** — every "Kedai Runcit Soloz" / "Soloz" / "KEDAI RUNCIT SOLOZ" literal in `storefront/src` is replaced with "PekanGame" (or the natural possessive / short form). These are fallbacks only; the live path still reads `reseller_branding.store_name`.

3. **Backend brand data** — one new migration renames the primary reseller row: `resellers.business_name` `'Platform Owner'` → `'PekanGame'`, and updates that reseller's `reseller_branding.store_name` (+ any seeded `description`) to "PekanGame". Guarded (`where('is_primary', true)`, skipped if absent — fresh test DBs untouched). `DatabaseSeeder` and `OrderFactory` swap their literal `'business_name' => 'Platform Owner'` to `'PekanGame'`. The three historical migrations keep their inline `'Platform Owner'` lookups (they run first on `migrate:fresh`, creating the row under the old name; the new migration then renames it).

4. **CHIP payment description** — `CheckoutService` sends `"PekanGame order {order_number}"`.

5. **Out of scope — deliberately not renamed:** the repo / directory name (`kedairuncitsoloz`), the database name, the git remote, session / cookie key prefixes (`kerox_*`), and any other purely-internal identifier. Renaming those is churn with no user-visible benefit and a large blast radius.

6. **`Logo.tsx`** — the diamond "S" emblem is Kedai Runcit Soloz's mark. It is replaced with a PekanGame placeholder mark (ADR-063 decision 8); a real exported asset drops into `storefront/public/` and swaps in later.

7. **Admin-editable content already in the DB** (footer text, legal page bodies, hero slides, SEO meta templates) is **not touched by code** — the founder updates those through `/admin` at rollout. This ADR only changes what ships as the default / fallback.

**Rationale:** Post-ADR-061 the rename is almost entirely a string swap plus one guarded migration — no logic keys on the brand name. Keeping the internal identifiers (repo, DB, cookie keys) as-is avoids a multi-hundred-file rename that buys nothing. The payment-description change is the one backend edit worth making because it is the only brand string a customer sees on an external system.

**Consequence to track:**
- The E2E storefront-checkout golden path (ADR-023) does not assert on the brand name (checked — it asserts "Delivered" and payment-flow text only), so the rename does not break it. Re-run `cd e2e && npm test` after the change regardless.
- Migration gotcha (§14): run plain `php artisan migrate` against the local dev DB — `php artisan test` passing is not proof the local DB is renamed. Never `migrate:fresh`.
- If ADR-060 later makes `store_name` fully `Host`-resolved, these hardcoded fallbacks stay as the "no brand resolved / API down" default. An unresolved `Host` returns 404 (ADR-060 decision 2), so the fallback is only ever seen on the primary storefront — "PekanGame" is the right default there. Acceptable.
- PRD §14 build-log entry + §15 tracker note owed when this ships.

**Addendum, 2026-09-01 (deploy-prep, `feature/rename-pekangame`) — rename extended to admin + reseller + the order-number prefix, before production go-live.** The founder's reason: shipping to production with two names still live anywhere a human looks ("PekanGame" storefront, "KedaiRuncitSoloz" admin, `KRS-` on bank statements) is a standing source of confusion, and the deploy pipeline itself is already uniformly `pekangame` (`registry.digitalocean.com/pekangame`, DB `pekangame`, `api.pekangame.space`, `/opt/pekangame`). Changes made:

1. **`admin/` chrome** — `layout.tsx` meta title, `AppHeader`, `AppSidebar` ("KedaiRuncitSoloz Admin" / "KRS" → "PekanGame Admin" / "PG"), and two Settings placeholders (`FooterSettingsSection`, `StoreBrandingSection` support-email). Display-only; no admin logic keys on these.
2. **`reseller/` chrome** — `layout.tsx` meta title.
3. **`README.md` / root `AGENTS.md`** H1 → "PekanGame".
4. **Order-number prefix** — `OrderNumberService::PREFIX` `KRS-` → `PG-`, and the format shortened from `PREFIX + Str::ulid()` (29 chars) to `PREFIX + 12 uppercase base36 chars` (15 chars) at the founder's request — it lands on the customer's bank/e-wallet statement and the ULID was needlessly long. `reference_number` (`REF-`, supplier idempotency key) is brand-neutral and unchanged. Entropy drops from ULID's ~80 random bits to ~62 (12 × log₂36) from a CSPRNG (`Str::random`) — still far beyond brute-force for the "knowing the number = proof of ownership" trust model on `GET /api/track-order/{n}` (ADR-011), and `orders.order_number`'s `UNIQUE` constraint is the hard backstop against the (negligible, ~1-in-4.7e18) collision. Updated with it: `OrderFactory`, `E2ESeeder` + `e2e/tests/constants.ts` fixtures (`KRS-E2E-*` → `PG-E2E-*`), the `OrderNumberServiceTest` / `CheckoutServiceTest` prefix assertions, the `TrackOrderController` / `OrderStatusUpdated` docblocks, and the storefront `TrackOrderClient` placeholder. Safe window: production is not live, **zero real orders exist**.
5. **Internal-identifier cleanup (cosmetic only):** `BackupRestoreTester`'s dead MySQL-DB-name fallback `'kedairuncitsoloz'` → `'pekangame'` (only reached if `config('database.connections.mysql.database')` is null, which prod never is); test-fixture storage paths `kedairuncitsoloz/backup.zip` → `pekangame/backup.zip`.

**Still deliberately not renamed** (decision 5 stands): local repo directory, git remote (`topup-website`), the actual database name, cookie/session key prefixes (`kerox_*`), the Herd dev hostname `kedairuncit-backend.test` (local-only; prod uses `api.pekangame.space`), and `backend/.env.example`'s `REVERB_APP_ID` (an arbitrary local identifier; tooling blocks `.env*` edits and prod sets its own). Historical ADR/PRD text and the ADR-020 "kedairuncitsoloz" references (that is the prospective *partner company*, a different entity — see `legacy-reference-notes.md`) are left as written.

This addendum is open to challenge at review like any decision — the prefix token and length especially.
