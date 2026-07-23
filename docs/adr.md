# Architecture Decision Log — Game Top-Up Reseller Platform

Immutable record of foundation decisions made before any code was written. Each entry is *why*, not *what* — the living spec lives in [`prd.md`](./prd.md), the security checklist derived from these decisions lives in [`foundation-security.md`](./foundation-security.md).

**Do not edit past entries to reflect new thinking.** If a decision changes later, add a new entry that supersedes it and say so explicitly — the history of *why we changed our minds* is as valuable as the original rationale.

---

## ADR-001 (D1): Payment gateway — Xendit

**Status:** Accepted — 2026-07-23

**Decision:** MVP uses Xendit's standard Invoice/Payment API only (single merchant account, platform collects 100% of payment). **xenPlatform** (sub-account fund-splitting) is deferred to Phase 2.

**Rationale:** Xendit confirmed available in Malaysia (FPX, DuitNow QR, cards) with xenPlatform available for future marketplace-style splitting once real third-party resellers are onboarded with automatic settlement. Using xenPlatform on day 1 adds sub-merchant KYC/onboarding overhead the MVP doesn't need — all money is the platform owner's in MVP, so no split is required.

**Addendum — payment-gateway seam, 2026-07-23:** Xendit requires business-credential/KYC verification before approving an account, and approval isn't guaranteed — if Xendit rejects the platform's business verification, a fallback gateway with a sub-account/split-payment feature comparable to xenPlatform would be needed (that feature, not just "any gateway," is the actual requirement driving the fallback candidate list). Unlike the Supplier situation (ADR-006), no second payment gateway is confirmed or researched yet, so building a second full implementation now would be guessing at an unconfirmed shape. Instead: build directly against Xendit's Payment Request API, but behind a **thin** `PaymentGateway` interface (`createPayment()`, `getPayment()`, `verifyWebhookSignature()`, `parseWebhookEvent()` — see `app/Services/Payment/`) so that *if* a swap is ever needed, it costs one new adapter implementation, not a rewrite of Order/Ledger business logic. This is a smaller, cheaper seam than the Supplier Adapter (4 methods vs. 5, no capability-flag complexity) precisely because — unlike suppliers — only one gateway is ever actually in use at a time; the interface exists purely to isolate switching risk, not to run multiple gateways concurrently. Does not change the MVP decision above (Xendit only, xenPlatform deferred) — it only changes how the Xendit integration is wired internally.

---

## ADR-002 (D2): Ledger-based balance, not a mutable column

**Status:** Accepted — 2026-07-23

**Decision:** Every credit/debit (order profit, withdrawal, voucher issuance) is written as an immutable `LedgerEntry` row. Current balance for any owner (platform or, in Phase 2, reseller) is always `SUM(ledger entries)` — never a `balance` column that gets directly `UPDATE`d.

**Rationale:** A mutable `balance` column is vulnerable to race conditions — e.g. two concurrent withdrawal requests both reading the same starting balance before either write completes, resulting in double payout. A ledger also gives a full, queryable audit trail for financial disputes ("why is the balance what it is?" is always answerable).

**Addendum — implementation, 2026-07-23:** `SELECT SUM(...) FOR UPDATE` on `ledger_entries` alone can't serialize concurrent withdrawals reliably: a brand-new owner has zero existing rows to lock, so two first-ever withdrawals could both pass the sufficiency check before either commits. Solved with a second table, `ledger_accounts` — one row per owner, created upfront, holding **no balance data at all**, existing purely as a lock anchor. `LedgerService::withdraw()` runs inside a DB transaction that `lockForUpdate()`s this row before computing `SUM(ledger_entries)` and checking sufficiency, so a second concurrent withdrawal attempt blocks until the first commits. Verified with a real two-OS-process test (`tests/Concurrency/LedgerWithdrawConcurrencyTest.php`, run via `phpunit.concurrency.xml` against the dockerized MySQL in `docker-compose.yml` — SQLite's file-level locking can't faithfully exercise this, so the default `:memory:` test suite doesn't cover this specific guarantee). This does not weaken the "no mutable balance column" principle above: `ledger_accounts` never stores an amount, only `ledger_entries` does.

---

## ADR-003 (D3): Tenant-aware schema, platform-owner-only MVP features

**Status:** Accepted — 2026-07-23

**Decision:** Schema and query layer are built multi-tenant-aware from day 1 (`reseller_id` as a first-class column, tenant scoping enforced via ORM global scopes, not per-query convention). MVP *feature* scope, however, is platform-owner-only — no reseller self-service dashboard, no custom domains, no reseller onboarding flow.

**Rationale:** Retrofitting tenant isolation onto a single-tenant schema after real data exists is expensive and risky — a single missed `WHERE` clause becomes a cross-tenant data leak. Architecture readiness is cheap now; reseller-facing *features* are expensive and can be deferred without penalty. This separates the cheap decision (schema shape) from the expensive one (build reseller UX).

---

## ADR-004 (D4): No cash refunds, ever

**Status:** Accepted — 2026-07-23

**Decision:** Failed or undeliverable orders are resolved by (a) retry delivery, or (b) issuing a Voucher (store credit) — never a refund back to the original payment method.

**Rationale:** Eliminates an entire class of payment-gateway refund-API integration risk (partial refunds, reconciliation) and closes a common cash-out fraud vector (stolen-card fraud can't be laundered into cash back via a refund). Directly reinforced by ADR-005.

---

## ADR-005 (D5): Player-ID pre-payment validation is not universal

**Status:** Accepted — 2026-07-23

**Decision:** The order flow must support two paths: validate-before-payment where the supplier/game supports a dedicated validation endpoint, and payment-then-validate-at-order-time as a fallback where it doesn't.

**Rationale:** Confirmed via direct research on real supplier APIs that dedicated validate-ID endpoints are typically only available for specific games (e.g. MLBB), not universally offered by every supplier for every game. This cannot be engineered around — for suppliers without pre-validation, "customer pays, then player ID turns out invalid" is a real scenario that must be handled by policy (ADR-004's voucher-only refund), not assumed away by code.

---

## ADR-006 (D6): Supplier Adapter/Normalizer layer is MVP, not Phase 2

**Status:** Accepted — 2026-07-23 (supersedes the original PRD v0.2 draft, which listed a "Custom Supplier Adapter SDK" as a Phase 2 / future-phase item)

**Decision:** Every supplier integration is implemented behind a common internal interface. Business logic never reads a raw supplier response directly — every adapter normalizes into one canonical internal shape.

**Rationale:** Comparing two real supplier APIs during the foundation audit (Gamevion API docs; a second store's MLBB-validator endpoints) showed materially different auth schemes, response envelopes, and error shapes even between just two suppliers. Without a normalizing layer from day 1, every new supplier integration touches business logic directly, and inconsistent error handling across suppliers becomes a direct source of financial/logic bugs.

---

## ADR-007 (D7): Internal fraud blacklist is first-class MVP

**Status:** Accepted — 2026-07-23

**Decision:** The system maintains its own internal blacklist of player IDs and/or customer contacts, checked at order creation — independent of whatever blacklist (if any) a given supplier offers.

**Rationale:** Observed in a real competitor's API integration (a `check-blacklist` endpoint). The platform should not depend on a supplier providing this protection — it must be built and owned internally, populated from the platform's own chargeback/fraud history.

---

## ADR-008 (D8): Split frontend stack — Vue for Admin, Next.js for Storefront

**Status:** ~~Accepted~~ **Superseded by ADR-009** — 2026-07-23 (same-day reversal, before any code was written)

**Decision:** Admin Panel is a Vue 3 + Vite SPA. Storefront is Next.js (React). This is a deliberate two-ecosystem split, not a single unified frontend framework.

**Rationale:** Admin Panel has no SEO/SSR requirement (it's behind auth, never indexed) — a plain Vue 3 SPA matches the legacy system's precedent and keeps things simple. Storefront has a genuine SEO/SSR requirement (see PRD §6.16 — meta tags, OG images, per-game SEO exist specifically to drive organic traffic), which a plain client-rendered SPA serves poorly. The team chose Next.js over the Vue-ecosystem alternative (Nuxt 3) specifically for React's larger ecosystem and Next.js's SSR maturity, consciously accepting the cost of maintaining two frontend stacks instead of one.

**Why superseded:** the "matches legacy precedent" argument only carries weight if the team is actually going to reference/maintain the legacy Vue codebase directly — for a greenfield build by a team that already prefers React (as evidenced by choosing Next.js over Nuxt for the Storefront in this same decision), keeping Vue for Admin only adds a second framework to learn and maintain, with no offsetting benefit. See ADR-009.

---

## ADR-009 (D8-revised): Unify frontend stack — Next.js (React) for both Admin Panel and Storefront

**Status:** Accepted — 2026-07-23 — supersedes ADR-008

**Decision:** Both Admin Panel and Storefront are built in Next.js (React), under Laravel's REST API. Admin Panel uses Next.js in a client-rendered/SPA-style mode (`"use client"` throughout, no server-side data fetching through Next) — it calls the Laravel API directly from the browser using the Bearer token (AUTH-2), the same way the Storefront's dynamic/authenticated parts would. Next's SSR/React Server Components are used only where the Storefront actually benefits from them (public, SEO-relevant pages).

**Rationale:** Once the team confirmed a genuine preference for React over Vue (ADR-008's own storefront decision), maintaining Vue for Admin Panel purely for "legacy precedent" cost more (a second framework, second tooling/testing/CI setup, no shared component/utility code between the two apps) than it saved. A single stack means one set of conventions, and a shared API-client/types layer between Admin and Storefront. Admin Panel gains nothing from SSR (it's behind auth, never indexed), so it deliberately avoids the added complexity of Next's server/client component boundary — treat it as "a SPA that happens to run on Next.js," not a server-rendered app.

**Consequence to track:** because Admin Panel doesn't need Next's SSR features, watch for accidental complexity creep (e.g. someone reaching for a Server Component or a Next API route for something that's simpler as a plain client fetch to Laravel) — if that starts happening often, it's a signal Admin Panel would genuinely be simpler as a plain Vite + React SPA instead of Next.js. Not a concern to solve now, just one to notice later.

---

## ADR-010: Docker for MySQL only, not full Laravel Sail

**Status:** Accepted — 2026-07-23

**Decision:** `backend/docker-compose.yml` runs a single MySQL 8.4 service, nothing else. PHP continues to run natively via Laravel Herd (already working). A dedicated PHPUnit config, `phpunit.concurrency.xml`, points only the small set of tests that need real row-level locking at this dockerized MySQL; the default `phpunit.xml` suite keeps using sqlite `:memory:` for speed.

**Rationale:** SQLite locks at the file level, not per-row, so it can't faithfully prove the ledger/voucher locking guarantees (ADR-002 addendum) — those specific tests need real MySQL `SELECT ... FOR UPDATE` semantics. Laravel Sail was considered but rejected for this stage: Sail also containerizes PHP, which would duplicate/conflict with the already-working native Herd setup for no added benefit — the only real gap was "we have no database server," not "we need a full containerized stack." Keeping Docker's footprint to just the one MySQL service is the minimal fix for the actual gap.

**Consequence to track:** concurrency-sensitive tests live in `tests/Concurrency/` and require `docker compose up -d` first; they are intentionally excluded from the default `php artisan test` run so day-to-day development never depends on Docker being up. Revisit if/when the project needs Sail's broader containerization (e.g. matching a containerized production deploy) — not needed for MVP.

---

## ADR-011: Storefront stays guest-checkout — no Customer account/auth in MVP

**Status:** Accepted — 2026-07-24

**Decision:** The storefront has no customer login/registration and no `Customer` table. A purchase only ever captures `customer_email`/`customer_phone` as plain strings on the `Order` row itself — there is no persistent customer identity anywhere in the system. Auth (Sanctum) exists only for `AdminUser` (Super Admin/Admin).

**Rationale:** Confirmed via live comparison of two real reference sites: keroxshop.com (our own current storefront) has no login/register anywhere — only a "Track Order" page that looks up status by order number, which is exactly the guest-checkout + reference-lookup model already assumed in the PRD's data model (no Customer entity was ever listed in §8). gamevion.com, by contrast, does have full customer accounts — but for a reason specific to its own business model, not applicable here: it runs a pre-funded customer wallet ("Top Up" your own balance, then spend it) plus a reseller/membership-tier program where customers can upgrade and refer others. Both of those features genuinely require persistent identity. Neither exists in this PRD's MVP scope — there is no customer wallet, and Phase 2's reseller concept is a business owner with their own storefront, not a walk-in customer upgrading their tier. Since the actual driver behind Gamevion's account requirement doesn't apply here, adopting accounts anyway would be copying a competitor's architecture without its underlying reason.

**Consequence to track:** if a customer-facing wallet, loyalty program, or persistent order-history feature is ever deliberately added later, it changes this decision and deserves its own ADR entry (per this file's own convention — supersede, don't silently rewrite). Until then, do not add a `Customer` model, customer login endpoints, or a `customer_id` foreign key "just in case" — `Order.customer_email` is sufficient for the guest-checkout model this ADR commits to.
