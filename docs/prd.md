# PRODUCT REQUIREMENTS DOCUMENT (PRD)

## Game Top-Up Reseller Platform

**STATUS: DRAFT FINAL v0.3 (Hardened)**

| | |
| --- | --- |
| **Product Name** | Game Top-Up Reseller Platform |
| **Document Version** | v0.3 (Security & Financial-Integrity Hardening Pass) |
| **Prepared by** | Bubududu (System Analysis) — original v0.2 |
| **Reviewed & Hardened by** | Founder + Claude, foundation audit session, 2026-07-23 |
| **Related Documents** | [`adr.md`](./adr.md) — foundation decision log (the *why*); [`foundation-security.md`](./foundation-security.md) — security/financial-integrity checklist (the *guardrails*); [`legacy-reference-notes.md`](./legacy-reference-notes.md) — legacy system walkthrough (admin.keroxshop.com, manage.keroxshop.com), UI/UX reference only, never a source of project facts; supplier API research (Gamevion, MLBB-validator sample store) |

> Foundation decisions (payment gateway, ledger model, tenant strategy, refund policy, frontend stack, etc.) are recorded with full rationale in **[`adr.md`](./adr.md)**, not duplicated here. This document assumes those decisions and specifies the product built on top of them.

---

# 1. Product Overview

Game top-up reseller platforms currently exist as fragmented systems with separate admin panels (business operations) and supplier middleware (technical integration). These systems are tightly coupled to specific suppliers and lack modularity, scalability, and proper separation of concerns. Pain points include manual price syncing, lack of automated supplier failover, no clear audit trails, and difficulty onboarding new resellers.

The proposed system is a **greenfield multi-tenant-ready game top-up platform** built with modular architecture. It comprises four main layers: **(1) Supplier Middleware** — technical layer that handles all supplier API communication (product matching, price sync, player validation where available, order delivery, response normalization); **(2) Core API / Admin Backend** — business logic layer managing games, packages, pricing rules, resellers, orders, and payouts on a ledger-based financial model; **(3) Admin Panel** — web SPA for super admins to oversee operations; and **(4) Storefront** — customer-facing app, operated by the platform owner in MVP, architected so branded per-reseller storefronts can be switched on in Phase 2 without a schema rewrite.

**MVP framing:** build and prove the platform as a single-owner operation first (all profit, all risk, one storefront) on top of a tenant-ready schema. Onboard real third-party resellers — with their own markup and ledger-settled withdrawal (manual bank transfer, no xenPlatform) — once the core money-handling logic (pricing, ledger, order lifecycle, fraud controls) has been validated in production with real transactions. Reseller Phase 2 is now in active build (ADR-056..061).

---

# 2. Goals & Objectives

- **Centralize game credit operations** — single platform handling supplier integration, order processing, and financial reporting.
- **Automate supplier price synchronization** — eliminate manual price updates with scheduled sync, automatic reactivation approval, and exchange rate management.
- **Provide transparent multi-tier pricing, computed server-side only** — cost price (from supplier), system markup, and reseller markup ([ADR-056](./adr.md)/[ADR-061](./adr.md)), recomputed at order time from stored config — never trusted from client input.
- **Reduce order failures** — real-time supplier balance monitoring, automated retry on failure, circuit-breaking on repeated supplier failure, and clear escalation workflows for stuck orders.
- **Protect customer money and supplier balance from manipulation** — every requirement in this document that touches price, balance, or delivery must be read with the assumption that a hostile actor will try to exploit it.
- **Deliver actionable business intelligence** — live dashboards, conversion funnel analysis, and drill-down reports.
- **(Phase 2, in build) Enable rapid reseller onboarding** — branded storefronts with custom domains; the platform collects all retail into one company account and the ledger splits it (no xenPlatform — [ADR-059](./adr.md)). Reseller payout is a manual bank transfer via the existing WTH-1..5 flow.

---

# 3. Users & Roles

- **Super Admin :** Full system access — manages games, packages, suppliers, admin users, all orders, withdrawals, vouchers, blacklist, settings, and developer tools. Only role that can approve withdrawals above the maker-checker threshold, and the only role that can create a voucher above its own threshold (see WTH-5, VCH-6 — these are no longer the same mechanism, see VCH-6's own row for why).
- **Admin :** Operational access — manages orders, reports, reviews, withdrawals (below threshold), vouchers (below threshold), and customer analytics. Cannot modify system settings, supplier credentials, or blacklist rules.
- **Reseller (Phase 2, in build):** Owns a branded storefront with custom domain. Sets own markup within limits, manages own orders, views own reports and analytics, and requests withdrawals settled against the ledger (manual bank transfer, WTH-1..5 — no xenPlatform).
- **Customer :** End user who browses games, purchases top-up credits, provides player ID, and leaves reviews after purchase.

---

# 4. Scope

## 4.1 In Scope (MVP)

- Supplier Middleware — product matching, price sync, player validation **where supplier/game supports it**, order creation with idempotency, response normalization (Adapter layer), request logging, reconciliation polling.
- Admin Panel — dashboard, games & packages management, supplier management, orders management, reports, withdrawals (ledger-based), vouchers, reviews, backups, settings, profile, developer tools, **blacklist management**.
- Fraud & Integrity Controls — internal blacklist, webhook/callback signature verification where supported, idempotent order creation, MFA for Super Admin/Admin, maker-checker on large withdrawals/vouchers, checkout rate limiting.
- Customer Analytics — segmentation, spending patterns, repeat rate analysis.
- Storefront — single platform-owner-operated game catalog, top-up checkout, order tracking, reviews. Built on tenant-aware schema (see ADR-003) but not yet exposing multi-reseller UI.

## 4.2 Out of Scope / Future Phases (Phase 2+)

- ~~Reseller self-service onboarding, branded storefronts, custom domains, reseller theme system.~~ **All shipped** — onboarding/storefronts/domains via ADR-056..061 + ADR-078 (§15's Affiliates row); the theme system shipped as a **curated fixed-preset picker** (ADR-081, 2026-09-09 — colour tokens only; the THM-1/3/4 custom-colour/permissions/bulk-assign system is dropped, not deferred).
- ~~Xendit xenPlatform sub-account fund-splitting~~ — dropped, not deferred (ADR-059). The ledger does the split; reseller payout is a manual bank transfer.
- ~~The API / H2H "pull supply" channel + prepaid deposit wallet + top-up flow~~ — **shipped as the `Reseller` (prepaid wallet) system** (ADR-072..076: wallet + REST API keys + WhatsApp bot).
- Automated customer support chatbot, mobile native apps, loyalty/rewards program, affiliate marketing module, cross-reseller inventory sharing.
- Automated (non-admin-reviewed) refund decisions — a failed wallet/reseller order's `wallet_refund` credit is still always a deliberate, terminal admin action (never triggered by a webhook or a timer). ~~Automated reactivation decisions~~ — **shipped** as `PendingReactivationAutoApprover` (ADR-100, live prod since 2026-09-16, streak-only gate since its 2026-09-24 addendum) plus the two 2026-09-24 addenda that made a dependent combo's own reactivation cascade automatically too (ADR-094 for single-package reactivation call sites, ADR-046 for the supplier bulk toggle) — this line was never reconciled when ADR-100 shipped; caught 2026-09-24.

---

# 5. Assumptions & Constraints

- **Technology stack**: Laravel REST API for backend (proven pattern from legacy system). Frontend is a **unified Next.js (React) stack** for both apps (ADR-009, superseding the earlier Vue/Next split in ADR-008): **Admin Panel = Next.js in client-rendered/SPA mode** — calls the Laravel API directly from the browser via Bearer token (AUTH-2), no SSR needed behind auth. **Storefront = Next.js**, using SSR/React Server Components where it genuinely benefits SEO-relevant public pages (§6.16).
- **Database** assumed to be relational (MySQL/PostgreSQL). All data models in Chapter 8 reflect this assumption, including the ledger table.
- **Hosting** assumed to use Cloudflare CDN + nginx for production deployment.
- **Multi-tenant architecture**: schema tenant-aware (`reseller_id`) from day 1 per ADR-003; the ORM global scope it promised was actually built in [ADR-057](./adr.md) (2026-08-30). Every storefront — ours and third-party — is a `Reseller` row ([ADR-061](./adr.md), superseding ADR-013's platform-owner special-case).
- **Supplier APIs**: assumed RESTful/JSON, but **must not be assumed to have a uniform response shape, auth style, or validation capability.** Confirmed by direct research (Gamevion API, MLBB-validator sample store) that these vary per supplier and even per game. All supplier calls go through a normalizing Adapter layer (see 6.21) — no business logic touches raw supplier response shapes.
- **Payment gateway: CHIP only** (`PaymentGatewayFactory` kept for a future multi-region second gateway — [ADR-022's 2026-09-01 addendum](./adr.md) removed Xendit; launch channels FPX + DuitNow QR). Single company CHIP account; the platform collects 100% of every payment (including reseller storefronts) and the ledger does the split. **xenPlatform dropped entirely** — not deferred ([ADR-059](./adr.md) decision 6, superseding ADR-001's addendum).
- **Currency** assumed MYR as base, with dynamic exchange rate support for foreign-currency suppliers.
- **Auth** Bearer token-based with role middleware on backend — frontend routing is convenience, not security. MFA required for Super Admin/Admin (see AUTH-7).
- **Refund policy**: no cash refunds under any circumstance (ADR-004). Resolution paths for a failed order are limited to retry-delivery or voucher issuance.
- **Balance/financials**: ledger-based (ADR-002). No entity may expose or rely on a directly-mutated `balance` column as the source of truth.

---

# 6. Functional Requirements

## 6.1 System — Authentication & Authorization

| ID | Functional Requirement | Priority |
| --- | --- | --- |
| **AUTH-1** | System provides login page for Super Admin and Admin with email + password | **MVP** |
| **AUTH-2** | System supports Bearer token authentication with role-based middleware on the backend | **MVP** |
| **AUTH-3** | System provides role-based route guards on the frontend | **MVP** |
| **AUTH-4** | Super Admin can manage admin users (create, edit, activate, deactivate) | **MVP** |
| **AUTH-5** | Super Admin can manage API keys for system integration | **Phase 2** |
| **AUTH-6** | System displays profile page with edit personal info and change password | **MVP** |
| **AUTH-7** | System enforces MFA (TOTP) for Super Admin and Admin accounts — mandatory before any withdrawal/voucher/refund-affecting action | **MVP** |

## 6.2 Admin — Dashboard

| ID | Functional Requirement | Priority |
| --- | --- | --- |
| **DASH-1** | System displays KPI cards: Sales Today, Orders Today, Profit Today, Refunds Today with comparison vs yesterday | **MVP** |
| **DASH-2** | System displays System Health: supplier API status per supplier, supplier balances, stuck orders count, pending payments count, queue depth, **circuit-breaker state per supplier** | **MVP** |
| **DASH-3** | System displays Conversion Funnel (last 7 days): Orders Created → Payment Confirmed → Delivered with drop-off counts | **MVP** |
| **DASH-4** | System displays Top Games weekly ranking (order count + revenue + % change) | **MVP** |
| **DASH-5** | System displays Hourly Activity chart (orders per hour, last 7 days, day selector) | **MVP** |
| **DASH-6** | Dashboard data refreshes automatically every N seconds/minutes | **Important** |

## 6.3 Admin — Games & Packages Management

| ID | Functional Requirement | Priority |
| --- | --- | --- |
| **GAME-1** | System displays game list in card layout: image, name, category badge, status badge, package count | **MVP** |
| **GAME-2** | System provides game filters: All/Active/Inactive/Not Found at Supplier, plus search by name or supplier ID | **MVP** |
| **GAME-3** | Admin can edit game: name, slug, category, image, supplier mappings, validation rules, SEO fields | **MVP** |
| **GAME-4** | Admin can activate/deactivate game | **MVP** |
| **GAME-5** | Admin can delete game with confirmation | **MVP** |
| **GAME-6** | Admin can reorder games via drag-and-drop | **Important** |
| **GAME-7** | Admin can manage packages per game: add, edit, delete — each package has name, `markup_percent` (admin-set input; `standard_selling_price` is computed and stored from it, never typed directly — revised 2026-07-25, matches the legacy reference system's own Markup %/Base Price pattern; renamed from `reseller_cost_price` 2026-08-29, [ADR-027](./adr.md) — it was never actually reseller-specific), `cost_price` (supplier-sourced, never admin-editable), supplier reference | **MVP** |
| **GAME-8** | System displays platform profit margin per package (`standard_selling_price − cost_price`) — this is the platform's own margin, not the final customer-facing selling price (see §8 Package/Order for the full 3-tier breakdown) | **MVP** |
| **GAME-9** | Admin can sync input fields (pull field schema from supplier) per game or bulk | **MVP** |
| **GAME-10** | Admin can sync prices (pull latest pricing from supplier) per game or bulk | **MVP** |
| **GAME-11** | Admin can perform bulk price update (apply system markup to all packages) | **Important** |
| ~~**GAME-12**~~ | ~~Each supplier mapping on a Game stores its own `supports_validation` flag~~ — **❌ Dropped, 2026-09-13.** Neither supplier this platform has ever integrated (Gamevion, Digiflazz) exposes player-validation via its own API — both `validatePlayer()` unconditionally throw `ValidationNotSupportedException` (ADR-005's own "validation happens implicitly at order time"), and no code path anywhere ever read the flag this row described. Not a capability to build an admin toggle for. | — |

## 6.4 Admin — Price Sync Center

| ID | Functional Requirement | Priority |
| --- | --- | --- |
| **SYNC-1** | System displays stats: total games, active packages, pending reactivation count, last sync status & timestamp | **MVP** |
| **SYNC-2** | System displays last sync details: games total/success/failed, packages created/updated/deactivated, duration | **MVP** |
| **SYNC-3** | System displays currency exchange rate with history and manual refresh | **MVP** |
| **SYNC-4** | Admin can trigger Sync All Prices Now (full sync from all suppliers) | **MVP** |
| **SYNC-5** | Admin can review pending reactivation packages: table with package name, supplier, game, price, cost, reason, days inactive, actions (Approve/Dismiss). Since ADR-100 (§7.3 addenda), a package can also skip this queue and self-approve — this manual path is unchanged, still available for everything the automatic gate doesn't catch | **MVP** |
| **SYNC-6** | Admin can bulk approve or bulk dismiss pending reactivations | **MVP** |

## 6.5 Admin — Supplier Management

| ID | Functional Requirement | Priority |
| --- | --- | --- |
| **SUPP-1** | System displays supplier cards with logo, connection status, balance display, refresh balance button | **MVP** |
| **SUPP-2** | System displays supplier game catalog: list of available games from supplier APIs with product ID per supplier | **MVP** |
| **SUPP-3** | Admin can add game from supplier catalog to local games list | **MVP** |
| **SUPP-4** | Admin can search games in supplier catalog | **MVP** |
| **SUPP-5** | Supplier credentials (`api_config`) are encrypted at rest, never returned in full via any API response or shown unmasked in Developer Tools | **MVP** |

## 6.6 Admin — Image Gallery

| ID | Functional Requirement | Priority |
| --- | --- | --- |
| **IMG-1** | System provides image upload (drag-drop or file picker) for game assets | **MVP** |
| **IMG-2** | System displays grid/list view with search, preview, copy URL, and delete | **MVP** |

## 6.7 Admin — Affiliates Management (Phase 2)

**Renamed from "Resellers" to "Affiliates" — ADR-072 PR-A, 2026-09-04, zero behaviour change.** `Reseller`/`resellers` (this whitelabel-storefront-partner entity — branding, custom domain, subscription tiers, earnings ledger, portal login) is now `Affiliate`/`affiliates`, vacating the `Reseller` name for a new prepaid-wallet entity (ADR-073..075, later PRs). Every ID below (`RES-1..6`) keeps its historical numbering — only the entity name changed.

| ID | Functional Requirement | Priority |
| --- | --- | --- |
| **RES-1** | System displays affiliate table: business name, contact, markup %, active tier + subscription status, earnings balance, orders count, domains, status, actions | **✅ Done (ADR-058 58b)** |
| **RES-2** | Admin can add affiliate with business info, markup % + max markup %, domains, an initial wholesale tier, the first portal user (set-password invite), and an **"Our own brand" flag** (`is_owned`, ADR-061) — when set, `markup_pct` defaults 0 and a per-brand consumer-Membership toggle is shown. **No xenPlatform sub-account** — dropped entirely (ADR-059 decision 6); all money lands in the one company CHIP account | **✅ Done (ADR-058 58b; `is_owned` + Membership toggle ADR-061 PR-A)** |
| **RES-3** | Admin can edit affiliate details + change tier (writes an `affiliate_tier_changes` audit row); can toggle `is_owned` / per-brand `membership_enabled` (ADR-061) | **✅ Done (ADR-058 58b; flags ADR-061 PR-A)** |
| **RES-4** | Admin can impersonate affiliate — mints a short-lived `affiliate`-guard Sanctum token scoped with an `impersonate` ability, audited in `affiliate_impersonation_sessions` (real admin identity, portal identity, token id, IP, start/end). The persistent "impersonating" banner is the portal's job — **built in ADR-059 59c (`reseller/src/components/ImpersonationBanner.tsx`), live at `reseller.pekangame.space`**. 58b ships the mint/end endpoints, the audit table, and auto-ends sessions on affiliate deactivate/delete | **✅ Done — backend + admin UI (ADR-058 58b), portal entry + banner (ADR-059 59c), live** |
| **RES-5** | Admin can activate/deactivate affiliate (deactivate ends any live impersonation; ADR-060 storefront 503 + Cloudflare suspend is ADR-060) | **✅ Done (ADR-058 58b)** |
| **RES-6** | Admin can delete affiliate — **soft-delete only**, blocked unless earnings balance = 0 and no pending/approved withdrawal; the **`is_primary`** row cannot be deleted (ADR-061 — was "platform-owner row") | **✅ Done (ADR-058 58b; `is_primary` guard ADR-061 PR-A)** |

**Build status (ADR-056..061).** RES-1..6 shipped in **ADR-058 58b** (`/admin/resellers`, still the admin route/frontend directory name — ADR-072 PR-A is backend-only): affiliate CRUD, tier assign/charge/reactivate, `affiliate_membership_tiers` CRUD on the same screen, RES-4 impersonation, Plunk set-password invite — all `super_admin`. **ADR-061** then abolished the platform-owner special case: every storefront is an `Affiliate` discriminated by `is_owned` / `is_primary` (not `business_name`), and consumer Membership became a per-brand capability (`affiliates.membership_enabled`, effective only when the global `PlatformSettings.membership_enabled` master is also on). **PR-B (2026-08-31)** made Membership identity per-brand (`memberships` / `membership_otp_codes` carry `affiliate_id`) and added the Record-Payment brand picker. The affiliate **portal (ADR-059)** shipped 2026-08-31 (59a/b/c, PRs #33–37) and is **live at `reseller.pekangame.space`** (2026-09-02, [ADR-066](./adr.md)) — the portal app keeps its `reseller/` name deliberately (ADR-072 decision 3: it will host both `Affiliate` and the new wallet `Reseller` accounts once that entity ships). The **branded `Host`-resolved storefront** (ADR-060) is built: PR-1…4d (per-`Host` brand resolution, per-brand pricing + ledger split, brand-scoped vouchers) **live in production 2026-09-07** (#124); **PR-5** (self-serve custom domains — Vercel-native, provider-opaque portal screen, admin break-glass, daily reconcile, hard `/store-unavailable` for unknown hosts) built 2026-09-07 → `staging`. Custom-domain TLS is Vercel-native, **not** Cloudflare-for-SaaS (decision 3 reversed 2026-09-06). **PR-6** — the portal "Storefront" screen (branding / logo / hero slides / GA-FB-TikTok pixel / `markup_pct` live preview / `affiliate_game` catalog toggle) — **built 2026-09-07 → `staging`**; `ImageIngestService` seam (`intervention/image` v3, WebP, disk-config for a later R2 swap), `affiliate_markup_changes` audit, pixel-ID XSS charset gate. **ADR-060 is now complete** bar the founder's real-domain end-to-end verify.

## 6.8 Admin — Orders Management

| ID | Functional Requirement | Priority |
| --- | --- | --- |
| **ORD-1** | System displays order table: order #, customer, game, package, price, fee, profit, status, delivery status, date | **MVP** |
| **ORD-2** | System provides status filters: Need Action, Processing, Completed, Today, All | **MVP** |
| **ORD-3** | System provides advanced filters: Needs Resolution, Pending Delivery, Failed Delivery, date range, game | **MVP** |
| **ORD-4** | System provides pagination with configurable per-page (10/25/50/100) | **MVP** |
| **ORD-5** | Admin can export orders to CSV and Excel | **MVP** |
| **ORD-6** | Admin can view order details: customer info, game/package, payment info, delivery attempts, supplier responses, status history timeline | **MVP** |
| **ORD-7** | Admin can resolve an order via retry-delivery or voucher issuance only — **no cash refund action exists in the system** (ADR-004) | **MVP** |
| **ORD-8** | Every order generates a unique `reference_number` **before** the first supplier API call, and this same reference is reused on any retry of that same order — never regenerated | **MVP** |
| **ORD-9** | Every monetary field (`cost_price`, `standard_selling_price`, `selling_price`, `transaction_fee`, `final_amount`, `platform_profit`, `reseller_profit`) is always computed server-side at order-creation time from stored Package/Reseller/Settings config, then snapshotted onto the Order — the system never accepts a client-submitted value for any of these fields. `transaction_fee` is computed on `selling_price − voucher_discount` (fee is charged on the post-voucher amount, not the original selling price). Renamed from `reseller_cost_price` 2026-08-29 ([ADR-027](./adr.md)) — the field is the platform owner's own guest/standard price, never reseller-specific; a real reseller's own wholesale price comes from `reseller_membership_tiers` instead (`PricingService::calculateForReseller()`, backend built [ADR-056](./adr.md); checkout wiring is [ADR-060](./adr.md)) | **MVP** |
| **ORD-10** | A scheduled reconciliation job polls supplier `check-status`-equivalent endpoints for any order left in an ambiguous/pending state beyond a threshold — the system never relies solely on a supplier webhook/callback for final order state | **MVP** |
| **ORD-11** | `delivery_status` may only transition out of `not_started` (i.e. a supplier order-creation call may only be attempted) when `payment_status == paid`. This guard applies to every delivery attempt, including retries — never just the first one. Enforced in code (not just process/UI), since this is the single most direct path to giving away free game credits without confirmed payment | **MVP** |

## 6.9 Admin — Reports

| ID | Functional Requirement | Priority |
| --- | --- | --- |
| **RPT-1** | System displays live stats: total sales, orders count, owner profit, margin %, latest order with time ago | **MVP** |
| **RPT-2** | System displays daily sales trend chart (last 7/14/30 days) with overlay sales vs profit | **MVP** |
| **RPT-3** | System provides date filters (year, month) and export (CSV/PDF) | **Important** |

## 6.10 Admin — Withdrawal Management

| ID | Functional Requirement | Priority |
| --- | --- | --- |
| **WTH-1** | System displays stats cards: Pending/Approved/Completed/Rejected with count + total amount | **MVP** |
| **WTH-2** | System displays withdrawal table: date, amount, bank details, status, actions | **MVP** |
| **WTH-3** | Admin can approve, reject, or mark as completed withdrawal requests | **MVP** |
| **WTH-4** | Admin can view withdrawal details (full history), sourced from the ledger, not a cached balance field | **MVP** |
| **WTH-5** | Withdrawals above a configurable threshold require **Super Admin** approval (maker-checker) — an Admin who created/initiated a withdrawal request cannot also be its sole approver | **MVP** |

## 6.11 Admin — Voucher Management

| ID | Functional Requirement | Priority |
| --- | --- | --- |
| **VCH-1** | System displays stats: active/total issued/total used/expired vouchers with amounts | **MVP** |
| **VCH-2** | System displays voucher table: code, customer email, amount, remaining, status, created/expiry dates | **MVP** |
| **VCH-3** | Admin can create voucher (customer email, amount, expiry date, reason) | **MVP** |
| **VCH-4** | Admin can revoke active voucher | **MVP** |
| **VCH-5** | Voucher redemption decrements `remaining` via an atomic, row-locked operation — concurrent redemption attempts on the same voucher cannot both succeed against an insufficient remaining balance. **Implemented**: unlike the Ledger (ADR-002 addendum), a Voucher row always exists before it can be redeemed, so `lockForUpdate()` on the voucher row itself is sufficient — no separate lock-anchor table needed. Proven with a real two-OS-process test (`tests/Concurrency/VoucherRedeemConcurrencyTest.php`) | **MVP** |
| **VCH-6** | Vouchers above a configurable amount can only be created by a Super Admin. **Deliberately not** the same two-person maker-checker as WTH-5 — this is a single-step role gate (no separate "different approver" check), decided 2026-07-24 because voucher issuance is store credit, never cash-out (ADR-004), judged lower-stakes than an actual withdrawal. `foundation-security.md` §1 was updated to match — if this rule ever tightens to match WTH-5, update both together. | **Important** |

## 6.12 Admin — Customer Analytics

| ID | Functional Requirement | Priority |
| --- | --- | --- |
| **ANL-1** | System displays stats: total customers, avg order value, repeat rate, top spender | **MVP** |
| **ANL-2** | System provides customer segmentation: VIP (≥ threshold), Frequent (10+ orders), New (< 30 days), Dormant (> 90 days), One-time | **MVP** |
| **ANL-3** | System displays customer table: email, name, segment, orders count, total spent, last order date | **MVP** |
| **ANL-4** | Admin can filter by segment and date range, export CSV | **MVP** |
| **ANL-5** | Admin can drill into one customer's full detail: stats, profit-reconciliation breakdown, monthly spend trend, top packages/resellers, and full order history (added post-launch, ADR-050) | **MVP** |

## 6.13 Admin — Review Management

| ID | Functional Requirement | Priority |
| --- | --- | --- |
| **REV-1** | System displays stats: pending/approved/rejected count, total reviews, average rating | **MVP** |
| **REV-2** | System displays review table: rating, comment, customer, game, package, status, date | **MVP** |
| **REV-3** | Admin can approve or reject individual review | **MVP** |
| **REV-4** | Admin can bulk approve all pending reviews | **MVP** |
| **REV-5** | System provides filters: status, rating, game, "with comment only" | **MVP** |

## 6.14 Admin — Database Backups

| ID | Functional Requirement | Priority |
| --- | --- | --- |
| **BAK-1** | System displays backup stats: total count, latest timestamp, total size | **MVP** |
| **BAK-2** | System displays backup table: filename, type (automatic/manual), size, created date, created by | **MVP** |
| **BAK-3** | Admin can create manual backup | **MVP** |
| **BAK-4** | Admin can download or delete backup files | **MVP** |
| **BAK-5** | System supports automatic backup schedule (Daily/Weekly/Monthly) with retention period | **MVP** |

## 6.15 Admin — Store Themes

**Resolved 2026-09-09 by [ADR-081](./adr.md).** Shipped: a **curated fixed set of 5 theme presets** (`affiliate_branding.theme_preset`), an affiliate picks one in the portal "Theme" tab (live preview), the storefront injects the preset's Material-3 **colour** tokens only — structure/typography unchanged. Adding a preset is a code change, not an admin operation. The heavier THM-1..4 vision below is **dropped, not deferred** — re-argue only against real sized demand. (One of the 5, `default`/Digital Architect, was later retired from the affiliate-pickable set — see the 2026-09-24 entry below; 4 are affiliate-selectable today.)

**Extended 2026-09-11 by [ADR-090](./adr.md).** Grilled after the founder found the presets barely changed the page in production — root cause: `tokens` only ever covered accent colours (`--color-primary`/`secondary`/`tertiary`/`warning`), never `--color-surface*`/`--color-ink` (background/text), which stayed fixed once in `globals.css :root` regardless of preset. All 5 presets now carry surface/ink overrides too, so a preset actually recolors the whole page, not just buttons/badges. Also delivers the first slice of ADR-081's pinned dark-mode requirement: a per-affiliate fixed **Site Mode** (light/dark, not a viewer-side toggle) with a hand-authored `tokensDark` for the `default` preset; the other 4 presets' dark palettes were backlog at the time — **built 2026-09-24, see the next entry.**

**Extended 2026-09-24 by [ADR-113](./adr.md).** Dark mode now ships for all 4 **affiliate-selectable** presets (Cyber Bumblebee, Red Giants Edition, Cyber Emerald, Hyper Cobalt) — the §16 backlog item this closed. `storefront/DESIGN.md` written as the living design-system reference (new file, includes named Dark Mode Rules). A same-session policy change: **Digital Architect (`default`) is no longer offered to affiliates at all** — it's PekanGame's own primary-brand identity (verified zero non-primary affiliates were on it in production before removing it), so its own dark palette was built, then deleted again as unreachable once it was pulled from the affiliate picker. A real WCAG contrast bug in Cyber Bumblebee's light mode (`primary` unreadable as running text on white, 41 call sites) was also found and fixed the same session.

| ID | Original Phase-2 requirement | Status |
| --- | --- | --- |
| **THM-1** | Theme list (cards/grid) with preview, status badge, usage count | 🟡 **Partial** — a fixed preset picker with live preview shipped (ADR-081); no admin CRUD, no usage count |
| **THM-2** | Admin creates/edits a theme: colour scheme, fonts, radii, backgrounds, header/button styles, logo | ❌ **Dropped** (ADR-081) — presets are code-defined; per-brand custom colour/typography is not built |
| **THM-3** | Reseller theme *permissions* table | ❌ **Dropped** (ADR-081) — every affiliate may pick any of the 4 affiliate-selectable presets (ADR-113 excludes the primary-brand-only 5th) |
| **THM-4** | Bulk update reseller theme assignments | ❌ **Dropped** (ADR-081) |

## 6.16 Admin — SEO Management

| ID | Functional Requirement | Priority |
| --- | --- | --- |
| **SEO-1** | System displays SEO Overview dashboard: overall score, games SEO coverage, recent issues | **MVP** |
| **SEO-2** | Admin can configure Global SEO settings: default meta tags, OG defaults | **MVP** |
| **SEO-3** | Admin can configure Meta Templates reusable per page type | **Important** |
| **SEO-4** | Admin can edit per-game SEO: title (EN/local), description (EN/local), keywords, OG image | **MVP** |
| **SEO-5** | Admin can manage URL redirects (301/302) | **Important** |
| **SEO-6** | Admin can manage custom scripts (head, body-end) — analytics pixels, tracking codes | **MVP** |
| **SEO-7** | System supports Google Analytics, Facebook Pixel, TikTok Pixel integration | **MVP** |

## 6.17 Admin — Settings

| ID | Functional Requirement | Priority |
| --- | --- | --- |
| **SET-1** | Admin can configure platform info: name, support email, phone, currency, description | **MVP** |
| **SET-2** | Admin can configure system markup percentage and apply to all packages | **MVP** |
| **SET-3** | Admin can configure reseller settings: default markup, max markup, auto-approve toggle | **Phase 2** |
| **SET-4** | Admin can configure backup schedule and retention period | **MVP** |
| **SET-5** | Admin can configure store contact links (WhatsApp/Telegram) | **MVP** |
| **SET-6** | Admin can toggle maintenance mode with custom message | **MVP** |
| **SET-7** | Admin can toggle payment gateway with custom disabled message | **MVP** |
| **SET-8** | Admin can configure notification settings (email toggles) | **MVP** |
| **SET-9** | Admin can configure Telegram bot integration | **Important** |
| **SET-10** | Admin can configure footer content and social media links | **MVP** |
| **SET-11** | Admin can configure transaction fee rate (percentage + flat component) per payment method, matching the gateway's current published rates (e.g. FPX/DuitNow ≈ flat-only; Cards/e-wallets ≈ percentage + flat) — never hardcoded in application code. Written against Xendit's rate card; the gateway is CHIP since ADR-022's 2026-09-01 cutover, `payment_methods` table unchanged | **MVP** |

## 6.18 Admin — Developer Tools

| ID | Functional Requirement | Priority |
| --- | --- | --- |
| **DEV-1** | System provides supplier API tester: check balance, test validate player, test create order with dry-run toggle | **MVP** |
| **DEV-2** | Each supplier tester provides raw JSON editor and response viewer, with credentials always masked | **MVP** |

## 6.19 Supplier Middleware — Product Management

| ID | Functional Requirement | Priority |
| --- | --- | --- |
| **MID-1** | Middleware provides product sync from all connected suppliers | **MVP** |
| **MID-2** | Middleware provides product matching: identify and link same products across suppliers | **MVP** |
| **MID-3** | System displays product table with status: Matched, Supplier A Only, Supplier B Only | **MVP** |
| **MID-4** | Admin can edit product mappings (link/unlink supplier IDs) | **MVP** |
| **MID-5** | Admin can add matched product to games catalog | **MVP** |
| **MID-6** | Middleware provides price sync from suppliers: scheduled and manual | **MVP** |
| **MID-7** | Middleware provides currency exchange rate management | **MVP** |
| **MID-8** | Middleware provides player validation endpoint per supplier-game mapping **where the supplier/game combination supports it** — otherwise validation occurs implicitly at order time and the fallback flow (7.1) applies | **MVP** |
| **MID-9** | Middleware provides order creation endpoint (test orders), always passing through the idempotent reference-number pattern (ORD-8) | **MVP** |
| **MID-10** | Middleware logs all API requests to suppliers in request logs, with credentials/PII redacted | **MVP** |
| **MID-11** | Admin can filter and search request logs (date, supplier, endpoint, status) | **MVP** |
| **MID-12** | Middleware provides region-based validators: map country codes to game variants | **Important** |
| **MID-13** | Middleware provides full game catalog (150+ games from supplier data) | **MVP** |

## 6.20 Supplier Middleware — UI Pages

| ID | Functional Requirement | Priority |
| --- | --- | --- |
| **MUI-1** | Dashboard: supplier status cards, quick actions, recent API calls, circuit-breaker state | **MVP** |
| **MUI-2** | Price List: supplier prices in base and foreign currencies with exchange rate | **MVP** |
| **MUI-3** | Product Manager: product table with filters and bulk actions | **MVP** |
| **MUI-4** | Games: full game catalog with edit and export | **MVP** |
| **MUI-5** | Validators: CRUD interface for region-based game routing rules | **Important** |
| **MUI-6** | Price Sync: sync status, manual trigger, history | **MVP** |
| **MUI-7** | Validate Player: game selector, player ID input, validation result (shows "not supported for this game" state where applicable) | **MVP** |
| **MUI-8** | Orders: test order creation with game/package/player selectors, dry-run enforced outside production credentials | **MVP** |
| **MUI-9** | Request Logs: full log viewer with filters and search, redacted secrets | **MVP** |
| **MUI-10** | Settings: API key management, supplier credentials (encrypted, masked), sync schedule | **MVP** |
| **MUI-11** | Developer: raw API tester per supplier | **MVP** |

MUI-11 is the same screen as DEV-1/2 (§6.18 Admin — Developer Tools) — both describe a per-supplier raw API tester with masked credentials. Build one screen, not two; see §15's Middleware Panel row for build status.

**MUI-4 was grilled and dropped, 2026-08-28 — see [ADR-052](./adr.md).** No concrete export use-case was ever established, and `/admin/games` + `/middleware/product-manager` already cover the real catalog+edit workflow this row describes. **MUI-1 and MUI-7 were grilled the same session and built at reduced scope** (MUI-1 as a thin `/middleware` landing page reusing three already-shipped data sources; MUI-7 merged onto `/middleware/validators` rather than a standalone screen) — see the same ADR and §15's Middleware Panel row.

## 6.21 Supplier Middleware — Adapter / Normalization Layer (pulled forward from Phase 2, per ADR-006)

| ID | Functional Requirement | Priority |
| --- | --- | --- |
| **ADAPT-1** | Every supplier integration is implemented behind a common internal interface (e.g. `checkBalance()`, `listProducts()`, `createOrder()`, `checkStatus()`, `validatePlayer()`) — business logic never reads a raw supplier response directly | **MVP** |
| **ADAPT-2** | Each adapter normalizes its supplier's response into one canonical internal shape (e.g. `{success: bool, data, errorCode, errorMessage}`), regardless of how inconsistent the source supplier's own envelope is (confirmed varies: boolean `error` flag vs. string-or-absent `error` key, different success/failure schemas) | **MVP** |
| **ADAPT-3** | Adding a new supplier requires implementing the adapter interface only — no changes to order/pricing/validation business logic | **MVP** |
| **ADAPT-4** | Each adapter also normalizes the **outgoing request**, not just the response: our canonical `reference_number` is transformed into whatever field name/format/charset a given supplier's API expects (e.g. Gamevion's `referenceNumber` field has its own format convention) — business logic generates one canonical reference and never constructs a supplier-specific request shape itself | **MVP** |

## 6.22 Fraud Prevention & Blacklist (new, per ADR-007)

| ID | Functional Requirement | Priority |
| --- | --- | --- |
| **FRAUD-1** | System maintains an internal blacklist of player IDs and/or customer contacts (email/phone), independent of any supplier-provided blacklist | **MVP** |
| **FRAUD-2** | Every order creation checks the customer/player against the internal blacklist before proceeding to payment or supplier submission | **MVP** |
| **FRAUD-3** | Admin can add/remove blacklist entries with a required reason, and view history of orders blocked by a given entry | **MVP** |
| **FRAUD-4** | Checkout endpoint applies rate limiting / velocity checks (e.g. max attempts per IP/device per minute) distinct from general API rate limiting, to reduce card-testing (carding) abuse | **MVP** |

## 6.23 Payment & Webhook Integrity (new)

| ID | Functional Requirement | Priority |
| --- | --- | --- |
| **PAY-1** | All payment gateway webhook/callback payloads are verified (token/signature check per the gateway's own verification mechanism) before being trusted — an unverified callback is logged and discarded, never actioned. Written against Xendit; the live gateway is CHIP since ADR-022's 2026-09-01 cutover — `ChipWebhookController` verifies CHIP's signature the same way | **MVP** |
| **PAY-2** | Payment confirmation is idempotent — receiving the same webhook/callback event more than once must not create duplicate orders or duplicate credit delivery | **MVP** |
| **PAY-3** | A scheduled reconciliation job cross-checks the payment gateway's transaction records against local orders to catch any missed or lost webhook deliveries. Written against Xendit; CHIP settlement reconciliation (ADR-110 PR-B) fills this for the live gateway | **MVP** |
| **PAY-4** | The system never touches raw card data directly — card entry happens only via the gateway's hosted fields/redirect, keeping the platform out of PCI-DSS scope. Written against Xendit; CHIP's own hosted checkout satisfies this today | **MVP** |

---

# 7. Key User Flows

## 7.1 Top-Up Order (Happy Path — revised for supplier reality)

1. Customer visits storefront and selects a game.
2. Customer selects a package (price always resolved server-side from stored config — never from client input).
3. Customer enters Player ID (and Server ID if required).
4. **If** the selected game/supplier mapping supports pre-payment validation (MID-8): system validates the player ID synchronously and blocks checkout on failure before payment is attempted.
   **Else:** system proceeds to payment, and validation happens implicitly when the order is submitted to the supplier (step 8) — this is a known, accepted limitation for suppliers without a validation endpoint, mitigated by the no-cash-refund/voucher policy (ADR-004/ADR-005).
5. System checks customer/player against the internal blacklist (FRAUD-2). Blocked entries are rejected before payment.
6. Customer completes payment via Xendit. System generates a unique `reference_number` for the order **before** calling any supplier API (ORD-8).
7. System creates order: `payment_status = pending`, `delivery_status = not_started`.
8. Xendit webhook confirms payment (verified per PAY-1) → `payment_status = paid`. **Only now** may delivery proceed (ORD-11) — `delivery_status` moves to `processing`.
9. System submits order to the appropriate supplier via the Adapter layer, using the same `reference_number` on any retry.
10. Supplier delivers credits and returns success response (normalized via Adapter) → `delivery_status = delivered`. A reconciliation job independently confirms this via the supplier's status-check endpoint if no callback is received within a threshold (ORD-10).
11. Customer receives notification (email/auto) and can leave a review.

## 7.2 Order Failure & Resolution (no cash refund, per ADR-004)

1. System attempts delivery via Supplier Middleware (only ever reachable when `payment_status = paid`, per ORD-11).
2. Supplier returns failure response (insufficient balance / invalid player / service down) — or player ID validation fails at order time for a supplier without pre-validation (7.1 fallback).
3. System marks `delivery_status = failed`. `payment_status` remains `paid` (needs resolution) — the customer's money was genuinely received; only delivery didn't complete.
4. Admin notified (dashboard badge + email).
5. Admin reviews order details: supplier response, player ID validity, balance status.
6. Admin resolution options are limited to exactly two: **(a) retry delivery** (same or different supplier), or **(b) issue a voucher** (store credit). No cash-refund action exists in the system.
7. Customer notified of the outcome.

## 7.3 Supplier Price Sync

1. Admin or scheduler triggers Price Sync.
2. Supplier Middleware queries all supplier APIs (via Adapter layer) for latest product catalog and prices.
3. Middleware compares with local data: updates prices, creates new packages, deactivates removed ones.
4. Packages that were previously inactive but now available are flagged as "Pending Reactivation".
5. System records sync details: games processed, packages created/updated/deactivated.
6. Admin reviews Pending Reactivation table and approves/dismisses packages.
7. Approved packages are reactivated in the catalog with updated prices.

**Addendum, 2026-09-16 ([ADR-100](./adr.md)):** step 6 can optionally happen
automatically instead — off by default (`PENDING_REACTIVATION_AUTO_APPROVE`),
**on in production since 2026-09-24**. When on, a package confirmed active
again either inside its own Digiflazz cutoff window, or after enough
consecutive syncs confirm it, skips the admin queue entirely and reactivates
on its own; every other case still lands in the manual queue exactly as
steps 1-7 describe.

**Addendum, 2026-09-24 (ADR-100's own addendum):** the original 14-day
flap-history gate (step 6's automatic path also required no more than 2
deactivations in the trailing 14 days) is retired — live data showed it
permanently blocking popular SKUs that were genuinely confirmed active for
20+ hours straight. Streak length alone is now the gate.

**Addendum, 2026-09-24 (ADR-094/ADR-046 addenda):** step 7's "reactivated in
the catalog" now cascades onto a dependent **combo** too — once every one of
a combo's own components is active again (through any of the reactivation
paths, manual or automatic), the combo reactivates automatically. Applies
whether the components came back through the Pending Reactivation queue
(this section) or Supplier Management's own bulk on/off toggle (SUPP-1,
`/admin/suppliers` — see ADR-046's addendum).

## 7.4 Withdrawal (ledger-based, per ADR-002)

1. Platform owner (Phase 2: reseller) accrues profit from delivered orders — each order's profit is written as a **credit entry** in the ledger, not added to a mutable balance field.
2. Owner/reseller submits withdrawal request: selects amount (validated against SUM of ledger entries, not a cached balance), enters bank details.
3. Request created with status "Pending" — this itself does **not** yet write a ledger entry (a pending request is not a debit until approved).
4. Admin sees new pending withdrawal on Withdrawal Management page.
5. Admin reviews ledger history, confirms sufficiency.
6. Admin approves — **if amount is above the configured threshold, a second Super Admin approval is required (WTH-5, maker-checker)**.
7. On approval, a **debit entry** is written to the ledger atomically with the status change.
8. Admin processes bank transfer externally, then marks withdrawal as "Completed" with processing timestamp.
9. Owner/reseller notified of completed withdrawal.

## 7.5 Voucher Issuance (store-credit refund, per ADR-004)

Two distinct creation paths, confirmed with the founder 2026-07-24 — not one flow with two entry points, two genuinely different risk profiles:

**Path A — standalone, from the Vouchers page:**
1. Admin creates voucher: enters customer email, **sets the amount themselves**, records reason, optional expiry. Amount at/above threshold can only be created by a Super Admin (VCH-6 — single-step role gate, not the same two-person maker-checker as WTH-5; see VCH-6's own row for why).
2. Voucher created with status "Active", system generates a unique code, `order_id` is null.

**Path B — from a failed order (§7.2's resolution options):**
1. Order fails and cannot be re-delivered (per 7.1/7.2), `delivery_status = failed`.
2. Admin triggers voucher issuance for that order — the amount is **never admin-entered**: it's server-computed as `final_amount − transaction_fee` (the same "never trust a client-submitted money value" principle as ORD-9, applied to refunds). No maker-checker threshold applies, since the amount is bounded by what the customer actually paid, not an open admin choice. One voucher per order — a second attempt is rejected.
3. Voucher created with status "Active", `order_id` set to the originating order, reason defaults to a delivery-failure note (admin can override).
4. **Restore-only, ADR-024's 2026-09-17 addendum:** if that computed amount is exactly `0` (a full-cover-by-voucher order, ADR-024 decision 5, that later fails delivery), no new Voucher is created — the button auto-labels "Restore Voucher" instead of "Issue Voucher," and only the order's own original voucher (the one it was paid with) gets its spent balance given back. Same one action either way, decided from the order's own state before the admin clicks anything.

**From here, both paths converge:**
4. Customer receives voucher code via email.
5. Customer applies voucher on next purchase — remaining amount is decremented via an **atomic, row-locked** operation (VCH-5) so concurrent redemption attempts cannot double-spend the same voucher.
6. Voucher becomes "Exhausted" when remaining reaches zero, or "Expired" past expiry date.

---

# 8. Data Model (High-Level)

| Entity | Key Fields | Description |
| --- | --- | --- |
| **Game** | `id`, `name`, `slug`, `category`, `is_active`, `sort_order`, `image_url`, `banner_url`, `supplier_mappings` (JSON array of `{supplier_id, product_ref, supports_validation, supports_server_list}`), `validation_rules` (JSON), SEO fields | A game title available for top-up. `supports_validation` remains in the stored JSON shape (harmless, unread) but is dead — GAME-12 (which introduced it) is dropped, 2026-09-13; see §16. Real player-ID validation runs through `Game.player_validator_enabled`/`player_validator_profile_id` (`PlayerValidatorRegistry`, ADR-093) instead — an unrelated, separate mechanism. |
| **Package** | `id`, `game_id`, `name`, `cost_price` (supplier wholesale, sourced only from the supplier sync — never admin-editable), `markup_percent` (admin-set per package, decimal — added 2026-07-25), `standard_selling_price` (= `cost_price` × (1 + `markup_percent` / 100), rounded to the nearest sen — **stored**, recomputed only when `markup_percent` changes via `PackageMarkupService`, never recomputed live at order time; renamed from `reseller_cost_price` 2026-08-29, [ADR-027](./adr.md)), `is_active`, `supplier_id`, `supplier_package_ref`, `sort_order` | A specific top-up denomination within a game. `standard_selling_price` is the platform owner's own guest/standard price — it is **not** the customer-facing price for every buyer type; a real affiliate's own wholesale rate is a separate mechanism (`affiliate_membership_tiers`, see the Affiliate row below and [ADR-056](./adr.md) — backend built, checkout wiring [ADR-060](./adr.md)). Today it happens to equal the customer-facing price (the primary brand runs at `markup_pct = 0`), but the schema/formula never assumes that. |
| **Supplier** | `id`, `name`, `slug`, `logo_url`, `is_active`, `api_config` (JSON — credentials, endpoints; **encrypted at rest**), `balance`, `currency` | A game credit supplier integrated via Middleware, accessed only through its Adapter (6.21). |
| **Affiliate** | `id`, `business_name`, `contact_name`, `email`, `phone`, `markup_pct`, `max_markup_pct`, `domains` (JSON array, nullable), `status`, `is_owned` (bool — our own brand, [ADR-061](./adr.md)), `is_primary` (nullable bool, DB-enforced-single — the console/job/non-`Host` fallback tenant, never deletable), `membership_enabled` (bool — per-brand consumer-Membership toggle, effective only when the global `PlatformSettings.membership_enabled` master is also on), `deleted_at` (soft-delete, ADR-058 58b), `xendit_subaccount_id` (nullable, unused — xenPlatform dropped), `notes` — **no `balance` column**; balance is derived from `LedgerEntry` — **renamed from `Reseller`/`resellers` 2026-09-04, [ADR-072](./adr.md) PR-A, zero behaviour change** (vacates the `Reseller` name for a future prepaid-wallet entity, ADR-073..075) | A branded storefront owner. **Every storefront is a row here** — ours and third-party alike, with no special code path ([ADR-061](./adr.md), superseding ADR-013's `business_name = 'Platform Owner'` magic string). `Affiliate::primary()` resolves the one `is_primary` row for any context with no `Host` to resolve a brand from. `markup_pct`/`max_markup_pct` = the affiliate's own margin on top of what they pay the platform (an `is_owned` brand defaults 0 — all margin books as `platform_profit`). **What an affiliate pays the platform** depends on which `affiliate_membership_tiers` row they're subscribed to (or `standard_selling_price` if none active) — [ADR-056](./adr.md), backend built, checkout wiring ADR-060. |
| **Reseller** *(new, [ADR-072/073](./adr.md), backend + admin CRUD built 2026-09-04, PR-B)* | `id`, `business_name`, `contact_name`, `email`, `phone`, `reseller_tier_id` (nullable FK → `reseller_tiers`, restrict-on-delete, direct swap — no subscription state machine), `is_active` (bool, default true — account-level order-placement kill switch, doesn't freeze the balance), `notes`, `deleted_at` (soft-delete) — **no `balance` column**; wallet balance is derived from `LedgerEntry`, owner type `reseller_wallet` | The prepaid-wallet wholesale-buyer entity the Reseller API (ADR-074) and Reseller Bot (ADR-075) channels share — reclaims the `Reseller` name vacated by ADR-072's `Affiliate` rename. Structurally the opposite of `Affiliate`: only ever **spends** against a deposited balance, never earns. `reseller_tiers.markup_percent` (over `cost_price`, no monthly fee) prices every wallet order. No order-placing logic yet (PR-D), no API key (PR-E, `reseller_api_keys`), no portal login yet (PR-G, polymorphic `reseller_users`). |
| **LedgerEntry** | `id`, `owner_type` (platform/affiliate — `'affiliate'` since ADR-072 PR-A, was `'reseller'`), `owner_id`, `type` (order_profit/withdrawal/voucher_issued/adjustment), `amount` (signed sen: credit positive, debit negative), `reference_type`, `reference_id`, `created_at`, `created_by`, `reason` (required for `adjustment`) | **Immutable, append-only.** Sole source of truth for any balance. Never updated or deleted, only inserted (ADR-002). **Every delivered order writes exactly two `order_profit` credit entries** — one for `platform_profit` (owner_type=platform) and one for `affiliate_profit` (owner_type=affiliate, the order's `affiliate_id`) — kept as two rows even in MVP (where both happen to resolve to the same owner) so the ledger logic never needs to change when Phase 2 onboards real affiliates. |
| **LedgerAccount** | `id`, `owner_type`, `owner_id` (unique together) | Pure lock/mutex anchor, **no balance column** — created upfront alongside the owner so `withdraw()` always has a row to `lockForUpdate()`, even for a brand-new owner with zero prior entries. See ADR-002 addendum. |
| **AdminUser** | `id`, `name`, `email`, `password`, `role` (super_admin/admin), `phone`, `is_active`, `mfa_secret`, `mfa_enabled` | System administrator account. |
| **Order** | `id`, `order_number` (customer-facing, e.g. `KRS-8xxxxxxx`, platform-generated), `reference_number` (our idempotency key, generated before first supplier call — ORD-8, transformed per-supplier format by the Adapter per ADAPT-4), `customer_email`, `customer_name` (added 2026-07-25 — required by Xendit's `customer.individual_detail.given_names`, see ADR-001's fourth addendum), `customer_phone`, `player_id`, `server_id`, `game_id`, `package_id`, `supplier_id`, `affiliate_id` (**NOT NULL** since ADR-061 PR-B; FK `restrictOnDelete` — an order keeps its brand for the life of the record; every order is `Affiliate::primary()`'s until ADR-060's `Host` resolution; renamed from `reseller_id` 2026-09-04, [ADR-072](./adr.md) PR-A), `cost_price` (snapshot from Package), `standard_selling_price` (snapshot from Package — renamed from `reseller_cost_price` 2026-08-29, [ADR-027](./adr.md)), `affiliate_markup_pct` (snapshot from Affiliate at order time — renamed from `reseller_markup_pct` 2026-09-04, ADR-072 PR-A), `selling_price` (= `standard_selling_price` + affiliate markup — customer-facing price), `voucher_discount` (nullable), `transaction_fee` (computed on `selling_price − voucher_discount`, per SET-11 rate for the chosen `payment_method`), `final_amount` (= `selling_price − voucher_discount + transaction_fee` — what Xendit actually charges), `platform_profit` (= `standard_selling_price − cost_price`), `affiliate_profit` (= `selling_price − standard_selling_price` — renamed from `reseller_profit` 2026-09-04, ADR-072 PR-A) — all server-computed, ORD-9, `payment_status` (pending/paid/failed — reflects the payment gateway only), `paid_at` (nullable timestamp, set the instant `payment_status` becomes paid — added 2026-08-26, the sole source of truth for "when this order was actually paid," see `ReportService`/`DashboardService`), `delivery_status` (not_started/processing/delivered/failed/needs_review/pending — reflects the Supplier API only; `needs_review` added by ADR-026, `pending` added by ADR-032 for an async supplier's not-yet-confirmed outcome), `delivered_at` (nullable timestamp, set the instant `delivery_status` becomes delivered — added 2026-08-27, ADR-045, backfilled for pre-existing rows via `updated_at` as a best-available proxy), `payment_method`, `payment_ref`, `supplier_ref` (supplier's own order number, opaque string, format varies per supplier — only known after their response), `supplier_response` (JSON, PII-redacted), `timestamps` | A customer's top-up purchase transaction. Three distinct identifiers exist per order: `order_number` (ours, customer-facing), `reference_number` (ours, idempotency key sent to supplier), and `supplier_ref` (theirs, returned after success) — never conflate them. Cost/price fields are snapshotted at order time (not live-joined from Package/Affiliate) so historical orders stay accurate even if prices change later. **`payment_status` and `delivery_status` are two independent state machines — delivery may only move out of `not_started` when `payment_status == paid` (ORD-11); never conflate a single combined "status" field again.** |
| **Withdrawal** | `id`, `owner_type`, `owner_id`, `amount`, `bank_name`, `bank_account_no`, `bank_account_holder`, `status`, `admin_note`, `requested_by`, `approved_by` (must differ from `requested_by` above threshold — WTH-5), `processed_at` | Profit withdrawal request, settled against the ledger. |
| **Voucher** | `id`, `order_id` (nullable — set only when issued via Path B, §7.5), `code`, `customer_email`, `amount`, `remaining`, `status` (active/exhausted/expired/revoked), `expires_at`, `reason`, `created_by`, `approved_by` (set only when created at/above the VCH-6 threshold — records which Super Admin's role satisfied the gate, not a second approver) | A store-credit refund issued to a customer — the only refund mechanism in the system (ADR-004). |
| **Review** | `id`, `order_id`, `customer_email`, `game_id`, `package_id`, `rating` (1-5), `comment`, `status`, `created_at` | Customer feedback on a completed order. |
| **BlacklistEntry** | `id`, `type` (player_id/email/phone), `value`, `reason`, `created_by`, `is_active`, timestamps | Internal fraud-prevention list, checked at checkout (FRAUD-1/2). Shipped 2026-07-29 as two tables, not one — corrects this row's original pre-build design (no `game_id`; a blacklist entry blocks everywhere, not per-game; `is_active` replaces hard-delete, see ADR-007's shipped addendum for why). |
| **BlacklistHit** | `id`, `blacklist_entry_id`, `player_id`, `customer_email`, `customer_phone`, `ip`, timestamps | One row per blocked checkout attempt (FRAUD-3's audit trail) — not in the original §8 design, added during the 2026-07-29 build since a blocked attempt never creates an `Order` and so has no other record. |
| **Backup** | `id`, `filename`, `size`, `type` (automatic/manual), `created_at`, `created_by` | Database backup record. |
| **Validator** | `id`, `name`, `slug`, `rules` (JSON array of `{country_code, game_variant_slug}` mappings) | Region-based routing rules for player validation. |

---

# 9. Non-Functional Requirements

> **Security & financial-integrity rules are maintained as a standalone checklist in [`foundation-security.md`](./foundation-security.md) — read it before implementing anything that touches auth, money, pricing, supplier calls, or customer PII.** The bullets below are the remaining, non-security NFRs.

- **Responsiveness :** Admin Panel and Storefront must be responsive — mobile, tablet, desktop. Storefront is customer-facing, mobile-first.
- **Scalability :** Platform must support growth to 30+ resellers (Phase 2) with thousands of daily orders without significant degradation. Database queries must be optimized (indexes, eager loading). Tenant-scoping is enforced at the ORM layer, not per-query by convention.
- **Performance :** Dashboard page load < 2s. End-to-end order processing < 5s (from payment to supplier delivery). Caching for game list, package prices.
- **Resilience :**
  - Supplier API failures must not block the system. Failed deliveries enter a retry queue.
  - A circuit breaker trips per-supplier after repeated failures, preventing cascading timeouts from a down supplier blocking the whole delivery queue.
  - Dashboard must remain accessible even when one supplier is down.
  - The system never relies solely on a supplier webhook/callback for final state — a scheduled reconciliation job independently confirms order and payment status (ORD-10, PAY-3).
- **Error Handling :** All supplier API errors must be logged with full request/response payload, credentials and excess PII redacted. Admin receives notifications for critical failures (stuck orders, low supplier balance, tripped circuit breaker).
- **Data Privacy :** Player IDs and customer personal data (including validation-response data such as in-game nickname/country) must be handled securely, retained only as long as operationally needed, and never over-logged. No logging of raw credentials. Admin action audit trails, including impersonation.
- **Backup & Recovery :** Automatic daily backup, 30-day retention. Manual backup via admin panel. Backups must be downloadable.
- **Multi-language :** Storefront supports multiple languages (English + local language). Admin panel is single language (English).
- **Financial Integrity :** Every entity representing money (order profit, withdrawal, voucher) is reconstructable from the `LedgerEntry` table alone. No balance is ever the sole record of truth (ADR-002).

---

# 10. Third-Party Integrations

| Service | Function | Notes |
| --- | --- | --- |
| **CHIP** | **Sole payment gateway** — Malaysia-local (FPX live; DuitNow QR / `fpx_b2b1` seeded inactive). Also membership subscription + reseller wallet top-up checkout | 🟢 **LIVE in production since 2026-09-03** (live keys, `fpx` channel active, RM1.00 flat fee) — real money proven end-to-end (order `PG-PYAYMRYNUYV0`). Per-purchase `success_callback` (not a portal webhook), RSA signature verification with key-rotation re-fetch. Still resolved via `PaymentGatewayFactory` for a future multi-region 2nd gateway. `app:chip-smoke-test`. ADR-022 (+ 2026-09-01 CHIP-only addendum), ADR-068. |
| **~~Xendit~~** | ~~International payment processing~~ | **Removed entirely 2026-09-01** (ADR-022 addendum). Code archived to branch `archive/xendit-gateway` + tag `xendit-archive-2026-09-01`. International acceptance is gone, not dormant — its own grilled ADR if cross-border selling becomes real. xenPlatform was never adopted (ADR-059 decision 6 — the ledger splits partner money, not the gateway). |
| **Gamevion** | Supplier — game credit catalog, price feed, player validation, order creation/fulfillment | First real `SupplierAdapter`. Static Bearer + API-key, `referenceNumber` + `409 Duplicate` idempotency, per-purchase `callback_url`. Sandbox via `GAMEVION_SANDBOX=true`. ADR-006/040. |
| **Digiflazz** | Second supplier — Indonesian H2H aggregator (486 game SKUs + data SKUs) | Prod key wired 2026-09-02. IDR→MYR FX conversion at the sync boundary (ADR-033). Inbound `POST /api/webhooks/digiflazz` (HMAC-SHA1 `X-Hub-Signature` + IP allowlist). **`checkBalance` = Rp 0 — must be funded before live orders.** ADR-030/067/069. |
| **Supplier API(s) — general** | The Adapter contract all suppliers implement | Confirmed to vary significantly per supplier (auth, response envelopes, validation availability per-game, idempotency). All access goes through the Adapter layer (§6.21), resolved per-order by `supplier_id` (`SupplierAdapterFactory`, ADR-031). Onboarding a new supplier = a new adapter, enforced from MVP (ADR-006). |
| **OpenWA** | WhatsApp gateway for the Reseller Bot channel (`.order` / `.baki` / `.listharga` / `.topupbaki` command set) | Self-hosted OpenWA session. Inbound `OpenWaWebhookController` (HMAC `X-OpenWA-Signature`). Provisioned + prod-verified end-to-end 2026-09-05/06. ADR-075/076. |
| **Plunk** | Transactional email — order confirmation/delivery/failure-voucher, membership OTP + receipts, partner-portal invites | API-based (`PLUNK_API_KEY`). Replaced the generic "SMTP or API" placeholder. ADR-027/068. |
| **Laravel Reverb** | Realtime broadcasting — order-status push, replaces application-level polling on the customer buy-flow | Self-hosted WebSocket server + Echo client. ADR-047, ADR-071 PR4. |
| **Vercel** | Custom-domain provisioning for Affiliate whitelabel storefronts | `AffiliateDomainProvider` seam (Vercel / no-op). `affiliate_domains` table, self-serve onboarding, provider opacity in the portal. ADR-060 (reverses decision 3's Cloudflare-for-SaaS), ADR-078. |
| **Google Analytics (GA4) / Facebook Pixel / TikTok Pixel** | Website analytics + ad conversion tracking | IDs from Settings (per-brand for Affiliates). |
| **Telegram Bot** | Admin notifications — new orders, failures, withdrawal requests, tripped circuit breakers | Configurable token + chat ID from Settings. |

---

# 11. Proposed Features / Future Phases

- **~~Affiliate Self-Service Platform.~~** ✅ **Shipped.** Wholesale pricing/tiers + tenant isolation + auth + admin management + portal app + branded custom-domain storefronts all live (ADR-056–061, ADR-078). Tracked in §15's Affiliates row.
- **~~API / H2H "pull supply" channel.~~** ✅ **Shipped as the `Reseller` (prepaid wallet) system.** A partner pulling supply for their own off-platform site — prepaid deposit wallet + CHIP top-up + REST API keys (ADR-074) + WhatsApp bot (ADR-075). Tracked in §15's Reseller wallet row.
- **Automated Customer Support Chatbot.** Integrate a chatbot (Telegram/WhatsApp) for order tracking, player ID lookup, and basic troubleshooting.
- **Mobile Native Apps.** React Native or Flutter apps for the customer storefront experience.
- **Loyalty & Rewards Program.** Points system for customers based on order frequency/value.
- **Affiliate Marketing Module.** Third-party affiliate referral with commission tracking.
- **Cross-Reseller Inventory Sharing.** Route orders to best-available supplier when one reseller's preferred supplier is low on balance.
- **Automated Exchange Rate Feeds.** Real-time exchange rate API integration instead of manual/fallback rates.
- **Automated Refund/Reactivation Decisions.** Move from manual admin approval (MVP default, per Open Question resolution below) to rules-based automation once enough fraud/failure pattern data exists.

---

# 12. Open Questions / TBD

**Resolved during foundation audit (2026-07-23):** see [`adr.md`](./adr.md) for full rationale on payment gateway (ADR-001), refund policy (ADR-004), reseller tiering/scope (ADR-003), and frontend stack (ADR-009, superseding ADR-008).

**Resolved during Withdrawal/Voucher implementation (2026-07-24):** maker-checker thresholds — Withdrawal (WTH-5) is RM 2,000, `WITHDRAWAL_MAKER_CHECKER_THRESHOLD_SEN`; Voucher (VCH-6) is RM 500, `VOUCHER_MAKER_CHECKER_THRESHOLD_SEN`, and turned out to be a **different maker-checker mechanism**, not the same one at a different amount — see VCH-6's row in §6.11 and `foundation-security.md` §1 for why. ~~xenPlatform sub-account type is OWNED, not MANAGED~~ — moot: xenPlatform dropped entirely ([ADR-059](./adr.md)).

**Resolved during the reseller batch grilling (2026-08-30, ADR-056..061):** wholesale pricing model (paid tiers, cost-anchored — ADR-056); tenant isolation mechanism (`BelongsToReseller` + global scope — ADR-057); partner auth (separate `affiliate` guard — ADR-058, guard renamed from `reseller` in ADR-072); partner domains use **custom domains only, no subdomains** (ADR-060 — TLS/provisioning is **Vercel-native custom domains**, not Cloudflare for SaaS; decision 3 was reversed 2026-09-06); money model — platform collects all retail centrally, ledger splits, no xenPlatform (ADR-059); the platform owner stops being a special case — every storefront is an `Affiliate` row (ADR-061, entity renamed from `Reseller` in ADR-072).

**Resolved during Database Backups build (2026-08-26):** backup storage target — `BACKUP_DISK`-configurable, defaults to local disk; the local-disk single-point-of-failure risk is explicitly tracked as a known gap, not an oversight (ADR-039).

**Still open:**

- MFA method confirmation — TOTP assumed (AUTH-7); confirm no SMS-OTP fallback is wanted (SMS OTP has its own SIM-swap risk profile).
- What is the SLA for supplier APIs? Should there be alerts if response time exceeds threshold (ties into circuit-breaker tuning)?
- Per-supplier, per-game audit: which games/suppliers actually support pre-payment validation vs. order-time-only? Needs to be catalogued during supplier onboarding, not assumed.
- Blacklist data source: internal chargeback history only, or also manually curated from known fraud reports? Needs a policy for false-positive appeal.
- MFA for `affiliate_users` (was `reseller_users`) — deferred with the same reasoning as the admin MFA gap (ADR-058 consequence note); resolve before the partner portal's withdrawal screen carries meaningful balances.
- Multi-region payment routing (Malaysia → CHIP, other countries → a 2nd gateway, auto by country) — founder want. **Superseded for now** by the CHIP-only cutover (ADR-022, 2026-09-01): the `PaymentGatewayFactory` seam is kept, but a real 2nd gateway is only revisited when cross-border selling is real. Xendit (the original candidate) is deleted.
- Public-facing review display — added ad-hoc in PR #150/#151 (2026-09-09); retro-documented in ADR-082.

---

# 13. Glossary

- **Supplier Middleware :** System layer that handles API communication with game credit suppliers via the Adapter layer. Provides product matching, price sync, player validation (where available), and order delivery.
- **Adapter / Normalizer :** A per-supplier module implementing a common internal interface, translating that supplier's specific auth/response format into one canonical shape so business logic never depends on raw supplier response structure (6.21).
- **Ledger Entry :** An immutable, append-only record of a single credit or debit against a platform-owner or affiliate's balance. Balance is always a derived SUM, never a directly-mutated field (ADR-002).
- **Idempotency Key / Reference Number :** A unique identifier generated before the first attempt at a money-moving or order-creating API call, reused on every retry of that same logical operation so a retried request cannot create a duplicate effect.
- **Maker-Checker :** A dual-control pattern where the person who initiates a sensitive action (large withdrawal, large voucher) cannot also be the sole approver of that same action.
- **Cost Price :** Price from supplier (wholesale). Base for all markup calculations.
- **System Markup :** Profit percentage added on top of cost price (system's margin).
- **Affiliate Markup :** Profit percentage an affiliate adds on top of their wholesale base (`affiliates.markup_pct`, capped by `max_markup_pct`). Real since [ADR-061](./adr.md); an `is_owned` brand defaults 0 (all margin books as `platform_profit`). Applied at checkout — ADR-060. Renamed from "Reseller Markup" 2026-09-04, [ADR-072](./adr.md) PR-A.
- **Pending Reactivation :** Packages that were previously inactive but are now available again from supplier. Admin-reviewed by default; can optionally auto-approve on a confirmed-active streak (ADR-100, on in production since 2026-09-24) — see §7.3's addenda.
- **Player ID :** Unique identifier for a player within a game (username, user ID, or character ID).
- **Server ID :** Server identifier within a game (for games with multiple servers).
- **Region Validator :** Mapping of country codes to game variants (e.g. MY → Malaysia variant, ID → Indonesia variant).
- **Dry Run :** Testing mode for API calls without creating real orders or deducting balance.
- **Stuck Order :** Order that is "paid" but not yet "delivered" beyond a threshold (e.g. 5 minutes).
- **Multi-Tenant :** Architecture where a single system instance serves multiple affiliates (tenants) with data isolation. Schema-ready from MVP (ADR-003); feature-exposed in Phase 2.
- **xenPlatform :** Xendit's product for platform/marketplace businesses — sub-account creation and automatic payment splitting between platform and merchants. ~~Deferred to Phase 2 (ADR-001).~~ **Dropped entirely, 2026-08-30 ([ADR-059](./adr.md) decision 6):** the platform will not use xenPlatform at all — all customer money (platform-owner and every affiliate storefront) is collected into one company CHIP account, and affiliate payout is a manual bank transfer via the existing WTH-1..5 flow. The ledger, not the gateway, is the source of truth for who is owed what (ADR-022 decision 2). Kept in this glossary only so the term isn't mistaken for a live plan.
- **Blacklist :** Internal, platform-owned list of player IDs/contacts blocked from ordering due to prior fraud/chargeback history, independent of any supplier-side blacklist (ADR-007).
- **Method Key :** A stable identifier for a real-world payment method (e.g. `tng`, `fpx_maybank2u`, `grabpay`) independent of which `PaymentGateway` processes it — distinct from `channel_code` (gateway-specific, unique per `payment_methods` row) and `category` (coarser grouping like `fpx`/`ewallet` that legitimately holds several different active methods at once). Exists so at most one gateway's row for the same customer-facing method can be active at a time (ADR-022's newest addendum).
- **Voucher Merge :** An admin-triggered, opt-in action that consolidates two or more of one customer's active vouchers into a single new code, sized to the sources' combined `remaining` (not their original `amount`). The sources are voided (`status = 'merged'`, `remaining = 0`) — never deleted — and no `ledger_entries` row is written, since the liability was already booked once by each source's own original issuance. Distinct from *redemption* (spending a voucher at checkout) and from Path B's *restore* (giving a reserved amount back to `remaining`) — a merge never happens automatically and is never part of the checkout flow itself (ADR-036).
- **Customer (Customer Analytics) :** Not a stored entity — a derived grouping of `Order` rows by `customer_email`, computed at query time. This does not reopen ADR-011 (guest checkout, no `Customer` model/login); it's the same field Reports already surfaces as `latest_order.customer_email`, now used as a grouping key (ADR-049).
- **Standard Selling Price :** The platform owner's own guest/non-member retail price — `cost_price × (1 + package.markup_percent)`. Renamed from `reseller_cost_price` 2026-08-29 (migration shipped) once its usage was recognized as never affiliate-specific — see Affiliate Wholesale Tier below and [ADR-027](./adr.md).
- **Affiliate Wholesale Tier :** A paid, admin-CRUD-managed row in `affiliate_membership_tiers` that an affiliate subscribes to monthly to unlock a lower `cost_price`-based wholesale rate for their own purchases from the platform. Fully independent of consumer VIP Membership (ADR-027) — no membership feature exists on any *third-party* affiliate storefront, and an affiliate's tier is never derived from anything their own downstream customers do. An unpaid or lapsed affiliate (past a ~3-day grace period) falls back to Standard Selling Price, the same price a guest pays — not a shutdown, just the walk-in rate, self-correcting on payment. Backend built ([ADR-056](./adr.md), 2026-08-30): `affiliate_membership_tiers` + `affiliate_subscriptions`, `PricingService::calculateForAffiliate()`, `AffiliateTierFeeService` + grace/lapse state machine, `ChargeAffiliateTierFeesCommand`. Admin CRUD shipped ADR-058 58b. Checkout wiring is ADR-060. Renamed from "Reseller Wholesale Tier" (and `calculateForReseller()`) 2026-09-04, [ADR-072](./adr.md) PR-A.
- **`is_owned` affiliate :** A storefront the founder owns (our brand), flagged `affiliates.is_owned` ([ADR-061](./adr.md), built PR-A 2026-08-30; entity renamed from `Reseller`/`resellers` 2026-09-04, [ADR-072](./adr.md) PR-A). Its contribution is internal money, not a payable; its `markup_pct` defaults 0 (all margin books as `platform_profit`) but is editable; it may run consumer Membership. Contrast a third-party affiliate (`is_owned = false`) — feature-limited, **no consumer Membership** (the founder's chosen differentiator). Replaces the `business_name = 'Platform Owner'` magic string.
- **`is_primary` affiliate :** The single `is_owned` row (`affiliates.is_primary`, DB-enforced as exactly one via a nullable-unique index) that every non-`Host` code path resolves to — console commands, queue jobs, migrations, not-yet-brand-aware admin screens — via `Affiliate::primary()`. Never deletable. [ADR-061](./adr.md). Once ADR-060 lands, storefront requests resolve their brand by `Host` instead; `is_primary` stays only the fallback.
- **`Reseller` (wallet) :** Distinct from `Affiliate` (whitelabel storefront partner, above) — a `Reseller` only ever *spends* from a prepaid deposit balance, never earns; buffers the platform's own working capital against Digiflazz/Gamevion running dry. Split out of the original single `Reseller` table and renamed from it 2026-09-04 ([ADR-072](./adr.md)/[ADR-073](./adr.md)). Assigned a `reseller_tiers` markup tier (admin-managed, zero self-serve pricing); orders it places carry `orders.wallet_reseller_id` and `pricing_basis = 'reseller-wallet'`, with `reseller_profit` always `0` by construction (`PricingService::calculateForAffiliate(affiliateMarkupPct: 0.0)`, [ADR-073](./adr.md) decision 1) — the markup is realized as ordinary `platform_profit` instead. Two ordering interfaces share one order-placement contract (`ResellerOrderPlacementService::placeOrder()`, [ADR-073](./adr.md) decision 4): the **Reseller API** (per-tenant `reseller_api_keys`, [ADR-074](./adr.md)) and the **Reseller Bot** (WhatsApp via OpenWA, group-identity mapping, [ADR-075](./adr.md)).
- **Wallet Top-up / Wallet Debit / Wallet Refund :** The three `ledger_entries.type` values a `Reseller` (wallet) account's own ledger ever carries (owner type `reseller_wallet`), pinned here per [ADR-073](./adr.md)'s own consequence-to-track (owed since PR-C, closed at PR-G). **Wallet Top-up** — a credit, written by either of two equal-standing paths: an admin's manual credit (`ResellerWalletService::manualCredit()`, PR-C, with an optional receipt/proof file) or the reseller's own self-serve CHIP checkout (`ResellerWalletService::completeTopup()`, PR-G, ADR-073 decision 3(a) — a `wallet_topup_attempts` row, `pending → paid/failed/expired`, capped at one `pending` attempt at a time). **Wallet Debit** — the synchronous spend at order-placement time (`ResellerOrderPlacementService::placeOrder()`, PR-D), the tier-priced cost of one order, debited from the same `LedgerService::debit()` primitive every other debit in this codebase uses. **Wallet Refund** — a credit reversing a specific debit, written only by the admin's "Refund to Wallet" terminal action on a failed-and-not-retried wallet-owned order (PR-D decision 7) — an internal-credit reversal, not a reopening of [ADR-004](./adr.md)'s "no cash refund" policy, since no cash ever leaves the platform.
- **`reseller_code` / `catalog_code` :** The stable, human-readable product-code scheme both Reseller channels order against, since a raw `Package.id` is unstable across a supplier swap (ADR-031/SYNC-5/6). `games.reseller_code` (`[A-Z]{2,10}`, globally unique) identifies the game; a package resolves via `{reseller_code}-{denomination}` for a numeric-value package (e.g. `MLMY-14`) or `{reseller_code}-{catalog_code}` for a bundle/pass with no inherent numeric value (e.g. `MLMY-P1`, `packages.catalog_code` — must contain a letter, mutually exclusive with `denomination`). Two independent equivalence-key spaces by construction, so a bundle can never falsely dedup against an unrelated real-value package. Resolved by the shared `Package::cheapestActiveFor()` / `ResellerCatalogService::resolveByCode()` seam ([ADR-074](./adr.md)/[ADR-075](./adr.md) catalog-code addendum, built PR-E0).

---

# 14. Build Status

**Where things stand (2026-09-24).** The platform is feature-complete and live in
production — storefront, admin panel, reseller/affiliate portal, and the
developer-docs site all deployed; CHIP FPX payments and the CHIP + Digiflazz
webhooks proven end-to-end with real money (order `PG-PYAYMRYNUYV0`).
**Digiflazz was funded 2026-09-15** — real orders are now placed and delivered
(5 non-test orders as of that date, `PG-PYAYMRYNUYV0` itself later resent and
delivered once balance landed). **Gamevion (a MYR-billed supplier, not
IDR/Rp — do not confuse it with Digiflazz's own currency) stays deliberately
unfunded and deprioritized as of 2026-09-24**: the founder has decided to run
the platform Digiflazz-only for the foreseeable future rather than fund a
second supplier, so this is no longer tracked as a launch gate — see §16's
former item 1. Gamevion-routed orders can still be paid but not delivered
until/unless the founder revisits that call.

**2026-09-24 release (`staging`→`main`, PR #278, 11 PRs #267–#277):**
security patch (`next@16.3.1`→`16.3.5`, 2 critical unauthenticated-RCE CVEs),
5 Plunk emails moved to branded HTML Markdown Mailables, Reseller/Affiliate
portal order list & detail now show wallet-refund/voucher-compensation facts
separately from delivery status, `/track-order` polling fixed to stay under
its own rate limit (ADR-071 addendum), and **ADR-112** (Affiliate/Reseller
portal mobile-first redesign — role-aware shell, dashboards, responsive
Orders). Founder confirmed live post-deploy. See §16 items 20/22/23 (closed)
and `docs/build-log.md`'s matching entry.

**2026-09-24, second release (`staging`→`main`, PR #286, 7 PRs #279–#285)
— founder-confirmed live:** a founder-requested audit of a recurring
"70-100 packages pending" complaint led to 3 fixes, plus 2 real bugs the
founder caught by testing the live storefront himself. **PR #280**
(ADR-100 addendum) retired the Pending Reactivation auto-approver's
flap-count gate after live data proved it permanently blocked popular
Digiflazz SKUs (Valorant Singapore VP) confirmed continuously active for
~20 hours — streak length is now the sole non-cutoff signal. **PR #281**
(ADR-094 addendum) reverses decision 22 — a combo cascade-deactivated by a
component outage now reactivates automatically once every one of its
components is active again, closing §16's former item 21
(visibility+action gap). **PR #282** (ADR-046 addendum) found and fixed
the same combo-cascade gap in Supplier Management's bulk toggle (a raw
mass update that never called `ComboPricingService` at all) — including a
real self-join alias bug caught by its own new tests before merge, not
shipped. **PR #284** fixed a real cross-affiliate bug the founder found by
testing his own `fixfastapp.com` storefront: checkout/membership CHIP
`success_return_url` always pointed at `pekangame.space` regardless of
which affiliate the customer bought from, so `/track-order`'s
brand-scoping (correct by design) 404'd on their own order — now built
from the request's resolved `StorefrontBrand`. **PR #285** added a
bilingual (EN+BM) modal explaining Renew/Upgrade's non-obvious
expiry-stacking + delayed-quota mechanics before the customer commits,
prompted by the same session's questions about the redirect fix. PR #284
and #285 were both found and fixed same-session, never previously
tracked in this backlog. See §16 items 21/17 and `docs/build-log.md`'s
matching 2026-09-24 entries for full detail.

**Same-day production infra fix (not a code release — a Vercel/`.env`
config change, applied directly):** all 3 Vercel Function projects
(`pekangame-storefront`, `pekangame-admin`, `pekangame-reseller`) were
found misconfigured to the `iad1` (US East) Function region against a
Singapore-hosted backend — every page render was paying a cross-Pacific
tax (measured live: `/order/[slug]` averaged 3.9s, P95 6s, bursting to
48s under concurrent load, while the backend itself answers in
~100-200ms). Switched all 3 to `sin1` (Singapore); confirmed live,
duration dropped to 0.15-0.45s. Two `[catalog] listHeroSlides failed`
errors seen on `fixfastapp.com` in the preceding week (Cloudflare 520/522/
525 origin-connection failures) were downstream symptoms of the same
cross-region latency, not a separate bug. Also updated prod `.env`
(founder's own call, made live while tuning ADR-100's fix):
`REACTIVATION_STABILITY_SYNCS` 1→6 and `PRICE_SYNC_INTERVAL_MINUTES`
60→30 — raised together to keep the ~3h stability window at the faster
sync cadence.

Since the 2026-09-18 snapshot this headline used to carry:
**ADR-110** (CHIP status-mapping/expiry fix, settlement reconciliation filling
ADR-083 PR-2, and the CHIP credential `.env`→DB migration, plus two same-day
follow-up addenda) shipped and released to `main` 2026-09-19 (PR #250–252,
#254) — **founder has since filled in the real CHIP credentials via
`/middleware/payment-gateways` and confirmed the connection probe passes; the
`.env` `CHIP_SECRET_KEY`/`CHIP_BRAND_ID` fallback vars are being kept on Forge
deliberately (belt-and-braces), not an owed removal — see the "Parked" section
below**; **ADR-109** (per-game "How to Buy" info popup + delivery badge) and the
hero-banner optional-fields/mobile-crop fix both shipped; **Membership**'s
savings-badge fix + tier-economics redesign (new RM19.90/RM49.90 tiers) +
quota transparency shipped 2026-09-20 (PR #256/257); a Reports/Customer
Analytics money-critical audit found and fixed 3 real bugs 2026-09-21 (PR
#258, ADR-086 addendum); and a Combo Package pre-scale reliability audit
closed 3 real gaps the same day (PR #259, ADR-094 addendum), alongside a
deliberate, parked pricing-arbitrage decision on PUBG Mobile's 9 colliding
denominations (PR #260, ADR-094 addendum — see §16). Everything else
outstanding is polish or a deliberately-parked ADR — see §16.

- **Per-feature-area status:** §15 below.
- **Full chronological build record** (every session, what shipped, the gotchas): `docs/build-log.md`.
- **Decision rationale** (Context → Decision → Rationale → Consequence): `docs/adr.md`.

---

# 15. MVP Scope Tracker

The coarse status-by-feature-area map. Read at a glance; the file-level detail and
the chronology are in `docs/build-log.md`, the *why* in `docs/adr.md`.

| Area | Status | Key ADRs |
| --- | --- | --- |
| Auth & Admin Users (AUTH-1..7) | ✅ Live. Login rate-limited + logged. MFA (AUTH-7) descoped, founder's call | ADR-019 |
| Admin Dashboard (DASH-1..6) | ✅ Live — KPIs, System Health, funnel, top games, hourly activity | ADR-045 |
| Money core (Pricing, Ledger, Voucher, Order status, idempotency) | ✅ Live — full service layer, concurrency-proven. The most mature part of the codebase | ADR-002 |
| Affiliates / whitelabel (RES-1..6) | 🟢 Built on `staging` — wholesale tiers + subscription state machine, tenant isolation, `affiliate` guard + portal, platform-owner special-case abolished (`is_owned`/`is_primary`), per-brand Membership, `Host`-resolved branded storefront + Vercel-native custom domains, per-brand pricing + ledger split, brand-scoped vouchers. Real affiliate domain verified end-to-end. PR #273's invite/session and compensation visibility fixes, plus ADR-112's role-aware shell, dashboard recent orders, and responsive Orders presentation, are merged to `staging` in PRs #273, #275, and #276; production release is not claimed | ADR-056–061, 078, 112 |
| Reseller (wallet) — Affiliate/API/Bot channels | 🟢 Built on `staging` — prepaid wallet + admin manual credit + self-serve CHIP top-up, `ResellerOrderPlacementService` contract, `reseller_code`/`catalog_code` scheme, REST API keys + IP allowlist + delivery webhook, WhatsApp bot (OpenWA), shared portal. Dev docs site live at `docs.pekangame.space`. Public `/price-list` acquisition page (ADR-091), admin-selected tiers. `.order` fat-finger safety net — auto player-ID/region validation + player ID echo (ADR-093, 2026-09-13). PR #273's ledger-backed refund fields and API/docs patch, plus ADR-112's role-aware shell, dashboard recent orders, and responsive Orders presentation, are merged to `staging` in PRs #273, #275, and #276; production release is not claimed | ADR-072–076, 084, 091, 093, 112 |
| Supplier Adapter (ADAPT-1..4) | ✅ Gamevion + Digiflazz both live. Per-supplier circuit breaker, `SupplierAdapterFactory` routing, async delivery state machine + poll backstop, inbound webhooks (HMAC). **ADR-097 PR-1 + PR-2 built 2026-09-16** — Digiflazz `customer_no` separator moves per-game (was wrongly supplier-wide); per-game Zone ID picklist replaces free text, with the same presence+value-validation now shared (`CheckoutInputValidator`) across storefront, Reseller API, and Bot. PR-1 merged to `staging`; PR-2 open. **ADR-098 built 2026-09-16** — `SupplierResponse::$transactionAlreadyFormed` (supplier-agnostic) routes a non-retriable Digiflazz `rc` (Terbentuk Transaksi=Ya, 20 codes) or Gamevion `duplicate_reference` straight to `needs_review`, closing a real gap in the async webhook/poll finalize path a plain `Failed` resend could never resolve. PR open | ADR-006, 030–032, 067, 069, 097, 098 |
| Payment Gateway (CHIP only, PAY-1..4) | 🟢 Live in prod — real RM FPX payment + webhook proven end-to-end (order `PG-PYAYMRYNUYV0`). `fpx` active; `fpx_b2b1` / `duitnow_qr` seeded inactive (later phases). Xendit deleted (archived). **ADR-110 PR-A built 2026-09-19** — `overdue`/`expired`/`blocked` now map to `Failed` (were silently stuck `Pending` forever); every purchase now sets `due`+`due_strict` so CHIP itself closes it after 30 min. **PR-C built 2026-09-19** — credentials moved to encrypted `payment_gateways` DB row + `/middleware/payment-gateways` admin screen, binding falls back to `.env` until the founder completes the manual cutover (founder-owed, see §16 Parked). **PR-B built 2026-09-19** — CHIP settlement `.xlsx` reconciliation + Monthly Accounting Summary, fills ADR-083 PR-2 (see §16 item 12). All three ADR-110 PRs now built | ADR-022, ADR-110 |
| Games & Packages (GAME-1..11) | 🟢 Live — GAME-1..11 all shipped (list/detail, markup %, activate/deactivate, delete, bulk markup via `/admin/settings`, SEO fields via `/admin/seo/games`, drag-drop reorder via `/admin/games`'s "Reorder Games", folded with the storefront's Quick Top-Up widget). GAME-12 dropped, 2026-09-13 (dead requirement, see §16) | ADR-029 |
| Combo Package | 🟢 Live in prod as of `staging` (functionally complete) — several existing catalog Packages assembled into one opaque, sellable SKU above a game's native max denomination, so a reseller/guest pays one CHIP FPX fee instead of two. Data model + creation endpoint, pricing (sum-of-components, override optional) on the same Price Sync cadence, fulfillment leg-engine (both suppliers, partial-delivery → `needs_review`), admin UI (`/admin/games` composition CRUD, Order-detail leg breakdown, custom-amount Voucher for partial delivery, component-churn guards). Verified genuinely buyable through storefront, Affiliate, and Reseller API/Bot (Reseller Portal has no order-placement surface). **2026-09-16:** max legs raised 3→5 (real usage, decision 20's own revisit bar), component SKU (`supplier_package_ref`) now shown in the composition picker and the Order-detail leg breakdown — previously only the component name/denomination, ambiguous when two components share a denomination across suppliers. **2026-09-17:** `platform_profit` now reconciles as a money-conservation residual on final delivery instead of staying frozen through a leg retry (ADR-107, §16 item 18). **2026-09-21:** pre-scale reliability audit closed 3 real gaps — Mark Delivered hard-blocked on a combo order with an outstanding leg, `isPartialComboDelivery()` widened to catch a NeedsReview-caused over-compensation path (not just Digiflazz-Gagal), and a TOCTOU race in `confirmDeliveryFailed()` fixed by re-checking the partial-delivery guard inside the row lock. Same day: a live-data check found 9 real PUBG Mobile Global combo SKUs silently undercutting their native equivalent (RM1.96–163.70/unit, same 10% markup both sides — a genuine Digiflazz tier-pricing cost gap, not a margin bug) — founder's deliberate call to leave pricing as-is and use the existing `combo_override_price` lever manually later. **Same day, per-leg audit trail built (ADR-106 addendum, §16 item 16)** — every leg attempt (initial + retry) now writes a durable row, closing decision 1's own deferral; same fix closed the identical gap on plain orders too. Open, deliberately deferred: no edit-composition UI (delete-and-recreate only), no admin-facing leg-attempt-history UI (data is durable and queryable, view itself deferred). **2026-09-24:** the last known gap in this row closed — a cascade-deactivated combo now reactivates automatically once every one of its components is active again (ADR-094 addendum, reverses decision 22's original one-directional call; founder chose auto-cascade over a manual-but-visible queue), wired into every component-reactivation path including the Supplier Management bulk toggle (ADR-046 addendum, which also fixed that toggle never cascading combo *deactivation* either). Released `main` via PR #286, 2026-09-24 | ADR-094, ADR-100, ADR-046, ADR-106, ADR-107 |
| Price Sync (SYNC-1..6) | ✅ Live — raw sync → promote-to-catalog, price propagation + deactivation detection, sanity guard (floor + swing), FX conversion, best-price dedup, per-supplier grouping, stuck-run hardening | ADR-015/016, 025, 033, 034, 067 |
| Supplier Management (SUPP-1..5) | ✅ Live — SUPP-1/CRUD/SUPP-5; credentials in encrypted `Supplier.api_config`; balance refresh + low-balance chip; credential-rotation probe on save. **2026-09-24:** the bulk "Deactivate All"/"Deactivate by Game"/"Reactivate" toggle now cascades onto dependent combos (found while auditing the Pending Reactivation combo gap above — the bulk toggle had never called `ComboPricingService`'s cascade at all, deactivate or reactivate). Released `main` via PR #286, 2026-09-24 | ADR-046, 069 |
| Orders Management (ORD-1..11) | ✅ Live — model + fulfillment + checkout, Resend Delivery (same-game swap), ORD-10 reconciliation, async `pending_delivery`. First real prod order 2026-09-03. Six KPI cards on `/admin/orders` (ADR-092, 2026-09-13). **"Check from Supplier"/"Check from Gateway" manual-poll buttons built (ADR-096, 2026-09-15)** — synchronous on-demand status check for a Pending order, shares logic with the scheduled reconcile jobs, cache-based cooldown. **ADR-102 Phase 1 built 2026-09-16** — `Order::isAlreadyCompensated()` unifies every Resend/Retry/Mark-Delivered/Confirm-Failed guard against a voucher OR a wallet refund already given (was voucher-only), checked inside `fulfill()`/`fulfillCombo()`'s own row lock as the real final defense, not just a controller pre-check. Also fixed mid-build: `refundToWallet()` had no DB-level backstop against a double wallet-refund (unlike Voucher's real unique index) — now locks the same way, proven via a new concurrency test. **ADR-102 Phase 2 built 2026-09-16 (decisions 3-9)** — a Digiflazz confirmed-Gagal `rc` (even one unsafe to resubmit) now routes straight to `Failed` instead of `needs_review` (Issue Voucher immediately available, superseding ADR-098 decision 6); a non-combo resend from `Failed` regenerates its `reference_number` (safe — confirmed non-delivery), reuse preserved from `needs_review`; Resend/Retry button disables with a mandatory logged override reason when genuinely futile (non-combo: `needs_review` only; combo: regardless of status); `ReconcilePendingDeliveriesCommand` permanently self-corrects any stuck `needs_review` row. **ADR-102 Phase 3 built 2026-09-16 (decisions 10-13, closes out ADR-102's own decision list)** — an optional Player ID/Server ID correction on Resend (re-validated before resubmitting); Order Detail's 3 independent Refund Information cards (Voucher Used to Pay / Compensation Voucher Issued / Wallet Refund) replace the old single-line mentions; `/admin/orders` gains 🎫/🎟️/💰 compensation badges; `NeedsReviewBanner` explains in plain language why Resend/Retry is disabled. **ADR-103 built 2026-09-17** — a combo leg now gets its own independently-regenerable `reference_number` (was derived/regex-parsed off the order's), closing the combo scope ADR-102 decision 9 explicitly deferred: a `Failed` leg mints a fresh (ULID-suffixed) reference on retry, a `NeedsReview` leg keeps reusing its stored one; the Digiflazz webhook resolves `ref_id` via two direct lookups (Order, then OrderDeliveryLeg) instead of a regex parse; the combo-wide Retry button's futility warning is now an OR-rollup across legs' own unsafe flag, retiring ADR-102 decision 3's old (always-quiet) combo branch. This family is now fully built. **ADR-024 restore-only addendum built 2026-09-17** — a full-cover-by-voucher order that later fails delivery no longer mints a pointless RM0.00 compensation voucher (button auto-labels "Restore Voucher," restores the original voucher only); found and fixed the same session: `isAlreadyCompensated()`'s guard had a real gap for this exact order shape. The 🎫/🎟️/💰 badges above are now plain-text `<Tag>` pills (founder feedback — emoji read as noisy next to the status tags), plus a 4th "Restored" pill/card. **ADR-104 PR-2 + PR-2b + a founder-driven live-browser audit, all 2026-09-17** — header action-bar + compact 5-column summary strip (incl. Channel), card-merge (Game & fulfillment / Payment & supplier), card-heading icons, `RefundInformationCards` emoji→icon + responsive 2-col grid, sidebar regrouped into 6 titled sections (app shell newly brought into ADR-104 scope), plus 2 real dark-mode token bugs found+fixed (D1: unstyled `<dd>` rendering `rgb(0,0,0)` on dark cards; D2: `info-surface`/`info-ink` missing a `.dark` override entirely) — see the ADR-038/104 note below the table. **ADR-108 built 2026-09-18** — `need_action` (KPI + tab) now excludes an already-compensated order (found live on prod: real actionable count was 0, KPI showed 4); Delivery column caps compensation badges to 1 (was up to 4 stacked, ADR-102 decision 12 reversed); new toolbar — Source/Game/date-range filters, a Columns toggle, and **ORD-5 export finally built** (CSV streams the current filtered view, plus a money-audit breakdown — Pricing Basis/Cost/Markup%/Profit — beyond the visible table). Founder live-verified. | ADR-017, 024, 026, 032, 092, 096, 102, 103, 104, 108 |
| Reports (RPT-1..3) | ✅ Live — ledger-sourced profit, `paid_at`-scoped sales, reseller-aware, tabbed analytics suite, CSV/PDF (now 13-column, every breakdown dimension). **ADR-086 complete** (PR-1 grouped-SQL rewrite + PR-2 Reseller-wallet breakdown; PR-3 chart migration closed without a code change — no charting library, matches the hand-rolled-visual house style). **ADR-088 built** same day — unified date-range filter (trend charts now follow the page filter, no more a private day-toggle), export widening. **ADR-087 built 2026-09-12** — Gemini Flash LLM assistant at `/admin/reports/assistant`, `super_admin`-only; needs `GEMINI_API_KEY` provisioned before it works in any real environment (same .env-only rollout as CHIP/Digiflazz) | ADR-086, 087, 088 |
| Withdrawals (WTH-1..5) | ✅ Live. Maker-checker threshold RM 2,000 (`WITHDRAWAL_MAKER_CHECKER_THRESHOLD_SEN`) | — |
| Vouchers (VCH-1..6) | ✅ Live — + voucher-at-checkout (wallet model, partial/full cover), Path A double-submit key, Voucher Merge. Maker-checker RM 500 | ADR-024, 035, 036 |
| Customer Analytics (ANL-1..4) | ✅ Live — `/admin/customer-analytics`, derived `customer_email` grouping (no new entity), VIP/Frequent/Dormant/New/One-time segments | ADR-049 |
| Membership (VIP, per-brand) | 🟢 Live in prod (kill switch ON) — 2 fixed tiers, email-OTP identity, live member pricing + quota, self-serve subscribe + pay via CHIP, admin per-member detail. Real tier numbers set. Per-brand `/membership` fully gated. WhatsApp renewal-reminder half deferred (vendor unpicked) | ADR-027, 055, 068, 080 |
| Reviews (REV-1..5) | ✅ Live — guest submit gated on Delivered, admin approve/reject/bulk, + public display (homepage marquee + per-game PDP section, brand-scoped) | ADR-053, 082 |
| Backups (BAK-1..5) | ✅ Live on Cloudflare R2 (`pekangame-backups`, private) — full DB dump except `player_validations`, encrypted, 7d/4w/6m retention, restore-tested every run, CLI-only restore. **2026-09-14: found the restore-test had failed 14/14 since go-live** (managed-MySQL GTID privilege gap) **and its alert never reached an inbox** (`MAIL_MAILER=log`) — both fixed and **re-verified live same day**: a manual "Backup Now" landed on `r2_backups` with `status=success`/`restore_test_passed=1`, the first success ever recorded | ADR-039, ADR-095 |
| Image Gallery (IMG-1..2) | 🟢 Live in prod on Cloudflare R2 — upload/grid/search/copy-URL/delete, WebP-at-upload (2000px cap, reuses `ImageIngestService`) + delete referential-safety warning. `GALLERY_DISK=r2_gallery`/`BACKUP_DISK=r2_backups` live since 2026-09-14; every existing gallery/logo/favicon file migrated + verified 200 on `cdn.pekangame.space`. In-modal picker still not wired (paste URL) | ADR-095 |
| SEO (SEO-1..7) | ✅ Live — per-brand settings, meta templates, redirects (via `proxy.ts`), scripts, crawler rules, JSON-LD, native robots/sitemap/llms.txt. Full backend test coverage | ADR-029 |
| Settings (SET-1..11) | ✅ Live — `/admin/settings` (branding / footer / platform), HTML sanitization, maintenance mode. SET-7/11 via the Payment Methods tool. SET-9 Telegram *sender* unbuilt. Logo + favicon upload for the primary brand added (ADR-089) — previously only the affiliate portal had this | ADR-028, 089 |
| Developer Tools (DEV-1..2) | ✅ Live — `/middleware/developer-tools`, all 5 adapter methods, typed-DTO editor, dry-run default. Same screen as MUI-11 | ADR-054 |
| Blacklist / Fraud (FRAUD-1..4) | ✅ Live — `BlacklistService` + `CheckoutVelocityGuard` wired into checkout; `/admin/blacklist` screen. `foundation-security.md` §4 fully checked | ADR-007 |
| Middleware Panel (MID-1..13, MUI-1..11) | ✅ Live — sync/matching/catalog (Product Manager), price sync + FX, player validation, test orders, request logging, supplier credentials, landing page. MUI-4 (export) dropped | ADR-051, 052 |
| Storefront (checkout flow) | 🟢 Live in prod — all catalog/checkout/validate/track endpoints; server-side validation enforcement; PekanGame neo-brutalist redesign, mobile pass, read-path perf (Redis cache), dynamic payment SVGs + UX polish. Logo/favicon upload UI + aspect-preserving sizing + preset background/dark-mode groundwork shipped (ADR-089/090). Real logo/hero artwork asset itself still placeholder. Per-game "How to Buy" info popup (description/important notes) + admin-editable instant/manual delivery badge, `EditGameModal` gained Basic Info/Content tabs (ADR-109) — admin-side live verification still owed | ADR-062–065, 071, 077–079, 089, 090, 109 |
| Internal Accounting (supplier funding ledger + CHIP settlement recon) | 🟢 PR-1 built 2026-09-11 — `supplier_transfers`/`supplier_ledger_entries` (append-only, foreign-currency), Record Supplier Transfer UI (`/admin/accounting`), `ORDER_DRAWDOWN` capture (Digiflazz webhook + Gamevion sync response — a `Gagal` after `Pending` writes no `REFUND`, grilled), drift check + amber chip on Dashboard Health, Transaction Register + CSV export. **2026-09-15 addendum built:** `supplier_fee` field (the supplier's own deposit-side cut, e.g. Digiflazz's flat IDR fee — ledger now credits net, not gross) + Adjust/Void correction actions (never edits/deletes the append-only ledger, always a new `MANUAL_ADJUSTMENT` entry). **PR-2 built 2026-09-19 (ADR-110 PR-B)** — CHIP `.xlsx` settlement reconciliation (`payment_settlements` + `chip_settled_transactions` dedup guard) + Monthly Accounting Summary screen, verified against a real PekanGame CHIP settlement file | ADR-083, ADR-110 |

**PrimeReact migration (ADR-038):** complete 2026-08-29 — every hand-rolled
TailAdmin primitive in `admin/` migrated or deleted; `RichTextEditor` is the one
kept. Screen-by-screen history in `docs/build-log.md`.

**Visual redesign (ADR-104):** retires the TailAdmin-origin token *values*
ADR-038 left untouched. **PR-1 (foundation — token layer, Figtree/JetBrains
Mono fonts, `@primeicons/react` icon swap) merged to staging 2026-09-17
(PR #233)**, app-wide. **PR-2 (Orders — list/detail/all states) built same
day, PR #234, + a same-day correction pass** — `Tag` severity remapped to
the artifact's own status tokens (new `review` severity, distinct from
`warn`, for `needs_review`; opt-in `dot` indicator), cyan for "act here"
(active filters/cards, focus ring), `page-title`/`section-title`/
`metric-lg`/`overline`/`code-id` typography tokens applied. The correction
pass (same session, prompted by the founder re-checking against the real
artifact in a browser) reverted an over-generalized "purple = profit"
read back to neutral (purple on Orders is reserved for urgency, per the
artifact's own KpiCards — profit-purple is a Reports-chart-only
convention), added missing `border`/`border-strong`/`border-control`
tokens PR-1 had missed, and gave the Orders list's Amount cell a
two-line (value + payment-method) treatment. **PR-2b (Order Detail header
action-bar + a compact 4-column summary strip) built 2026-09-17, PR #235**
— grilled and locked to a strict layout-only scope; every conditional
action button's condition/handler/label unchanged, only repositioned.
**Founder then drove a live-browser audit against the real artifact
screenshots (same day, same PR #235)** and correctly rejected an initial
"no contrast issue"/"confirmed match" read — found and fixed two real
dark-mode token bugs (D1: every unstyled `<dd>` in `OrderDetailCards.tsx`
silently rendered `rgb(0,0,0)` on a dark card, confirmed+fixed via
`getComputedStyle`; D2: `info-surface`/`info-ink` had no `.dark` override
at all, rendering as a bright light-cyan island — fixed by mirroring
cyan's own dark pair). Re-grilled scope with a strict rule (current
implementation = functional truth, mockup = visual truth only, never
invent/rename to chase fidelity) and built: card-merge into "Game &
fulfillment"/"Payment & supplier" (+ structured "Latest supplier
result"), card-heading icons, `RefundInformationCards`' emoji→icon +
responsive 2-col grid, info-strip's 5th "Channel" column, uniform
header-button styling, KpiCards icon consistency, and — app shell newly
brought into scope this session — the sidebar's flat menu regrouped
into 6 titled sections (Overview/Commerce/Catalog/Customers/Partners/
System) matching the artifact exactly, plus a logo-box treatment and a
shared-component retint (also benefits the Middleware Panel, confirmed
unaffected). **PR-3 (Reports)** still open — re-open the artifact
directly in a browser before building it (the Artifact tool's own
read/read_db paths return only an empty shell for this artifact type),
and drive a real live-browser audit against actual screenshots before
declaring anything "matched" — this session's own early false-positive
is the concrete lesson. The other ~13 admin sections (excluding the app
shell, now visually redone) stay out of scope until the founder's own
mockups exist for them (ADR-104 decision 7).

---

# 16. Open Items & Backlog

Last walked with the founder 2026-09-09, re-verified against production 2026-09-11,
spot-checked against real code again 2026-09-13 twice in the same day (Voucher/
Blacklist/GAME-11/SEO-fields turned out already shipped; GAME-6 then shipped
same-session, PR #192), again 2026-09-14 (real logo/hero asset uploaded), and
again 2026-09-16 (Digiflazz confirmed funded 2026-09-15, real orders now
delivering — see §14; `/admin/reviews` item's "zero orders
delivered" premise is now stale, re-check its corpus next session), again
2026-09-21 (full docs-vs-code audit; no open item below turned out already
shipped), and again 2026-09-24 (staging→main release PR #278 — items 20/22/23
closed; Gamevion launch gate retired, see below) — this list drifts easily,
re-verify against real code/production before trusting an "open" line here,
not just this doc's memory. Anything shipped and verified drops off this
list into `docs/build-log.md`.

## No open launch gate

~~**Fund Gamevion.**~~ — **Retired as a launch gate, 2026-09-24, founder
decision.** Digiflazz was funded 2026-09-15 and has been delivering real
orders since; the founder has decided to run the platform Digiflazz-only for
the foreseeable future rather than fund a second supplier. Gamevion (billed
in **MYR**, not IDR/Rp — do not conflate its currency with Digiflazz's) stays
integrated and buildable but deliberately unfunded/deprioritized; revisit
only if the founder actively decides to bring it online. Until then,
Gamevion-routed orders can still be paid but not delivered — a known,
accepted state, not a gap to chase. See §14.

## Polish (not blocking launch)

2. ~~Real PekanGame logo + hero artwork~~ — **DONE 2026-09-14.** Founder uploaded
   the real asset via `/admin/settings`'s Logo + Favicon panel (ADR-089
   pipeline). SVG placeholder replaced.
3. ~~`/admin/reviews` approved-corpus re-read~~ — **RESOLVED 2026-09-18, no
   audit needed.** ADR-082's risk was a corpus approved *before* it landed,
   under the old "not spam" bar, suddenly going public under the new "shown
   to customers" one. Confirmed: no such corpus ever existed — the first
   order wasn't delivered until Digiflazz funded 2026-09-15 (REV-1..5 gates
   submission on Delivered), so no review could have been submitted, let
   alone approved, before ADR-082 shipped. Founder end-to-end-verified the
   live pipeline the same day: placed a real order, approved its review from
   `/admin/reviews`, confirmed it renders on the storefront.
4. **OpenWA droplet resize (+$20/mo) + the webhook nginx IP-restriction** — the
   Bot channel works and every command is prod-verified; the webhook already has
   HMAC-signature auth (ADR-076). Resize when capacity actually calls for it.
   **2026-09-25 note (ADR-114 migration audit):** the new production droplet
   (`pekangame-prod-lwf`) does not carry this nginx-level 127.0.0.1
   IP-restriction at all — checked directly, no match in its `sites-available`
   config. Not urgent (HMAC auth still gates the route, and OpenWA itself
   hasn't moved to this box yet), but the IP-restriction needs re-adding here
   once the bot migrates onto the new server, not assumed carried over from
   the Cloudflare-mTLS config copy.
5. ~~**e2e flake** — `storefront-checkout.spec.ts`'s "Delivered" assertion uses a
   30s timeout under the 60s per-test budget; raise it.~~ — **FIXED
   2026-09-22.** Real bottleneck wasn't the overall test budget
   (`test.slow()` already triples it to 180s) — the final assertion's own
   `15_000`ms timeout was racing a cold Next.js compile of the status
   route. Raised to `30_000`. See `docs/build-log.md`'s matching entry.
6. Small unbuilt scope, none blocking: ~~ORD-5 (order export)~~ — **built
   2026-09-18, ADR-108** — SET-9 (the Telegram
   *sender* — the setting fields exist), gallery in-modal picker (paste-URL —
   gallery→WebP + delete referential safety already shipped, ADR-095),
   SEO `AggregateRating` JSON-LD on the PDP,
   ~~ADR-090's dark-palette backfill (`bumblebee`/`redgiants`/`emerald`/`cobalt`)~~
   — **built 2026-09-24, ADR-113**, the **reseller-family audit's item A3**
   (per-tier rate limit on the Reseller API/Bot) — deliberately deferred design
   call from the 2026-09-10 audit, not forgotten — and the reseller portal's
   `ThemeTab.tsx` live-preview panel still hardcoding a few cosmetic details
   (the payment-strip background/badge colours) outside the injected tokens,
   first flagged in ADR-090's Consequence-to-track and confirmed still
   unresolved during ADR-113's audit (2026-09-24) — the mockup made it look
   like a preset's secondary/tertiary accents were missing when the real
   storefront actually renders them fine; low priority since the real
   storefront is unaffected, but worth fixing so the preview can be trusted
   without a live-storefront cross-check every time.
7. ~~ADR-090 dark mode needs a real redo.~~ **Resolved 2026-09-24 by
   [ADR-113](./adr.md)** — the founder tried the antislop/impeccable skills
   directly against real competitor references instead of waiting for a
   Stitch reference, found and fixed the actual causes (border contrast, a
   large-fill color clash), and separately decided Digital Architect is no
   longer an affiliate-selectable preset at all (see §6.15).
8. **GAME-6, GAME-11 (bulk price update), Game SEO fields, and GAME-12 are OFF
   this list — confirmed already resolved 2026-09-13, not by this list's own
   prior entries:**
   - **GAME-6 — shipped same-session** (PR #192, `feature/game-6-reorder-games`):
     `/admin/games`' "Reorder Games" (drag-drop, PrimeReact `reorderableRows`),
     `GameController::reorder()`. Widened by one necessary fact found mid-build:
     `games.sort_order` was completely dead everywhere — admin list, public
     storefront catalog, **and** the Affiliate whitelabel catalog all ordered
     alphabetically, ignoring the column entirely — now wired into all three.
     Quick Top-Up's hardcoded `QUICK_COUNTER_SLUGS` shortlist (dev-only,
     no admin control) dropped in the same PR — its tiles are now just the
     first 6 games in the same admin-ordered list, one control surface
     instead of two. `AffiliateGame` still has no `sort_order` of its own
     (confirmed unchanged) — an affiliate storefront inherits the primary
     brand's order verbatim, by design (`CatalogController::index()` is
     shared/brand-scoped, not per-brand-ordered); revisit only if an
     affiliate actually asks for independent ordering.
   - GAME-11 — already shipped: `SettingsController::bulkMarkup()` +
     `PlatformSettingsSection.tsx` under `/admin/settings`, not the Games page.
   - Game SEO fields — already shipped, ADR-029 (`/admin/seo/games`, built
     2026-08-22).
   - **GAME-12 dropped as a dead requirement**, not merely deprioritized:
     `supports_validation` (the per-supplier-mapping player-validation-capability
     flag) has zero code path anywhere that ever reads it, and both suppliers
     this platform has ever integrated — Gamevion and Digiflazz —
     unconditionally `throw ValidationNotSupportedException` from
     `validatePlayer()` (confirmed in both adapters, consistent with ADR-005's
     own "validation happens implicitly at order time" call). There was never
     a capability to expose an admin toggle for. §8's `Game` row and the
     GAME-1..12 requirements table are corrected to match.

## Hardening (founder `.env` / infra)

9. `MYSQL_ATTR_SSL_CA` (link already VPC-private + TLS); DuitNow QR / `fpx_b2b1`
   are later phases; DNSSEC (founder's call). `.env.example` still owes
   `STOREFRONT_PRIMARY_HOSTS` / `VERCEL_*` / `GALLERY_DISK` / `CACHE_STORE=redis` /
   `PULSE_*`.
10. **MFA** — admin (AUTH-7) and `affiliate_users` both descoped; revisit before
    the partner portal's withdrawal balances get meaningful.
11. **No external uptime monitor / error tracking (Sentry).** ADR-019's accepted
    deferral — a real operational risk once real traffic exists.
## Buildable now (design done, not started)

~~12. **ADR-083 PR-2** — CHIP `.xlsx` settlement reconciliation + Monthly
    Accounting Summary screen.~~ — **grilled + BUILT 2026-09-19, [ADR-110](./adr.md)
    PR-B** (+ same-day addendum: `chip_settled_transactions` dedup guard
    against a re-uploaded/date-overlapping file). `payment_settlements` +
    `chip_settled_transactions` tables, `SettlementFileParser`/
    `SettlementReconciliationService`/`MonthlyAccountingSummaryService`,
    `/admin/accounting/settlements` (+ detail) and `/admin/accounting/summary`
    screens. Verified against the real PekanGame CHIP settlement file
    (`settlements-20260919025821.xlsx`).
13. **ADR-085 candidate** — self-serve reseller signup (payment risk, KYC, auto
    tier-assignment). Needs its own ADR + grill; ADR-084 assumes invite-only.
14. **ADR-087 candidate addendum** — persisted, multi-thread chat history for the
    LLM Report Assistant (`/admin/reports/assistant`): a ChatGPT/Gemini-style
    sidebar (new chat, switch between past threads, delete a thread), the
    assistant still remembering a past thread's context when reopened. Reverses
    ADR-087 decision 7 ("session-scoped only, never persisted to the database
    long-term") — needs its own grill before building, not a trivial addition:
    open questions include retention policy for old threads (vs. the existing
    90-day audit log, which stays regardless and serves a different, compliance
    purpose), per-admin thread isolation, and DB/endpoint shape (list/create/
    delete thread, fetch a thread's messages). Raised by the founder 2026-09-12;
    not a system-load concern either way — Gemini's own per-message cost already
    scales with resent history length today, persisting it doesn't add API cost,
    only cheap DB storage for a handful of `super_admin` accounts.
15. ~~Durable "Initial Delivery" audit row — needs its own ADR + grill.~~ —
    **grilled + BUILT 2026-09-18, [ADR-106](./adr.md)** — merged to `staging`
    2026-09-17 (PR #237, bundled with ADR-107 below).
    Found 2026-09-15 while fixing the Delivery Logs outcome bugs (see
    `docs/build-log.md`'s 2026-09-15 entry): `order_resend_attempts` (ADR-017
    decision #4, "record every attempt, not just the latest") used to only
    ever log RESEND attempts — the very first/original fulfillment attempt
    had no durable row of its own, and neither did a `markDeliveredManually()`
    confirmation (a third gap found while grilling this ADR). Both now write
    a real row (`attempt_type` = `initial`/`manual_confirm`, alongside the
    existing `resend`), from inside `OrderFulfillmentService::fulfill()`/
    `markDeliveredManually()` themselves — no rename, no new table, same
    `order_resend_attempts`. The admin's "Initial Delivery" row now reads
    the real durable row once one exists for an order; the 2026-09-15
    honest-fallback synthesis stays unchanged for any order created before
    this shipped (its true initial data is already unrecoverably gone).
    Non-combo only for now at the time (decision 1) — see item 16 below,
    closed 2026-09-21.
16. ~~Combo order `retryDelivery()` has zero audit trail — deliberately
    deferred, non-combo only for now.~~ — **grilled + BUILT 2026-09-21,
    [ADR-106](./adr.md)'s own 2026-09-21 addendum.** Real reseller volume
    ramping up was the revisit trigger this item itself was waiting for.
    Reuses `order_resend_attempts` (nullable `order_delivery_leg_id`, no
    new table) rather than forking a parallel one — writes on every leg
    attempt (`initial`/`retry`, not just retries) from inside `attemptLeg()`
    itself, so all 6 of `FulfillOrderJob`'s dispatch sites get a correct row
    automatically. Found and closed the identical gap on PLAIN orders in the
    same fix — `retryDelivery()`'s route was never `is_combo`-gated, so a
    non-combo retry through the same job had the same silent blind spot.
    `override_reason` now persists into `note` too (was log-only), retrofitted
    onto `resend()` for consistency. Admin-facing "Leg History" UI stays
    deferred — the data is durable and queryable today, a UI surface is its
    own follow-up.
17. ~~Should Pending Reactivation ever auto-approve?~~ — **grilled + BUILT
    2026-09-16, [ADR-100](./adr.md).** Off by default
    (`PENDING_REACTIVATION_AUTO_APPROVE=false` — today's fully-manual
    behavior is unchanged until a founder opts in). Two triggers when on:
    a Digiflazz product back inside its own documented `start_cut_off`/
    `end_cut_off` window (real API fields this project was discarding,
    found live via the founder's own dashboard + Digiflazz's docs)
    approves immediately; every other case needs a consecutive-active-sync
    streak (originally paired with a 14-day flap-history gate reusing
    `deactivation_logs` — **retired by a 2026-09-24 addendum**, live data
    showed it permanently blocking popular SKUs confirmed active 20+ hours
    straight; streak length is now the sole gate). **2026-09-24, LIVE
    PROD via PR #286:** prod `.env` settled at `REACTIVATION_STABILITY_SYNCS=6`
    and `PRICE_SYNC_INTERVAL_MINUTES=30` (founder raised the sync cadence
    for fresher prices, then raised the streak requirement 3→6 in the same
    sitting to keep the real-world stability window at ~3h rather than
    letting it silently halve to ~1.5h). Founder-owed: `.env.example`
    entries (agent write-permission gap) and a live spot-check of
    `HOK_GB_16_PG2`'s real cutoff API value once Digiflazz's pricelist rate
    limit isn't a concern (the code's fallback fails safe either way).
18. ~~Combo order profit never reconciles on retry, and its partial-delivery
    voucher suggestion reads a live price~~ — **grilled + BUILT 2026-09-17,
    [ADR-107](./adr.md)** — merged to `staging` 2026-09-17 (PR #237, bundled
    with ADR-106 above). `platform_profit` now reconciles once at
    final all-legs-Delivered resolution as a money-conservation residual
    (`order.selling_price (frozen) − Σ live componentPackage->cost_price
    across every leg − affiliate_profit`, same identity ADR-105 decision 8
    established) — `affiliate_profit` itself is never touched. A resulting
    loss is never blocked (decision 3): the order still delivers, the
    figure is recorded as-is, and a new Order Detail card
    (`combo_profit_reconciled_negative` — **renamed `profit_reconciled_flagged`
    and generalized to every order type, not just combo, by ADR-111
    decision 7, see item 19 below**) flags it for admin visibility
    after the fact. `Order::suggestedPartialVoucherAmount()` now apportions
    `final_amount − transaction_fee` proportionally across failed legs'
    frozen `order_delivery_legs.selling_price_sen` weights, never a live
    price. **Build-time revision** (found in plan-review, before code): the
    ADR's original decision 2 would have reused the raw supplier-currency
    drawdown price for reconciliation — a real currency-scale bug against
    MYR-sen `selling_price`/`affiliate_profit`; revised to read each leg's
    `cost_price` LIVE instead (matching ADR-105 decision 1's precedent),
    so the originally-planned frozen `cost_price_sen` column was dropped
    (only `selling_price_sen` is built) — see ADR-107's own build addendum
    in `docs/adr.md`. Zero real combo resends/partial-deliveries exist in
    prod to date — latent fix, not yet touched real money. Independent of
    ADR-106 (item 15), which is sequenced next on the same branch.
19. ~~Real-cost profit reconciliation at delivery time — grilled + DECIDED
    2026-09-21/22, [ADR-111](./adr.md), not yet built.~~ — **BUILT
    2026-09-22.** Real production
    order (`PG-B2BL0G1YDMVS`) found `resolveComboOutcome()`'s reconciliation
    (ADR-107 above) using a stale `Package.cost_price` that traced exactly
    to a FAILED supplier attempt's price, not the one that actually
    delivered — confirmed via raw supplier response payloads, not assumed.
    Widened during the grill: the identical `Package.cost_price`-as-live-cost
    pattern also drives `OrderResendService::resend()` (ADR-105), and the
    plain non-combo retry path reconciles **nothing at all** today — a
    bigger latent gap than combo's own. Design: widen ADR-033's single FX
    conversion boundary to a second call site (delivery, not just Price
    Sync) via one new `CurrencyRateService::convertToSen()` method; capture
    the supplier's own real per-transaction price into new
    `real_cost_price_sen` columns (`orders`, `order_delivery_legs`) at all 4
    delivery-finalization sites; one basis-agnostic residual formula
    (`selling_price − real_cost − affiliate_profit`, the same ADR-105
    decision 8 identity) for every successful delivery, first-attempt or
    retry. Confirmed cheap/safe (cached FX rate, queued job, ADR-014) —
    not a per-order live API call. Feature-flagged rollout given the blast
    radius (every future order's `platform_profit`, not a scoped subset).
    Explicit per-`pricing_basis` (Standard/Affiliate/Member/ResellerWallet)
    test coverage built and green, one test per basis, for both combo and
    non-combo. `config('services.real_cost_reconciliation.enabled')` is
    the kill switch — real cost is captured unconditionally on every
    delivery, but only USED to recompute `platform_profit` once flipped
    on. **🟢 LIVE PROD, flag enabled 2026-09-22** (same day as build,
    ahead of the `staging`→`main` release) — verified against a real
    delivered order via SSH the same day. Founder's explicit call: no
    backfill for orders delivered before this shipped (`real_cost_price_sen`
    stays null for them permanently, `cost_basis` reads 'estimated'). Also
    shipped same day: a "Cost Price + Cost Basis" addendum unifying Order
    Detail and the `/admin/orders` CSV export into one reconciled cost
    figure (Real/Mixed/Estimated), so neither ever shows two competing
    cost numbers. See `docs/build-log.md`'s 2026-09-22 entries and
    `docs/adr.md`'s ADR-111 for the full build record.
20. ~~**`/track-order` intermittently shows "Too Many Attempts".**~~ —
    **🟢 FIXED, LIVE PROD 2026-09-24** (PR #273, released `main` via PR
    #278; founder-confirmed post-deploy). Root cause: the adaptive poll
    cadence (`nextPollDelay()`, ADR-071 PR3) polled every 2 seconds for the
    delivery's first minute — up to 30 requests/minute — against the
    route's own `throttle:20,1,track-order` limit (ADR-065), so any
    delivery taking longer than ~40s to resolve tripped the frontend's own
    legitimate polling into the backend's own rate limit. Fixed by slowing
    the first-minute cadence to 4s and recovering automatically from any
    residual 429 via the now cross-origin-exposed `Retry-After` header. See
    `docs/adr.md`'s ADR-071 addendum and `docs/build-log.md`'s 2026-09-23/24
    entries.
21. ~~**Pending Reactivation approval doesn't cascade to reactivate a
    dependent combo.**~~ — **🟢 FIXED, LIVE PROD 2026-09-24** (ADR-094's
    2026-09-24 addendum, PR #281, released `main` via PR #286).
    `ComboPricingService::cascadeReactivate()`
    reactivates a `combo_component_deactivated` combo the moment every
    one of its components is active again — wired into every site a
    component's `is_active` can flip false→true (Pending Reactivation
    approve/bulk-approve, dismiss-restore, pending price change
    approve/dismiss, the Games admin toggle, ADR-100's auto-approver).
    Founder chose auto-cascade over manual-but-visible. New
    `package_reactivation_logs.admin_user_id` records provenance. Full
    backend suite green (2236/2236).
22. ~~**`docs-site/` three high-severity audit entries.**~~ — **🟢 FIXED, LIVE
    2026-09-24** (PR #273, released `main` via PR #278). Traced to one
    build-time chain: `starlight-openapi` → `httpsnippet` → vulnerable
    `form-data`, not three independent runtime flaws. Pins patched
    `form-data@4.0.6` through a scoped npm override, retaining
    `starlight-openapi@0.26.2`. Remove the override later when upstream
    resolves the transitive pin without it (ADR-084 addendum).
23. ~~**Affiliate/Reseller portal mobile-first redesign — [ADR-112](./adr.md).**~~
    **🟢 LIVE PROD 2026-09-24** (PRs #275/#276, released `main` via PR #278).
    The role-aware shell, dashboard recent-order panels, responsive Orders
    cards, and PrimeReact-styled filter controls passed role-by-role browser
    verification at phone/intermediate/desktop widths (both locally,
    against a live backend, and founder-confirmed post-deploy). Pending
    top-up resume/cancel and a precise exception aggregate remain
    deliberately separate backlog items (§16 items 24/25), not hidden work
    in this redesign.
24. **Reseller wallet pending top-up recovery/cancellation UX — design not
    started.** The current one-active-top-up/30-minute rule and second-attempt
    `422` remain correct money-safety behavior, but the portal does not yet
    offer a tenant-scoped read/resume/cancel path. Requires a new ADR and
    gateway/webhook review before any backend or payment-state change; do not
    solve this with a client-only button.
25. **Cross-order portal “needs attention” aggregate — design not started.**
    Dashboard recent orders are intentionally bounded and cannot prove a
    global exception count. A reliable count needs a backend aggregate with
    explicit payment/delivery/compensation semantics and a versioned API
    contract; never sum the current paginated page in the browser. Requires a
    separate ADR before implementation.
26. **Unauthenticated non-JSON request to a `auth:affiliate`-guarded portal
    route crashes 500 instead of a clean 401 — found 2026-09-24, not started.**
    Reproduced against production via `curl` (no `Accept: application/json`
    header) on `/api/affiliate/orders` and `/api/reseller-portal/orders`:
    Sanctum's `Authenticate` middleware falls into `redirectTo()` → `route(
    'login')`, which throws `RouteNotFoundException` (this is an API-only
    backend with no named `login` route) instead of returning a plain
    `{"message":"Unauthenticated."}` 401. **Scope is narrow — confirmed by
    reading the actual middleware, not assumed:** only the two portal login
    surfaces sharing the `auth:affiliate` Sanctum guard
    (`/api/affiliate/*`, `/api/reseller-portal/*`) are affected. The
    Reseller **API** (`/api/reseller/v1/*`, partner-facing, bearer key from
    `reseller_api_keys` set in `/admin/resellers`) uses its own
    `EnsureResellerApiKey` middleware — a completely separate auth
    boundary that never touches Sanctum or `Accept` headers and always
    throws a clean `ResellerApiException` envelope (`MISSING_API_KEY`/
    `INVALID_API_KEY`) — **not affected.** The Reseller Bot (WhatsApp/
    OpenWA) doesn't go through this HTTP guard either. No customer impact:
    every real frontend (`admin/`, `storefront/`, `reseller/`) always sends
    `Accept: application/json` on its own API calls. Low-severity
    robustness fix, not urgent — likely a small `Authenticate::redirectTo()`
    override or `exceptions()` handler tweak so an unauthenticated
    non-JSON request to an API-only guard always gets a clean 401.
27. **`e2e`'s `playwright` CI job intermittently fails to boot `admin/`'s
    `next dev` webServer — a recurring CI-environment flake, not a code
    bug.** Hit twice in one day (2026-09-24) on two unrelated PRs (#278:
    security/portal-fixes release; #279: docs + `.claude/` config only, zero
    app code) — same signature both times: Turbopack's Google Fonts loader
    throws `Module not found: Can't resolve
    '@vercel/turbopack-next/internal/font/google/font'` / `next/font/google
    queries have exactly one entry` while compiling `layout.tsx`, so the
    `webServer` never comes up and Playwright times out after 60s. A full
    workflow rerun passed cleanly both times with no code change, and
    PR #279 touched no app code at all — rules out a real regression.
    `e2e/playwright.config.ts`'s admin `webServer` command is plain `npx
    next dev --port 3000`, which defaults to Turbopack on Next 16; likely
    fix is pinning it to `--webpack` (matching the known local-dev gotcha
    already in `AGENTS.md`'s Build & Test section, where the same Turbopack
    CSS-worker/font-loader class of failure was hit and worked around the
    same way). Not urgent — a rerun always clears it — but worth a proper
    fix before it happens a third time. Not started.

## Parked by founder decision (2026-09-09) — not scheduled

~~**CHIP credential `.env`→DB migration**~~ — **grilled + BUILT 2026-09-19,
[ADR-110](./adr.md) PR-C, fully settled 2026-09-24.** `payment_gateways` table
(`api_config` `encrypted:array`, mirrors `Supplier.api_config`),
`/middleware/payment-gateways` admin screen, `ChipGateway`'s container binding
DB-first with a `config('services.chip')` fallback. Founder has filled in the
real `secret_key`/`brand_id`/`base_url` via the screen and confirmed the
connection probe passes. **Deliberate founder call:** the `.env`
`CHIP_SECRET_KEY`/`CHIP_BRAND_ID` vars stay on Forge as a belt-and-braces
fallback rather than being removed — not an owed cleanup, don't re-flag it.
**Cloudflare R2 storage moved to item 17 above, 2026-09-14** — no
longer parked, design done, buildable now.
The `PaymentGatewayFactory` seam is kept for multi-region payment; a real 2nd
gateway is revisited only when cross-border selling is real (ADR-022 — Xendit
deleted).

~~Voucher double-submit guard (Path A)~~ — **removed 2026-09-13, already
shipped** (ADR-035, built 2026-08-26 — `vouchers.idempotency_key`,
confirmed live in `VoucherController::store()`). This list had drifted;
see the header note above.

~~Blacklist data-source / appeal policy~~ — **removed 2026-09-13, already
resolved, not a build task.** The blacklist feature itself shipped
2026-07-29 (ADR-007's addendum, `/admin/blacklist`). Data-source was already
settled by ADR-007's own Decision (internal, admin-curated from the
platform's own chargeback/fraud history — never an external feed). Appeal
already has a working mechanism (admin deactivates an entry via the existing
`is_active` toggle); what was actually missing was a one-line documented
policy, not a feature: *a wrongly-blacklisted customer contacts support,
admin reviews the `blacklist_hits` history and deactivates the entry.*

---
