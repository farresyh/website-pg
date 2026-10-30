# PRODUCT REQUIREMENTS DOCUMENT (PRD)

## Game Top-Up Reseller Platform

**STATUS: LIVING DOCUMENT** (originally DRAFT FINAL v0.3, hardened 2026-07-23; the current build state is §14–15)

| | |
| --- | --- |
| **Product Name** | Game Top-Up Reseller Platform |
| **Document Version** | Living document — originally v0.3 (Security & Financial-Integrity Hardening Pass, 2026-07-23) |
| **Prepared by** | Bubududu (System Analysis) — original v0.2 |
| **Reviewed & Hardened by** | Founder + Claude, foundation audit session, 2026-07-23 |
| **Related Documents** | [`adr.md`](./adr.md) — foundation decision log (the *why*); [`foundation-security.md`](./foundation-security.md) — security/financial-integrity checklist (the *guardrails*); [`legacy-reference-notes.md`](./legacy-reference-notes.md) — legacy system walkthrough (admin.keroxshop.com, manage.keroxshop.com), UI/UX reference only, never a source of project facts; supplier API research (Gamevion, MLBB-validator sample store) |

> Foundation decisions (payment gateway, ledger model, tenant strategy, refund policy, frontend stack, etc.) are recorded with full rationale in **[`adr.md`](./adr.md)**, not duplicated here. This document assumes those decisions and specifies the product built on top of them.

---

# 1. Product Overview

Game top-up reseller platforms currently exist as fragmented systems with separate admin panels (business operations) and supplier middleware (technical integration). These systems are tightly coupled to specific suppliers and lack modularity, scalability, and proper separation of concerns. Pain points include manual price syncing, lack of automated supplier failover, no clear audit trails, and difficulty onboarding new resellers.

The proposed system is a **greenfield multi-tenant-ready game top-up platform** built with modular architecture. It comprises four main layers: **(1) Supplier Middleware** — technical layer that handles all supplier API communication (product matching, price sync, player validation where available, order delivery, response normalization); **(2) Core API / Admin Backend** — business logic layer managing games, packages, pricing rules, resellers, orders, and payouts on a ledger-based financial model; **(3) Admin Panel** — web SPA for super admins to oversee operations; and **(4) Storefront** — customer-facing app, operated by the platform owner in MVP, with branded Affiliate storefronts on the same schema (live, ADR-056..061).

**MVP framing:** build and prove the platform as a single-owner operation first (all profit, all risk, one storefront) on top of a tenant-ready schema. Onboard real third-party resellers — with their own markup and ledger-settled withdrawal (manual bank transfer, no xenPlatform) — once the core money-handling logic (pricing, ledger, order lifecycle, fraud controls) has been validated in production with real transactions. That phase is live: Affiliate (whitelabel) and Reseller (prepaid wallet) channels both run in production (ADR-056..061, 072..076).

---

# 2. Goals & Objectives

- **Centralize game credit operations** — single platform handling supplier integration, order processing, and financial reporting.
- **Automate supplier price synchronization** — eliminate manual price updates with scheduled sync, automatic reactivation approval, and exchange rate management.
- **Provide transparent multi-tier pricing, computed server-side only** — cost price (from supplier), system markup, and affiliate markup ([ADR-056](./adr/ADR-056-reseller-wholesale-pricing-subscription-tiers.md)/[ADR-061](./adr/ADR-061-every-storefront-is-a-reseller.md)), recomputed at order time from stored config — never trusted from client input.
- **Reduce order failures** — real-time supplier balance monitoring, automated retry on failure, circuit-breaking on repeated supplier failure, and clear escalation workflows for stuck orders.
- **Protect customer money and supplier balance from manipulation** — every requirement in this document that touches price, balance, or delivery must be read with the assumption that a hostile actor will try to exploit it.
- **Deliver actionable business intelligence** — live dashboards, conversion funnel analysis, and drill-down reports.
- **(Live) Enable rapid partner onboarding** — branded storefronts with custom domains; the platform collects all retail into one company account and the ledger splits it (no xenPlatform — [ADR-059](./adr/ADR-059-reseller-portal.md)). Affiliate payout is a manual bank transfer via the existing WTH-1..5 flow.

---

# 3. Users & Roles

- **Super Admin :** Full system access — manages games, packages, suppliers, admin users, all orders, withdrawals, vouchers, blacklist, settings, and developer tools. Only role that can approve withdrawals above the maker-checker threshold, and the only role that can create a voucher above its own threshold (see WTH-5, VCH-6 — these are no longer the same mechanism, see VCH-6's own row for why).
- **Admin :** Operational access — manages orders, reports, reviews, withdrawals (below threshold), vouchers (below threshold), and customer analytics. Cannot modify system settings, supplier credentials, or blacklist rules.
- **Affiliate (live, renamed from "Reseller" by ADR-072):** Owns a branded storefront with custom domain. Sets own markup within limits, manages own orders, views own reports and analytics, and is paid out monthly: the platform opens one withdrawal ticket per affiliate on the 1st, settled against the ledger by manual bank transfer (WTH-1..6, ADR-132 — no xenPlatform).
- **Reseller (live, ADR-072):** Prepaid-wallet account (shown to resellers as "Purchase Balance", ADR-131), not a storefront owner. Places wholesale orders against a deposited balance through the portal, the REST API (ADR-074) or the WhatsApp bot (ADR-075); only ever spends, never earns.
- **Customer :** End user who browses games, purchases top-up credits, provides player ID, and leaves reviews after purchase.

---

# 4. Scope

## 4.1 In Scope (MVP)

- Supplier Middleware — product matching, price sync, player validation **where supplier/game supports it**, order creation with idempotency, response normalization (Adapter layer), request logging, reconciliation polling.
- Admin Panel — dashboard, games & packages management, supplier management, orders management, reports, withdrawals (ledger-based), vouchers, reviews, backups, settings, profile, developer tools, **blacklist management**.
- Fraud & Integrity Controls — internal blacklist, webhook/callback signature verification where supported, idempotent order creation, maker-checker on large withdrawals/vouchers, checkout rate limiting.
- Customer Analytics — segmentation, spending patterns, repeat rate analysis.
- Storefront — single platform-owner-operated game catalog, top-up checkout, order tracking, reviews. Every Affiliate brand is `Host`-resolved on the same catalog (ADR-060).

## 4.2 Out of Scope / Future Phases (Phase 2+)

- ~~Reseller self-service onboarding, branded storefronts, custom domains, reseller theme system.~~ **All shipped** — onboarding/storefronts/domains via ADR-056..061 + ADR-078 (§15's Affiliates row); the theme system shipped as a **curated fixed-preset picker** (ADR-081, 2026-09-09 — colour tokens only; the THM-1/3/4 custom-colour/permissions/bulk-assign system is dropped, not deferred).
- ~~Xendit xenPlatform sub-account fund-splitting~~ — dropped, not deferred (ADR-059). The ledger does the split; reseller payout is a manual bank transfer.
- ~~The API / H2H "pull supply" channel + prepaid deposit wallet + top-up flow~~ — **shipped as the `Reseller` (prepaid wallet) system** (ADR-072..076: wallet + REST API keys + WhatsApp bot).
- Automated customer support chatbot, mobile native apps, loyalty/rewards program, affiliate marketing module, cross-affiliate inventory sharing.
- Automated (non-admin-reviewed) refund decisions — a failed wallet/reseller order's `wallet_refund` credit is still always a deliberate, terminal admin action (never triggered by a webhook or a timer). ~~Automated reactivation decisions~~ — **shipped** as `PendingReactivationAutoApprover` (ADR-100, live prod since 2026-09-16, streak-only gate since its 2026-09-24 addendum) plus the two 2026-09-24 addenda that made a dependent combo's own reactivation cascade automatically too (ADR-094 for single-package reactivation call sites, ADR-046 for the supplier bulk toggle).

---

# 5. Assumptions & Constraints

- **Technology stack**: Laravel REST API for backend (proven pattern from legacy system). Frontends are a **unified Next.js (React) stack** (ADR-009, superseding the earlier Vue/Next split in ADR-008) for admin, storefront and the partner portal, plus an Astro public API docs site (ADR-084): **Admin Panel = Next.js in client-rendered/SPA mode** — calls the Laravel API directly from the browser with a Sanctum cookie session (ADR-121), no SSR needed behind auth. **Storefront = Next.js**, using SSR/React Server Components where it genuinely benefits SEO-relevant public pages (§6.16).
- **Database** assumed to be relational (MySQL/PostgreSQL). All data models in Chapter 8 reflect this assumption, including the ledger table.
- **Hosting:** the backend runs on a Laravel Forge-managed DigitalOcean droplet behind Cloudflare, and the four frontends on Vercel (ADR-066, ADR-114).
- **Multi-tenant architecture**: schema tenant-aware (`affiliate_id`, `reseller_id` before ADR-072) from day 1 per ADR-003; the ORM global scope it promised was actually built in [ADR-057](./adr/ADR-057-tenant-isolation-mechanism.md) (2026-08-30). Every storefront — ours and third-party — is an `Affiliate` row ([ADR-061](./adr/ADR-061-every-storefront-is-a-reseller.md), superseding ADR-013's platform-owner special-case).
- **Supplier APIs**: assumed RESTful/JSON, but **must not be assumed to have a uniform response shape, auth style, or validation capability.** Confirmed by direct research (Gamevion API, MLBB-validator sample store) that these vary per supplier and even per game. All supplier calls go through a normalizing Adapter layer (see 6.21) — no business logic touches raw supplier response shapes.
- **Payment gateway: CHIP only** (`PaymentGatewayFactory` kept for a future multi-region second gateway — [ADR-022's 2026-09-01 addendum](./adr/ADR-022-multi-gateway-payment-strategy.md) removed Xendit; FPX proven live with real money; which methods are switched on: §14). Single company CHIP account; the platform collects 100% of every payment (including reseller storefronts) and the ledger does the split. **xenPlatform dropped entirely** — not deferred ([ADR-059](./adr/ADR-059-reseller-portal.md) decision 6, superseding ADR-001's addendum).
- **Currency** assumed MYR as base, with dynamic exchange rate support for foreign-currency suppliers.
- **Auth** Role middleware on the backend — frontend routing is convenience, not security. Admins use a Sanctum cookie session with opt-in MFA (AUTH-7, ADR-121); affiliate/reseller-portal users use bearer tokens.
- **Refund policy**: no cash refunds (ADR-004), **being revised**: since 2026-10-22 the published Refund Policy (`/refund-policy`, founder's decision for CHIP's merchant review) offers an undelivered order a full refund to the original payment method within 7 working days, as an alternative to a voucher. The system path is not built yet (§16 item 129); until then a failed order resolves by retry-delivery or voucher.
- **Balance/financials**: ledger-based (ADR-002). No entity may expose or rely on a directly-mutated `balance` column as the source of truth.

---

# 6. Functional Requirements

## 6.1 System — Authentication & Authorization

| ID | Functional Requirement | Priority |
| --- | --- | --- |
| **AUTH-1** | System provides login page for Super Admin and Admin with email + password | **MVP** |
| **AUTH-2** | System supports Sanctum authentication with role-based middleware on the backend: a cookie session with CSRF for the admin (ADR-121), bearer tokens for the partner portal, API keys for the Reseller API | **MVP** |
| **AUTH-3** | System provides role-based route guards on the frontend | **MVP** |
| **AUTH-4** | Super Admin can manage admin users (create, edit, activate, deactivate) | **MVP** |
| **AUTH-5** | Super Admin can manage API keys for system integration | **Phase 2** |
| **AUTH-6** | System displays profile page with edit personal info and change password | **MVP** |
| **AUTH-7** | Admin MFA (TOTP), opt-in per account (set by an admin in Admin Users); without it, the dangerous actions are refused (CHIP credentials, supplier credentials, manual reseller wallet credit, withdrawal approval, admin account changes; plus revealing or replacing a customer's game login and the Customer Login Policy, ADR-124), and with it they ask for a fresh code | **MVP** (ADR-121) |

## 6.2 Admin — Dashboard

| ID | Functional Requirement | Priority |
| --- | --- | --- |
| **DASH-1** | One period filter for the page (Today, Last 7 days, Last 30 days, Custom; always opens on Today) drives KPI cards for Sales, Owner profit, Orders and Margin. Each is compared with the previous period up to the same time, with a summary sentence above them. Figures come from `/reports/*` (ADR-104 2026-10-15) | **MVP** |
| **DASH-2** | Status strip ("N need action" or "All systems OK"): each item links to its screen. System details shows supplier status, balances and drift, OpenWA sessions, scheduled tasks, stuck orders, pending payments, queue, and reseller wallets vs supplier balance | **MVP** |
| **DASH-3** | Sales trend (daily sales and owner profit; the last 14 days for a period under 7 days) and sales by channel (Own brand / Reseller wallet / External affiliate) | **MVP** |
| **DASH-4** | Top 5 games by paid sales, each with its change vs the previous period | **MVP** |
| **DASH-5** | Busy hours: paid orders per hour of day, summed over the period, with the peak window named. Highlights: best day (with an all-time-record mark), top reseller, biggest order | **MVP** |
| **DASH-6** | The status strip and System details refresh every 60 seconds; period figures refresh on a period change or Refresh | **Important** |

## 6.3 Admin — Games & Packages Management

| ID | Functional Requirement | Priority |
| --- | --- | --- |
| **GAME-1** | System displays game list in card layout: image, name, category badge, status badge, package count | **MVP** |
| **GAME-2** | System provides game filters: All/Active/Inactive/Not Found at Supplier, plus search by name or supplier ID | **MVP** |
| **GAME-3** | Admin can edit game: name, slug, category, image, supplier mappings, validation rules, SEO fields | **MVP** |
| **GAME-4** | Admin can activate/deactivate game | **MVP** |
| **GAME-5** | Admin can delete game with confirmation | **MVP** |
| **GAME-6** | Admin can reorder games via drag-and-drop | **Important** |
| **GAME-7** | Admin can manage packages per game: add, edit, delete — each package has name, `markup_percent` (admin-set input; `standard_selling_price` is computed and stored from it, never typed directly — revised 2026-07-25, matches the legacy reference system's own Markup %/Base Price pattern; renamed from `reseller_cost_price` 2026-08-29, [ADR-027](./adr/ADR-027-vip-membership.md) — it was never actually reseller-specific), `cost_price` (supplier wholesale, sourced from supplier sync for API suppliers; agreed cost edited and audited by admin for manual packages per ADR-125), supplier reference | **MVP** |
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
| **RES-1** | System displays affiliate table: business name, contact, markup %, active tier + subscription status, earnings balance, orders count, domains, status, actions | ADR-058 58b |
| **RES-2** | Admin can add affiliate with business info, markup % + max markup %, domains, an initial wholesale tier, the first portal user (set-password invite), and an **"Our own brand" flag** (`is_owned`, ADR-061) — when set, `markup_pct` defaults 0 and a per-brand consumer-Membership toggle is shown. **No xenPlatform sub-account** — dropped entirely (ADR-059 decision 6); all money lands in the one company CHIP account | ADR-058 58b; `is_owned` + Membership toggle ADR-061 |
| **RES-3** | Admin can edit affiliate details + change tier (writes an `affiliate_tier_changes` audit row); can toggle `is_owned` / per-brand `membership_enabled` (ADR-061) | ADR-058 58b; flags ADR-061 |
| **RES-4** | Admin can impersonate affiliate — mints a short-lived `affiliate`-guard Sanctum token scoped with an `impersonate` ability, audited in `affiliate_impersonation_sessions` (real admin identity, portal identity, token id, IP, start/end). The persistent "impersonating" banner is the portal's job (ADR-059 59c, `reseller/src/components/ImpersonationBanner.tsx`). 58b owns the mint/end endpoints, the audit table, and auto-ends sessions on affiliate deactivate/delete | backend + admin UI ADR-058 58b; portal entry + banner ADR-059 59c |
| **RES-5** | Admin can activate/deactivate affiliate (deactivate ends any live impersonation; ADR-060 storefront 503 + Cloudflare suspend is ADR-060) | ADR-058 58b |
| **RES-6** | Admin can delete affiliate — **soft-delete only**, blocked unless earnings balance = 0 and no pending/approved withdrawal; the **`is_primary`** row cannot be deleted (ADR-061 — was "platform-owner row") | ADR-058 58b; `is_primary` guard ADR-061 |

**Since ADR-061** every storefront is an `Affiliate`, told apart by `is_owned` / `is_primary`, and consumer Membership is per-brand. The admin route and frontend directory are still `/admin/resellers`, and the portal app keeps its `reseller/` name on purpose (ADR-072 decision 3). The branded `Host`-resolved storefront, per-brand pricing and ledger split, custom domains and the portal Storefront screen are ADR-060. Build and live status: §15's Affiliates row.

## 6.8 Admin — Orders Management

| ID | Functional Requirement | Priority |
| --- | --- | --- |
| **ORD-1** | System displays order table: order #, customer, game, package, price, fee, profit, status, delivery status, date | **MVP** |
| **ORD-2** | System provides status filters: Need Action, Processing, Completed, Today, All | **MVP** |
| **ORD-3** | System provides advanced filters: Needs Resolution, Pending Delivery, Failed Delivery, date range, game | **MVP** |
| **ORD-4** | System provides pagination with configurable per-page (10/25/50/100) | **MVP** |
| **ORD-5** | Admin can export orders to CSV and Excel | **MVP** |
| **ORD-6** | Admin can view order details: customer info, game/package, payment info, delivery attempts, supplier responses, status history timeline | **MVP** |
| **ORD-7** | Admin can resolve an order via retry-delivery or voucher issuance only — **no cash refund action exists in the system** (ADR-004; being revised: refund to the original payment method, §16 item 129) | **MVP** |
| **ORD-8** | Every order generates a unique `reference_number` **before** the first supplier API call, and this same reference is reused on any retry of that same order — never regenerated | **MVP** |
| **ORD-9** | Every monetary field (`cost_price`, `standard_selling_price`, `selling_price`, `transaction_fee`, `final_amount`, `platform_profit`, `affiliate_profit`) is always computed server-side at order-creation time from stored Package/Affiliate/Settings config, then snapshotted onto the Order — the system never accepts a client-submitted value for any of these fields. `transaction_fee` is computed on `selling_price − voucher_discount` (fee is charged on the post-voucher amount, not the original selling price). Renamed from `reseller_cost_price` 2026-08-29 ([ADR-027](./adr/ADR-027-vip-membership.md)) — the field is the platform owner's own guest/standard price, never reseller-specific; a real affiliate's own wholesale price comes from `affiliate_membership_tiers` instead (`PricingService::calculateForAffiliate()`, [ADR-056](./adr/ADR-056-reseller-wholesale-pricing-subscription-tiers.md); live in checkout via [ADR-060](./adr/ADR-060-multi-tenant-branded-storefront-custom-domain.md)) | **MVP** |
| **ORD-10** | A scheduled reconciliation job polls supplier `check-status`-equivalent endpoints for any order left in an ambiguous/pending state beyond a threshold — the system never relies solely on a supplier webhook/callback for final order state | **MVP** |
| **ORD-11** | `delivery_status` may only transition out of `not_started` (i.e. a supplier order-creation call may only be attempted) when `payment_status == paid`. This guard applies to every delivery attempt, including retries — never just the first one. Enforced in code (not just process/UI), since this is the single most direct path to giving away free game credits without confirmed payment | **MVP** |

## 6.9 Admin — Reports

| ID | Functional Requirement | Priority |
| --- | --- | --- |
| **RPT-1** | System displays live stats: total sales, orders count, owner profit, margin %, latest order with time ago | **MVP** |
| **RPT-2** | System displays a sales and profit trend chart that follows the page's date-range filter (ADR-088). ADR-104's 2026-10-08 addendum adds Daily/Weekly/Monthly buckets and makes "All time" span real history | **MVP** |
| **RPT-3** | System provides one date-range filter (presets + custom, ADR-088), an affiliate filter, and export (CSV/PDF) | **Important** |
| **RPT-4** | Reports redesign (ADR-104 2026-10-08 addendum): Paid sales with a Bridge to Accounting, Failed & compensated + outstanding store credit, Compare period, sales by channel, 7 tabs | **Important** |

## 6.10 Admin — Withdrawal Management

| ID | Functional Requirement | Priority |
| --- | --- | --- |
| **WTH-1** | System displays stats cards: Pending/Approved/Completed/Rejected with count + total amount | **MVP** |
| **WTH-2** | System displays withdrawal table: date, amount, bank details, status, actions | **MVP** |
| **WTH-3** | Admin can approve, reject, or mark as completed withdrawal requests | **MVP** |
| **WTH-4** | Admin can view withdrawal details (full history), sourced from the ledger, not a cached balance field | **MVP** |
| **WTH-5** | Withdrawals above a configurable threshold require **Super Admin** approval (maker-checker) — an Admin who created/initiated a withdrawal request cannot also be its sole approver | **MVP** |
| **WTH-6** | The platform opens one withdrawal ticket per Affiliate per Malaysia month (ADR-132): balance at the 1st 00:00 MYT cut-off minus the tier fee still to come, minimum RM10, bank details required; the admin transfers within the 1st to 7th. The Affiliate can no longer request one | **MVP** |

Tickets are opened by the platform each month, not requested by Affiliates (ADR-132, was ADR-059 59c). The platform owner no longer requests a withdrawal here: since [ADR-083](./adr/ADR-083-internal-accounting-financial-reconciliation.md)'s 2026-10-10 addendum (decision 17), money leaving to a director is an Envelope Ledger repayment or dividend.

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
| **ANL-2** | System provides customer segmentation: VIP (≥ threshold), Frequent (20+ orders), Dormant (last order > 60 days ago), New (first order < 30 days ago), One-time, first match wins (ADR-049 decision 4) | **MVP** |
| **ANL-3** | System displays customer table: email, name, segment, orders count, total spent, last order date | **MVP** |
| **ANL-4** | Admin can filter by date range (the Reports presets), Affiliate, Reseller and segment, search by email, sort, page through the list, and export CSV (ADR-049 2026-10-15 addendum) | **MVP** |
| **ANL-5** | Admin can drill into one customer's full detail: stats, profit-reconciliation breakdown, monthly spend trend, top packages/affiliates, and full order history (added post-launch, ADR-050) | **MVP** |

## 6.13 Admin — Review Management

| ID | Functional Requirement | Priority |
| --- | --- | --- |
| **REV-1** | System displays stats: pending/approved/rejected count, total reviews, average rating | **MVP** |
| **REV-2** | System displays review table: rating, comment, customer, game, package, status, date | **MVP** |
| **REV-3** | Admin can approve or reject individual review | **MVP** |
| **REV-4** | Admin can bulk approve all pending reviews | **MVP** |
| **REV-5** | System provides filters: status, rating, game, "with comment only" | **MVP** |

## 6.14 Admin — Backups

| ID | Functional Requirement | Priority |
| --- | --- | --- |
| **BAK-1** | System displays backup stats: total count, total size, and the latest run of each source | **MVP** |
| **BAK-2** | System displays backup table: source, filename, type (automatic/manual), size, created date, created by; filterable by source | **MVP** |
| **BAK-3** | Admin can create manual backup | **MVP** |
| **BAK-4** | Admin can download or delete backup files | **MVP** |
| **BAK-5** | System supports automatic backup schedule (Daily/Weekly/Monthly) with retention period | **MVP** |
| **BAK-6** | System backs up the OpenWA gateway's data (SQLite, WhatsApp session keys, its `.env`) daily, keeps 14 days, restore-tests every run, and an admin can run it before an OpenWA upgrade (ADR-039 2026-10-22 addendum) | **MVP** |

## 6.15 Admin — Store Themes

**Resolved 2026-09-09 by [ADR-081](./adr/ADR-081-affiliate-storefront-theme-presets.md).** Decision: a **curated fixed set of 5 theme presets** (`affiliate_branding.theme_preset`), an affiliate picks one in the portal "Theme" tab (live preview), the storefront injects the preset's Material-3 **colour** tokens only — structure/typography unchanged. Adding a preset is a code change, not an admin operation. The heavier THM-1..4 vision below is **dropped, not deferred** — re-argue only against real sized demand. (One of the 5, `default`/Digital Architect, was later retired from the affiliate-pickable set — see ADR-113; 4 are affiliate-selectable today.)

**Extended 2026-09-11 (ADR-090) and 2026-09-24 (ADR-113).** Presets carry background and ink tokens as well as accents, each affiliate has a fixed Site Mode (light or dark, not a viewer toggle), and dark palettes ship for all 4 affiliate-selectable presets (Cyber Bumblebee, Red Giants Edition, Cyber Emerald, Hyper Cobalt). Digital Architect (`default`) is PekanGame's own identity and is no longer offered to affiliates. Design-system reference: `storefront/DESIGN.md`. Detail: [ADR-090](./adr/ADR-090-theme-preset-background-ink-tokens-a-per.md), [ADR-113](./adr/ADR-113-affiliate-theme-preset-dark-mode-for-all-4.md).

| ID | Original Phase-2 requirement | Decided in |
| --- | --- | --- |
| **THM-1** | Theme list (cards/grid) with preview, status badge, usage count | **Reduced** (ADR-081) — a fixed preset picker with live preview; no admin CRUD, no usage count |
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
| **SET-3** | Admin can configure affiliate settings: default markup, max markup, auto-approve toggle | **Phase 2** |
| **SET-4** | Admin can configure backup schedule and retention period | **MVP** |
| **SET-5** | Admin can configure store contact links (WhatsApp/Telegram) | **MVP** |
| **SET-6** | Admin can toggle maintenance mode with custom message | **MVP** |
| **SET-7** | Admin can toggle payment gateway with custom disabled message | **MVP** |
| **SET-8** | ~~Admin can configure notification settings (email toggles)~~ | **❌ Dropped, 2026-10-12** (YAGNI, ADR-116 decision 10). The customer already gets CHIP's own payment email on purchase, so no email toggle is needed. The one switch that exists is `whatsapp_notifications_enabled` (ADR-116) |
| **SET-9** | ~~Admin can configure Telegram bot integration~~ | **Dropped 2026-10-10** (ADR-028 addendum) — no sender was ever built; ops alerts go by Plunk email |
| **SET-10** | Admin can configure footer content and social media links, and the four legal pages (Terms, Privacy, About Us, Refund Policy) | **MVP** |
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
| **MID-8** | Player validation runs through a supplier-independent validator profile (`PlayerValidatorProfile`: RedxGame, UniPlay) when the game has one enabled (`games.player_validator_enabled`, ADR-005 + its 2026-10-16 addenda) — otherwise validation occurs implicitly at order time and the fallback flow (7.1) applies | **MVP** |
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

**MUI-4 was grilled and dropped, 2026-08-28 — see [ADR-052](./adr/ADR-052-middleware-panel-closure-batch.md).** No concrete export use-case was ever established, and `/admin/games` + `/middleware/product-manager` already cover the real catalog+edit workflow this row describes. **MUI-1 and MUI-7 were grilled the same session and kept at reduced scope** (MUI-1 as a thin `/middleware` landing page reusing three existing data sources; MUI-7 merged onto `/middleware/validators` rather than a standalone screen) — see the same ADR and §15's Middleware Panel row.

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

## 6.24 Login Top-up & Manual Suppliers (per [ADR-124](./adr/ADR-124-customer-account-credentials.md), [ADR-125](./adr/ADR-125-login-top-up-manual-supplier.md))

For games where the supplier tops up through the customer's own login, not a UID (Roblox and eFootball first). Sold on PekanGame and Affiliate storefronts, never through a Reseller channel. An external supplier with no API does the top-up and is paid in RM outside the system.

| ID | Functional Requirement | Priority |
| --- | --- | --- |
| **LOGIN-1** | A game is a **login game** or a **UID game**, never both. It is a login game when it has a credential schema (`validation_rules.credential_fields`: key, label, type `text`/`email`/`password`/`select`, required, secret, identifier). Exactly one non-secret field is the identifier: it fills `player_id`, validated by its own type. A `password` field is always secret. A login game has no `reseller_code` (no Reseller channel), no player validator, no instant delivery, and must have a delivery subtext. The kind cannot switch while packages of the other kind exist. Login games and their schema are created and edited in `/admin/games` | **Important** |
| **LOGIN-2** | Every game has a member-pricing switch (`member_pricing_enabled`). Off means no member price and no member quota at checkout, on the public price and in the savings badge. The order still binds to the member's email | **Important** |
| **LOGIN-3** | Checkout for a login game renders the schema's fields on every brand, and requires a consent checkbox. The consent text comes from the Customer Login Policy and is snapshotted on the order. The server sets `player_id` from the identifier and refuses a client-sent `player_id`/`server_id`, so the blacklist (FRAUD-2) checks the real identifier. The order and its credentials are written in one transaction, before any gateway call | **Critical** |
| **LOGIN-4** | Credentials live in their own table (`order_credentials`), never on `orders`. `Order` has no relation to it. Secret fields are one encrypted JSON (`APP_KEY`, rotation via `previous_keys`). The table is left out of the DB dump. Secrets never enter the idempotency hash, a log line, an exception context or a notification | **Critical** |
| **LOGIN-5** | Only a `super_admin`, behind MFA step-up (ADR-121), can reveal a secret field (shown 30 s, `no-store`) or replace one on an open order. Reveals are rate-limited per admin (default 20 an hour). Every reveal, replace and identifier change writes a `credential_reveals` row (actor, order, field, action, IP), kept for one year | **Critical** |
| **LOGIN-6** | A sweep every 15 minutes wipes secret fields by one rule set: closed (Delivered or compensated) for **G** hours (default 24); unpaid **H** hours after creation (default 24); and a hard ceiling stored on each login (`secrets_expire_at`): the game's own "keep login at most" days if set (Edit Game, a login game only; joki 30), else **D** days (default 14), counted from when the secrets were last set, whatever the status. A Failed order inside its ceiling keeps its login for a retry. Changing D or a game's days moves the ceiling of every unwiped login it governs. G, H, D, the consent text and the reveal limit are set in Admin → Settings → Customer Login Policy (`super_admin`, step-up) | **Critical** |
| **LOGIN-7** | Every public surface masks the identifier: Track Order, the realtime status push, the WhatsApp status card and the Affiliate portal. Customer and admin copy uses the schema's identifier label, not "Player ID", and promises no minutes. The Delivered banner and message say the login will be wiped within G hours and ask the customer to change their password | **Important** |
| **MSUP-1** | A **manual supplier** is a supplier with `driver = manual`, a free unique slug, MYR currency and a funding mode: `prepaid` (we hold a balance; low-balance applies) or `pay_after` (we owe after delivery; shows "Owed: RM X"). It is created in Middleware → Suppliers and starts inactive. It cannot be activated without a linked WhatsApp group. Switching it off takes its packages off sale, and switching it on restores exactly those. The order is: create, link group, activate, add packages | **Important** |
| **MSUP-2** | An admin creates manual packages for a login game with an **agreed supplier cost** (`> 0`) and a markup. Standard, Member and Affiliate pricing is unchanged. A cost edit is written to the package's price history with the admin's name. A combo cannot use a manual supplier | **Important** |
| **MSUP-3** | The **Manual Queue** (`/admin/manual-queue`, any admin) lists paid orders waiting on a manual supplier. Delivered asks for the real cost, which defaults to the agreed cost; a different value needs a reason. Delivered, Failed and the supplier's own replies all go through one confirmation path (`finalizePendingDelivery`), which records real cost, profit, voucher commit and the supplier drawdown. A second confirmation is refused. Every confirmation names the supplier it acts for (the admin sends the one on screen, the bot the group's), and the confirmation is refused, writing nothing, if the locked order is on another supplier. A wrong Failed on a manual order may be corrected to Delivered, with a reason, if no compensation was issued. A Failed order goes to Need Action as in §7.2 | **Critical** |
| **MSUP-4** | Once an order is Pending on a manual supplier, the bot posts it to that supplier's WhatsApp group: order number, game, package, the non-secret login fields, and a short link `https://<api host>/s/<token>`. GET on the link shows only the summary. The secret fields come only from a POST, which is rate-limited, audited (actor = supplier) and works only while the order is Pending on that supplier with that reference (for a joki order, the same assignment) and the login is not wiped; it expires with the login's ceiling. The link is minted at send time and never stored in the message. One post per attempt and supplier (and per joki assignment): a retry posts again, labelled RETRY, and an order moved to another supplier posts to that supplier's group while the old queued post is dropped | **Critical** |
| **MSUP-5** | Supplier commands in its group: `.done PG-…`, `.gagal PG-… <reason>` (a reason is required) `.pending` (order, game, package, age; never a login field) and, for a joki order, `.progress PG-… <rank>` (JOKI-6). They act only on that supplier's own Pending orders. The WhatsApp sender is recorded as `confirmed_by`. The bot's own messages are never parsed. Rate-limited per supplier | **Important** |
| **MSUP-6** | Each manual supplier has a reply SLA (`api_config.manual.reply_sla_minutes`, default 30, 5–4320). A manual Pending order is never polled or auto-escalated. Its SLA runs from `orders.manual_activity_at` (stamped when the post reaches the group, and by a joki assignment or progress update), not from any touch of the order. Past its SLA, the 15-minute reconciler posts one reminder per attempt and supplier into the group. The Dashboard counts orders not posted to their supplier, active manual suppliers with no group, and Delivered manual orders with no drawdown | **Important** |
| **MSUP-7** | Admin → Partners → Suppliers (`super_admin`) manages each manual supplier's group (link from the shared pending-group list, unlink), reply SLA and active switch. A group has one owner, and a supplier has one active group. Deposits to and payments of a manual supplier are `SupplierTransfer` rows with receipts, and each Delivered records a drawdown, so the balance shown is the funding-ledger balance | **Important** |

## 6.25 Joki MLBB (per [ADR-126](./adr/ADR-126-joki-mlbb.md))

Rank boosting: the customer picks a current and target MLBB rank, pays, and hands over their login; one of the platform's penjoki plays the account to the target. Owned brands only, sold on the `/joki` page, never in a catalog or through a Reseller channel.

| ID | Functional Requirement | Priority |
| --- | --- | --- |
| **JOKI-1** | Joki is sold only where both the global Joki switch (`joki_settings.is_enabled`) and the brand's own `joki_enabled` are on, on an owned brand (`is_owned`; the admin forces it off otherwise). The joki game (`games.is_joki`) is in no catalog on any brand and in no Affiliate portal list; its packages are kept by joki, never by the Games & Packages screens or Bulk Markup | **Critical** |
| **JOKI-2** | The price is computed on the server from the panel's rank model and rates (whole RM): a star is paid at the rate of the tier it is won in, a division is worth its own stars, Mythic Grading is one flat price that guarantees Mythic 15★, Mythic and up is priced on the Mythic star count, Express uses the Express rates, and a climb of fewer than the minimum stars is refused unless it includes Grading. A route package sells its own climb at its own price (Reguler only); a quotation route is never sold. The client sends ranks and Express only | **Critical** |
| **JOKI-3** | A joki order is an ordinary order on the `Fixed` pricing basis: selling price = the joki price, cost = the penjoki's payout = price × the payout share in force at checkout (default 100%), no tier markup, no member price, no affiliate margin. It goes through the one storefront checkout (login, consent, blacklist, idempotency, voucher, payment), and its `joki_orders` row (ranks, Express, hero/role/play window, a snapshot of the quote, model, rates, share and terms) is written in the order's transaction | **Critical** |
| **JOKI-4** | No path changes a joki order's payout: resend keeps the cost and refuses another package, a retry after Failed returns the order to the pool at the same cost, Manual Delivered refuses another cost, Bulk Markup skips joki. There is no extra or partial pay: a failed or reassigned order pays the penjoki nothing (ADR-126 2026-10-24 addendum) | **Critical** |
| **JOKI-5** | A paid joki order waits on the assignment pool. An admin assigns it (Pending only, under the order and penjoki locks, to an active penjoki with a group), writing a `joki_assignments` row; the order and a fresh login link go to that penjoki's group. Reassigning kills the old link and the old penjoki's `.done`. A penjoki with orders in progress can't be switched off | **Critical** |
| **JOKI-6** | Progress: the penjoki sends `.progress PG-… Epic III 2` (Mythic and up: `Mythic 30`), or an admin updates it from Order Detail. Each update is checked against the rank model, kept in `joki_progress_updates`, sets the order's current rank and restarts its stale clock | **Important** |
| **JOKI-7** | The customer sees the climb and the current rank on Track Order (live) and in the WhatsApp status card. An opted-in customer gets a WhatsApp progress message: the first at once, then at most one per panel interval (default 2 h), always with the latest rank, never after the order finishes or the customer stops updates | **Important** |
| **JOKI-8** | Admin → Joki: the joki orders (waiting, in progress, stale, done, failed) with Assign and progress, each penjoki's open orders and amount owed with Record payment, and the settings (switch, rank model and icons, rates, Grading, minimum stars, route packages, share, roles, Express wording, terms, progress interval, banner). A Dashboard card counts waiting, in progress and stale joki orders; joki is left out of the Manual Queue and Need Action | **Important** |
| **JOKI-9** | The `/joki` page: rank icons with tier and star pickers, Reguler or Express, the route packages, a live price from the server, then the login form with consent, hero, role and play window, the terms, and payment. Reached from a "Joki Rank" menu item and a home banner | **Important** |
| **JOKI-10** | A penjoki is paid through the existing pay-after flow: `.done` records the drawdown (the amount owed), and the founder records each bank payment as a `SupplierTransfer` with a receipt. Revenue for a joki order paid in one month and delivered in the next follows §16 item 131, which must ship before launch | **Critical** |

---

# 7. Key User Flows

## 7.1 Top-Up Order (Happy Path — revised for supplier reality)

1. Customer visits storefront and selects a game.
2. Customer selects a package (price always resolved server-side from stored config — never from client input).
3. Customer enters Player ID (and Server ID if required).
4. **If** the game has a player-validator profile enabled (MID-8): system validates the player ID synchronously and blocks checkout on failure before payment is attempted.
   **Else:** system proceeds to payment, and validation happens implicitly when the order is submitted to the supplier (step 8) — this is a known, accepted limitation for suppliers without a validation endpoint, mitigated by the no-cash-refund/voucher policy (ADR-004/ADR-005).
5. System checks customer/player against the internal blacklist (FRAUD-2). Blocked entries are rejected before payment.
6. Customer completes payment via CHIP. System generates a unique `reference_number` for the order **before** calling any supplier API (ORD-8).
7. System creates order: `payment_status = pending`, `delivery_status = not_started`.
8. The CHIP webhook (`success_callback`) confirms payment (verified per PAY-1) → `payment_status = paid`. **Only now** may delivery proceed (ORD-11) — `delivery_status` moves to `processing`.
9. System submits order to the appropriate supplier via the Adapter layer, using the same `reference_number` on any retry.
10. Supplier delivers credits and returns success response (normalized via Adapter) → `delivery_status = delivered`. A reconciliation job independently confirms this via the supplier's status-check endpoint if no callback is received within a threshold (ORD-10).
11. Customer receives a WhatsApp receipt (the order status card plus a review link), **if their phone number has opted in**. Opting in means messaging the customer-support number with any order number, from either WhatsApp button on the order page or typed by hand, and that message is answered with the order's status card. `STOP` opts out and `START` opts back in. No email. See [ADR-116](./adr/ADR-116-customer-order-notifications-over-whatsapp.md) and its 2026-09-30 addendum.

## 7.2 Order Failure & Resolution (no cash refund, per ADR-004)

1. System attempts delivery via Supplier Middleware (only ever reachable when `payment_status = paid`, per ORD-11).
2. Supplier returns failure response (insufficient balance / invalid player / service down) — or player ID validation fails at order time for a supplier without pre-validation (7.1 fallback).
3. System marks `delivery_status = failed`. `payment_status` remains `paid` (needs resolution) — the customer's money was genuinely received; only delivery didn't complete.
4. Admin sees it in the Orders Need Action KPI and pill.
5. Admin reviews order details: supplier response, player ID validity, balance status.
6. Admin resolves it by **(a) retry or resend delivery** (same or different supplier), or **(b) compensation**: a store-credit voucher, restoring the checkout voucher, or a refund to a reseller's own wallet. No cash-refund action exists in the system yet: the published Refund Policy promises a refund to the original payment method on request, and that path is §16 item 129.
7. Customer notified of the outcome.

## 7.3 Supplier Price Sync

1. Admin or scheduler triggers Price Sync.
2. Supplier Middleware queries all supplier APIs (via Adapter layer) for latest product catalog and prices.
3. Middleware compares with local data: updates prices, creates new packages, deactivates removed ones.
4. Packages that were previously inactive but now available are flagged as "Pending Reactivation".
5. System records sync details: games processed, packages created/updated/deactivated.
6. Admin reviews Pending Reactivation table and approves/dismisses packages.
7. Approved packages are reactivated in the catalog with updated prices.

**Addenda ([ADR-100](./adr/ADR-100-pending-reactivation-auto-approve.md), [ADR-094](./adr/ADR-094-combo-package.md), [ADR-046](./adr/ADR-046-supplier-management-screen.md)):** step 6 can run automatically (`PENDING_REACTIVATION_AUTO_APPROVE`, off by default; prod setting in §15). A package confirmed active again inside its own Digiflazz cutoff window, or after enough consecutive syncs (streak length alone is the gate since ADR-100's 2026-09-24 addendum), skips the admin queue and reactivates itself; every other case still lands in the manual queue. Step 7 also cascades onto a dependent **combo**: once all of its components are active again, by any reactivation path (this queue, the automatic path, or Supplier Management's bulk toggle, SUPP-1), the combo reactivates automatically.

## 7.4 Withdrawal (ledger-based, per ADR-002)

1. An Affiliate accrues profit from delivered orders — each order's profit is written as a **credit entry** in the ledger, not added to a mutable balance field. (The platform's own `('platform', null)` account also accrues, but is no longer withdrawn from — ADR-083 2026-10-10 addendum, decision 17.)
2. On the 1st (cut-off 00:00 MYT) the platform's scheduled command opens a ticket for each Affiliate with at least RM10 after the tier-fee reserve, using the bank details saved in Profile (amount from SUM of ledger entries, not a cached balance; ADR-132).
3. Ticket created with status "Pending" — this itself does **not** yet write a ledger entry (a pending request is not a debit until approved).
4. Admin sees new pending withdrawal on Withdrawal Management page.
5. Admin reviews ledger history, confirms sufficiency.
6. Admin approves — **if amount is above the configured threshold, a second Super Admin approval is required (WTH-5, maker-checker)**.
7. On approval, a **debit entry** is written to the ledger atomically with the status change.
8. Admin processes bank transfer externally, then marks withdrawal as "Completed" with processing timestamp.
9. Affiliate notified of completed withdrawal.

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
4. Customer receives the voucher code on WhatsApp, at the order's phone number (Path B), or the phone the admin entered (Path A, optional). It is sent proactively, with no opt-in needed. It is never shown on the track-order page ([ADR-116](./adr/ADR-116-customer-order-notifications-over-whatsapp.md)). If the send fails or is skipped, the admin sees why on the order or voucher page and sends the code by hand.
5. Customer applies voucher on next purchase — remaining amount is decremented via an **atomic, row-locked** operation (VCH-5) so concurrent redemption attempts cannot double-spend the same voucher.
6. Voucher becomes "Exhausted" when remaining reaches zero, or "Expired" past expiry date.

## 7.6 Login Top-up Order (per [ADR-124](./adr/ADR-124-customer-account-credentials.md), [ADR-125](./adr/ADR-125-login-top-up-manual-supplier.md))

1. Customer selects a login game and a package. The storefront shows the game's login fields instead of Player ID, and a "Manual Processing" badge.
2. Customer enters the login, ticks the consent box and reviews. The review shows the masked identifier.
3. The server sets `player_id` from the identifier, checks the blacklist (FRAUD-2), and creates the order with its credentials in one transaction. Payment then follows §7.1 steps 6–8.
4. Once paid, fulfilment sets the order Pending on the manual supplier, and the bot posts it to the supplier's WhatsApp group with the one-time login link (MSUP-4).
5. The supplier opens the link, taps "Show login", tops up the account, then replies `.done PG-…` (or `.gagal PG-… <reason>`). An admin can confirm the same order from the Manual Queue instead.
6. **Delivered:** real cost defaults to the agreed cost, profit and the supplier drawdown are recorded, and the customer sees Delivered with the change-your-password line. The sweep wipes the login G hours later (LOGIN-6).
7. **Failed:** the order lands in Need Action (§7.2). Retry mints a new reference and posts again labelled RETRY, and the old link dies. If the login was wiped (past its ceiling, LOGIN-6), Retry is refused until a `super_admin` replaces it.
8. **No reply:** after the supplier's SLA, the bot posts one reminder to the group. The order stays Pending; nothing is auto-escalated.

---

# 8. Data Model (High-Level)

| Entity | Key Fields | Description |
| --- | --- | --- |
| **Game** | `id`, `name`, `slug`, `category`, `is_active`, `sort_order`, `image_url`, `banner_url`, `supplier_mappings` (JSON array of `{supplier_id, product_ref, supports_validation, supports_server_list}`), `validation_rules` (JSON; holds `credential_fields` for a login game, LOGIN-1), `member_pricing_enabled` (LOGIN-2), `credential_max_days` (nullable — a login game's own wipe ceiling, LOGIN-6), `is_joki` (JOKI-1), SEO fields | A game title available for top-up. `supports_validation` remains in the stored JSON shape (harmless, unread) but is dead — GAME-12 (which introduced it) is dropped, 2026-09-13; see §16. Real player-ID validation runs through `Game.player_validator_enabled`/`player_validator_profile_id` (`PlayerValidatorRegistry`, ADR-093) instead — an unrelated, separate mechanism. |
| **Package** | `id`, `game_id`, `name`, `cost_price` (API suppliers: from the supplier sync, never admin-editable; manual packages: agreed cost, admin-edited and audited, ADR-125), `markup_percent` (admin-set per package, decimal — added 2026-07-25), `standard_selling_price` (= `cost_price` × (1 + `markup_percent` / 100), rounded to the nearest sen — **stored**, recomputed only when `markup_percent` changes via `PackageMarkupService`, never recomputed live at order time; renamed from `reseller_cost_price` 2026-08-29, [ADR-027](./adr/ADR-027-vip-membership.md)), `is_active`, `supplier_id`, `supplier_package_ref`, `sort_order` | A specific top-up denomination within a game. `standard_selling_price` is the platform owner's own guest/standard price — it is **not** the customer-facing price for every buyer type; a real affiliate's own wholesale rate is a separate mechanism (`affiliate_membership_tiers`, see the Affiliate row below and [ADR-056](./adr/ADR-056-reseller-wholesale-pricing-subscription-tiers.md) — live, checkout wiring [ADR-060](./adr/ADR-060-multi-tenant-branded-storefront-custom-domain.md)). Today it happens to equal the customer-facing price (the primary brand runs at `markup_pct = 0`), but the schema/formula never assumes that. |
| **Supplier** | `id`, `name`, `slug`, `driver` (the adapter: an API driver, where `slug = driver`, or `manual`), `funding_mode` (`prepaid`/`pay_after`), `joki_role` (null, `pool` or `penjoki`, JOKI-5), `logo_url`, `is_active`, `api_config` (JSON — credentials, endpoints, or for a manual supplier `manual.reply_sla_minutes`; **encrypted at rest**), `balance`, `currency` | A game credit supplier integrated via Middleware, accessed only through its Adapter (6.21). A manual supplier (MSUP-1) is panel data, not code; capabilities (`scopeApiBacked`, `pollsStatus`, `displayBalance`) are asked of the row, never inferred from the slug. |
| **Affiliate** | `id`, `business_name`, `contact_name`, `email`, `phone`, `markup_pct`, `max_markup_pct`, `domains` (JSON array, nullable), `status`, `is_owned` (bool — our own brand, [ADR-061](./adr/ADR-061-every-storefront-is-a-reseller.md)), `is_primary` (nullable bool, DB-enforced-single — the console/job/non-`Host` fallback tenant, never deletable), `membership_enabled` (bool — per-brand consumer-Membership toggle, effective only when the global `PlatformSettings.membership_enabled` master is also on), `joki_enabled` (bool — per-brand Joki toggle, owned brands only, effective only with the global Joki switch, JOKI-1), `deleted_at` (soft-delete, ADR-058 58b), `notes` — **no `balance` column**; balance is derived from `LedgerEntry` — **renamed from `Reseller`/`resellers` 2026-09-04, [ADR-072](./adr/ADR-072-reseller-system-split.md) PR-A, zero behaviour change** (vacates the `Reseller` name for a future prepaid-wallet entity, ADR-073..075) | A branded storefront owner. **Every storefront is a row here** — ours and third-party alike, with no special code path ([ADR-061](./adr/ADR-061-every-storefront-is-a-reseller.md), superseding ADR-013's `business_name = 'Platform Owner'` magic string). `Affiliate::primary()` resolves the one `is_primary` row for any context with no `Host` to resolve a brand from. `markup_pct`/`max_markup_pct` = the affiliate's own margin on top of what they pay the platform (an `is_owned` brand defaults 0 — all margin books as `platform_profit`). **What an affiliate pays the platform** depends on which `affiliate_membership_tiers` row they're subscribed to (or `standard_selling_price` if none active) — [ADR-056](./adr/ADR-056-reseller-wholesale-pricing-subscription-tiers.md), live, checkout wiring ADR-060. |
| **Reseller** *([ADR-072/073](./adr/ADR-072-reseller-system-split.md))* | `id`, `business_name`, `contact_name`, `email`, `phone`, `reseller_tier_id` (nullable FK → `reseller_tiers`, restrict-on-delete, direct swap — no subscription state machine), `is_active` (bool, default true — account-level order-placement kill switch, doesn't freeze the balance), `notes`, `balance_cap_sen` (nullable, ADR-131: this reseller's Purchase Balance cap, null = the platform default `platform_settings.balance_cap_default_sen`), `deleted_at` (soft-delete; set only by the Close account action, ADR-131) — **no `balance` column**; wallet balance is derived from `LedgerEntry`, owner type `reseller_wallet` | The prepaid-wallet wholesale-buyer entity the Reseller API (ADR-074) and Reseller Bot (ADR-075) channels share — reclaims the `Reseller` name vacated by ADR-072's `Affiliate` rename. Structurally the opposite of `Affiliate`: only ever **spends** against a deposited balance, never earns. `reseller_tiers.markup_percent` (over `cost_price`, no monthly fee) prices every wallet order. Orders are placed through `ResellerOrderPlacementService` by the API (`reseller_api_keys`) and the Bot; portal login via polymorphic `affiliate_users`. |
| **LedgerEntry** | `id`, `owner_type` (platform/affiliate/reseller_wallet — `'affiliate'` since ADR-072 PR-A, was `'reseller'`), `owner_id`, `type` (order_profit/withdrawal/voucher_issued/adjustment/wallet_topup/wallet_debit/wallet_refund/membership_fee/affiliate_tier_fee), `amount` (signed sen: credit positive, debit negative), `reference_type`, `reference_id`, `created_at`, `created_by`, `reason` (required for `adjustment`) | **Immutable, append-only.** Sole source of truth for any balance. Never updated or deleted, only inserted (ADR-002). **Every delivered order writes exactly two `order_profit` credit entries** — one for `platform_profit` (owner_type=platform) and one for `affiliate_profit` (owner_type=affiliate, the order's `affiliate_id`) — kept as two rows even when both resolve to the same owner (an `is_owned` brand), so the ledger logic is identical for every affiliate. |
| **LedgerAccount** | `id`, `owner_type`, `owner_id` (unique together) | Pure lock/mutex anchor, **no balance column** — created upfront alongside the owner so `withdraw()` always has a row to `lockForUpdate()`, even for a brand-new owner with zero prior entries. See ADR-002 addendum. |
| **AdminUser** | `id`, `name`, `email`, `password`, `role` (super_admin/admin), `phone`, `is_active`, `mfa_secret`, `mfa_enabled` | System administrator account. |
| **Order** | `id`, `order_number` (customer-facing, e.g. `PG-` + 12 characters, platform-generated), `reference_number` (our idempotency key, generated before first supplier call — ORD-8, transformed per-supplier format by the Adapter per ADAPT-4), `customer_email`, `customer_name` (added 2026-07-25 for Xendit, ADR-001; kept), `customer_phone`, `player_id`, `server_id`, `game_id`, `package_id`, `supplier_id`, `affiliate_id` (**NOT NULL** since ADR-061 PR-B; FK `restrictOnDelete` — an order keeps its brand for the life of the record; the brand comes from ADR-060's `Host` resolution; renamed from `reseller_id` 2026-09-04, [ADR-072](./adr/ADR-072-reseller-system-split.md) PR-A), `cost_price` (snapshot from Package), `standard_selling_price` (snapshot from Package — renamed from `reseller_cost_price` 2026-08-29, [ADR-027](./adr/ADR-027-vip-membership.md)), `affiliate_markup_pct` (snapshot from Affiliate at order time — renamed from `reseller_markup_pct` 2026-09-04, ADR-072 PR-A), `selling_price` (= `standard_selling_price` + affiliate markup — customer-facing price), `voucher_discount` (nullable), `transaction_fee` (computed on `selling_price − voucher_discount`, per SET-11 rate for the chosen `payment_method`), `final_amount` (= `selling_price − voucher_discount + transaction_fee` — what CHIP charges), `real_cost_price_sen` (nullable, the supplier's real charge at delivery, ADR-111; a combo keeps it per leg), `platform_profit` (estimated at checkout per `pricing_basis`; on delivery recomputed as `selling_price − real cost − affiliate_profit`, ADR-111, combo ADR-107, partial ADR-094; read the cost behind it with `Order::effectiveCostPriceSen()`), `affiliate_profit` (= `selling_price − standard_selling_price` — renamed from `reseller_profit` 2026-09-04, ADR-072 PR-A) — all server-computed, ORD-9, `payment_status` (pending/paid/failed — reflects the payment gateway only), `paid_at` (nullable timestamp, set the instant `payment_status` becomes paid — added 2026-08-26, the sole source of truth for "when this order was actually paid," see `ReportService`/`DashboardService`), `delivery_status` (not_started/processing/delivered/failed/needs_review/pending/partially_delivered — reflects the Supplier API only; `needs_review` ADR-026, `pending` ADR-032, `partially_delivered` ADR-094 combo), `wallet_reseller_id` (nullable, ADR-073), `pricing_basis` (standard/member/affiliate/reseller-wallet/fixed — `fixed` is a joki order, JOKI-3), `manual_activity_at` (nullable — a manual order's stale clock, MSUP-6), `placed_via` (storefront/reseller_api/reseller_bot/sandbox, ADR-104 R16), `delivered_at` (nullable timestamp, set the instant `delivery_status` becomes delivered — added 2026-08-27, ADR-045, backfilled for pre-existing rows via `updated_at` as a best-available proxy), `payment_method`, `payment_ref`, `supplier_ref` (supplier's own order number, opaque string, format varies per supplier — only known after their response), `supplier_response` (JSON, PII-redacted), `timestamps` | A customer's top-up purchase transaction. Three distinct identifiers exist per order: `order_number` (ours, customer-facing), `reference_number` (ours, idempotency key sent to supplier), and `supplier_ref` (theirs, returned after success) — never conflate them. Cost/price fields are snapshotted at order time (not live-joined from Package/Affiliate) so historical orders stay accurate even if prices change later. **`payment_status` and `delivery_status` are two independent state machines — delivery may only move out of `not_started` when `payment_status == paid` (ORD-11); never conflate a single combined "status" field again.** |
| **Withdrawal** | `id`, `owner_type`, `owner_id`, `amount`, `bank_name`, `bank_account_no`, `bank_account_holder`, `status`, `admin_note`, `requested_by` (null on a ticket the platform opens), `payout_period` (`YYYY-MM`, unique per owner; null on manual-era rows, ADR-132), `approved_by` (must differ from `requested_by` above threshold — WTH-5), `processed_at` | Profit withdrawal ticket, settled against the ledger. |
| **Voucher** | `id`, `order_id` (nullable — set only when issued via Path B, §7.5), `code`, `customer_email`, `amount`, `remaining`, `status` (active/exhausted/expired/revoked/merged), `expires_at`, `reason`, `created_by`, `approved_by` (set only when created at/above the VCH-6 threshold — records which Super Admin's role satisfied the gate, not a second approver) | A store-credit refund issued to a customer — the only refund mechanism in the system (ADR-004). |
| **Review** | `id`, `order_id`, `customer_email`, `game_id`, `package_id`, `rating` (1-5), `comment`, `status`, `created_at` | Customer feedback on a completed order. |
| **BlacklistEntry** | `id`, `type` (player_id/email/phone), `value`, `reason`, `created_by`, `is_active`, timestamps | Internal fraud-prevention list, checked at checkout (FRAUD-1/2). Two tables, not one — corrects this row's original pre-build design (no `game_id`; a blacklist entry blocks everywhere, not per-game; `is_active` replaces hard-delete, see ADR-007's 2026-07-29 addendum for why). |
| **BlacklistHit** | `id`, `blacklist_entry_id`, `player_id`, `customer_email`, `customer_phone`, `ip`, timestamps | One row per blocked checkout attempt (FRAUD-3's audit trail) — not in the original §8 design, added during the 2026-07-29 build since a blocked attempt never creates an `Order` and so has no other record. |
| **BackupRun** | `id`, `source` (`database` / `openwa`), `status` (queued/running/success/failed), `triggered_by` (`system` or the admin's name), `disk`, `path`, `size_bytes`, `restore_test_passed`, `restore_test_details`, `error_message`, `started_at`, `finished_at`, timestamps | One backup attempt (`backup_runs`, ADR-039): the dump, where it landed, and its restore-test result. |
| **PlayerValidatorProfile** / **PlayerRegionMapping** | Profile: `key`, `name`, `public_name`, `public_slug`, `public_image_url`, `last_test_result`, `last_tested_at`. Mapping: `game_id`, `player_validator_profile_id`, `country_code` (ISO alpha-2, the only part read), `country_name` (label) | A validator profile checks a player ID through one provider; a game's region mappings route the reported country to that game's variant (ADR-005 2026-10-16 addenda). Results are cached in `PlayerValidation`. |
| **OrderCredential** | `order_id` (unique), `fields` (non-secret, plain), `secret_fields`, `secrets` (encrypted JSON), `secrets_set_at`, `secrets_expire_at` (the wipe ceiling, LOGIN-6), `closed_at`, `purged_at`, `consented_at`, `consent_text` | A login order's credentials (LOGIN-4). No relation from `Order`; excluded from the DB dump; secrets wiped by the sweep (LOGIN-6), consent kept. |
| **CredentialReveal** | `order_id`, `actor_type` (admin/supplier), `actor_id`, `action` (reveal/replace/identifier), `field_key`, `ip`, `created_at` | Audit of every look at or change to a login (LOGIN-5, MSUP-4). Pruned after one year. |
| **WhatsAppGroup** | `whatsapp_group_id` (unique), `owner_type` (reseller/supplier), `owner_id`, `is_active` | One registry of bot groups; replaced `reseller_whatsapp_groups`. Routes inbound commands to the reseller or supplier bot (MSUP-5, MSUP-7). |
| **SupplierLoginLink** | `token_hash` (SHA-256), `order_id`, `supplier_id`, `reference_number`, `joki_assignment_id` (nullable, JOKI-5), `expires_at` | The supplier's one-time login link (MSUP-4). Only the hash is stored, so a dump holds no working link; expired rows pruned daily. |
| **CustomerNotification** | `event`, `dedupe_key`, `order_id`, `voucher_id`, `session` (`customer_support`/`bot`), `phone` or `chat_id`, `message`, `status`, `attempts`, `error`, `sent_at` | The persisted WhatsApp outbox: customer receipts on the customer-support session (ADR-116) and supplier-group posts and reminders on the bot session (MSUP-4, MSUP-6). A joki progress message (JOKI-7) is queued with an earliest send time and worded when it is sent. |
| **JokiSetting** | one row: `is_enabled`, `payout_share_pct`, `min_stars`, `grading_price_rm`, `grading_express_price_rm`, `grading_lands_at_stars`, `max_mythic_stars`, `roles`, `express_description`, `terms_html`, `progress_message_interval_minutes` | Joki's panel settings (JOKI-1, 2, 3, 7). Whole RM |
| **JokiTier** | `name`, `sort_order`, `kind` (`divisional`/`mythic`), `divisions`, `stars_per_division`, `mythic_from_stars`, `rate_rm`, `express_rate_rm`, `icon_url` | The rank model, bottom first (JOKI-2) |
| **JokiRoute** | `package_id` (unique), from/to tier, division and stars, `price_rm` (nullable), `is_quotation` | A route package's climb and promo price, or a quotation (JOKI-2) |
| **JokiOrder** | `order_id` (unique), `from_rank`, `to_rank`, `current_rank`, `current_rank_at`, `is_express`, `hero_request`, `role`, `play_window`, `quote` (snapshot), `payout_share_pct`, `terms_snapshot` | What a joki order bought and its progress (JOKI-3, JOKI-6) |
| **JokiAssignment** | `order_id`, `from_supplier_id`, `to_supplier_id`, `assigned_by`, `created_at` | One row per Assign (JOKI-5). Never edited |
| **JokiProgressUpdate** | `order_id`, `rank`, `actor`, `channel` (`admin`/`supplier_bot`), `created_at` | One row per progress update (JOKI-6). Never edited |

---

# 9. Non-Functional Requirements

> **Security & financial-integrity rules are maintained as a standalone checklist in [`foundation-security.md`](./foundation-security.md) — read it before implementing anything that touches auth, money, pricing, supplier calls, or customer PII.** The bullets below are the remaining, non-security NFRs.

- **Responsiveness :** Admin Panel and Storefront must be responsive — mobile, tablet, desktop. Storefront is customer-facing, mobile-first.
- **Scalability :** Platform must support growth to 30+ affiliates (Phase 2) with thousands of daily orders without significant degradation. Database queries must be optimized (indexes, eager loading). Tenant-scoping is enforced at the ORM layer, not per-query by convention.
- **Performance :** Dashboard page load < 2s. End-to-end order processing < 5s (from payment to supplier delivery). Caching for game list, package prices.
- **Resilience :**
  - Supplier API failures must not block the system. Failed deliveries enter a retry queue.
  - A circuit breaker trips per-supplier after repeated failures, preventing cascading timeouts from a down supplier blocking the whole delivery queue.
  - Dashboard must remain accessible even when one supplier is down.
  - The system never relies solely on a supplier webhook/callback for final state — a scheduled reconciliation job independently confirms order and payment status (ORD-10, PAY-3).
- **Error Handling :** All supplier API errors must be logged with full request/response payload, credentials and excess PII redacted. Admins are emailed (Plunk, every active admin) for a failed backup, a long Horizon queue wait and a stale FX rate. Stuck orders, supplier balance and circuit-breaker state are monitored on the Dashboard (System Health), not pushed. A low-supplier-balance email is designed with item 28 (ADR-115 2026-10-13 addendum), not built; it ships two weeks after launch. UptimeRobot polls `/api/health`, the OpenWA gateway's own health URL and `/api/health/whatsapp` (§16 item 11).
- **Data Privacy :** Player IDs and customer personal data (including validation-response data such as in-game nickname/country) must be handled securely, retained only as long as operationally needed, and never over-logged. No logging of raw credentials. Admin action audit trails, including impersonation.
- **Backup & Recovery :** Automatic daily backup, 7 daily / 4 weekly / 6 monthly retention (ADR-039, ADR-095); the OpenWA gateway's data daily, 14 days. Every archive is encrypted. Manual backup via admin panel. Backups must be downloadable.
- **Multi-language :** Not built: the storefront and admin are English only. More languages, together with more currencies, are a later expansion outside Malaysia (§16 item 117).
- **Financial Integrity :** Every entity representing money (order profit, withdrawal, voucher) is reconstructable from the `LedgerEntry` table alone. No balance is ever the sole record of truth (ADR-002).

---

# 10. Third-Party Integrations

| Service | Function | Notes |
| --- | --- | --- |
| **CHIP** | **Sole payment gateway** — Malaysia-local (FPX, FPX Business `fpx_b2b1`, DuitNow QR; which are switched on: §14). Also membership subscription + reseller wallet top-up checkout | 🟢 **Integrated with live keys since 2026-09-03** (RM1.00 flat FPX fee) — real money proven end-to-end (order `PG-PYAYMRYNUYV0`). Per-purchase `success_callback` (not a portal webhook), RSA signature verification with key-rotation re-fetch. Still resolved via `PaymentGatewayFactory` for a future multi-region 2nd gateway. `app:chip-smoke-test`. ADR-022 (+ 2026-09-01 CHIP-only addendum), ADR-068. |
| **~~Xendit~~** | ~~International payment processing~~ | **Removed entirely 2026-09-01** (ADR-022 addendum). Code archived to branch `archive/xendit-gateway` + tag `xendit-archive-2026-09-01`. International acceptance is gone, not dormant — its own grilled ADR if cross-border selling becomes real. xenPlatform was never adopted (ADR-059 decision 6 — the ledger splits partner money, not the gateway). |
| **Gamevion** | Supplier — game credit catalog, price feed, order creation/fulfillment | **Integrated but deliberately unfunded (2026-09-24, Digiflazz-only).** First real `SupplierAdapter`. Static Bearer + API-key, `referenceNumber` + `409 Duplicate` idempotency, per-purchase `callback_url`. Sandbox via `GAMEVION_SANDBOX=true`. ADR-006/040. |
| **Digiflazz** | Second supplier — Indonesian H2H aggregator (486 game SKUs + data SKUs) | **The only live supplier.** Prod key wired 2026-09-02, **funded 2026-09-15**, delivering real orders. IDR→MYR FX conversion at the sync boundary (ADR-033). Inbound `POST /api/webhooks/digiflazz` (HMAC-SHA1 `X-Hub-Signature` + IP allowlist). Topup has no rate limit; the full price list is limited to 1 call per 5 min (`rc 83`), per Digiflazz CS on 2026-09-29. Same-`ref_id` re-submit replays the stored outcome, which ADR-102's 2026-09-29 addendum relies on. ADR-030/067/069. |
| **Supplier API(s) — general** | The Adapter contract all suppliers implement | Confirmed to vary significantly per supplier (auth, response envelopes, validation availability per-game, idempotency). All access goes through the Adapter layer (§6.21), resolved per-order by `supplier_id` (`SupplierAdapterFactory`, ADR-031). Onboarding a new supplier = a new adapter, enforced from MVP (ADR-006). |
| **OpenWA** | WhatsApp gateway, two sessions. `reseller-bot` runs the Reseller Bot channel (`.order` / `.baki` / `.listharga` / `.topupbaki`) and, since ADR-125, supplier-group posts and commands (`.done` / `.gagal` / `.pending`). `customer-support` (+60 11-4301 3150) sends customer voucher codes and opted-in Delivered receipts, and receives opt-in/`STOP` (ADR-116) | Self-hosted on the prod box, v0.25.0 since 2026-10-22 (daemon runs `node dist/main`, no rebuild on restart; `data/` + `.env` backed up nightly to R2, encrypted, ADR-039 2026-10-22 addendum; `MESSAGE_RETENTION_DAYS=90` since 2026-10-25), Baileys engine, `AUTO_START_SESSIONS=true` since 2026-10-14 (sessions come back by themselves after a restart), `BAILEYS_MARK_ONLINE_ON_CONNECT=false` since 2026-10-17 (the paired phones keep their notifications). `SEND_PACING` is on (warm-up and cold caps) and `SIMULATE_TYPING` is on. Inbound `OpenWaWebhookController` (HMAC `X-OpenWA-Signature`) routes by `sessionId`. ADR-075/076/116. |
| **Plunk** | Transactional email — membership OTP, receipts and renewal reminders, partner-portal invites, domain-status mails, and ops alerts (backup failure, Horizon long-wait, stale FX rate). Customer order notifications go by WhatsApp instead (ADR-116) | API-based (`PLUNK_API_KEY`). Replaced the generic "SMTP or API" placeholder. ADR-027/068. |
| **Laravel Reverb** | Realtime broadcasting — order-status push, replaces application-level polling on the customer buy-flow | Self-hosted WebSocket server + Echo client. ADR-047, ADR-071 PR4. |
| **Vercel** | Custom-domain provisioning for Affiliate whitelabel storefronts | `AffiliateDomainProvider` seam (Vercel / no-op). `affiliate_domains` table, self-serve onboarding, provider opacity in the portal. ADR-060 (reverses decision 3's Cloudflare-for-SaaS), ADR-078. |
| **Google Analytics (GA4) / Facebook Pixel / TikTok Pixel** | Website analytics + ad conversion tracking | IDs from Settings (per-brand for Affiliates). |

---

# 11. Proposed Features / Future Phases

The scheduled roadmap lives in §16, group E ("New features: after the backlog"), each with its own ADR and grill: self-serve reseller signup (13), Marketing Campaigns (57), more languages and currencies (117), and others. Joki MLBB (114) is built and released, switched off, and waits on its launch gates (group D). Shipped features are tracked in §15, not here.

Unscheduled ideas, not on the backlog:

- **Automated Customer Support Chatbot.** Order tracking, player ID lookup and basic troubleshooting. Partly covered today by the WhatsApp status card (ADR-116) and the Reseller bot (ADR-075).
- **Mobile Native Apps.** React Native or Flutter apps for the customer storefront experience.
- **Loyalty & Rewards Program.** Points system for customers based on order frequency/value.
- **Affiliate Marketing Module.** Third-party affiliate referral with commission tracking.
- **Cross-Affiliate Inventory Sharing.** Route orders to best-available supplier when one affiliate's preferred supplier is low on balance.
- **Automated Refund Decisions.** Move from manual admin approval (MVP default, per Open Question resolution below) to rules-based automation once enough fraud/failure pattern data exists. (Reactivation is already automated, ADR-100.)

---

# 12. Open Questions / TBD

**Resolved during foundation audit (2026-07-23):** see [`adr.md`](./adr.md) for full rationale on payment gateway (ADR-001), refund policy (ADR-004), reseller tiering/scope (ADR-003), and frontend stack (ADR-009, superseding ADR-008).

**Resolved during Withdrawal/Voucher implementation (2026-07-24):** maker-checker thresholds — Withdrawal (WTH-5) is RM 2,000, `WITHDRAWAL_MAKER_CHECKER_THRESHOLD_SEN`; Voucher (VCH-6) is RM 500, `VOUCHER_MAKER_CHECKER_THRESHOLD_SEN`, and turned out to be a **different maker-checker mechanism**, not the same one at a different amount — see VCH-6's row in §6.11 and `foundation-security.md` §1 for why. ~~xenPlatform sub-account type is OWNED, not MANAGED~~ — moot: xenPlatform dropped entirely ([ADR-059](./adr/ADR-059-reseller-portal.md)).

**Resolved during the reseller batch grilling (2026-08-30, ADR-056..061):** wholesale pricing model (paid tiers, cost-anchored — ADR-056); tenant isolation mechanism (`BelongsToReseller` + global scope — ADR-057); partner auth (separate `affiliate` guard — ADR-058, guard renamed from `reseller` in ADR-072); partner domains use **custom domains only, no subdomains** (ADR-060 — TLS/provisioning is **Vercel-native custom domains**, not Cloudflare for SaaS; decision 3 was reversed 2026-09-06); money model — platform collects all retail centrally, ledger splits, no xenPlatform (ADR-059); the platform owner stops being a special case — every storefront is an `Affiliate` row (ADR-061, entity renamed from `Reseller` in ADR-072).

**Resolved during Database Backups build (2026-08-26):** backup storage target — `BACKUP_DISK`-configurable, defaults to local disk; the local-disk single-point-of-failure risk is explicitly tracked as a known gap, not an oversight (ADR-039). Closed since: prod runs `BACKUP_DISK=r2_backups` (ADR-095), and finance receipts moved to `r2_accounting` on 2026-10-08 (§16 item 56).

**Still open:**

- Blacklist data source: internal chargeback history only, or also manually curated from known fraud reports? Needs a policy for false-positive appeal.
- Multi-region payment routing (Malaysia → CHIP, other countries → a 2nd gateway, auto by country) — founder want. **Superseded for now** by the CHIP-only cutover (ADR-022, 2026-09-01): the `PaymentGatewayFactory` seam is kept, but a real 2nd gateway is only revisited when cross-border selling is real. Xendit (the original candidate) is deleted.

---

# 13. Glossary

- **Supplier Middleware :** System layer that handles API communication with game credit suppliers via the Adapter layer. Provides product matching, price sync, player validation (where available), and order delivery.
- **Adapter / Normalizer :** A per-supplier module implementing a common internal interface, translating that supplier's specific auth/response format into one canonical shape so business logic never depends on raw supplier response structure (6.21).
- **Ledger Entry :** An immutable, append-only record of a single credit or debit against the platform's, an affiliate's or a reseller wallet's balance. Balance is always a derived SUM, never a directly-mutated field (ADR-002).
- **Idempotency Key / Reference Number :** A unique identifier generated before the first attempt at a money-moving or order-creating API call, reused on every retry of that same logical operation so a retried request cannot create a duplicate effect.
- **Envelope / Envelope Ledger :** What company money is set aside for (Capital Rolling, Marketing Budget, …), never where it sits. Supplier top-ups, CHIP payouts and moves between the company's own accounts never touch an envelope ([ADR-083](./adr/ADR-083-internal-accounting-financial-reconciliation.md) 2026-10-10 addendum).
- **Posting :** One Envelope Ledger action: a header (type, date, receipt, director) over signed lines into or out of envelopes. Types: funding (loan or share capital), transfer, expense, director-paid expense, repayment, distribution, and profit allocation (month close only). Corrected only by a void (a reversal posting).
- **Director loan balance :** What the company owes one director: loans + director-paid expenses − repayments, computed from postings, never stored.
- **Operating profit :** The month's profit as `MonthlyAccountingSummaryService::operatingProfit()` defines it (ADR-083 2026-10-10 addendum, decision 11). Excludes compensation vouchers (a liability) and envelope expenses (already out of their envelopes).
- **Month close :** Once per ended KL month, in order from September 2026: allocates exactly the operating profit (plus any prior-month adjustment) to envelopes, snapshots the cash accounts, and checks the cash equation. Only the latest close can be reopened.
- **Prior-month adjustment :** How much earlier closed months' live operating profit has moved since they were closed; carried into the next close so a closed month is never rewritten.
- **Cash equation / gap :** At a close, what the company holds (cash accounts, supplier prepaid, CHIP not yet settled) minus what it owes or has set aside (envelopes, wallets, affiliate earnings and unpaid withdrawals, vouchers outstanding, undelivered orders). A gap above RM1 needs a written note.
- **Maker-Checker :** A dual-control pattern where the person who initiates a sensitive action (large withdrawal, WTH-5; a large voucher is a role gate only, VCH-6) cannot also be the sole approver of that same action.
- **Cost Price :** Price from supplier (wholesale). Base for all markup calculations.
- **System Markup :** Profit percentage added on top of cost price (system's margin).
- **Affiliate Markup :** Profit percentage an affiliate adds on top of their wholesale base (`affiliates.markup_pct`, capped by `max_markup_pct`). Real since [ADR-061](./adr/ADR-061-every-storefront-is-a-reseller.md); an `is_owned` brand defaults 0 (all margin books as `platform_profit`). Applied at checkout — ADR-060. Renamed from "Reseller Markup" 2026-09-04, [ADR-072](./adr/ADR-072-reseller-system-split.md) PR-A.
- **Pending Reactivation :** Packages that were previously inactive but are now available again from supplier. Admin-reviewed by default; can optionally auto-approve on a confirmed-active streak (ADR-100) — see §7.3's addenda.
- **Player ID :** Unique identifier for a player within a game (username, user ID, or character ID).
- **Server ID :** Server identifier within a game (for games with multiple servers).
- **Region Validator :** Mapping of country codes to game variants (e.g. MY → Malaysia variant, ID → Indonesia variant).
- **Game ID Checker :** The public storefront page (`/check-id`, [ADR-123](./adr/ADR-123-public-game-id-checker.md)) where anyone checks a player ID and is told the nickname and **our store** for that account. Separate from checkout's validation: its own endpoint, limits and table (`public_player_checks`), and it never approves a checkout.
- **Game family (checker) :** One validator profile shown on the Game ID Checker (Mobile Legends, Free Fire, …), with its own public name, URL slug and image. A family spans the region stores that profile maps to (e.g. Mobile Legends → Mobile Legends Malaysia / Indonesia / …).
- **Dry Run :** Testing mode for API calls without creating real orders or deducting balance.
- **Stuck Order :** Order that is "paid" but not yet "delivered" beyond a threshold (e.g. 5 minutes).
- **Multi-Tenant :** Architecture where a single system instance serves multiple affiliates (tenants) with data isolation. Schema-ready from MVP (ADR-003); used by Affiliate storefronts (ADR-060/061).
- **xenPlatform :** Xendit's sub-account payment-splitting product. Never adopted ([ADR-059](./adr/ADR-059-reseller-portal.md) decision 6): the platform collects all money in one CHIP account and the ledger does the split. Founder, 2026-10-12: its fee is too high and no other platform that splits payments was found, so it is not planned.
- **Blacklist :** Internal, platform-owned list of player IDs/contacts blocked from ordering due to prior fraud/chargeback history, independent of any supplier-side blacklist (ADR-007).
- **Method Key :** A stable identifier for a real-world payment method (e.g. `tng`, `fpx_maybank2u`, `grabpay`) independent of which `PaymentGateway` processes it — distinct from `channel_code` (gateway-specific, unique per `payment_methods` row) and `category` (coarser grouping like `fpx`/`ewallet` that legitimately holds several different active methods at once). Exists so at most one gateway's row for the same customer-facing method can be active at a time (ADR-022's newest addendum).
- **Voucher Merge :** An admin-triggered, opt-in action that consolidates two or more of one customer's active vouchers into a single new code, sized to the sources' combined `remaining` (not their original `amount`). The sources are voided (`status = 'merged'`, `remaining = 0`) — never deleted — and no `ledger_entries` row is written, since a merge creates no new cost: an admin-issued source's cost was booked at its own issuance, and a compensation source is customer cash that never debits the ledger (ADR-024 2026-10-12 addendum). An expired source cannot be merged (ADR-036 2026-10-12 addendum). Distinct from *redemption* (spending a voucher at checkout) and from Path B's *restore* (giving a reserved amount back to `remaining`) — a merge never happens automatically and is never part of the checkout flow itself (ADR-036).
- **Voucher Expiry :** A voucher is expired when `expires_at` is set and past (`Voucher::isExpired()`). Nothing sets `status` to `expired`; the stored status stays `active`. Checkout and merge refuse it, and admin screens show it as expired through `display_status` (§16 item 84). The Monthly Summary's breakage reads `expires_at` too.
- **Membership Renewal Reminder :** An email to a member 3 days before (`before`) and 1 day after (`after`) their paid-through date, counted in Malaysia days, one of each per paid-through period (`membership_reminders`). Not marketing; no unsubscribe. WhatsApp reminders are designed, not built (ADR-068 2026-10-13 addendum, §16 item 85).
- **Customer (Customer Analytics) :** Not a stored entity — a derived grouping of `Order` rows by `customer_email`, computed at query time. This does not reopen ADR-011 (guest checkout, no `Customer` model/login); it's the same field Reports already surfaces as `latest_order.customer_email`, now used as a grouping key (ADR-049).
- **Standard Selling Price :** The platform owner's own guest/non-member retail price — `cost_price × (1 + package.markup_percent)`. Renamed from `reseller_cost_price` 2026-08-29 once its usage was recognized as never affiliate-specific — see Affiliate Wholesale Tier below and [ADR-027](./adr/ADR-027-vip-membership.md).
- **Affiliate Wholesale Tier :** A paid, admin-CRUD-managed row in `affiliate_membership_tiers` that an affiliate subscribes to monthly to unlock a lower `cost_price`-based wholesale rate for their own purchases from the platform. Fully independent of consumer VIP Membership (ADR-027) — no membership feature exists on any *third-party* affiliate storefront, and an affiliate's tier is never derived from anything their own downstream customers do. An unpaid or lapsed affiliate (past a ~3-day grace period) falls back to Standard Selling Price, the same price a guest pays — not a shutdown, just the walk-in rate, self-correcting on payment. Backend built ([ADR-056](./adr/ADR-056-reseller-wholesale-pricing-subscription-tiers.md), 2026-08-30): `affiliate_membership_tiers` + `affiliate_subscriptions`, `PricingService::calculateForAffiliate()`, `AffiliateTierFeeService` + grace/lapse state machine, `ChargeAffiliateTierFeesCommand`. Admin CRUD shipped ADR-058 58b. Live in checkout via ADR-060. Renamed from "Reseller Wholesale Tier" (and `calculateForReseller()`) 2026-09-04, [ADR-072](./adr/ADR-072-reseller-system-split.md) PR-A.
- **`is_owned` affiliate :** A storefront the founder owns (our brand), flagged `affiliates.is_owned` ([ADR-061](./adr/ADR-061-every-storefront-is-a-reseller.md); entity renamed from `Reseller`/`resellers` 2026-09-04, [ADR-072](./adr/ADR-072-reseller-system-split.md) PR-A). Its contribution is internal money, not a payable; its `markup_pct` defaults 0 (all margin books as `platform_profit`) but is editable; it may run consumer Membership. Contrast a third-party affiliate (`is_owned = false`) — feature-limited, **no consumer Membership** (the founder's chosen differentiator). Replaces the `business_name = 'Platform Owner'` magic string.
- **`is_primary` affiliate :** The single `is_owned` row (`affiliates.is_primary`, DB-enforced as exactly one via a nullable-unique index) that every non-`Host` code path resolves to — console commands, queue jobs, migrations, not-yet-brand-aware admin screens — via `Affiliate::primary()`. Never deletable. [ADR-061](./adr/ADR-061-every-storefront-is-a-reseller.md). Since ADR-060, storefront requests resolve their brand by `Host`; `is_primary` stays only the fallback.
- **`Reseller` (wallet) :** Distinct from `Affiliate` (whitelabel storefront partner, above) — a `Reseller` only ever *spends* from a prepaid deposit balance, never earns; buffers the platform's own working capital against Digiflazz/Gamevion running dry. Split out of the original single `Reseller` table and renamed from it 2026-09-04 ([ADR-072](./adr/ADR-072-reseller-system-split.md)/[ADR-073](./adr/ADR-073-reseller-wallet.md)). Assigned a `reseller_tiers` markup tier (admin-managed, zero self-serve pricing); orders it places carry `orders.wallet_reseller_id` and `pricing_basis = 'reseller-wallet'`, with `affiliate_profit` always `0` by construction (`PricingService::calculateForAffiliate(affiliateMarkupPct: 0.0)`, [ADR-073](./adr/ADR-073-reseller-wallet.md) decision 1) — the markup is realized as ordinary `platform_profit` instead. Two ordering interfaces share one order-placement contract (`ResellerOrderPlacementService::placeOrder()`, [ADR-073](./adr/ADR-073-reseller-wallet.md) decision 4): the **Reseller API** (per-tenant `reseller_api_keys`, [ADR-074](./adr/ADR-074-reseller-api-channel.md)) and the **Reseller Bot** (WhatsApp via OpenWA, group-identity mapping, [ADR-075](./adr/ADR-075-reseller-bot-channel.md)).
- **Purchase Balance :** The name a Reseller sees for the wallet below (portal, Reseller API text, bot, receipts), and "Add Balance" for a top-up, per CHIP's activation condition (ADR-131 decision 1): "wallet" and "e-wallet" suggest a regulated e-money product. Display name only: the code, the admin panel, the ledger types and the API field names keep "wallet".
- **Wallet Top-up / Wallet Debit / Wallet Refund :** The three `ledger_entries.type` values a `Reseller` (wallet) account's own ledger ever carries (owner type `reseller_wallet`), pinned here per [ADR-073](./adr/ADR-073-reseller-wallet.md)'s own consequence-to-track (owed since PR-C, closed at PR-G). **Wallet Top-up** — a credit, written by either of two equal-standing paths: an admin's manual credit (`ResellerWalletService::manualCredit()`, PR-C, with an optional receipt/proof file) or the reseller's own self-serve CHIP checkout (`ResellerWalletService::completeTopup()`, PR-G, ADR-073 decision 3(a) — a `wallet_topup_attempts` row, `pending → paid/failed/expired`, capped at one `pending` attempt at a time). **Wallet Debit** — the synchronous spend at order-placement time (`ResellerOrderPlacementService::placeOrder()`, PR-D), the tier-priced cost of one order, debited from the same `LedgerService::debit()` primitive every other debit in this codebase uses. **Wallet Refund** — a credit reversing a specific debit, written only by the admin's "Refund to Wallet" terminal action on a failed-and-not-retried wallet-owned order (PR-D decision 7) — an internal-credit reversal, not a reopening of [ADR-004](./adr/ADR-004-no-cash-refunds-ever.md)'s "no cash refund" policy, since no cash ever leaves the platform.
- **`reseller_code` / `catalog_code` :** The stable, human-readable product-code scheme both Reseller channels order against, since a raw `Package.id` is unstable across a supplier swap (ADR-031/SYNC-5/6). `games.reseller_code` (`[A-Z]{2,10}`, globally unique) identifies the game; a package resolves via `{reseller_code}-{denomination}` for a numeric-value package (e.g. `MLMY-14`) or `{reseller_code}-{catalog_code}` for a bundle/pass with no inherent numeric value (e.g. `MLMY-P1`, `packages.catalog_code` — must contain a letter, mutually exclusive with `denomination`). Two independent equivalence-key spaces by construction, so a bundle can never falsely dedup against an unrelated real-value package. Resolved by the shared `Package::cheapestActiveFor()` / `ResellerCatalogService::resolveByCode()` seam ([ADR-074](./adr/ADR-074-reseller-api-channel.md)/[ADR-075](./adr/ADR-075-reseller-bot-channel.md) catalog-code addendum, built PR-E0).
- **Campaign :** A KOL/influencer marketing push — the grouping parent for one-or-more `PromoCode`s and tracking links, and the unit `CustomerAnalyticsService`/`ReportService` roll up a KOL's attributed sales/profit against. Distinct from `Affiliate` (a branded storefront the KOL would have to actively run) — a `Campaign` lets the platform pay a KOL without them operating any storefront at all ([ADR-118](./adr/ADR-118-marketing-campaigns.md)).
- **PromoCode :** A multi-use discount code under one `Campaign`, redeemable by many different customers (contrast `Voucher`, below, which is locked to one named customer). A flat percentage off the Standard price only (never Member pricing), with its own redemption-count/game-ID/customer lock and its own budget cap — never shares a budget with a sibling `PromoCode` under the same `Campaign`, so multiple codes (e.g. one per KOL or platform) can be compared fairly ([ADR-118](./adr/ADR-118-marketing-campaigns.md)).
- **Order Credential :** A customer's game-account login held for one order in its own encrypted table (`order_credentials`), never on `orders`; revealed only to a super_admin with MFA step-up and an audit row, wiped once the order closes ([ADR-124](./adr/ADR-124-customer-account-credentials.md)). Written at checkout for a login game ([ADR-125](./adr/ADR-125-login-top-up-manual-supplier.md) PR 1b). Build status: §15.
- **Login Top-up :** A top-up the supplier performs by logging in to the customer's account (Roblox, eFootball), not by player ID; packages are entered by hand at an agreed cost ([ADR-125](./adr/ADR-125-login-top-up-manual-supplier.md)). Build status: §15.
- **Manual Supplier :** A supplier with no API (`suppliers.driver = manual`), behind the same `SupplierAdapter` seam; it confirms or fails each order from its WhatsApp group (`.done` / `.gagal`) or the admin Manual Queue fallback ([ADR-125](./adr/ADR-125-login-top-up-manual-supplier.md)). Build status: §15.
- **Manual Queue :** The admin list of paid orders waiting on a manual supplier (the fallback to the supplier's WhatsApp group, [ADR-125](./adr/ADR-125-login-top-up-manual-supplier.md) PR 2b); Delivered defaults to the agreed `cost_price` (a different real cost needs a reason, decision 36) and goes through `finalizePendingDelivery()`, like every other confirmation. Build status: §15.
- **Funding Mode :** `suppliers.funding_mode`, `prepaid` (we hold a balance with them; every API supplier) or `pay_after` (we owe them after delivery, a payable). A manual supplier picks one in the panel; every penjoki is `pay_after` (ADR-126 decision 8).
- **Joki / Penjoki :** Joki is a rank-boosting service (MLBB first, owned brands only): the customer picks a current and target rank and hands over their login. A penjoki is the player who does it, modelled as a `pay_after` manual supplier (`suppliers.joki_role = penjoki`) with their own WhatsApp group ([ADR-126](./adr/ADR-126-joki-mlbb.md)). Build status: §15.
- **Assignment pool :** The one supplier (`suppliers.joki_role = pool`) a paid joki order waits on until an admin assigns a penjoki. It posts to no group, mints no login link, gets no reminder, is never Delivered (Failed is allowed) and is switched only by the Joki switch (ADR-126 decision 15).
- **Mythic Grading :** The 10 placement matches an MLBB account plays when it first reaches Mythic. Joki sells it at one flat price that guarantees Mythic 15★ (`grading_lands_at_stars`); only stars past that are charged per star (ADR-126 2026-10-23 PR 2b addendum).
- **Fixed pricing basis :** `PricingBasis::Fixed`, a joki order's price from the server's rate table, with cost = price × the penjoki's payout share and no affiliate markup (ADR-126 decision 2).

---

# 14. Build Status

**Where things stand (2026-10-30).** The platform is feature-complete and live
in production: storefront (plus every Affiliate whitelabel brand), admin panel,
Affiliate/Reseller portal, and the developer-docs site. CHIP FPX payments and
the CHIP + Digiflazz webhooks are proven end-to-end with real money.
**Digiflazz is the only live supplier** (funded 2026-09-15). **Gamevion**
(MYR-billed) stays integrated but deliberately unfunded, so Gamevion-routed
orders can be paid but not delivered. **Real customers exist on the reseller
channel:** reseller wallet orders (e.g. Naeem Industries) are real customer
orders. Storefront-direct orders are still mostly the founder's own testing, so
any analysis that needs the distinction must judge each order (corrected
2026-09-30, see `docs/build-log-archive.md`). The older "no external customers" line
was wrong from at least then on. **FixFast** (a reseller) and **FixFast Demo** (an
affiliate on `fixfastapp.com`) are *test* accounts; the FixFast reseller's
login was given to CHIP for its review. They are not customers. **OhahaStore** (an affiliate, one of our own brands, `is_owned`, on `ohahastore.com`, registered 2026-10-30) is live; its domain's payments are not approved yet (§16 item 147).

**Payment methods (checked read-only on prod 2026-10-29 06:31 UTC):** `fpx` on,
`fpx_b2b1` off, `dnqr` (DuitNow QR) on. History: all three were off from
2026-10-07 (the founder's choice, confirmed 2026-10-13); FPX was on for one
test order on 2026-10-16 (one storefront order paid) and off again; **since
2026-10-22 09:52 UTC FPX is on again**, on purpose, only while CHIP reviews the
site for LWF Group's merchant account (the founder will switch it off again);
DuitNow QR was switched on 2026-10-28 13:28 UTC to test it. Storefront
checkout and the wallet's self-serve top-up are payable by FPX. Re-read
read-only at release time on 2026-10-29: unchanged.
**Real payment proof so far:** FPX (the older CHIP-page flow) is proven with
real money. After release #589 **no FPX or DuitNow payment has completed**:
three test orders since the deploy (04:29–04:33 UTC) all ended Failed. Two
FPX orders reached CIMB's bank login through the new bank grid (CHIP's attempt
data shows the buyer bank `BCBB0235`) and were cancelled there ("Buyer Choose
Cancel At Login Page"); the DuitNow order reached DuitNow (CHIP's attempt shows
`payment_method: dnqr`, so the old "No matching terminal" is fixed) and was
cancelled by the user ("Transaction cancelled by user"). The payment-method
**Test** action has not been run since #584 (the stored results for `fpx` and
`dnqr` are the old HTTP 400 ones). Both proofs are §16 item 138.

**Latest release 2026-10-30 (`staging`→`main`, PRs #608 and #610).** #608 merged as
`8f3e9ea8` (07:31 UTC); the box runs release `81640214` after it. CI (all jobs)
and the `deploy` job were green. One migration, applied:
`2026_10_30_120000_add_payout_period_to_withdrawals_table`; no env changes. It
ships #601–#607: **ADR-132, the affiliate monthly auto-payout** (#605: the manual
request is gone, the platform opens one withdrawal ticket per affiliate per
Malaysia month, the admin gets a "Needs attention" panel and the portal the
schedule, §15 "Withdrawals"), the receipt redesign and failed-payment labels
(#602, #603), the package list browsable before the ID is verified (#606), the
domain payments-approval fix (#607) and two flaky-test fixes
(`CustomerAnalyticsListingTest`, the full-cover voucher concurrency test).
#606 had also changed the Bumblebee header by mistake. **#610** (storefront only,
merged `9140ba8c`, 08:44 UTC, box release `81645113`) restores the original header
and fixes the mobile bottom nav, whose labels were ink on ink on Cyber Bumblebee.
Verified read-only on prod after the deploys: the migration `[53] Ran`,
`/api/health` all true, `affiliate-payout-open` in the schedule (00:10 MYT), the
payout plan for the three affiliates (balances 0, 15 sen and 0, none with bank
details: no ticket opens on the first run at 31 Oct 00:10 MYT, and the first real
cycle is 1 Nov), the production storefront CSS has no `--color-header` and has
the new bottom-nav rule, and the Vercel production deployments `success`. The
founder checked `ohahastore.com` on a phone. **Not yet seen:** the first
auto-opened ticket on the prod Withdrawals page (none exists yet) and a
successful domain payments approval. **Owed:** §16 items 147 (OhahaStore
go-live) and 149 (owned-brand numbers), and 138 and 141 as before. §15
"Withdrawals", "Storefront (checkout flow)", "Affiliates / whitelabel".

**Previous release 2026-10-29 (`staging`→`main`, PR #599).** Merged as `a6ae7f4a`
(12:01 UTC); CI (all nine jobs) and the `deploy` job were green, and the box
runs release `81561008` at `a6ae7f4`. Two migrations, both applied (in
`migrations`): `2026_10_29_150000_add_purchase_balance_limits` and
`2026_10_29_170000_add_payments_approval_to_affiliate_domains`; no env changes.
It ships #590–#598: the failed-attempt-is-final work (#591/#592, ADR-110
2026-10-29 addendum, §16 item 140: `single_attempt`, `check-payment`, the
"Payment Not Completed / Try Again" page) and **ADR-131, CHIP's activation
conditions** (#596–#598): resellers see the wallet as **Purchase Balance**
(RM10,000 per top-up, RM100,000 default balance cap with a per-reseller
override, a Payment Receipt per top-up), one **Close account** action replaces
the bare DELETE, and a branded domain takes no CHIP payment until an admin
approves it. Verified read-only on prod after the deploy: `current` and the
migrations; the settings defaults (`topup_max_sen` 1000000,
`balance_cap_default_sen` 10000000, company details empty); the closure and
gate code present; `/api/health` 200; the four Vercel production builds
succeeded. Prod `affiliate_domains` holds exactly one row: `fixfastapp.com`
(FixFast Demo, active, **not approved**, so it cannot take a payment). Before
the release prod had no row for the primary affiliate (its hosts are config),
so the migration's backfill touched none. **Seen on prod by the founder the same
evening:** the storefront notice on `fixfastapp.com`, the admin "Payments not
approved" tag and Approve button, the Purchase Balance portal, a receipt PDF
with the company details filled, and a real cancel at a bank for FPX and
DuitNow QR (item 140: both orders went Failed within 12 s and 20 s and the
"Payment Not Completed / Try Again" page showed). The affiliate portal's
"Payments awaiting approval" badge was seen too, and an Add Balance cancelled at
the bank ended Failed and returned to `/balance`. The legacy `/wallet` link
was checked on prod too (`308` to `/balance`, query kept). **Not yet seen:** the
step-up saves with an MFA admin. The first domain approval attempt (2026-10-30) failed with "The approved field is required": a double-encoded request body, fixed in #607 and released in #608; a successful approval has not been seen yet. **Owed:** §16
items 138 and 141. Items 142–144 were added the same evening and closed 2026-10-30 (#602, #603), released in #608. §15 "Reseller
(wallet)", "Affiliates / whitelabel", "Payment Gateway", "Settings".

**Previous release 2026-10-29 (`staging`→`main`, PR #589).** Merged as `c7cfcf06`
(12:15 MYT); the box repointed `current` at 04:27 UTC (release `81523398`). One
migration, `2026_10_28_120000_rename_duitnow_qr_channel_code_to_dnqr` (applied:
it is in `migrations`); no env changes. It ships #582–#588: FPX bank-direct
(ADR-130: the customer picks the bank on our page; #587 checkout and `/joki`,
#588 membership and the partner portal's wallet top-up; the "BNM Compliant"
line removed), the DuitNow QR code fix to `dnqr` (#584, ADR-022 2026-10-28
addendum), and the MLBB player-ID chain Redx → UniPlay with MooGold removed
(#583, ADR-005 2026-10-28 addendum; the public Game ID Checker uses it too).
Verified on prod after the deploy: `player_validations` has 3 `valid` rows from
`uniplay` (latest 04:33 UTC) and none from `moogold`; Redx's own MLBB checker
had answered 503 since 2026-10-28. §15 "Payment Gateway", "Middleware Panel".

**Previous release 2026-10-27 (`staging`→`main`, PR #581).** Merged as
`2db6d62c`. No migrations, no env changes — the rollout is frontend-only. It
ships #575–#580, the ADR-129 action-feedback (toast) rollout: one PrimeReact
headless toaster, one shared `admin/src/components/ui/toast.tsx` generated
into `reseller/`, a `notify` facade, and a toast-vs-inline policy swept
across admin, middleware and the partner portal (a money-action failure is
sticky; a 200 whose body carries a failure is read, not assumed success).
The #580 review also fixed the tier-fee charge toast to report whether the
fee was actually debited. §15 "Action feedback (toasts)".

**Previous release 2026-10-27 (`staging`→`main`, PR #571).** Merged as
`f4177bc` (2026-10-26 17:06 UTC). CI and `deploy` were green; the box runs
release `81251053` at `f4177bc`, Horizon is running, `/api/health` 200, and
all four Vercel production builds succeeded. No migrations, no env changes.
It ships #560–#570:
- **Dependency advisories (#570).** `next` 16.4.3 in admin, storefront and
  portal (closes a critical RCE advisory), `zod` 4.6.7, `livewire` v4.4.8.
- **Partner portal redesign, layer 1, and the shared component kit
  (ADR-128, #562–#565, #567, #568).** §15 "Partner portal UI". Live on
  `reseller.pekangame.space`. The kit change also restyles the admin's
  buttons and inputs (status buttons soft, ADR-128 2026-10-27 addendum).
- **Gallery delete warning (#560)** covers every image field.

**Previous release 2026-10-25 (`staging`→`main`, PR #557).** Merged as
`369fbbb`. CI and `deploy` were green, and the box was verified the same day:
release `81131278`, migration `2026_10_25_110000` ran, Horizon is running.
It ships #556:
- **One order, one place (ADR-086 2026-10-25 addendum).** A reseller-wallet
  order counts toward its Reseller only, never the primary brand, on Reports,
  Customer Analytics, the Affiliates list, the portal aggregate and the
  Report Assistant's view. Prod, all time, before → after: PekanGame
  RM563.72 / 37 → RM110.60 / 22. Brands plus resellers now equal total
  sales (RM571.91), and the view has no row with both an affiliate and a
  reseller. Accounting is unaffected (wallet orders' affiliate ledger rows
  are RM0);
- the Joki rank model saves (Mythic tiers no longer send `divisions: null`);
- admin uploads name a file that is too large or refused. The founder raised
  Forge's Max File Upload Size to 12 MB (PHP and nginx verified; nginx had
  been at its 1 MB default).

`/api/health` is 200 with `scheduler: true` again (checked 2026-10-26, after
`supplier-balance-refresh` ran at 00:00 UTC with the item 130 fix live).

**Earlier release the same day (`staging`→`main`, PR #554).** Merged as
`0d6b8ad`. CI and `deploy` were green, and the box was verified the same day:
release `81129239`, all 14 migrations ran, Horizon is running, and joki is
off with Epic up only. It ships #529–#553:
- the item 130 fix (#531), so an unreachable supplier no longer aborts the
  daily balance sweep;
- Joki MLBB, PR 1–5 and Epic-up (#533–#536, #538–#540, #552). It ships
  **switched off**, gated by §16 item 114. PR 1 also changes Login Top-up:
  the post key carries the supplier, Delivered/Failed re-check it, the stale
  clock is explicit, and each login has its own wipe ceiling;
- ADR-127 (§16 item 131, #543–#549): revenue, cost and profit by
  `recognised_at`, closed months that cannot move, CHIP's own paid time and
  fee, dated voucher revokes, Mark Delivered at the supplier's price, one
  drawdown per order (unique index), pay-after supplier payments on their own
  Summary line, and the membership gate judging the request's brand;
- item 132 (#551): IDR at a moving weighted average from 1 October 2026.
  Read-only after release: October's COGS (38,506 sen) and Digiflazz
  prepaid (18,549 sen) did not move, because there has been no deposit
  since September;
- plain-words Dashboard tooltips (#553), and docs.

`/api/health` stayed 503 after the deploy, because `supplier-balance-refresh`'s
heartbeat still holds the failed run from 25 Oct 00:00 UTC (a Gamevion SSL
timeout, before the item 130 fix). It cleared at the 26 Oct 00:00 UTC run.

**Previous release 2026-10-22 (`staging`→`main`, PR #528).** Merged as
`d26c795`; CI and `deploy` green, box verified the same day (migration
`refund_policy_content` ran, Horizon running, `/refund-policy` serves 200).
Ships #527: an admin-edited Refund Policy page and the removal of the
storefront's "Bank Negara Malaysia" lines, both for CHIP's merchant review of
LWF Group Sdn. Bhd. The founder filled the Refund Policy, About Us (with the
business address) and Terms the same day.

**Previous release, same day (PR #526).** Merged as
`d2f841b`; CI green, `deploy` job green, box verified the same day (sha,
migration `add_source_to_backup_runs` ran, Horizon running, backup password
and `OPENWA_INSTALL_DIR` read). Ships #523 (OpenWA `400` "not on WhatsApp"
fails the send job at once) and #524 (OpenWA as a second backup source,
backup encryption enforced, ADR-039 2026-10-22 addendum), plus docs
#519–#522 and #525. One additive migration; the two env vars were set before
the release (§16 item 96).

**Previous release 2026-10-19 (PR #517).** Merged as
`6a5052e`; CI green and its `deploy` job called the Forge hook (16:59 UTC);
box verified 2026-10-22; #516 measured, admin GETs send no OPTIONS (§16 item 124, closed). Ships #516: admin reads skip the CORS
preflight (`apiFetch` sends `Content-Type` only with a body) and `cors.php`
`max_age` is 7200 (ADR-121 2026-10-19 addendum); plus #515, the docs sync after
#514. No migration, no env change.

**Release before that, same day (PR #514).** Merged as `8982994c`, CI deploy job
green; box verified 2026-10-22. Ships item 112
(customer account credentials base, ADR-124) and item 113 (Login Top-up, Roblox
and eFootball: supplier `driver` + manual suppliers, admin manual packages with
audited agreed cost, login checkout with consent, Manual Queue fallback,
supplier WhatsApp group bot with short login links, Partners → Suppliers screen,
and package-off-sale switch on deactivation, ADR-125). Eight additive
migrations: `order_credentials`, `credential_reveals`, the Customer Login Policy
columns on `platform_settings`, `suppliers.driver` + `funding_mode`,
`games.member_pricing_enabled` + `price_change_logs.admin_user_id`,
`whatsapp_groups`, `customer_notifications.session` + `chat_id`, and
`supplier_login_links`. Existing games and checkout behave as before; a customer
sees something new only once an admin creates a manual supplier, links its
WhatsApp group, activates it and adds a login game and its packages.

**Storefront domain:** `pekangame.com` (`pekangame.space` and both `www.`
hosts 308-redirect to it since 2026-10-08). `api.`, `reseller.`, `docs.`,
`cdn.` stay on `pekangame.space`.

**Earlier releases** (#500 on 2026-10-18: items 123 and 120, Transaction Register corrections and keeping CHIP source files; #476 on 2026-10-17: dependency advisories, storefront route skeletons, weekly dependency audit; #472 on 2026-10-16: Game ID Checker, ADR-123; #469 on 2026-10-16: RedxGame player validation; #462 and #449 on 2026-10-15: items 97–103; #441; #437 on 2026-10-14: admin MFA, ADR-121; #430 and #425 on 2026-10-13, #416 and #406 on 2026-10-12/13, #393 Envelope Ledger,
#385 Reports redesign, #376 Reseller API `max_price_sen` / Assistant = Reports /
`r2_accounting`, #369 ADR-120 SEO/GEO, #362, #354, #346, #342, #332,
#320 and the 2026-09-28 audit waves) are recorded release by release in
`docs/build-log.md` (2026-10-15 onward) and `docs/build-log-archive.md`
(before that). This section keeps only the current state.

**Infrastructure:**
- Backend runs on the `pekangame-prod-lwf` DigitalOcean droplet (ADR-114);
  the old droplet, its managed MySQL and Reserved IP were destroyed
  2026-09-30 (§16 item 52).
- The 4 frontends are on Vercel in `sin1`.
- Cloudflare sits in front of `api.`.

- **Per-feature-area status:** §15 below.
- **Full chronological build record** (every session, what shipped, the gotchas): `docs/build-log.md` (entries before 2026-10-15 in `docs/build-log-archive.md`).
- **Decision rationale** (Context → Decision → Rationale → Consequence): `docs/adr.md`.

---

# 15. MVP Scope Tracker

The coarse status-by-feature-area map. Read at a glance; the file-level detail and
the chronology are in `docs/build-log.md`, the *why* in `docs/adr.md`.

| Area | Status | Key ADRs |
| --- | --- | --- |
| Auth & Admin Users (AUTH-1..7) | ✅ Live. Login rate-limited + logged. Admin MFA (AUTH-7) and the cookie-session admin login live since 2026-10-14 (#437). **2026-10-19 (ADR-121 addendum, released via #517):** admin GETs carry no `Content-Type`, so they skip the CORS preflight; preflight answers are cached (`max_age` 7200). Verified on prod 2026-10-22: 0 OPTIONS on `/admin/orders` | ADR-019, ADR-121 |
| Admin Dashboard (DASH-1..6) | ✅ Live — **redesign (§16 item 102, ADR-104 2026-10-15), live 2026-10-15 via #462:** daily briefing with a period filter, status strip, trend, channels, top games, busy hours, highlights; the funnel and single-day hourly chart are removed. **Scheduler liveness (ADR-122, §16 item 98):** live 2026-10-15 via #449. Per-task heartbeat (`scheduled_task_heartbeats`); a stale critical task fails `/api/health` (`checks.scheduler`); a "Scheduled tasks" row on System Health | ADR-045, ADR-122 |
| Money core (Pricing, Ledger, Voucher, Order status, idempotency) | ✅ Live — full service layer, concurrency-proven. The most mature part of the codebase | ADR-002 |
| Affiliates / whitelabel (RES-1..6) | 🟢 Live in prod — wholesale tiers + subscription state machine, tenant isolation, `affiliate` guard + portal, platform-owner special-case abolished (`is_owned`/`is_primary`), per-brand Membership, `Host`-resolved branded storefront + Vercel-native custom domains, per-brand pricing + ledger split, brand-scoped vouchers. Real affiliate domain verified end-to-end. PR #273's invite/session and compensation visibility fixes, plus ADR-112's role-aware shell, dashboard recent orders, and responsive Orders presentation, shipped in PRs #273, #275 and #276, released to `main` via #278 (2026-09-24) **2026-10-29 (ADR-131, released via #599):** a custom domain takes no CHIP payment until an admin approves it (`payments_approved_at`, super_admin + step-up); the migration approved only the primary affiliate's own rows (none in prod), so `fixfastapp.com` cannot pay; Approve/Revoke in admin Affiliates, an "awaiting approval" status in the affiliate's portal. | ADR-056–061, 078, 112, 131 |
| Reseller (wallet) — Affiliate/API/Bot channels | 🟢 Live — prepaid wallet (admin manual credit + self-serve CHIP top-up, payable by FPX, §14), one order-placement contract (`ResellerOrderPlacementService`), `reseller_code`/`catalog_code`, REST API v1.5.0 (keys, IP allowlist, delivery webhook, optional `max_price_sen`, per-account rate limits: orders 60/min and reads 120/min, ADR-074 2026-10-13 addendum, live via #425), WhatsApp Bot (OpenWA), shared role-aware portal (ADR-112), per-game player-input contract (ADR-097), own queue lane (ADR-048). Real customer orders run on this channel. Detail: build-log + ADRs **2026-10-19 (ADR-125 PR 2a, released via #514):** the group registry is `whatsapp_groups` (owner reseller or supplier; the reseller rows moved as-is); the reseller bot is unchanged, and the webhook now drops the bot's own messages **2026-10-29 (ADR-131, released via #599):** shown to resellers as **Purchase Balance** ("Add Balance"; the admin, the code, the ledger types and the API field names keep "wallet"); RM10,000 per top-up and a RM100,000 default balance cap, overridable per reseller, enforced under the wallet mutex for the CHIP top-up (portal and bot) and the admin manual credit; a Payment Receipt PDF per paid top-up and per manual credit; one "Close account" action (transfer first, then the debit) replaces the bare DELETE; the portal route is `/balance` (`/wallet` redirects). | ADR-072–076, 084, 091, 093, 112, 048, 097, 131 |
| Supplier Adapter (ADAPT-1..4) | ✅ Digiflazz live (the only funded supplier). Gamevion is integrated but deliberately unfunded (2026-09-24). Per-supplier circuit breaker, `SupplierAdapterFactory` routing, async delivery state machine + poll backstop, inbound webhooks (HMAC). **ADR-097 PR-1 + PR-2 built 2026-09-16** — Digiflazz `customer_no` separator moves per-game (was wrongly supplier-wide); per-game Zone ID picklist replaces free text, with the same presence+value-validation now shared (`CheckoutInputValidator`) across storefront, Reseller API, and Bot. Both PRs live. **ADR-098 built 2026-09-16** — `SupplierResponse::$transactionAlreadyFormed` (supplier-agnostic) routes a non-retriable Digiflazz `rc` (Terbentuk Transaksi=Ya, 20 codes) or Gamevion `duplicate_reference` straight to `needs_review`, closing a real gap in the async webhook/poll finalize path a plain `Failed` resend could never resolve (later refined by ADR-102). **2026-09-29:** the breaker also counts `createOrder`/`checkStatus` timeouts (`listProducts` excluded). For a replay-safe supplier (Digiflazz), an ambiguous outcome now goes to Pending with a same-ref poll instead of NeedsReview (ADR-102 addendum). Live | ADR-006, 030–032, 067, 069, 097, 098, 102 |
| Payment Gateway (CHIP only, PAY-1..4) | 🟢 Live in prod — real RM FPX payment + webhook proven end-to-end (order `PG-PYAYMRYNUYV0`). Methods `fpx`, `fpx_b2b1`, `dnqr` (DuitNow QR; was seeded as `duitnow_qr`, a wrong guess found 2026-10-28, fixed by #584, released in #589 on 2026-10-29). FPX has been on since 2026-10-22 and DuitNow QR since 2026-10-28 (§14); since #589 CHIP routes DuitNow to DuitNow (attempt `payment_method: dnqr`), but a DuitNow payment has never completed (the one order since was cancelled by the user). Xendit deleted (archived). **FPX bank-direct (ADR-130) live since 2026-10-29** (#587 checkout and `/joki`, #588 membership and the portal's wallet top-up, released in #589; a 20-bank grid that goes straight to the bank). Two orders reached CIMB's login through it and were cancelled there; no payment has completed through it yet (§16 item 138). **A failed attempt is final (ADR-110 2026-10-29 addendum), released 2026-10-29 in #599** (#591, #592; §16 item 140). **ADR-110 PR-A built 2026-09-19** — `overdue`/`expired`/`blocked` now map to `Failed` (were silently stuck `Pending` forever); every purchase now sets `due`+`due_strict` so CHIP itself closes it after 30 min. **PR-C built 2026-09-19** — credentials moved to encrypted `payment_gateways` DB row + `/middleware/payment-gateways` admin screen, binding is DB-first; the founder completed the cutover 2026-09-24, and the `.env` fallback is kept deliberately (§16 Parked). **PR-B built 2026-09-19** — CHIP settlement `.xlsx` reconciliation + Monthly Accounting Summary, fills ADR-083 PR-2 (see §16 item 12). All three ADR-110 PRs now built **2026-10-29:** #591/#592 (a failed attempt is final, `check-payment`, "Payment Not Completed / Try Again") released via #599; the real cancel at a bank is §16 item 140. | ADR-022, ADR-110, ADR-130, 110 addendum |
| Games & Packages (GAME-1..11) | 🟢 Live — GAME-1..11 all shipped (list/detail, markup %, activate/deactivate, delete, bulk markup via `/admin/settings`, SEO fields via `/admin/seo/games`, drag-drop reorder via `/admin/games`'s "Reorder Games", folded with the storefront's Quick Top-Up widget). GAME-12 dropped, 2026-09-13 (dead requirement, see §16). **2026-10-02:** package delete now blocks a package with order history or live combo-component usage instead of silently orphaning order history or crashing with a raw DB error (ADR-119). **2026-10-18 (ADR-125 PR 1b, §16 item 113), released via #514:** "New Login Game" with its login-form editor, manual packages at an agreed cost (audited edit, per-package price history with the admin), a per-game member-pricing switch; a login game can't take a reseller code, validator, supplier product or combo | ADR-029, ADR-119, ADR-125 |
| Combo Package | 🟢 Live — several catalog Packages sold as one opaque SKU (one FPX fee), sum-of-components pricing with optional `combo_override_price` (never below cost), up to 5 legs, per-leg references and attempt rows, `partially_delivered` + `OrderSettlementService` settlement (voucher or wallet refund), real-cost profit residual (ADR-107/111), component cascade on/off, bulk markup routed through `ComboPricingService` (ADR-028), component SKU in the Edit Combo modal. Per-leg attempt history shown under each leg on Order Detail (live via #406, 2026-10-12, item 66). **2026-10-13 (ADR-094 addendum, item 93):** the stored price stays current while a combo is off and is recomputed before the reactivation cascade; a component markup reprices its combos; a markup on a combo itself is rejected; live since 2026-10-14 (#430). Edit-composition parked 2026-10-14 (§16 Parked) | ADR-094, ADR-100, ADR-046, ADR-106, ADR-107, ADR-028 |
| Price Sync (SYNC-1..6) | ✅ Live — raw sync → promote-to-catalog, price propagation + deactivation detection, sanity guard (floor + swing), FX conversion, best-price dedup, per-supplier grouping, stuck-run hardening. **Live 2026-10-17 (#490, item 106):** `partial` run status with failed suppliers listed, stale-supplier email (default 90 min) | ADR-015/016, 025, 033, 034, 067 |
| Supplier Management (SUPP-1..5) | ✅ Live — SUPP-1/CRUD/SUPP-5; credentials in encrypted `Supplier.api_config`; balance refresh + low-balance chip; credential-rotation probe on save. **2026-09-24:** the bulk "Deactivate All"/"Deactivate by Game"/"Reactivate" toggle now cascades onto dependent combos (found while auditing the Pending Reactivation combo gap above — the bulk toggle had never called `ComboPricingService`'s cascade at all, deactivate or reactivate). Released `main` via PR #286, 2026-09-24 **2026-10-19 (ADR-125 PR 2a/2b, §16 item 113), released via #514:** a manual supplier is created inactive and can be switched on (here or in Partners → Suppliers) only with an active WhatsApp group; the Partners → Suppliers screen (`/admin/suppliers`: group link/unlink, reply SLA, active switch, super_admin, 2b); switching a manual supplier off takes its packages off sale through the ADR-046 `supplier_issue` seam and switching it on restores them (decision 64) | ADR-046, 069 |
| Orders Management (ORD-1..11) | ✅ Live — checkout + fulfillment, Resend (same-game swap, one resend seam, ADR-105), ORD-10 reconciliation, async `pending`, manual "Check from Supplier/Gateway" (ADR-096), one compensation guard `isAlreadyCompensated()` (ADR-102/103), restore-only voucher (ADR-024), six KPI cards (ADR-092), ADR-104 reskin, ADR-108 toolbar (Need Action excludes compensated, Source/Game/date filters, Columns, 2-sheet Excel export with earned profit) and a Failed pill (#385). **2026-10-18 (ADR-125 PR 1b), released via #514:** `/admin/manual-queue` and Order Detail actions for a manual supplier's order (Delivered at the agreed or a reasoned real cost, Failed, a supervised Failed → Delivered correction before any voucher), all through `finalizePendingDelivery()`; Retry/Resend refuse a wiped login; resend may correct a login's identifier (audited). **2026-10-23 (ADR-126 PR 1, item 114), released 2026-10-25 via #554:** a manual post's dedupe key carries the supplier; Delivered/Failed re-check the supplier under the finalize lock (the admin sends the one on screen); the stale clock is `orders.manual_activity_at` (stamped when the group post is sent), and the manual SLA max is 3 days. Detail: build-log + ADRs | ADR-017, 024, 026, 032, 092, 096, 102, 103, 104, 105, 108, 125 |
| Reports (RPT-1..4) | 🟢 Live — **2026-10-25 (#556, released via #557):** an Affiliate's figures are its storefront orders only; a reseller-wallet order counts toward its Reseller only, on every tab and filter (ADR-086 addendum). redesigned per ADR-104 (2026-10-08 addendum R1–R21 + 2026-10-09 R8 addendum), live via #385 (2026-10-09). 7 tabs on Recharts. **Paid sales** (money collected, net of wallet refunds, `Order::netSalesSql()`) with a **Bridge to Accounting** to recognised revenue from one `RecognisedRevenue` seam shared with the Monthly Summary. Ledger-sourced profit, `paid_at` scoping, KL days. **Failed & compensated** per delivery status plus outstanding store credit. Compare period on every tab, sales by channel + API vs Bot (`orders.placed_via`), Delivery by game. CSV/PDF carry Paid / Wallet refund / Paid sales. Default range All time. LLM assistant at `/admin/reports/assistant` (ADR-087, `super_admin`) matches the page. History: ADR-086 grouped-SQL rewrite, ADR-088 unified date filter **Live via #462 (2026-10-15; #454/#455, ADR-104 2026-10-15):** Compare for a range ending today runs to the same time (`previous_range.until`, label "…, to 10:30 AM"), a Today preset, `/reports/busy-hours`, `/reports/highlights` and `/reports/top-games?compare=` (the new Dashboard reads them). **2026-10-24 (ADR-127 PR B1/C, #544, #546, released 2026-10-25 via #554):** profit is filed by recognition (a manual correction by its own date), sales stay on `paid_at`; the Bridge gains "recognised here, paid in another range"; a profit bridge (owner profit → operating profit) on the Profit tab; store credit equals Cash Position (Path A included); labels Sales / Owner profit (market rate). | ADR-086, 087, 088, 104 |
| Withdrawals (WTH-1..5) | ✅ Live. **Monthly auto-payout (WTH-6, ADR-132): live, released 2026-10-30 (#605 in #608).** The platform opens one ticket per affiliate per Malaysia month (balance at the 1st 00:00 MYT minus the tier-fee reserve, minimum RM10, bank details required; `app:open-affiliate-payouts` daily 00:10 MYT, unique `payout_period`); the manual request is removed; the admin page shows the affiliate and period, a "Needs attention" panel and a "this month only" toggle (`GET /api/withdrawals/payout-run`); the portal shows the schedule, the scheduled amount and the fee reserve. No ticket has opened on prod yet (balances 0, 15 sen, 0). Maker-checker threshold RM 2,000 (`WITHDRAWAL_MAKER_CHECKER_THRESHOLD_SEN`). **Live via #393 (2026-10-10):** the platform-owner request (button, "Available balance", `POST /api/withdrawals`) is removed; the screen reviews Affiliate requests only | ADR-059, ADR-083 |
| Vouchers (VCH-1..6) | ✅ Live — + voucher-at-checkout (wallet model, partial/full cover), a typed expiry date valid through that Malaysia day (ADR-024 2026-10-13 addendum, live via #425), Path A double-submit key, Voucher Merge. Maker-checker RM 500. **2026-10-04 (ADR-024 addendum, live via #354):** a lost voucher or member-quota reservation now fails the checkout closed (Failed order, coded 422 `checkout_closed`, link never handed out) on every path, including idempotent replay; admin "Voucher Paid" reads the redemption. **2026-10-12 (ADR-036 addendum, item 80):** a merge rejects an expired source voucher. **2026-10-12 (ADR-024 addendum, item 79):** only an admin-issued voucher debits the platform ledger; the 4 old compensation-voucher debits were reversed on release (live via #416, 2026-10-13, verified on prod). **2026-10-13 (item 84):** expiry stays `expires_at` alone; the admin list, detail and order Refund cards show an active voucher past it as expired (`display_status`), and it can't be ticked for merge (live via #425) **2026-10-24 (ADR-127 PR B2/C, #545, #546, released 2026-10-25 via #554):** a restore carries `restored_at`; a revoke is a locked `VoucherService::revoke()` with `revoked_at` (it was an undated status flip); breakage is the balance at expiry or revoke. | ADR-024, 035, 036 |
| Customer Analytics (ANL-1..5) | ✅ Live — **2026-10-25 (#557):** the Affiliate filter leaves reseller-wallet orders out. `/admin/customer-analytics`, derived `customer_email` grouping (no new entity), VIP/Frequent/Dormant/New/One-time segments. **Item 103, live 2026-10-15 via #462:** one SQL aggregate, server-paged list with sort, email search and the Reports date presets, mixed Source label, paged detail order history **2026-10-24 (ADR-127 PR C, #546, released 2026-10-25 via #554):** the detail total is labelled "Customer spend". | ADR-049, ADR-050 |
| Membership (VIP, per-brand) | 🟢 Live in prod (kill switch ON) — 2 fixed tiers, email-OTP identity, live member pricing + quota, self-serve subscribe + pay via CHIP, admin per-member detail. Real tier numbers set. Per-brand `/membership` fully gated. Renewal-reminder emails at H-3 and H+1 (Malaysia days), listed on the admin member page (item 64, live via #416 2026-10-13). WhatsApp reminders designed, waiting on a trigger (§16 item 85) | ADR-027, 055, 068, 080 |
| Customer notifications (WhatsApp) | 🟢 Live in prod since 2026-09-30, switch **ON**. The CS-session webhook was added (its secret fixed by the model), and OpenWA `SEND_PACING` is on. Founder live-tested: two Delivered receipts sent, each branded by its own order (FixFast and PekanGame from one number), and `STOP` confirmed. The voucher path was live-tested 2026-10-06 (a failed order's Issue Voucher code arrived on WhatsApp), along with the status card and not-found reply. Voucher codes are sent proactively (Issue/Restore Voucher; a standalone voucher with a phone). Delivered receipts go to opted-in numbers. Sends go from the OpenWA `customer-support` session on a paced one-worker lane, recorded in `customer_notifications` and shown on the admin order and voucher pages. Email deliberately not used. **Follow-up #326 released to `main` 2026-10-01 via #332** (order number = opt-in, timeline status card, not-found reply, `START`, skipped revival, reordered help card; see §14). **2026-10-12 (§16 item 69):** every order-number message gets a card or a not-found reply; the silent repeat throttles are removed (live via #416; the founder confirmed on a real phone 2026-10-13) **2026-10-19 (ADR-125 PR 2a, released via #514):** the same persisted queue also carries supplier-group posts on the bot session (`session`/`chat_id`), with a send slot per session, so customer pacing and the customer switch are unchanged | ADR-116 |
| Reviews (REV-1..5) | ✅ Live — guest submit gated on Delivered, admin approve/reject/bulk, + public display (homepage marquee + per-game PDP section, brand-scoped) | ADR-053, 082 |
| Backups (BAK-1..5) | ✅ Live on Cloudflare R2 (`pekangame-backups`, private): full DB dump except `player_validations`, 7d/4w/6m retention, nightly at 02:07. **Encrypted only from 2026-10-22:** `BACKUP_ARCHIVE_PASSWORD` was unset from go-live until the founder set it on 2026-10-22, so every archive before then is a plain zip in the private bucket (deleted 2026-10-25, §16 item 96). Fail-closed encryption and the OpenWA source (ADR-039 2026-10-22 addendum) are live since release #526 (2026-10-22); OpenWA runs nightly at 19:37 with a passing restore test since 2026-10-22; the password-manager copy of the password decrypted the 2026-10-25 database archive (drill, 2026-10-25, §16 item 96). **Restore test verified again 2026-10-15** (run #47, `restore_test_passed = 1`, 72s, after release #449); nightly runs 2026-10-01..15 had failed (§16 item 97, closed). Fixed in code on 2026-10-14. The fix adds one restore path shared by the nightly test and a new `app:restore-backup` command; restores go only into an empty database; DEFINER clauses are dropped on restore; verification is real (no missing table, no pending migration); 300s timeout on `redis-long`; and killed runs close themselves. Released 2026-10-15 (#441, then the fixed-name follow-up #442 via #449) with the ADR-117 exact-name grant. Earlier history: the 2026-09-14 GTID fix, build-log-archive | ADR-039, ADR-095, ADR-117 |
| Image Gallery (IMG-1..2) | 🟢 Live in prod on Cloudflare R2 — upload/grid/search/copy-URL/delete, WebP-at-upload (2000px cap, reuses `ImageIngestService`) + delete referential-safety warning (live since #571, 2026-10-26, from #560: it also lists Joki rank icons, a brand's default OG image and Game ID Checker images, every `ImageUrlInput` consumer). `GALLERY_DISK=r2_gallery`/`BACKUP_DISK=r2_backups` live since 2026-09-14; every existing gallery/logo/favicon file migrated + verified 200 on `cdn.pekangame.space`. In-form picker (`ImageUrlInput`, live via #406, 2026-10-12) on Edit Game, Hero Slide and the two SEO OG-image fields; the URL stays a plain text field | ADR-095 |
| SEO (SEO-1..7) | ✅ Live — per-brand settings, meta templates, redirects (via `proxy.ts`), scripts, crawler rules, JSON-LD, native robots/sitemap/llms.txt. Full backend test coverage. **ADR-120 (live 2026-10-08):** tokens in every SEO field, per-brand canonical origin (canonical/sitemap/robots/llms/JSON-LD), all packages in HTML, Product `offers` + `aggregateRating`, fact line, admin FAQ + `FAQPage` | ADR-029, ADR-042, ADR-120 |
| Settings (SET-1..11) | ✅ Live — `/admin/settings` (branding / footer / platform), HTML sanitization, maintenance mode. SET-7/11 via the Payment Methods tool. SET-9 (Telegram) dropped and its fields removed (live via #406, 2026-10-12). Logo + favicon upload for the primary brand added (ADR-089) — previously only the affiliate portal had this. A re-upload shows at once (`?v=` on the URL, #409, live via #416; founder-checked 2026-10-13). **2026-10-22:** a fourth legal page, Refund Policy (`/refund-policy`, edited beside Terms/Privacy/About Us), for CHIP's merchant review, live via #528 **2026-10-29 (ADR-131, via #599):** a Purchase Balance tab: the limits (step-up) and the company details a receipt prints. | ADR-028, 089, 131 |
| Developer Tools (DEV-1..2) | ✅ Live — `/middleware/developer-tools`, all 5 adapter methods, typed-DTO editor, dry-run default. Same screen as MUI-11 | ADR-054 |
| Blacklist / Fraud (FRAUD-1..4) | ✅ Live — `BlacklistService` + `CheckoutVelocityGuard` wired into checkout; `/admin/blacklist` screen. `foundation-security.md` §4 fully checked | ADR-007 |
| Middleware Panel (MID-1..13, MUI-1..11) | ✅ Live — sync/matching/catalog (Product Manager), price sync + FX, player validation, test orders, request logging, supplier credentials, landing page. MUI-4 (export) dropped. **Player validation, live 2026-10-16 via #469** (ADR-005's three 2026-10-16 addenda): MLBB RedxGame → MooGold (**MooGold replaced by UniPlay 2026-10-28 (ADR-005 addendum, #583, released 2026-10-29 in #589; 3 `valid` UniPlay checks on prod since)**; it had been broken since 2026-10-10, when AcidGameShop and Nexone stopped answering); Free Fire (RedxGame, country mappings); Genshin / HSR / ZZZ (RedxGame, the UID's server must match the chosen zone, `wrong_zone`). Validators: MLBB, Free Fire, Genshin, HSR, ZZZ, all toggled on for their games **2026-10-18 (ADR-125 PR 1a, §16 item 113), released via #514:** `suppliers.driver`/`funding_mode`; a manual supplier (any number, MYR, prepaid or pay-after) can be added in Suppliers and is skipped by price/product sync, balance refresh, the staleness alert, status checks and the API tester; its balance is its funding ledger ("Owed" when pay-after); one Pending rule per supplier for the reconciler and Dashboard | ADR-005, 051, 052 |
| Storefront (checkout flow) | 🟢 Live in prod — all catalog/checkout/validate/track endpoints; server-side validation enforcement; PekanGame neo-brutalist redesign, mobile pass, read-path perf (Redis cache), dynamic payment SVGs + UX polish. Logo/favicon upload UI + aspect-preserving sizing + preset background/dark-mode groundwork shipped (ADR-089/090). Real logo/hero artwork uploaded 2026-09-14 (§16 item 2); the placeholder mark is only the fallback when no logo is set. Per-game "How to Buy" info popup (description/important notes) + admin-editable instant/manual delivery badge, `EditGameModal` gained Basic Info/Content tabs (ADR-109) — admin Content tab live-verified 2026-10-08 (§16 item 67). **2026-10-05 (ADR-097 addendum, #356 — released via #362, 2026-10-06):** the ID fields follow the game's contract — digits-only fields get the numeric keypad and block typed non-digits, a paste is kept with an inline error, a Text game (e.g. Riot ID) gets a normal keyboard; the backend refuses the same input on checkout and Check ID **2026-10-17 (ADR-071 addendum, #474, released via #476):** every route has a matched `loading.tsx` (home, price list, Check ID, membership, legal pages were missing one and froze on navigation), enforced by a CI guard. **2026-10-18 (ADR-125 PR 1b), released via #514:** a login game's Step 1 is its login form plus the consent box; the order sends the login, never a Player ID; Track Order, its live push, the WhatsApp card and the affiliate portal show the login masked, with a change-your-password line on delivery. **2026-10-30 (#606, #610, released in #608 and #610):** Step 2 (the package list) is browsable before the ID is verified (a tapped package is kept and, on a phone, the page scrolls up to Step 1; Step 3 needs both the ID and a package, ADR-064 addendum); the mobile bottom nav labels are readable on Cyber Bumblebee (they were ink on ink, ADR-090 addendum). | ADR-062–065, 071, 077–079, 089, 090, 097, 109, 125 |
| Game ID Checker (ADR-123) | ✅ Live 2026-10-16 via #472 — `/check-id` + `/check-id/<slug>` for the five families (Mobile Legends, Free Fire, Genshin Impact, Honkai Star Rail, Zenless Zone Zero), Turnstile (widget "PekanGame Game ID Checker"), 5/min + 30/hour per IP, 200/hour provider budget, RedxGame only (MLBB: Redx → UniPlay since #583, released 2026-10-29, ADR-123 2026-10-28 addendum), `public_player_checks`; in the sitemap and `llms.txt` on the primary brand, `noindex` on affiliates. Mobile More drawer; header highlight follows the page. A new affiliate custom domain must be added to the widget by hand until §16 item 108 | ADR-123 |
| Customer Login (ADR-124) | 🟢 Live, verified on prod 2026-10-23 with the first real Login Top-up order (FPX-paid Roblox, `.done` in the supplier group; build-log 2026-10-23). Built 2026-10-18 (§16 item 112), released via #514 — `order_credentials` (encrypted secrets, out of DB dumps), `app:wipe-order-credentials` every 15 min (critical heartbeat), super_admin reveal/replace behind MFA step-up with a per-admin hourly limit and a `credential_reveals` audit (pruned after a year), Settings → Customer Login Policy (G, H, D, reveal limit, consent text), Order Detail "Customer Login" card. **2026-10-18 (ADR-125 PR 1b-i, §16 item 113), backend only, released via #514:** checkout writes the login with the order; the identifier is masked on every customer and affiliate surface; a resend's identifier correction is audited (`identifier`). **1b-ii:** the storefront login form with the consent box (the text the order snapshots); an admin-replaced password is no longer trimmed **2026-10-19 (ADR-125 PR 2a, released via #514):** the second reader is the supplier's short link `/s/<token>` (hash-only `supplier_login_links`, Pending attempt only, each reveal a `credential_reveals` row with actor supplier) **2026-10-23 (ADR-126 PR 1, item 114), released 2026-10-25 via #554:** each login stores its ceiling (`secrets_expire_at`): the game's own "keep login at most" days (Edit Game, login games), else D. Changing either moves the unwiped logins; the supplier link expires with the login | ADR-124, ADR-125 |
| Joki MLBB (ADR-126) | 🟡 Released 2026-10-25 (#554), switched off and not sellable yet. PR 1 (#533): shared manual-order seams (post key per supplier, supplier re-check, explicit stale clock, per-login wipe ceiling). PR 2a (#534): `Fixed` basis, assignment pool, Assign under lock with audit, payout locks, joki game hidden, `not.joki` gate. PR 2b (#535): rank model and draft rates, pricing rule (Grading guarantees Mythic 15★), joki through the one checkout, `joki_orders` snapshot, `/api/joki/catalog` + `/quote`, `JokiCatalog::ensure()`. PR 3 (#536): `.progress` and the admin progress update, Track Order climb and current rank, paced WhatsApp progress. PR 4a: admin backend (`JokiBoard`, `/api/joki-admin/*`, F4 with "needs voucher" on the Joki card, `/orders` type filter, penjoki creation). PR 4b: Admin → Joki (Orders, Penjoki, Settings), Order Detail Joki card, Dashboard card, `/orders` type select, brand "Sell Joki", penjoki on supplier create. PR 4a/4b merged (#538, #539). PR 5 (#540): `/joki` page with the rank calculator, Joki Rank menu, sitemap, voucher preview priced like checkout, e2e golden path. Offered from Epic up (#552, 2026-10-25, ADR-126 addendum). All released 2026-10-25 via #554. Launch gates: §16 item 114 | ADR-126 |
| Partner portal UI (ADR-128) | 🟢 Live — released 2026-10-27 (#571). Layer 1: the ADR-104 tokens generated into `reseller/` (`scripts/sync-shared-files.mjs`, CI drift check), the "PekanGame Partner" shell (grouped navigation, nude account block, support contacts from the primary brand's branding, trust footer), both Dashboards, Orders, Order detail, Wallet, Earnings, Withdrawal, the settings pages, Login and Set password. Shared-token fixes that also reach the admin: dark `on-cyan` and `focus-ring` follow the artifact (admin primary buttons in dark mode were 2.09:1), and JetBrains Mono is now loaded in both apps. **The one shared component kit (ADR-128 2026-10-26 addendum, #567 + #568, live via #571):** the admin kit moved onto the semantic tokens (Button severities resolved to the `-surface`/`-ink` badge pairs, touch targets `min-h-11 sm:min-h-9`), then generated into `reseller/src/components/ui/` and the portal's hand-rolled `ui.tsx` deleted in favour of it; status buttons are soft `-surface`/`-ink` pairs (2026-10-27 addendum). Reviewed page by page before release (build-log 2026-10-27). Layer 2 is items 24 and 25 **2026-10-29 (via #599):** the wallet screen is `/balance`, "Purchase Balance". | ADR-128, ADR-104, ADR-112, 131 |
| Internal Accounting (supplier funding ledger + CHIP settlement recon) | 🟢 Live — PR-1 built 2026-09-11: `supplier_transfers`/`supplier_ledger_entries` (append-only, foreign-currency), Record Supplier Transfer UI (`/admin/accounting`), `ORDER_DRAWDOWN` capture (Digiflazz webhook + Gamevion sync response — a `Gagal` after `Pending` writes no `REFUND`, grilled), drift check + amber chip on Dashboard Health (watches only a supplier with `api_config.drift_threshold` set; DigiFlazz has none as of 2026-10-17, so its chip is off until the founder sets one in Middleware), Transaction Register + CSV export. **2026-09-15 addendum built:** `supplier_fee` field (the supplier's own deposit-side cut, e.g. Digiflazz's flat IDR fee — ledger now credits net, not gross) + Adjust/Void correction actions (never edits/deletes the append-only ledger, always a new `MANUAL_ADJUSTMENT` entry). **PR-2 built 2026-09-19 (ADR-110 PR-B)** — CHIP `.xlsx` settlement reconciliation (`payment_settlements` + `chip_settled_transactions` dedup guard) + Monthly Accounting Summary screen, verified against a real PekanGame CHIP settlement file. **2026-10-08 (ADR-083 addendum, live via #376):** neutral "Affiliate tier fees (from earnings, no cash)" line, also in the Envelope Ledger's rough P&L. **2026-10-10 (ADR-083 addendum, §16 item 78) PR-1 (#390), live via #393:** Envelope Ledger postings with typed lines (funding loan/share capital, transfer, expense, director-paid expense, repayment, distribution), per-director loan balances, whole-posting void, zero-balance archive; `supplier_transfers.transferred_on`; `LedgerEntry` append-only guard. **PR-2 (#391), live via #393:** month close on the Monthly Summary (exact operating-profit allocation per KL month from September 2026, prior-month drift carried forward, reopen of the latest close only), "where is the money" cash equation with a cash-account snapshot and gap note, operating profit + new lines (voucher breakage, admin-issued vouchers, supplier corrections, envelope expenses), FX rate as of month end; the manual "Allocate Monthly Profit" and the platform "Request Withdrawal" are gone. **2026-10-11 (ADR-083 addendum, item 75), live via #406 (2026-10-12):** the Monthly Summary's issued-voucher lines are split into "Admin-issued vouchers (no order)" (a profit cost) and "Failed-order store credit issued" (information only); a merge result is in neither. **2026-10-17 (ADR-083 addendum, items 115 and 121), live via #495:** COGS at funded cost with a bridge to the per-order cost and a drawdowns-not-in-COGS line; operating profit unchanged. **2026-10-18 (ADR-083 addendum, item 123), live via #500:** a manual order-profit correction is its own Transaction Register row (`order_profit_correction`, dated when made); the order row keeps the original credit. **2026-10-18 (ADR-083 addendum, item 120), live via #500:** the original CHIP settlement file is kept and downloadable, a file with nothing new is refused, old settlements can attach their original, and receipts are filed by accounting month and never orphaned or deleted. **2026-10-18 (ADR-125 PR 1a, §16 item 113), released via #514:** an MYR supplier's rate is 1.0 everywhere (COGS, supplier corrections, month-end position); the cash position splits each supplier's balance into the prepaid asset and a new `supplier_payable_sen` (owed to a pay-after supplier; the gap is unchanged); a sandbox order never writes a supplier drawdown **2026-10-24 (ADR-127, #543–#546, released 2026-10-25 via #554):** revenue/COGS/commission by `recognised_at`; Cash Position, wallet line, breakage and the Register read dated facts only, so a closed month cannot move (`FinancialScenarioTest` invariant 2); `AccountingPeriod::assertOpen` refuses dated writes into a closed month; CHIP `paid_on`/fee recorded and the fee shown on Settlements; membership revenue from the ledger. **ADR-127 PR E (#549, released 2026-10-25):** the Monthly Summary splits "Supplier payments — pay-after" (paying a penjoki or manual supplier what is owed) from "Supplier prepaid — top-up". **§16 item 132 (released 2026-10-25, #554):** from 1 October 2026 a foreign-currency supplier is costed at a moving weighted average (`MovingAveragePool`), so every ringgit sent is a cost in some month or still in the prepaid balance. | ADR-083, ADR-110 |
| Balance / Wallet Visibility | 🟢 Part A live (released 2026-09-29, #320) — standalone `/admin/balance` (deliberately not nested under Accounting — a Commerce-level item, since Part B grows it into operational monitoring, not bookkeeping): live-refreshable supplier balance per supplier + every Reseller's prepaid wallet balance (total + per-row), zero new backend, reuses ADR-046/073. Part B (learned "comfortable buffer" forecast) is grilled and designed as ADR-115, with the build parked (§16 item 28). **2026-10-18 (ADR-125 PR 1a), released via #514:** a manual supplier shows its funding-ledger balance ("Owed: MYR …" when pay-after) with no Refresh, and the reseller-wallet coverage total counts API suppliers only | ADR-046, ADR-073, ADR-115 |
| Action feedback (toasts, ADR-129) | 🟢 Live (2026-10-27, #575–#580, released via #581). One PrimeReact headless toaster — `admin/src/components/ui/toast.tsx` generated into `reseller/` by `scripts/sync-shared-files.mjs` — with a `notify` facade (`success`/`info`/`warn`/`error`/`stickyError`/`promise`) on the semantic `-surface`/`-ink` tokens. Swept across admin, middleware and the partner portal under the ADR-129 toast-vs-inline policy: a money-action failure is sticky (`duration: Infinity`); field validation, load/empty states and the order-modals' confirmations stay inline. A 200 whose body carries a failure is read, not assumed success (the tier-fee charge and payment-method-test fixes). Frontend-only; no backend change. | ADR-129 |

**PrimeReact migration (ADR-038):** complete 2026-08-29 — every hand-rolled
TailAdmin primitive in `admin/` migrated or deleted; `RichTextEditor` is the one
kept. Screen-by-screen history in `docs/build-log-archive.md`.

**Visual redesign (ADR-104):** token foundation, Orders and the app shell
(PR-1/2/2b, #233–#235, live 2026-09-17), then Reports (PR-3, re-scoped by the
2026-10-08 addendum, built as #381–#384, live via #385 on 2026-10-09). The other
~13 admin sections stay out of scope until the founder's own mockups exist
(decision 7). Before calling a screen "matched", audit it in a live browser
against the artifact's screenshots (ADR-104 file, and `docs/build-log-archive.md`
for the 2026-09-17 audit that found two dark-mode token bugs).

---

# 16. Open Items & Backlog

**Restructured 2026-10-12; every open item re-checked against git and
production on 2026-10-15 after release #449; items 98, 99, 106 and 107
checked again on 2026-10-16, after release #469; 107 closed after release #472;
106, 11, 96 and the payment state re-checked 2026-10-17 after release #476;
every open item re-checked 2026-10-26 after release #557; item 133 closed 2026-10-27 after release #571; items 134 and 137 closed and 138–140 added 2026-10-29 after release #589, with the payment state re-checked on prod; 140 closed, 141 (ADR-131) released and 142–144 added 2026-10-29 after release #599.** This list holds only **open** items. "Next up"
is the one place they are sorted, one line each; "Details" holds each item's
full text once, by number. Everything closed is one line under "Recently
closed", pointing to where the full story lives (`docs/build-log.md` /
`docs/adr.md`). Item numbers are kept stable so older build-log and memory
references still resolve. This list drifts easily: re-verify an "open" line
against real code or production before building on it.

## Next up

**Founder's rule (2026-10-08): clear the backlog below before any new
feature.** Every open item sorted by what it needs to finish. Group D was
reviewed item by item with the founder on 2026-10-14 (seven items dropped,
listed under Recently closed). Every open item re-checked against code and
git in the 2026-10-17 docs audit, and again after items 112 and 113 PR 1a
and PR 1b on 2026-10-18. Items 124–128 added 2026-10-19 after the docs audit
and the admin-loading investigation (#515, #516). Re-checked again
2026-10-26 after releases #554 and #557 (items 131 and 132 closed, 114
moved to group D). Re-verify before building.

**A. Buildable now: code, no new design**
- **149** Owned brand prices as the platform: no tier, no brand markup (ADR-061 2026-10-30 addendum, Proposed; grilled with the founder, code-traced against prod). Build: one `Affiliate` seam for (tier, brand markup) read by checkout, preview, catalog, voucher preview and the portal; admin/portal write guards; the tier-fee cron skips owned brands; per-brand catalog cache flush on `is_owned`/tier change; parity + invariant tests; fix the `max_markup_pct` clamp (null = no ceiling). Open for the founder afterwards: the RM0 OhahaStore tier subscription (leave or remove), and the "Owned Brand" tier name once FixFast Demo is deactivated (item 141).

**B. Needs a short grill / ADR addendum first**
(none: 149 grilled 2026-10-30, now in A)

**C. Founder actions (no code)**
- **147** OhahaStore go-live (founder). `ohahastore.com` is registered and active (2026-10-30), payments not approved. Owed: (a) the second `_vercel` TXT at GoDaddy for `www.ohahastore.com` (the value is in the portal's Domains row), then Check now: Vercel asks one TXT per hostname and the apex's does not cover `www`; (b) look in Vercel for another account or team that still holds `ohahastore.com` (an expired `*.ohahastore.com` certificate from February 2026 was served on `www`, which suggests the domain was on Vercel earlier) and remove it there; (c) after CHIP's written approval: Affiliates → OhahaStore → Manage → Approve payments (works since #607/#608); (d) OhahaStore has no bank details, so the monthly payout skips it until they are saved in Profile (item 146 would force them). See ADR-060's 2026-10-30 addendum.
- **138** Real-bank proof for release #589 (2026-10-29), partly done. Seen on
  prod: two orders reached CIMB's login through the bank grid and were
  cancelled there; a DuitNow order reached DuitNow and was cancelled by the
  user. **Still owed:** (a) one completed FPX payment (RM1) through a grid bank
  (CHIP's test mode accepts any `fpx_bank_code`, so only a real payment proves
  the customer lands at the right bank and the order is Paid); (b) one
  completed DuitNow QR payment (never completed yet); (c) run **Test** on `fpx`
  and `dnqr` in Payment Methods (their stored results are the old HTTP 400
  ones, from before #584). The FixFast test reseller's first real Add Balance (RM10 minimum, through a
  grid bank) exercises (a) and also produces a real receipt for CHIP. If (a)
  lands on CHIP's normal page or an error, revert the `FpxBankRedirect` calls;
  nothing else changed.
- **141** CHIP activation conditions (ADR-131), **released 2026-10-29 in #599**; the
  code is complete. What is owed is the founder's, then one reply to CHIP:
  (a) the company details are filled on prod (checked 2026-10-29): receipts print
  them, read live; (b) deactivate the `fixfastapp.com`
  test affiliate (Affiliates → FixFast Demo → Deactivate; its domain already
  cannot take a payment, and item 129's switch-over lists it too); (c) the
  browser checks not done: the "Save Limits", "Close account" and
  domain-approval submits with an MFA admin (covered by backend tests; the
  storefront notice, the admin Approve button and the affiliate portal badge were seen on prod; the first domain approval, 2026-10-30, failed with "The approved field is required", a double-encoded body fixed in #607 and released in #608, and has not been seen to succeed); (d) one reply to CHIP with attachments (portal screenshots from
  the FixFast test reseller, a receipt, the onboarding procedure PDF). Agreed
  wording: no officially onboarded resellers and one in onboarding; a failed
  reseller order returns to the Purchase Balance (the balance is that order's
  payment source) and unused balance is refunded to the original payer only on
  closure; `ohahastore.com` submitted as the first affiliate domain; (e) approve a
  branded domain in the admin only after CHIP's written approval (`ohahastore.com`
  has been in prod since 2026-10-30, active and not approved: item 147). The onboarding procedure, the written confirmations and
  any reseller documents live outside the repo (it is public). Extends items 24
  and 104 (naming); item 129 stays on hold.
- **11** Sentry (error tracking) is still not set up. UptimeRobot watches
  `/api/health`, the OpenWA gateway and `/api/health/whatsapp` (third monitor
  added and alert-tested by the founder 2026-10-14).

**D. Waiting on a trigger: don't build early**
- **114** Joki MLBB (ADR-126), built and released 2026-10-25 (#554),
  switched off, Epic up only. Launch gates: CHIP payments (approval of LWF
  Group's merchant account), item 129, the rank icons and the rates plus
  the Grading Express price (founder, with the penjoki).
- **129** Refund to the original payment method, on hold until CHIP answers
  (founder, 2026-10-23). The founder plans to go back to voucher-only once
  CHIP approves. Before payments go live for real customers, either the
  published policy is voucher-only again or this is built.
- **139** FPX bank-down status on the bank grid (ADR-130 decision 9), only
  if real payments show customers failing at a down bank. Advisory and
  fail-open from `fpxstatus.chip-in.asia`'s JSON feed; never a hard
  dependency of checkout.
- **128** Shared admin loading skeleton (through the admin `DataTable`), only
  if "Loading…" still bothers the founder now that #516 is measured (item 124
  closed 2026-10-22).
- **122** Store each combo leg's cost at delivery (`order_delivery_legs.cost_sen`),
  so a leg with no drawdown isn't costed at today's catalog in COGS; at the
  first Monthly Summary fallback on a combo leg.
- **81** Month-end pack and the open accounting policies (vouchers by
  reason, transfer fees in inventory cost), when an accountant is engaged.
- **4** Bot-host resize, when capacity calls for it.
- **28** Supplier buffer forecast plus a low-balance email alert (ADR-115),
  two weeks after launch (payments back on for real customers).
- **55** Test-order flag, with item 28.
- **59** OpenWA engine switch, with item 4 (Baileys stays until then, ADR-075
  2026-10-10 addendum).
- **74** Ask the external reviewer, before the first non-zero affiliate tier
  fee.
- **83** Give back an admin voucher's ledger debit on revoke/expiry, with the
  first admin-issued voucher.
- **85** Membership renewal reminder on WhatsApp (designed), at 10 active members
  or when members don't renew despite the email.
- **108** Add affiliate custom domains to the Turnstile widget automatically
  (ADR-123), when ~5 affiliate domains are active.
- **111** Turnstile on the admin and portal logins (ADR-019 2026-10-17
  addendum), at the first "Account locked" alert that isn't the founder, or
  when the portal opens to public signup (item 13).
- **104** Reseller wallet top-up KPIs (how often, how much), with item 28.
- **105** Batch `Order::cashCompensationSen()`'s wallet-refund lookup, when the
  Customer Detail page is slow for a big reseller (Pulse > 500 ms).

**E. New features: after the backlog** (each needs its own ADR + grill)
- **13** Self-serve reseller signup.
- **14** Assistant chat history.
- **24** Wallet pending top-up resume/cancel.
- **25** Portal "needs attention" aggregate.
- **57** Marketing Campaigns (ADR-118, designed).
- **94** Best-selling products per game in Reports.
- **146** Affiliate and Reseller onboarding (forced bank details, first-run steps). Founder has a reference video; needs its own ADR + grill.
- **148** `www` pairing for a branded domain, and a TXT note for each hostname in the Domains guide (ADR-060's 2026-10-30 addendum: register `www.<apex>` as a redirect at Vercel, remove it with the apex, an "Also add www" option, and the guide saying each hostname needs its own `_vercel` TXT). Check Vercel's add-domain `redirect` parameter first; needs the ADR-060 design revisited.
- **117** Storefront in more languages and currencies, to sell outside
  Malaysia. Long-term, not soon (founder, 2026-10-17).

## Details

4. **Bot-host capacity (+$20/mo resize)** — resize only when capacity actually
   calls for it. It is also the moment to revisit the OpenWA engine (item 59:
   Baileys stays until then). OpenWA now shares `pekangame-prod-lwf` with the API
   (ADR-114).

11. **External uptime monitor added 2026-10-10 (founder-reported, UptimeRobot on
    `https://api.pekangame.space/api/health`); error tracking (Sentry) is still
    not set up.** ADR-019's accepted deferral. Since 2026-09-29 `/api/health`
    reports DB + Redis + **Horizon liveness** (503 if any is down), which is what
    the monitor polls; Horizon's own LongWait alert (Plunk) can't fire if Horizon
    itself is dead, so the external poll covers that. Also since 2026-09-30:
    DigitalOcean's free Droplet monitoring (`do-agent`) on `pekangame-prod-lwf`,
    with Memory and Disk alerts (both >80% for 5 min, emailing the founder).
    Founder confirmed the monitor green on 2026-10-12 (100% over 7 and 30 days,
    5-minute checks, up 1d 17h). The founder's Test Notification reached them the
    same day.
    **2026-10-14:** a second UptimeRobot monitor on the OpenWA gateway,
    `https://bot.pekangame.space/api/health` (founder, green). It catches the
    OpenWA process down, not a WhatsApp session that is disconnected while
    the process runs; item 95 covers that.

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

28. **Supplier balance comfortable-buffer forecast — [ADR-115](./adr/ADR-115-supplier-balance-comfortable-buffer-forecast.md),
    fully designed + grilled, build deliberately parked.** Digiflazz-only,
    rolling lead-time-window-sum percentile (not a flat daily rate — a
    real Friday MLBB-event spend pattern would otherwise be
    underestimated), 2-day lead time, IDR+MYR side by side, info-only
    (Balance page detail + a Dashboard chip mirroring the existing
    funding-drift chip), cold-start falls back to the founder's own
    manual threshold. No automatic trigger to start building — the
    founder will say when, typically once real (non-testing) order
    volume exists. **Trigger set 2026-10-14 (founder): two weeks after
    launch**, i.e. payments back on for real customers; the email ships with
    the forecast, not before. **2026-10-13 addendum:** the build also adds a
    low-balance email to every active admin through the ops-alert path
    (`BackupFailureAlerter`, Plunk), throttled once a day per supplier, when
    the balance crosses the buffer. Since 2026-10-14 Digiflazz's
    `low_balance_threshold` is set on prod (IDR 10,000,000, about RM 2,280,
    founder), so the Dashboard "low balance" chip and the log warning work;
    with the balance at IDR 2,285,108 that day the chip shows until the
    balance passes the threshold.

55. **Test/internal order flag — grilled 2026-09-30, deliberately parked**
    (ADR-083 2026-09-30 second addendum, `docs/adr.md`). The founder's own
    self-purchases on the live storefront count as real "Sales revenue" —
    real gap, but no urgent trigger to build: at ~30 orders ever, manually
    remembering which were self-testing still works, and the platform's own
    operating model (founder hand-types one journal into the external SaaS
    monthly, auditor only at year-end) already gives a natural correction
    point. **Not** every current order is the founder's own testing — a real
    reseller's real customer order (e.g. Naeem Industries) sits in the same
    table, so any future build here needs per-order judgment, never a
    blanket assumption or an email-based auto-detect. Same trigger as item 28 (ADR-115):
    two weeks after launch — revisit together. **2026-10-10:** kept out of the ADR-083 Envelope Ledger addendum
    (item 78), to follow it as its own addendum. Whether an order can be
    tagged after its month is closed now depends on that addendum's
    decision 12.

57. **Marketing Campaigns — [ADR-118](./adr/ADR-118-marketing-campaigns.md), fully designed + grilled + stress-tested,
    build deliberately parked.** New `Campaign`→`PromoCode` family for paying KOLs/influencers
    without requiring them to run their own Affiliate storefront: multi-use discount codes
    (5% flat off Standard price, benchmarked against a real competitor and this platform's own
    margin data), locked per redemption to game ID + customer email/phone, never stacks with
    Member pricing but does stack with `Voucher`, per-code budget cap with a reserved/restored
    lifecycle (mirrors `Voucher`'s own), snapshot-at-creation attribution (never a live-rewriting
    tag), and an optional platform/affiliate funding split for third-party affiliate-brand
    campaigns. No automatic trigger to start building — founder will say when, once a real KOL
    partnership is ready to go. **Build from ADR-118 *plus* its 2026-10-02 review addendum
    (R1–R16)**, which supersedes the original wherever they conflict. That addendum covers
    discount storage, the resend split, the per-order loss guard, the fail-closed budget, the
    once-per-customer limit, brand scope, accounting treatment, and the UI under Customers.
    Before building, confirm R14's contra-revenue treatment with the external accounting
    reviewer.

59. **`ENGINE_TYPE=baileys` runs both OpenWA sessions — decided 2026-10-10: Baileys
    stays until the droplet is upgraded** ([ADR-075](./adr/ADR-075-reseller-bot-channel.md)'s
    2026-10-10 addendum). Waiting on a trigger: the host resize (item 4), a WhatsApp
    warning or ban, or `reseller-bot` volume that makes a ban cost more than the
    resize. Before any switch, check whether OpenWA can set the engine per session
    (`ENGINE_TYPE` is global, so a switch would also move `customer-support`), and
    plan the QR re-pairing of both numbers.

74. **Ask the external reviewer before the first non-zero affiliate tier
    fee** (ADR-083 2026-10-08 addendum). The Monthly Summary shows the fee
    as a neutral line; the reviewer decides whether it is revenue or a
    reduction of commission expense, and whether it needs an e-Invoice to
    the affiliate. Both tiers are RM0 today (1 entry ever, RM0.00).

81. **Month-end pack for the external accountant — waiting on an
    accountant (founder, 2026-10-17).** The founder has no accountant yet.
    ChatGPT's answers to the questions below (2026-10-17) were used only to
    decide item 115 (COGS at real, funded cost). The rest waits for a real
    accountant: the pack's contents and format (it suggested one ZIP a month
    with six folders), revenue and COGS by **delivery** date instead of
    `paid_at` (paid-but-undelivered at month end shown as a liability; now
    done by item 131 / ADR-127 on 2026-10-25, ahead of the accountant because Joki needed it),
    vouchers treated by reason (compensation = liability, promotion =
    revenue reduction), and whether transfer fees belong in inventory cost.
    Storing the CHIP file and dated folders went to item 120.
    - The founder's goal (2026-10-10): the accounting pages are a record
      kept so that each month one download hands the outside accountant
      everything, and nothing is reported missing.
    - Today each piece downloads separately, and four gaps remain:
      - The Monthly Summary and the month-close snapshot have no export.
      - Receipts download one at a time.
      - The original CHIP settlement `.xlsx` is not kept (only its
        filename and the matched rows).
      - A receipt is optional on an envelope expense, so one can be
        missing without notice.
    - Proposed: one "Month-end pack" zip per closed KL month, holding:
      - the Transaction Register and Envelope Ledger CSVs;
      - the Monthly Summary and close snapshot;
      - every receipt from that month;
      - the stored CHIP settlement files;
      - a warning list (e.g. expenses without a receipt);
      - a checklist of documents kept outside the system (bank
        statements, Digiflazz deposit statement, original invoices,
        company and payroll/tax documents) for the founder to tick.
    - Storage on `r2_accounting` (founder, 2026-10-10):
      - Today every upload is `->store('<category>')`, a random name with
        no date (`accounting/supplier-transfers/`,
        `accounting/budget-envelopes/`, `wallet-topup-receipts/`), and the
        CHIP `.xlsx` is parsed but never stored
        (`PaymentSettlementController::store`).
      - Proposed: new uploads go under `<category>/YYYY/MM/`, by the
        accounting date (`transferred_on`, posting date, settlement
        period), not the upload date, so a late-uploaded September
        receipt sits in September. Year/month only; no day folder (the
        date is in the DB).
      - The CHIP settlement `.xlsx` is stored the same way.
      - Existing files stay where they are: the app finds files by the
        stored path, so moving them means a prod data patch for no gain.
    - Before the grill: get the accountant's own monthly document list, so
      the pack follows what they actually ask for.
    - Also ask the accountant (added 2026-10-17, from ADR-125's addendum and
      ADR-126):
      - how to present supplier balances that are payables (pay-after
        suppliers and penjoki) next to prepaid deposits;
      - whether an order paid in one month and delivered in the next
        should take its supplier cost in the month it was paid (FX
        true-up scoped by the order's `paid_at`);
      - what the COGS line should mean once it uses the real cost
        (`effectiveCostPriceSen()`, item 115) rather than the checkout
        estimate.

83. **An admin-issued voucher's ledger debit is never given back — waiting on
    the first admin-issued voucher** (ADR-024 2026-10-12 addendum, founder's
    call). A revoked or expired admin voucher keeps its full `voucher_issued`
    debit, so the platform ledger understates by its unspent part. No screen
    reads that balance, and the Monthly Summary already carries breakage.
    Production had 0 admin-issued vouchers on 2026-10-12. Grill revoke and
    expiry together (expiry needs a scheduled job) when one exists.

85. **Membership renewal reminder on WhatsApp — designed, waiting on a
    trigger** (ADR-068 2026-10-13 addendum, decisions 15-19). Trigger: 10 active
    members, or the founder sees members not renewing despite the email,
    whichever comes first. Design: an optional WhatsApp number in the subscribe
    panel and on the `/membership` dashboard (filling it in is consent; STOP
    stops it), stored as `memberships.whatsapp_phone`; email stays and
    WhatsApp is added; `STOP RENEW` stops membership reminders only, so
    receipts continue; sent through `CustomerNotificationService` with a
    nullable `membership_id`; a "WhatsApp" card on the admin member page.

94. **Best-selling products per game in Reports — new feature, needs an
    ADR-104 addendum** (founder, 2026-10-13). Reports has a per-game breakdown
    only; nothing shows which product inside a game sells most (package names
    appear only per order in the export and per customer in Customer
    Analytics). Count by what the customer bought, not by SKU: the key is
    game + `denomination`, else `catalog_code`, else the package name; the
    label is the storefront's display name. Why not `package_id` or name:
    on prod (2026-10-13) 520 groups of active native packages share a game
    and denomination (several supplier SKUs, one storefront entry), 29 of 32
    paid orders sit on such a package, a resend swap rewrites
    `orders.package_id` to the SKU sent, a combo is named differently from a
    native package of the same total and gets a new row per version (a
    combo is recreated, never edited; item 66 parked), MLBB Malaysia has
    two combos per denomination since 2026-10-13, and names drift (3
    denomination groups differ only by spelling). For the grill: an
    optional drill-down per SKU/combo version, and whether to snapshot the
    product key on the order at checkout so a resend swap can't move it.

104. **Reseller wallet top-up KPIs (founder, 2026-10-15) — design not started.**
    Nothing shows how often or how much Resellers top up their wallets. Today
    there are only current balances (`/admin/balance`), the total owed
    (Dashboard), one reseller's ledger (wallet modal) and single top-up rows
    (Accounting → Transactions); Reports' reseller section counts order spend,
    not top-ups. Not part of item 28: ADR-115 forecasts supplier drain from
    orders, blended over channels (its decision 3), while top-ups are money
    coming in. Closest related: ADR-083 decision 8 (wallet liability vs
    supplier balance, raw numbers until real reseller volume). Idea, to grill:
    per reseller and period, top-up count, total, average, last top-up and
    average days between, on `/admin/balance` or Reports; source is
    `ledger_entries` type `wallet_topup` (CHIP and manual admin credit both
    write it; `reference_type` tells them apart). Prod 2026-10-15: 2 resellers, 3 paid top-ups (RM464.01), 2
    expired, 2 failed, too little data to read a pattern from. Trigger: with
    item 28 (two weeks after launch).

105. **Customer Detail Profit Analysis: one query per order (found 2026-10-15, item 103).**
    `Order::cashCompensationSen()` sums the order's wallet refunds with its
    own query, so Profit Analysis costs one query per realised order of that
    customer (`ponytail:` note in `CustomerAnalyticsService::profitAnalysis()`,
    pinned by `CustomerAnalyticsListingTest`'s query-count guard). Fine today
    (biggest prod customer: 21 orders). The fix is in the money model, shared
    with `Admin\OrderController` and Accounting's `RecognisedRevenue`: read a
    preloaded refund when the caller loaded them (`Order::walletRefundEntriesFor()`
    already batches the lookup). Needs the money-bug code trace (every reader
    of `cashCompensationSen()` / `compensationAmountSen()`), not a one-site
    patch. Trigger: Pulse shows the detail endpoint over 500 ms.

108. **Turnstile widget hostnames follow affiliate domains — waiting on a
    trigger (founder, 2026-10-16).** The Game ID Checker's Cloudflare
    Turnstile widget ("PekanGame Game ID Checker", site key
    `0x4AAAAAAFYZZNQMJTpU31qh`) only renders on hostnames listed on it. On
    2026-10-16 it lists 3 of its 10: `pekangame.com`, `pekangame.space`,
    `fixfastapp.com` (the one active affiliate domain); subdomains are
    covered. Until this is built, a new affiliate custom domain must be
    added to the widget by hand in the Cloudflare dashboard, or the checker
    on that domain shows a Turnstile error (fails closed; checkout is
    unaffected).
    - **Trigger:** about 5 active affiliate domains, or the first time one
      is missed.
    - **For the grill:** update the widget's domains through Cloudflare's
      Turnstile widget API from the affiliate custom-domain attach and
      detach flow (ADR-060), with a token scoped to Turnstile only; what
      happens when the API call fails (retry, admin alert). Re-check the
      10-hostname limit and the Enterprise "any hostname" option against
      current Cloudflare docs before designing past 10 (several widgets
      would need a per-host site key on the storefront).

111. **Turnstile on the login forms — parked 2026-10-17 (founder), waiting
    on a trigger.** Why it waits (ADR-019 2026-10-17 addendum): 1 admin with
    MFA, 3 invite-only portal accounts, no attack in the logs, and per-IP +
    per-account limits already in place. A password lockout now emails the
    admins, which is the signal. Turnstile Free's limits (20 widgets, 10
    hostnames each, unlimited challenges) don't block it: `admin.` and
    `reseller.` are covered by the existing widget's `pekangame.space`.
    - **Trigger:** the first "Account locked" alert that isn't the founder,
      or public portal signup (item 13).
    - **For the grill then:** fail-open vs fail-closed when Cloudflare is
      down (fail-closed can lock the founder out of admin), E2E test keys,
      and whether checkout needs it too.

114. **Joki MLBB (ADR-126; re-traced and re-grilled 2026-10-23).** The
    2026-10-23 addendum replaces the earlier summary here. Joki follows the
    Membership pattern (`joki_enabled` only on an owned brand, plus a global
    switch), and the game is hidden from every catalog. Rank model, rates,
    route packages and payout share (default 100%, snapshotted) are all
    set in the panel, in whole RM. `PricingBasis::Fixed` locks the cost:
    resend keeps it, Bulk Markup skips joki, and Retry returns the order to
    the pool. New orders wait on an explicit pool supplier. Assign happens
    in the admin under a lock, with an audit row. The post key gains the
    supplier, Delivered re-checks the supplier, and the stale clock is
    explicit (`manual_activity_at`, SLA max 3 days). Each login keeps its
    own wipe ceiling (joki 30 days). There is an Admin → Joki page and a
    Dashboard card, joki is out of "Need Action", and `/orders` gets a
    type filter. Five PRs (ADR-126 addendum, "Consequence to track").
    Launch gates (item 131 is done): CHIP payments, item 129, rank icons
    from the founder, and rates confirmed with the penjoki.
    **PR 1 (decisions 17, 18, 20, 22) built 2026-10-23, merged to
    `staging` via #533.** PR 2 is split in two: **2a** (Fixed basis,
    pool, Assign, payout locks, hidden game, F1) built 2026-10-23;
    **2b** built 2026-10-23 (rank model and draft rates,
    `JokiPricingService`, joki checkout and preview through the one
    checkout, `joki_orders`, `/api/joki/catalog` + `/quote`, the joki
    switches, `JokiCatalog::ensure()`). **PR 3** built 2026-10-23
    (`.progress` and the admin progress update, Track Order climb and
    current rank, paced WhatsApp progress to opted-in customers). All
    four merged to `staging` (#533–#536). **PR 4a** built
    2026-10-24 (the admin backend: `JokiBoard`, `/api/joki-admin/*`, joki
    out of Need Action and the Manual Queue with a "needs voucher" count
    on the Joki card, the `/orders` type filter, penjoki creation).
    **PR 4b** built 2026-10-24 (Admin → Joki with Orders, Penjoki and
    Settings; Assign and progress on Order Detail; Record payment; the
    Dashboard card; the `/orders` type select; "Sell Joki" in the brand
    form; the penjoki tick on supplier create). PR 4a and 4b merged to
    `staging` via #538 and #539. **PR 5** built 2026-10-24
    (the `/joki` page with the interactive rank calculator, the "Joki
    Rank" menu and sitemap entry, the voucher preview priced like
    checkout, a joki e2e golden path), merged via #540. Every planned PR
    is released (2026-10-25, #554); what remains is the launch
    gates. Founder to set the
    Grading Express price, confirm the rates, and supply the rank icons.
    2026-10-25: joki is offered from Epic up; the four lower tiers are
    removed (ADR-126 2026-10-25 addendum, #552, released via #554).

117. **Expansion outside Malaysia: languages and currencies (founder,
    2026-10-17).** The storefront is English-only and prices are MYR-only
    (CHIP, a Malaysian gateway, is the one payment gateway;
    `PaymentGatewayFactory` stays for a second, regional gateway). The
    founder wants more languages together with more currencies when the
    business expands abroad, not soon. Needs its own ADR and grill:
    display currency vs charge currency, FX source, which gateway per
    region, per-brand language, and how ledger and reports stay in MYR.

122. **Store each combo leg's cost at delivery (`order_delivery_legs.cost_sen`)** —
    `FundedCostOfSales`'s leg-level fallback (a combo leg with no drawdown)
    still uses today's catalog cost, because no per-leg cost is stored at
    delivery. Prod has none as of 2026-10-17. Store `cost_sen` on each leg
    at delivery or settlement when `FundedCostOfSales` first falls back on a
    combo leg in the Monthly Summary (ADR-083 2026-10-17 addendum).

128. **Shared admin loading skeleton (2026-10-19; item 124 closed 2026-10-22, now the founder's call).**
    The admin has no skeletons: 53 plain "Loading…" texts across 44 files,
    and the `DataTableLoading` primitive in `components/ui/datatable.tsx` is
    unused by the list pages. If still wanted after #516 is measured, build
    one skeleton in the admin `DataTable` (PrimeReact `Skeleton`, ADR-104
    tokens) so every list gets it, not a per-page component; the admin
    counterpart of the storefront's ADR-071 primitive. A visual change, so
    it needs the founder's sign-off (ADR-104 decision 7). A browser data
    cache stays out unless measurement demands it: stale data on money
    screens is a real risk.

129. **Refund to the original payment method (founder, 2026-10-22) —
    revises ADR-004, needs its grill and a code-trace pass.** CHIP's
    merchant review for LWF Group Sdn. Bhd. asked for a refund policy that
    allows a refund to the original payment method when a paid order is not
    delivered. The founder decided: after retries fail, the customer chooses
    a store voucher for the full amount (immediate) or a full refund to the
    original payment method within 7 working days. A delivered order is not
    refundable. The policy text is admin content (`/refund-policy`, item
    above in §15 Settings). Not designed yet: how a refund is done (CHIP's
    refund API or its dashboard) and recorded so that nothing silently
    disagrees: order and delivery state, the Transaction Register, CHIP
    settlement reconciliation (ADR-110), the cash equation (ADR-083), Reports
    and the order page. A money-critical ADR: grill plus the code-trace pass
    (root AGENTS.md). **Due before** payment methods are switched on for the
    new account; until then no refund can be owed. At that switch, also:
    move the CHIP credentials in `/middleware` to LWF Group's account, and
    deactivate the test affiliate on `fixfastapp.com` (the CHIP reply says it
    will be off before payments go live).
    **On hold (founder, 2026-10-23):** the refund wording was published for
    CHIP's review, and the founder intends to change the policy back to
    voucher-only once CHIP approves. So the trigger is CHIP's answer. If
    the policy changes back, ADR-004 stays as it is and this item closes,
    but an order paid while the refund wording was published may still be
    owed a refund under it. If the policy stays, build this before payments
    go live for real customers. The switch-over steps above apply either way.

## Recently closed (full detail in `docs/build-log.md` or, before 2026-10-15, `docs/build-log-archive.md`; why in `docs/adr.md`)

- **145** 2026-10-30: the affiliate monthly auto-payout (ADR-132), #605, released in #608. The platform opens one withdrawal ticket per affiliate per Malaysia month and the manual request is gone. On prod after the release: three affiliates, none at RM10 (balances 0, 15 sen, 0), no open ticket, `affiliate-payout-open` scheduled, `/api/health` ok. Follow-ups: items 147 and 149. Details: build-log 2026-10-30.
- **143** 2026-10-30: closed as not needed (founder). The partner portal footer keeps its hard-coded "LWF Group Sdn. Bhd. (202601038896)"; the company details do not change, and the footer also shows on the login page, where reading them from the admin setting would need a new public endpoint. Reopen only if the company name or SSM number changes.
- **142** 2026-10-30: the receipt layout and the LWF logo, built (ADR-131 2026-10-30 addendum, #603, released in #608). The other three parts (an admin download of a closed reseller's receipts, a stored snapshot of the company details, an automatic email) were closed as not needed (founder). Reopen each only at its trigger: an accountant's request or a change of company details, the first closed real reseller, a reseller asking for the email.
- **144** 2026-10-30: the failed-payment status page labels fixed (amount reads "Amount Due" until paid, one badge, the payment method as a label), in the same PR as the smaller FPX bank tiles and the removal of the "Other bank" link (ADR-130 2026-10-30 addendum). Details: build-log 2026-10-30. Released in #608.

- **140** 2026-10-29: a failed payment attempt is final (ADR-110 2026-10-29 addendum), #591 and #592, released in #599. Seen on prod the same evening: the founder cancelled one FPX and one DuitNow QR payment at the bank; both orders were Failed within 12 s and 20 s (no voucher, none left Pending) and the "Payment Not Completed / Try Again" page showed. The page's own copy has defects, item 144. Details: build-log 2026-10-29.

- **137** 2026-10-29: FPX bank-direct (ADR-130), #587 (checkout, `/joki`) and #588 (membership, partner-portal wallet top-up). The customer picks the bank on our page; one helper applies it where the checkout URL leaves the API; nothing is stored. Released 2026-10-29 in #589. Bank-down status split out as item 139; the real-bank proof is item 138. Details: build-log 2026-10-29.
- **134** 2026-10-29: the "BNM Compliant • 256-bit SSL" line removed from the shared payment picker (founder's decision), #588. Details: build-log 2026-10-29.
- **136** 2026-10-27: action feedback (toasts) across admin, middleware and the partner portal (ADR-129), #575–#580, released via #581. One PrimeReact headless toaster, one shared `components/ui/toast.tsx` (generated into `reseller/`), a `notify` facade, and the toast-vs-inline policy swept across every action-outcome screen; money-action failures are sticky. Details: build-log 2026-10-27.
- **133** 2026-10-27: partner portal visual redesign, layer 1 (ADR-128) plus the shared component kit: #562–#565, #567, #568, reviewed page by page (Reseller + Affiliate, light/dark, desktop/phone; 12 admin pages), released via #571. Layer 2 stays as items 24 and 25 plus a new ADR. Details: build-log 2026-10-27.
- **135** 2026-10-26: the Button severities `info`/`success`/`warn`/`danger`/`help` were mapped off Tailwind's stock colours onto the design system's `-surface`/`-ink` status pairs (soft fill + a border that intensifies on hover). The founder chose soft over solid when asked during shared-kit PR 2; the portal's `color-tokens.test.ts` (no `dark:` colour overrides) ruled out keeping the stock solid fills. ADR-128 2026-10-27 addendum; build-log 2026-10-26.

- **132** 2026-10-25: IDR supplier COGS at a moving (perpetual) weighted average from 1 October 2026 (`MovingAveragePool`, `SupplierRowValuer`; `CashPositionService` reads the pool's month-end value). The two-month invariant was red first (October gap −33 sen). Prod October did not move (no deposit since September). ADR-083 2026-10-25 addendum; #551, released 2026-10-25 (#554).
- **131** 2026-10-25: financial truth from dated facts (ADR-127). Revenue, COGS and commission at `recognised_at`, closed months that cannot move (`AccountingPeriod::assertOpen`), CHIP `paid_on`/fee recorded, dated voucher restores and revokes, one store credit, profit by recognition on Reports and the Report Assistant, and `FinancialScenario` with 11 cross-page invariants. PRs A–E (#543–#546, #548, #549), released 2026-10-25 (#554).
- **96** 2026-10-25: OpenWA after the v0.25.0 upgrade. The `400` fix and the encrypted nightly OpenWA backup were live since #526 (2026-10-22); "Backup Now → OpenWA" ran 2026-10-22 and every nightly run passed its restore test. With the founder's go-ahead: the 10 plaintext database archives on R2, `~/OpenWA-v0.23.7` (with its `.env.bak-*` files) and `~/openwa-backups/` deleted; `MESSAGE_RETENTION_DAYS=90` set and OpenWA restarted (both sessions `ready`). Restore drill: the password from the password manager decrypted the 2026-10-25 database archive. Recheck the two `400` phrases on each OpenWA upgrade. Details: build-log 2026-10-25.
- **71** 2026-10-25: GSC recheck (data to 16/10). The duplicate and canonical reasons ADR-120 targeted are gone. Left: 3 "Page with redirect" (`http://`, `www` → apex, as intended) and 42 "Discovered – currently not indexed", none crawled yet; robots, canonicals and the sitemap (50 URLs, read 25/10, Success) checked fine, so it is crawl priority on a new domain. Indexing requested for 5 top pages. `/order/call-of-duty-mobile` is `noindex` because the game is inactive (since 2026-10-18), which is correct. Details: build-log 2026-10-25.
- **130** 2026-10-23: the cause was not health judging an unfunded supplier. A refused connection was thrown out of `checkBalance()`, which aborted the daily balance sweep (Digiflazz, after Gamevion, wasn't refreshed) and 500'd Refresh Balance, the credential probe and Developer Tools. The breaker decorator now returns it as a `CONNECTION_FAILED` response for `checkBalance` only (counted once; order calls still throw), and the daily refresh isolates each supplier, failing the task only on a real bug. ADR-069 2026-10-23 addendum; details: build-log 2026-10-23. Released 2026-10-25 (#554). Prod hit the original failure once more that morning (a Gamevion SSL timeout at 00:00 UTC, before the release).
- **124** 2026-10-22: Releases #514 and #517 verified on the box; #516 measured. DevTools on `/admin/orders` shows 0 OPTIONS requests. Pulse, 3 days: 15 slow requests spread evenly, not clustered at the scheduler ticks, none on admin endpoints. Details: build-log 2026-10-22.
- **126** 2026-10-22: Login Top-up spec written from ADR-124/125 and the code: §6.24 (LOGIN-1..7, MSUP-1..7), §7.6 flow, §8 Supplier columns plus OrderCredential, CredentialReveal, WhatsAppGroup, SupplierLoginLink and CustomerNotification, and §11 now points at §16 group E with its shipped lines removed. `reply_sla_minutes` turned out to live in `api_config.manual`, not a column.
- **127** 2026-10-22: Build/live status kept to §15 (headline §14). Removed from the Glossary, §6 (AUTH-7, the §6.7 status column now names the deciding ADR, RPT-4, THM-1, MUI), §7.3, §8, and the ADR-046 and ADR-125 banners. `adr.md`'s rule now covers banners and the Glossary. §10's integration status and §11's "Shipped" lines are left for item 126.
- **125** 2026-10-22: Branch protection. The `ci.yml` header was right: none existed. One ruleset now protects `staging` and `main` (PR-only, merge commits, the deploy job's eight checks required), and `ci.yml` dropped its docs-only `paths-ignore`. The repo stays public on purpose (ADR-037 2026-10-22 addendum).
- **113** 2026-10-19: Login Top-up, Roblox and eFootball (ADR-125). Manual suppliers (`driver`/`funding_mode`), admin manual packages with audited agreed cost, login checkout with consent, Manual Queue fallback, supplier WhatsApp group bot (`.done`/`.gagal`/`.pending`) with short login links (`/s/<token>`), Partners → Suppliers screen. Released via #514 (2026-10-19). Details: build-log 2026-10-18 and 2026-10-19.
- **112** 2026-10-18: customer account credentials base (ADR-124). Encrypted `order_credentials` table, 15-minute sweep, super_admin reveal/replace with MFA step-up and audit row, Customer Login Policy settings. Released via #514 (2026-10-19); written at checkout since item 113. Details: build-log 2026-10-18.
- **120** 2026-10-18: accounting source files are kept. The CHIP settlement `.xlsx` is stored with its SHA-256 under `accounting/chip-settlements/YYYY/MM/` (by `date_to`) and downloadable. A file with nothing new (same file or a re-export) is refused, and old settlements #1–#4 can take their original if dates and totals match exactly. Every receipt goes through one `AccountingFileStore`: filed by accounting month, deleted only if its save fails (two paths used to leave orphans), and a transfer correction no longer deletes the receipt it replaces. Live via #500 (2026-10-18). R2 lifecycle confirmed safe (only the default multipart-abort rule). The founder attached the originals of #1–#4 the same day (verified: file exists, SHA-256 matches, figures unchanged). ADR-083 2026-10-18 addendum (keep accounting source files).
- **123** 2026-10-18: the Transaction Register showed order PG-KWKBUHNDMKQT's Net as −RM0.10 (only its manual ADR-105 correction, via `pluck`) instead of the +RM0.37 credit plus the −RM0.10 correction. The order row now keeps the automatic credit, and each manual platform `order_profit` correction is its own `order_profit_correction` row dated when made (screen, filter and CSV). Per order the rows sum to the ledger. Prod: the only affected order of 37. Monthly Summary and Month Close don't read the Register; September is unchanged. Live via #500 (2026-10-18). ADR-083 2026-10-18 addendum.
- **121** 2026-10-17: a realised combo with no real-cost basis is costed at `revenue − platform_profit − affiliate_profit` (the cost its profit used, set from the catalog at delivery or settlement), no longer today's catalog. Customer Detail, Order Detail, the Transaction Register, the Orders export and the Monthly Summary bridge all read it. Prod: #7 184 → 186, #28 1074 → 1065. A reader change only. Live via #495 (2026-10-17). ADR-083 2026-10-17 addendum (same-day correction).
- **115** 2026-10-17: Monthly Summary COGS is at funded cost: each recognised order's deposit drawdown × the MYR paid per unit (IFRIC 22 / IAS 2; Digiflazz's deposit can't be withdrawn). It is computed by `FundedCostOfSales` over `RecognisedRevenue`'s orders, rounded once per supplier. An order with no drawdown or rate falls back to its own cost and is counted on the page. The FX true-up left operating profit and became the bridge lines "COGS at per-order cost" + "Deposit rate vs market rate"; "Supplier drawdowns not in this month's COGS" shows the rest. Operating profit unchanged (prod check: September RM4.64, October RM3.64). Live via #495 (2026-10-17); September re-closed the same day (close #2, drift 0). ADR-083 2026-10-17 addendum; details: build-log 2026-10-17.
- **Login lockout alert** 2026-10-17: when 10 wrong passwords lock an admin or portal account, every admin gets a `[PekanGame Security] Account locked` email with the email and last IP. Only real accounts trigger it, so made-up emails don't. Before this, only a lock from wrong MFA codes alerted. It came out of item 111's grill (Turnstile parked, ADR-019 2026-10-17 addendum). Live via #495 (2026-10-17); no lockout has sent the email yet. Details: build-log 2026-10-17.
- **119** 2026-10-17: the backend deploys only through CI's `deploy` job again (ADR-066 decision 5). Since the ADR-114 move, `FORGE_DEPLOY_HOOK` had pointed at the destroyed old site and the new site had Forge "Push to deploy" on, so it built each push before CI finished. The founder re-pointed the secret, turned Push to deploy off, and removed the old Forge server and the unused `DIGITALOCEAN_ACCESS_TOKEN` secret. Verified on release #490: one release directory, built after the CI `deploy` job. Details: build-log 2026-10-17.
- **106** 2026-10-17: a supplier that fails inside a price-sync run is no longer hidden (ADR-015 2026-10-17 addendum). The run is `partial` (warn tag) with `stats.failed_suppliers` listed in admin Price Sync; `suppliers.last_price_sync_at` records each supplier's last good sync, and `app:check-price-sync-staleness` (scheduler, every 15 min) emails admins once when a supplier passes `price_sync_stale_alert_minutes` (Middleware → Suppliers; blank = 90, 0 = off) and once when it recovers. Live via #490 (2026-10-17). Details: build-log 2026-10-17.
- **118** 2026-10-17: the Report Assistant prompt calls an order's `cost_price` the checkout estimate and sends a past order's margin to `platform_profit` (already ledger-sourced, so it carries ADR-111's real cost), never to `cost_price`. Prompt only; the view is unchanged (ADR-125's addendum still governs the `effective_cost_price` column it will add). Live via #485 (2026-10-17). Details: build-log 2026-10-17.
- **115 (part)** 2026-10-17: Customer Detail profit analysis and the Transaction Register (screen and CSV) show each realised order's `effectiveCostPriceSen()`, the cost its profit used, for Delivered as well as settled partial orders. Before, Delivered rows showed the checkout estimate, so revenue − cost − commission − fees did not match profit in the same row. COGS stays open as item 115. Live via #485 (2026-10-17). Details: build-log 2026-10-17.
- **110** 2026-10-17: security check follow-ups. The API sends HSTS (HTTPS only, no `includeSubDomains`), `Referrer-Policy` and `Permissions-Policy` (`SecurityHeaders` middleware); admin, storefront and portal send `Referrer-Policy` and `Permissions-Policy`, the portal now sends `nosniff`, and none sends `x-powered-by`; a supplier-transfer correction receipt is capped at 5 MB like its siblings. The audit's "login throttle is per IP only" was wrong: `AccountLoginThrottle` (ADR-019 2026-09-29 addendum) already locks an email for 15 minutes after 10 failures from any IPs, on admin login, MFA code, step-up and affiliate login, so nothing was added. Live via #485 (2026-10-17). Details: build-log 2026-10-17.
- **109** 2026-10-17: admin, storefront and portal load fonts with `next/font/local` from committed latin woff2 files (the same variable files Google Fonts serves), so no build downloads fonts and the CI `playwright` font flake has no cause left. Verified on local production builds: every face `loaded`, 0 requests to Google, glyphs match prod. Live via #485 (2026-10-17). Details: build-log 2026-10-17.
- **116** 2026-10-17: the Failed-order status card no longer promises "a voucher for the full amount"; it now says "a voucher here for what you paid, excluding the payment fee", matching `OrderSettlementService` (`final_amount − transaction_fee` for every order the card can reach, since wallet and test orders are out of scope). The only copy with that promise. Live via #485 (2026-10-17). Details: build-log 2026-10-17.
- **107** 2026-10-16: public Game ID Checker (ADR-123, #471, released via #472). Verified on prod: five families listed, `/check-id` and every family page 200, six checker URLs in `sitemap.xml`, no `noindex` on the primary brand; a check with the founder's own MLBB test account → "Prime." → Mobile Legends Malaysia, the repeat a cache hit, `player_validations` untouched. Open follow-up: item 108 (widget hostnames). Details: build-log 2026-10-16.
- **98** 2026-10-16: Scheduler liveness (ADR-122, #449). After the first 00:00 UTC run all 19 tasks (11 critical, 8 routine) had a heartbeat with `last_ok_at` that day and no failure; `/api/health` 200 with `scheduler: true`. Longest daily task 12.3 s (backup cleanup); the 60-minute grace is reviewed after a week (ADR-122 consequence). Details: build-log 2026-10-16.
- **99** 2026-10-16: Digiflazz `[83]` price-list rate limit (ADR-069 2026-10-15 addendum, #449). Since the deploy (2026-10-15 09:08 UTC): 40 `listProducts` calls, 0 exceptions (was 42 of 254), no rc 83 in `laravel.log` (last 2026-10-15 03:00, before the deploy), slowest call 19.5 s succeeded. Details: build-log 2026-10-16.
- **103** 2026-10-15: Customer Analytics reads that scale (ADR-049/050 2026-10-15 addendum): one SQL aggregate for stats, list, export and detail stats; server-paged, sorted, searchable list; segment as a SQL CASE; segment and repeat rate from every paid order whatever the filters; Source `Reseller: A + Retail`; Reports date presets; paged detail order history. At 10k orders 409–502 ms / 46 MB became 84–137 ms / 0.1 MB. Released and verified 2026-10-15 (#462): 3 customers, list total equals Reports (RM220.70, 32 orders), `faris9745@` shows `Reseller: FixFast + Retail`. Details: build-log 2026-10-15.
- **102** 2026-10-15: Dashboard redesign as a daily briefing (ADR-104 2026-10-15 addendum). Released and verified 2026-10-15 (#462): Last 30 days RM210.10 / 27 orders equals Reports for the same range; light, dark and narrow views render. Details: build-log 2026-10-15.
- **101** 2026-10-15: admin low-contrast leftovers use the `ink-subtle` / `danger-ink` tokens, with a CI guard (ADR-104 2026-10-15 addendum). Released and verified 2026-10-15 (#462): on prod `ink-subtle` 4.80:1 light / 5.45:1 dark, `danger-ink` 5.70:1 / 8.42:1. Details: build-log 2026-10-15.

- **100** 2026-10-15: admin dark-mode status text had no `-400` token (1.83:1). It is now aliased to the ADR-104 status inks (7.86:1), 22 light classes no longer name shades that never existed, `body` gets `text-ink`, and a CI guard catches a class with no token. Released and verified 2026-10-15 (#449). Details: build-log 2026-10-15.
- **97** 2026-10-15: the nightly backup restore test works again (released #441 + #442 via #449; exact-name grant, ADR-117). Backup #47 `success`, `restore_test_passed = 1`, 72s.
- **10** 2026-10-14: admin MFA (TOTP) and a cookie-session admin login (ADR-121). The login lasts 7 days at most, ends after 2 days unused, and is shared by every tab. MFA is opt-in per account ("Require MFA" in Admin Users, one-way; set up on `/login`). Five actions need a code within the last 5 minutes and are refused without MFA: CHIP credentials, supplier credentials, manual reseller wallet credit, withdrawal approval, admin account changes. Eight recovery codes; `php artisan admin:mfa-reset {email}` over SSH. Released 2026-10-14 (#437); the founder's MFA is active. Details: build-log 2026-10-14.
- **95** 2026-10-14: `GET /api/health/whatsapp` asks OpenWA for both sessions and answers 503 unless both are `ready` (`unreachable`, `not_configured`, or the session's own status otherwise); throttled 30/min. Released 2026-10-14 (#437); UptimeRobot monitor added. Details: build-log 2026-10-14.
- **73** 2026-10-14: every Reseller API rejection a known reseller gets is logged (`warning`, since prod runs `LOG_LEVEL=warning`) from the one render hook: reseller, key, code, status, path, IP, details. A missing or wrong key is not logged (anonymous noise, already rate-limited per IP); `RATE_LIMITED` logs once a minute per reseller (ADR-074 2026-10-14 addendum). Released 2026-10-14 (#437).

- **49, 53, 58, 72, 82, 86, 89** dropped 2026-10-14 (founder): each was a
  "build if someone reports it" note, not scheduled work. Any design stays
  in its ADR (49 ADR-060; 58 ADR-075 2026-10-02 addendum; 72, 89 ADR-074
  addenda). Reasons per item: build-log 2026-10-14.
- **OpenWA sessions start themselves after a restart** 2026-10-14: every
  OpenWA restart (Ubuntu's daily unattended upgrade restarts supervisor)
  left both WhatsApp sessions disconnected until someone pressed Start,
  for up to 28 h 39 min (3–4 Oct). `AUTO_START_SESSIONS=true` in
  `~/OpenWA/.env`; verified on a restart the same day (both `ready` in
  under 2 minutes, no manual start). ADR-114 2026-10-14 addendum.

- **93** 2026-10-13: combo prices no longer go stale. Found on prod in the item-66 audit: 8 active combos sold at a price stored before their components' costs moved (a combo off during a price change came back on sale unrepriced). Every component price change now reprices every combo using it, active or not; the reactivation cascade reprices first; a component markup edit reprices its combos; a markup on a combo is rejected. A migration reprices the stale combos on deploy (ADR-094 2026-10-13 addendum). Released 2026-10-14 (#430; 28 combos repriced, 0 stale of 101). Details: build-log 2026-10-13.
- **91** 2026-10-13: the admin hero-slide Starts/Ends inputs are read and loaded as Malaysia time whatever the browser's timezone (`klInputToIso` / `isoToKlInput`), so a re-save no longer shifts the schedule 8 hours; the list's Schedule column shows Malaysia dates. No production slide had a schedule. Live via #425 (2026-10-13). Details: build-log 2026-10-13.
- **92** 2026-10-13: the Request Logs date filter uses Malaysia calendar days (the Orders filter's bounds) and rejects a non-date value with 422. Live via #425 (2026-10-13). Details: build-log 2026-10-13.
- **90** 2026-10-13: one shared Malaysia timezone constant, `App\Support\MalaysiaTime::TIMEZONE` (admin: `KL_TIMEZONE` in `lib/date-range.ts`), replacing the five copies; vouchers and accounting no longer read it from `ReportService`. No behaviour change. Live via #425 (2026-10-13). Details: build-log 2026-10-13.
- **88** 2026-10-13: a typed voucher expiry date is valid through the end of that Malaysia day (stored 23:59:59 MYT), date-only input with a native picker, today in Malaysia the earliest date (ADR-024 2026-10-13 addendum). No production voucher had an expiry. Live via #425 (2026-10-13). Details: build-log 2026-10-13.
- **6 / A3** 2026-10-13: the Reseller API is limited per Reseller account, not per key, in two buckets (orders 60/min, reads 120/min); per-tier limits closed as not wanted; Bot unchanged (ADR-074 2026-10-13 addendum). A per-account override was item 89, dropped 2026-10-14. Live via #425 (2026-10-13). Details: build-log 2026-10-13.
- **87** 2026-10-13: the founder deleted the two old `.env` backups on the prod box (`.env.bak-before-dbtls`, `.env.bak-2026-09-30-doadmin-least-privilege`; both held the DB password). Verified read-only: only `.env` remains in the site root.
- **84** 2026-10-13: the admin voucher list, voucher detail and order Refund cards show an active voucher past `expires_at` as expired (`display_status`); the stats split active/expired by it and an expired voucher can't be ticked for merge. `Voucher::isExpired()` is now the one expiry rule (checkout and merge use it). `status` is not backfilled. Live via #425 (2026-10-13). Details: build-log 2026-10-13.
- **64** 2026-10-13: membership renewal-reminder emails at H-3 and H+1, in Malaysia days, one per period (`membership_reminders`), listed on the admin member page (ADR-068 2026-10-13 addendum). The same change fixes the squashed email logo, the receipt's day-early end date and its missing link, and makes email and WhatsApp share one brand lookup. WhatsApp reminders are item 85. Live via #416 (2026-10-13). Details: build-log 2026-10-13.
- **Older closed items** (1–3, 5, 7, 8, 12, 15–54 except those open above, 60–63): moved verbatim to `docs/build-log-archive.md`, "PRD narrative moved 2026-10-12". Numbers stay stable. Items closed 2026-10-08 → 10-12 (79, 80, 69, 66 history view, 75, 76, 77, 6 part, 78, 9, 65, 27, 56, 70/68/67): moved verbatim to `docs/build-log-archive.md`, "PRD §16 closed items moved 2026-10-16".

## Parked by founder decision — not scheduled

- **66 Combo edit-composition.** A combo is never edited: to change one,
  create a new combo and deactivate the old one (the founder's workflow
  since the 2026-10-13 MLBB Malaysia rebuild, two combos per denomination).
  This keeps every combo a fixed SKU, so the paid-but-unfulfilled edit
  window never exists. Reopen only as a "Duplicate combo" button (no money
  path) if rebuilding combos by hand becomes a chore (ADR-094 2026-10-14
  addendum).

- **A second payment gateway.** The `PaymentGatewayFactory` seam is kept for
  multi-region payment, but a real 2nd gateway is revisited only when
  cross-border selling is real (ADR-022; Xendit deleted).
- **CHIP `.env` fallback vars stay on Forge.** `CHIP_SECRET_KEY`/`CHIP_BRAND_ID`
  are deliberately kept as a belt-and-braces fallback to the DB-first
  credentials (ADR-110 PR-C). This is not an owed cleanup; don't re-flag it.

---
