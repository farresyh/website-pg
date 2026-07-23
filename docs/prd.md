# PRODUCT REQUIREMENTS DOCUMENT (PRD)

## Game Top-Up Reseller Platform

**STATUS: DRAFT FINAL v0.3 (Hardened)**

| | |
| --- | --- |
| **Product Name** | Game Top-Up Reseller Platform |
| **Document Version** | v0.3 (Security & Financial-Integrity Hardening Pass) |
| **Prepared by** | Bubududu (System Analysis) — original v0.2 |
| **Reviewed & Hardened by** | Founder + Claude, foundation audit session, 2026-07-23 |
| **Related Documents** | [`adr.md`](./adr.md) — foundation decision log (the *why*); [`foundation-security.md`](./foundation-security.md) — security/financial-integrity checklist (the *guardrails*); legacy system analysis (admin.keroxshop.com, manage.keroxshop.com); supplier API research (Gamevion, MLBB-validator sample store) |

> Foundation decisions (payment gateway, ledger model, tenant strategy, refund policy, frontend stack, etc.) are recorded with full rationale in **[`adr.md`](./adr.md)**, not duplicated here. This document assumes those decisions and specifies the product built on top of them.

---

# 1. Product Overview

Game top-up reseller platforms currently exist as fragmented systems with separate admin panels (business operations) and supplier middleware (technical integration). These systems are tightly coupled to specific suppliers and lack modularity, scalability, and proper separation of concerns. Pain points include manual price syncing, lack of automated supplier failover, no clear audit trails, and difficulty onboarding new resellers.

The proposed system is a **greenfield multi-tenant-ready game top-up platform** built with modular architecture. It comprises four main layers: **(1) Supplier Middleware** — technical layer that handles all supplier API communication (product matching, price sync, player validation where available, order delivery, response normalization); **(2) Core API / Admin Backend** — business logic layer managing games, packages, pricing rules, resellers, orders, and payouts on a ledger-based financial model; **(3) Admin Panel** — web SPA for super admins to oversee operations; and **(4) Storefront** — customer-facing app, operated by the platform owner in MVP, architected so branded per-reseller storefronts can be switched on in Phase 2 without a schema rewrite.

**MVP framing:** build and prove the platform as a single-owner operation first (all profit, all risk, one storefront) on top of a tenant-ready schema. Onboard real third-party resellers — with their own markup, withdrawal, and eventually direct settlement via Xendit xenPlatform — once the core money-handling logic (pricing, ledger, order lifecycle, fraud controls) has been validated in production with real transactions.

---

# 2. Goals & Objectives

- **Centralize game credit operations** — single platform handling supplier integration, order processing, and financial reporting.
- **Automate supplier price synchronization** — eliminate manual price updates with scheduled sync, automatic reactivation approval, and exchange rate management.
- **Provide transparent multi-tier pricing, computed server-side only** — cost price (from supplier), system markup, and (in Phase 2) reseller markup, recomputed at order time from stored config — never trusted from client input.
- **Reduce order failures** — real-time supplier balance monitoring, automated retry on failure, circuit-breaking on repeated supplier failure, and clear escalation workflows for stuck orders.
- **Protect customer money and supplier balance from manipulation** — every requirement in this document that touches price, balance, or delivery must be read with the assumption that a hostile actor will try to exploit it.
- **Deliver actionable business intelligence** — live dashboards, conversion funnel analysis, and drill-down reports.
- **(Phase 2) Enable rapid reseller onboarding** — branded storefronts with custom domains, automated Xendit xenPlatform sub-account settlement.

---

# 3. Users & Roles

- **Super Admin :** Full system access — manages games, packages, suppliers, admin users, all orders, withdrawals, vouchers, blacklist, settings, and developer tools. Only role that can approve withdrawals/vouchers above the maker-checker threshold (see WTH-5, VCH-5).
- **Admin :** Operational access — manages orders, reports, reviews, withdrawals (below threshold), vouchers (below threshold), and customer analytics. Cannot modify system settings, supplier credentials, or blacklist rules.
- **Reseller (Phase 2):** Owns a branded storefront with custom domain. Sets own markup within limits, manages own orders, views own reports and analytics, and requests withdrawals settled via ledger + Xendit xenPlatform.
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

- Reseller self-service onboarding, branded storefronts, custom domains, reseller theme system.
- Xendit xenPlatform sub-account automatic fund-splitting and settlement.
- Automated customer support chatbot, mobile native apps, loyalty/rewards program, affiliate marketing module, cross-reseller inventory sharing.
- Automated (non-admin-reviewed) refund/reactivation decisions.

---

# 5. Assumptions & Constraints

- **Technology stack**: Laravel REST API for backend (proven pattern from legacy system). Frontend is a **unified Next.js (React) stack** for both apps (ADR-009, superseding the earlier Vue/Next split in ADR-008): **Admin Panel = Next.js in client-rendered/SPA mode** — calls the Laravel API directly from the browser via Bearer token (AUTH-2), no SSR needed behind auth. **Storefront = Next.js**, using SSR/React Server Components where it genuinely benefits SEO-relevant public pages (§6.16).
- **Database** assumed to be relational (MySQL/PostgreSQL). All data models in Chapter 8 reflect this assumption, including the ledger table.
- **Hosting** assumed to use Cloudflare CDN + nginx for production deployment.
- **Multi-tenant architecture**: schema and query layer built tenant-aware (`reseller_id` scoping, enforced via ORM global scopes) from day 1, per ADR-003 — even though MVP only operates one internal tenant.
- **Supplier APIs**: assumed RESTful/JSON, but **must not be assumed to have a uniform response shape, auth style, or validation capability.** Confirmed by direct research (Gamevion API, MLBB-validator sample store) that these vary per supplier and even per game. All supplier calls go through a normalizing Adapter layer (see 6.21) — no business logic touches raw supplier response shapes.
- **Payment gateway: Xendit.** Invoice/Payment API for MVP (single merchant account, platform collects 100% of payment). xenPlatform integration deferred to Phase 2 per ADR-001.
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
| **GAME-7** | Admin can manage packages per game: add, edit, delete — each package has name, `reseller_cost_price`, `cost_price`, supplier reference | **MVP** |
| **GAME-8** | System displays platform profit margin per package (`reseller_cost_price − cost_price`) — this is the platform's own margin, not the final customer-facing selling price (see §8 Package/Order for the full 3-tier breakdown) | **MVP** |
| **GAME-9** | Admin can sync input fields (pull field schema from supplier) per game or bulk | **MVP** |
| **GAME-10** | Admin can sync prices (pull latest pricing from supplier) per game or bulk | **MVP** |
| **GAME-11** | Admin can perform bulk price update (apply system markup to all packages) | **Important** |
| **GAME-12** | Each supplier mapping on a Game stores its **own** `supports_validation` flag (validation capability is per game-supplier mapping, not a blanket per-supplier flag — confirmed varies even within one supplier's catalog) | **MVP** |

## 6.4 Admin — Price Sync Center

| ID | Functional Requirement | Priority |
| --- | --- | --- |
| **SYNC-1** | System displays stats: total games, active packages, pending reactivation count, last sync status & timestamp | **MVP** |
| **SYNC-2** | System displays last sync details: games total/success/failed, packages created/updated/deactivated, duration | **MVP** |
| **SYNC-3** | System displays currency exchange rate with history and manual refresh | **MVP** |
| **SYNC-4** | Admin can trigger Sync All Prices Now (full sync from all suppliers) | **MVP** |
| **SYNC-5** | Admin can review pending reactivation packages: table with package name, supplier, game, price, cost, reason, days inactive, actions (Approve/Dismiss) | **MVP** |
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

## 6.7 Admin — Resellers Management (Phase 2)

| ID | Functional Requirement | Priority |
| --- | --- | --- |
| **RES-1** | System displays reseller table: business name, contact, markup %, domains, orders count, status, actions | **Phase 2** |
| **RES-2** | Admin can add reseller with business info, markup %, domains, and link Xendit xenPlatform sub-account | **Phase 2** |
| **RES-3** | Admin can edit reseller details | **Phase 2** |
| **RES-4** | Admin can impersonate reseller (login as reseller for debugging) — **every action taken during an impersonation session is tagged and logged with the real admin identity, session start/end time, and a visible "impersonating" banner** | **Phase 2** |
| **RES-5** | Admin can activate/deactivate reseller | **Phase 2** |
| **RES-6** | Admin can delete reseller | **Phase 2** |

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
| **ORD-9** | Every monetary field (`cost_price`, `reseller_cost_price`, `selling_price`, `transaction_fee`, `final_amount`, `platform_profit`, `reseller_profit`) is always computed server-side at order-creation time from stored Package/Reseller/Settings config, then snapshotted onto the Order — the system never accepts a client-submitted value for any of these fields. `transaction_fee` is computed on `selling_price − voucher_discount` (fee is charged on the post-voucher amount, not the original selling price) | **MVP** |
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
| **VCH-6** | Vouchers above a configurable amount require Super Admin approval (maker-checker, same principle as WTH-5) | **Important** |

## 6.12 Admin — Customer Analytics

| ID | Functional Requirement | Priority |
| --- | --- | --- |
| **ANL-1** | System displays stats: total customers, avg order value, repeat rate, top spender | **MVP** |
| **ANL-2** | System provides customer segmentation: VIP (≥ threshold), Frequent (10+ orders), New (< 30 days), Dormant (> 90 days), One-time | **MVP** |
| **ANL-3** | System displays customer table: email, name, segment, orders count, total spent, last order date | **MVP** |
| **ANL-4** | Admin can filter by segment and date range, export CSV | **MVP** |

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

## 6.15 Admin — Store Themes (Phase 2)

| ID | Functional Requirement | Priority |
| --- | --- | --- |
| **THM-1** | System displays theme list (cards/grid) with preview, status badge, usage count | **Phase 2** |
| **THM-2** | Admin can create/edit theme: name, color scheme, fonts, border radii, backgrounds, header/button styles, logo settings | **Phase 2** |
| **THM-3** | Admin can manage reseller theme permissions table | **Phase 2** |
| **THM-4** | Admin can bulk update reseller theme assignments | **Phase 2** |

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
| **SET-11** | Admin can configure transaction fee rate (percentage + flat component) per payment method, matching Xendit's current published rates (e.g. FPX/DuitNow ≈ flat-only; Cards/e-wallets ≈ percentage + flat) — never hardcoded in application code | **MVP** |

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
| **PAY-1** | All Xendit webhook/callback payloads are verified (token/signature check per Xendit's callback verification mechanism) before being trusted — an unverified callback is logged and discarded, never actioned | **MVP** |
| **PAY-2** | Payment confirmation is idempotent — receiving the same webhook/callback event more than once must not create duplicate orders or duplicate credit delivery | **MVP** |
| **PAY-3** | A scheduled reconciliation job cross-checks Xendit's transaction records against local orders to catch any missed or lost webhook deliveries | **MVP** |
| **PAY-4** | The system never touches raw card data directly — card entry happens only via Xendit-hosted fields/redirect, keeping the platform out of PCI-DSS scope | **MVP** |

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

1. Order fails and cannot be re-delivered (or player-ID-at-order-time fails with no viable retry, per 7.1/7.2).
2. Admin creates voucher: enters customer email, sets amount and expiry date, records reason. Amount above threshold requires Super Admin approval (VCH-6).
3. Voucher created with status "Active", system generates a unique code.
4. Customer receives voucher code via email.
5. Customer applies voucher on next purchase — remaining amount is decremented via an **atomic, row-locked** operation (VCH-5) so concurrent redemption attempts cannot double-spend the same voucher.
6. Voucher becomes "Exhausted" when remaining reaches zero, or "Expired" past expiry date.

---

# 8. Data Model (High-Level)

| Entity | Key Fields | Description |
| --- | --- | --- |
| **Game** | `id`, `name`, `slug`, `category`, `is_active`, `sort_order`, `image_url`, `banner_url`, `supplier_mappings` (JSON array of `{supplier_id, product_ref, supports_validation, supports_server_list}`), `validation_rules` (JSON), SEO fields | A game title available for top-up. `supports_validation` lives per supplier-mapping entry, not on Supplier globally (per GAME-12). |
| **Package** | `id`, `game_id`, `name`, `cost_price` (supplier wholesale), `reseller_cost_price` (= `cost_price` + platform/system markup, SET-2 — stored/admin-managed via GAME-7/9/10/11, **not** recomputed live from a percentage at order time), `is_active`, `supplier_id`, `supplier_package_ref`, `sort_order` | A specific top-up denomination within a game. `reseller_cost_price` is what a reseller effectively "pays" — it is **not** the customer-facing price; that's computed per-reseller at order time (see Order). In MVP (single internal owner-reseller) it happens to equal the customer-facing price, but the schema/formula never assumes that. |
| **Supplier** | `id`, `name`, `slug`, `logo_url`, `is_active`, `api_config` (JSON — credentials, endpoints; **encrypted at rest**), `balance`, `currency` | A game credit supplier integrated via Middleware, accessed only through its Adapter (6.21). |
| **Reseller** | `id`, `business_name`, `contact_name`, `email`, `phone`, `markup_pct`, `max_markup_pct`, `domains` (JSON array, nullable), `status`, `xendit_subaccount_id` (nullable until Phase 2), `notes` — **no `balance` column**; balance is derived from `LedgerEntry` | A branded storefront owner. **Table exists from MVP** (not Phase 2-only): exactly one row is created for the platform owner's own internal storefront, typically with `markup_pct = 0`, so the pricing/ledger formulas never special-case "no reseller yet." Phase 2 adds more rows (real third-party resellers) and the self-service UI (RES-1..6, Phase 2) — the table and pricing formula do not change. |
| **LedgerEntry** | `id`, `owner_type` (platform/reseller), `owner_id`, `type` (order_profit/withdrawal/voucher_issued/adjustment), `amount` (signed sen: credit positive, debit negative), `reference_type`, `reference_id`, `created_at`, `created_by`, `reason` (required for `adjustment`) | **Immutable, append-only.** Sole source of truth for any balance. Never updated or deleted, only inserted (ADR-002). **Every delivered order writes exactly two `order_profit` credit entries** — one for `platform_profit` (owner_type=platform) and one for `reseller_profit` (owner_type=reseller, the order's `reseller_id`) — kept as two rows even in MVP (where both happen to resolve to the same owner) so the ledger logic never needs to change when Phase 2 onboards real resellers. |
| **LedgerAccount** | `id`, `owner_type`, `owner_id` (unique together) | Pure lock/mutex anchor, **no balance column** — created upfront alongside the owner so `withdraw()` always has a row to `lockForUpdate()`, even for a brand-new owner with zero prior entries. See ADR-002 addendum. |
| **AdminUser** | `id`, `name`, `email`, `password`, `role` (super_admin/admin), `phone`, `is_active`, `mfa_secret`, `mfa_enabled` | System administrator account. |
| **Order** | `id`, `order_number` (customer-facing, e.g. `KRS-8xxxxxxx`, platform-generated), `reference_number` (our idempotency key, generated before first supplier call — ORD-8, transformed per-supplier format by the Adapter per ADAPT-4), `customer_email`, `customer_phone`, `player_id`, `server_id`, `game_id`, `package_id`, `supplier_id`, `reseller_id` (in MVP, always the single internal owner-reseller record), `cost_price` (snapshot from Package), `reseller_cost_price` (snapshot from Package), `reseller_markup_pct` (snapshot from Reseller at order time), `selling_price` (= `reseller_cost_price` + reseller markup — customer-facing price), `voucher_discount` (nullable), `transaction_fee` (computed on `selling_price − voucher_discount`, per SET-11 rate for the chosen `payment_method`), `final_amount` (= `selling_price − voucher_discount + transaction_fee` — what Xendit actually charges), `platform_profit` (= `reseller_cost_price − cost_price`), `reseller_profit` (= `selling_price − reseller_cost_price`) — all server-computed, ORD-9, `payment_status` (pending/paid/failed — reflects Xendit only), `delivery_status` (not_started/processing/delivered/failed — reflects the Supplier API only), `payment_method`, `payment_ref`, `supplier_ref` (supplier's own order number, opaque string, format varies per supplier — only known after their response), `supplier_response` (JSON, PII-redacted), `timestamps` | A customer's top-up purchase transaction. Three distinct identifiers exist per order: `order_number` (ours, customer-facing), `reference_number` (ours, idempotency key sent to supplier), and `supplier_ref` (theirs, returned after success) — never conflate them. Cost/price fields are snapshotted at order time (not live-joined from Package/Reseller) so historical orders stay accurate even if prices change later. **`payment_status` and `delivery_status` are two independent state machines — delivery may only move out of `not_started` when `payment_status == paid` (ORD-11); never conflate a single combined "status" field again.** |
| **Withdrawal** | `id`, `owner_type`, `owner_id`, `amount`, `bank_name`, `bank_account_no`, `bank_account_holder`, `status`, `admin_note`, `requested_by`, `approved_by` (must differ from `requested_by` above threshold — WTH-5), `processed_at` | Profit withdrawal request, settled against the ledger. |
| **Voucher** | `id`, `code`, `customer_email`, `amount`, `remaining`, `status` (active/exhausted/expired/revoked), `expires_at`, `reason`, `created_by`, `approved_by` (if above threshold) | A store-credit refund issued to a customer — the only refund mechanism in the system (ADR-004). |
| **Review** | `id`, `order_id`, `customer_email`, `game_id`, `package_id`, `rating` (1-5), `comment`, `status`, `created_at` | Customer feedback on a completed order. |
| **Blacklist** | `id`, `type` (player_id/email/phone), `game_id` (nullable), `value`, `reason`, `created_by`, `created_at` | Internal fraud-prevention list, checked at order creation (FRAUD-1/2). |
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
| **Xendit** | Online payment processing (FPX, DuitNow QR, cards) | MVP: standard Invoice/Payment API, single merchant account, platform collects 100% of payment. Webhook signature verification mandatory (PAY-1). **xenPlatform (sub-account splitting) deferred to Phase 2** — only needed once real resellers require automatic settlement (ADR-001). |
| **Supplier API(s)** | Game credit catalog, price feeds, player validation (where supported), order creation/fulfillment | **Confirmed via direct research to vary significantly per supplier**: different auth schemes (static Bearer+API-key vs. others), inconsistent response envelopes, validation-endpoint availability differs per game (not just per supplier), idempotency support varies (e.g. Gamevion's `referenceNumber` + `409 Duplicate` pattern). All access goes through the Adapter layer (6.21) — never assume any of the above is uniform across suppliers. |
| **Google Analytics (GA4)** | Website analytics and conversion tracking | Measurement ID from Settings. |
| **Facebook Pixel** | Ad conversion tracking and retargeting | Pixel ID from Settings. |
| **TikTok Pixel** | Ad conversion tracking | Pixel ID from Settings. |
| **Telegram Bot** | Admin notifications for new orders, failures, withdrawal requests, tripped circuit breakers | Configurable token and chat ID from Settings. |
| **Email Service** | Transactional emails (order confirmation, delivery notification, failure/voucher alerts) | SMTP or API-based. Templates configurable. |
| **[Additional Suppliers]** | Future supplier integrations | Standard Adapter pattern for onboarding — enforced from MVP, not Phase 2 (ADR-006). |

---

# 11. Proposed Features / Future Phases

- **Reseller Self-Service Platform.** Full multi-tenant reseller onboarding: branded storefronts, custom domains, self-service dashboard, reseller-set markup within limits (ADR-003). Schema is already tenant-ready; this phase exposes the UI/flows.
- **Xendit xenPlatform Integration.** Automatic fund-splitting and direct settlement to reseller sub-accounts, replacing/supplementing the manual withdrawal flow once reseller volume justifies the onboarding/KYC overhead (ADR-001).
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

**Still open:**

- Exact maker-checker withdrawal/voucher threshold amount (RM value) — needs a business decision, not just an engineering default.
- MFA method confirmation — TOTP assumed (AUTH-7); confirm no SMS-OTP fallback is wanted (SMS OTP has its own SIM-swap risk profile).
- Will backups be stored on local server or cloud storage (S3, etc)?
- What is the SLA for supplier APIs? Should there be alerts if response time exceeds threshold (ties into circuit-breaker tuning)?
- Per-supplier, per-game audit: which games/suppliers actually support pre-payment validation vs. order-time-only? Needs to be catalogued during supplier onboarding, not assumed.
- Blacklist data source: internal chargeback history only, or also manually curated from known fraud reports? Needs a policy for false-positive appeal.
- Will reseller domains (Phase 2) use subdomains or custom domains — DNS/session-cookie implications across different eTLDs need a design pass before Phase 2 starts.

---

# 13. Glossary

- **Supplier Middleware :** System layer that handles API communication with game credit suppliers via the Adapter layer. Provides product matching, price sync, player validation (where available), and order delivery.
- **Adapter / Normalizer :** A per-supplier module implementing a common internal interface, translating that supplier's specific auth/response format into one canonical shape so business logic never depends on raw supplier response structure (6.21).
- **Ledger Entry :** An immutable, append-only record of a single credit or debit against a platform-owner or reseller's balance. Balance is always a derived SUM, never a directly-mutated field (ADR-002).
- **Idempotency Key / Reference Number :** A unique identifier generated before the first attempt at a money-moving or order-creating API call, reused on every retry of that same logical operation so a retried request cannot create a duplicate effect.
- **Maker-Checker :** A dual-control pattern where the person who initiates a sensitive action (large withdrawal, large voucher) cannot also be the sole approver of that same action.
- **Cost Price :** Price from supplier (wholesale). Base for all markup calculations.
- **System Markup :** Profit percentage added on top of cost price (system's margin).
- **Reseller Markup (Phase 2) :** Profit percentage added by reseller on top of base price (cost + system markup).
- **Pending Reactivation :** Packages that were previously inactive but are now available again from supplier. Requires admin review for reactivation.
- **Player ID :** Unique identifier for a player within a game (username, user ID, or character ID).
- **Server ID :** Server identifier within a game (for games with multiple servers).
- **Region Validator :** Mapping of country codes to game variants (e.g. MY → Malaysia variant, ID → Indonesia variant).
- **Dry Run :** Testing mode for API calls without creating real orders or deducting balance.
- **Stuck Order :** Order that is "paid" but not yet "delivered" beyond a threshold (e.g. 5 minutes).
- **Multi-Tenant :** Architecture where a single system instance serves multiple resellers (tenants) with data isolation. Schema-ready from MVP (ADR-003); feature-exposed in Phase 2.
- **xenPlatform :** Xendit's product for platform/marketplace businesses — sub-account creation and automatic payment splitting between platform and merchants. Deferred to Phase 2 (ADR-001).
- **Blacklist :** Internal, platform-owned list of player IDs/contacts blocked from ordering due to prior fraud/chargeback history, independent of any supplier-side blacklist (ADR-007).

---

# 14. Implementation Status (`backend/`)

Built test-first (red → green), per the TDD approach agreed for money-critical logic. Everything below is pure/framework-light business logic — no controllers, routes, or UI wired up yet. Updated 2026-07-23; keep this section current as a quick "where did we leave off" marker for future sessions, rather than re-deriving it from git history each time.

| Area | Status | Files | Tests |
| --- | --- | --- | --- |
| **Pricing** (3-tier: cost → reseller cost → selling price) | ✅ Done | `app/Services/Pricing/{PricingBreakdown,PricingService,InvalidPricingConfigException}.php` | `tests/Unit/Services/Pricing/PricingServiceTest.php` (3) |
| **Checkout total** (voucher → transaction fee → final amount) | ✅ Done | `app/Services/Pricing/{CheckoutTotal,CheckoutTotalService,PaymentMethodFeeConfig}.php` | `tests/Unit/Services/Pricing/CheckoutTotalServiceTest.php` (5) |
| **Ledger** (append-only balance, ADR-002) | ✅ Done | `app/Models/{LedgerAccount,LedgerEntry}.php`, `app/Services/Ledger/{LedgerService,InsufficientBalanceException}.php`, migrations for both tables | `tests/Feature/Services/Ledger/LedgerServiceTest.php` (4) + `tests/Concurrency/LedgerWithdrawConcurrencyTest.php` (1, proves the lock, not just the arithmetic) |
| **Order status** (payment_status/delivery_status, ORD-11 guard) | ✅ Done | `app/Services/Order/{PaymentStatus,DeliveryStatus,OrderStatusService,InvalidOrderTransitionException}.php` | `tests/Unit/Services/Order/OrderStatusServiceTest.php` (9) |
| **Voucher redemption** (VCH-5) | ✅ Done | `app/Models/Voucher.php`, `app/Services/Voucher/{VoucherService,InvalidVoucherException}.php`, migration | `tests/Feature/Services/Voucher/VoucherServiceTest.php` (4) + `tests/Concurrency/VoucherRedeemConcurrencyTest.php` (1) |
| **Order idempotency** (`reference_number` generation before supplier call, ORD-8) | ✅ Done | `app/Services/Order/ReferenceNumberService.php` — `generate()` produces a unique `REF-<ULID>` value; `resolve(?string $existing)` is the retry-safe entry point (returns the existing value unchanged, generates only when none exists yet). Not yet wired to an `Order` model/migration — those don't exist yet (see below) | `tests/Unit/Services/Order/ReferenceNumberServiceTest.php` (5) |
| **Supplier Adapter layer** (ADAPT-1..4) | 🟡 Interface done, one real adapter done | `app/Services/Supplier/{SupplierAdapter,SupplierResponse,SupplierOrderRequest,ValidationNotSupportedException}.php` — canonical contract + normalized-response DTO. First concrete implementation: `app/Services/Supplier/Gamevion/GamevionAdapter.php`, built against Gamevion's real OpenAPI spec (docs.gamevion.com/gamevion-api, v1.0.10 — not assumed). Confirmed Gamevion-specific quirks the adapter absorbs: dual auth headers (Bearer **and** X-API-KEY, both required), sandbox mode returns a differently-prefixed product shape (`sandbox_*` vs `product_*`), a 409 on order creation means "duplicate reference" (their idempotency signal), `check-status` takes Gamevion's own `invoice_number` (not our `reference_number`), and no player-validation endpoint exists at all (ADR-005 fallback applies to every game on this supplier). Config placeholder in `config/services.php['gamevion']` (env-based; set `GAMEVION_BEARER_TOKEN`/`GAMEVION_API_KEY` locally — not committed). Not yet wired to a `Supplier` model/DB config (SUPP-5) or to any order-creation flow | `tests/Feature/Services/Supplier/GamevionAdapterTest.php` (12) |
| **Payment Gateway layer** (PAY-1..4, ADR-001 addendum) | 🟡 Interface done, Xendit adapter done, not wired to a webhook route | `app/Services/Payment/{PaymentGateway,PaymentRequest,PaymentResponse,PaymentWebhookEvent}.php` — thin canonical contract (4 methods, deliberately smaller than SupplierAdapter — see ADR-001 addendum for why). `app/Services/Payment/Xendit/XenditGateway.php` built against Xendit's real Payment Request API v3 docs (docs.xendit.co, api-version 2024-11-11 — the current API, not the older Invoice API). Confirmed from real docs: HTTP Basic Auth (secret key as username, empty password), webhook authenticity is a plain `x-callback-token` comparison (not HMAC — verified with `hash_equals`), `request_amount` is a decimal in the currency's major unit (adapter converts to/from our internal integer-sen convention — **unconfirmed against a live MYR sandbox response, verify before production**). `parseWebhookEvent()` reuses the existing `PaymentStatus` enum rather than inventing a parallel status type. Config placeholder in `config/services.php['xendit']` (env-based; set `XENDIT_SECRET_KEY`/`XENDIT_WEBHOOK_TOKEN` locally — not committed). Not yet wired to an actual webhook-receiving route/controller (none exist yet) or to PAY-2 idempotent-processing / PAY-3 reconciliation-job logic — this is the adapter only | `tests/Feature/Services/Payment/XenditGatewayTest.php` (10) |
| **Blacklist check** (FRAUD-1..4) | ⬜ Not started | — | — |
| **`Order` model/migration** | ✅ Done | `app/Models/Order.php`, migration `2026_07_23_153930_create_orders_table.php`. All money fields integer sen; `payment_status`/`delivery_status` cast directly to the existing `PaymentStatus`/`DeliveryStatus` enums (native Eloquent enum casting) — this is the connective tissue tying `ReferenceNumberService`, `OrderStatusService`, and the Supplier/Payment adapters to real persisted state for the first time. `game_id`/`package_id`/`supplier_id`/`reseller_id` are plain FK-id columns **without** a `foreign()` constraint yet — `Game`/`Package`/`Supplier`/`Reseller` tables don't exist; add the constraints in a follow-up migration once they do | `tests/Feature/Models/OrderTest.php` (4) — includes an explicit round-trip proof that `ReferenceNumberService::resolve()` persists once and is reused on retry through a real `Order` row |
| **`Supplier` model/migration** | ✅ Done | `app/Models/Supplier.php`, migration `2026_07_23_154800_create_suppliers_table.php`. `api_config` uses Eloquent's native `encrypted:array` cast (SUPP-5: encrypted at rest, verified by a test reading the raw DB column and asserting the plaintext secret is absent) and is also `$hidden` (defense in depth — never returned unmasked via `toArray()`/`toJson()` even before any controller exists). `balance` is a plain mutable column mirroring what the supplier's own API last reported (DASH-2) — explicitly **not** governed by ADR-002, since it's a third party's number, not our ledger. `GamevionAdapter` is **not yet wired** to read from this table — still configured via env vars in `config/services.php`; that wiring (a `Supplier`→Adapter factory) is a follow-up, not done here | `tests/Feature/Models/SupplierTest.php` (4) |
| **`CheckoutService` / `OrderFulfillmentService`** (orchestration) | ✅ Done | First pieces that actually connect everything above into a working flow. `app/Services/Checkout/{CheckoutService,CheckoutRequest,CheckoutFailedException}.php` — pricing → checkout total → create `Order` (pending/not_started) → create Xendit payment request → store `payment_ref`, all inside one DB transaction (a gateway failure never leaves a dangling unpayable Order). `app/Services/Fulfillment/OrderFulfillmentService.php` — assumes payment already confirmed Paid (by whatever processes the verified webhook — not built yet); guards via `OrderStatusService` (ORD-11), resolves `reference_number` via `ReferenceNumberService` (ORD-8), submits to the supplier via `SupplierAdapter`, updates `delivery_status`, and on success writes the two `order_profit` ledger credits (platform + reseller) per PRD §8. Deliberately does **not** attempt auto-reconciliation on a duplicate-reference response — surfaces as `delivery_status=failed` with the raw adapter response attached for admin review instead, since Gamevion's real error-body shape for that case isn't confirmed and the actual reconciliation job (ORD-10) is separately scoped. Added `App\Services\Order\OrderNumberService` (order_number generator, `KRS-<ULID>` — distinct identifier from `reference_number`/`supplier_ref`, no retry-reuse semantics) and a new `supplier_product_ref` column on `orders` (same ORD-9 snapshotting principle as cost_price). `SupplierAdapter`/`PaymentGateway` are now bound in `AppServiceProvider` (Gamevion/Xendit, single-supplier placeholder — becomes per-order factory once the `Supplier` model is wired for real routing) | `tests/Unit/Services/Order/OrderNumberServiceTest.php` (2), `tests/Feature/Services/Checkout/CheckoutServiceTest.php` (2), `tests/Feature/Services/Fulfillment/OrderFulfillmentServiceTest.php` (5) — all use small in-test fake `PaymentGateway`/`SupplierAdapter` implementations, not `Http::fake()`, since the point is testing orchestration logic in isolation from any real HTTP layer |
| **Auth (Sanctum) + admin role middleware** (AUTH-1, AUTH-2 backend half, AUTH-6 partial) | ✅ Done | Renamed `User`→`AdminUser` (class + table `users`→`admin_users`) to match PRD §8's own naming, distinct from storefront customers (confirmed 2026-07-24: MVP stays guest-checkout, no Customer table — matches keroxshop.com's own precedent and PRD's data model, which has no Customer entity at all). Added `role`/`phone`/`is_active`/`mfa_secret` (encrypted cast)/`mfa_enabled` columns. `POST /api/login`, `POST /api/logout`, `GET /api/me` (`app/Http/Controllers/Auth/AuthController.php`) via `laravel/sanctum` (installed via `php artisan install:api`). `App\Http\Middleware\EnsureAdminRole` (alias `admin.role:super_admin,admin`) enforces role + `is_active` server-side (foundation-security.md §1) — throws `HttpException` directly rather than using the `abort()` helper, so it's unit-testable without booting the framework. Not yet attached to any route (nothing role-restricted exists yet — ready for AUTH-4/withdrawal/voucher endpoints later) | `tests/Unit/Http/Middleware/EnsureAdminRoleTest.php` (5), `tests/Feature/Http/Controllers/Auth/AuthControllerTest.php` (6) |
| **Xendit webhook endpoint** (PAY-1, PAY-2) | ✅ Done | `POST /api/webhooks/xendit` (`app/Http/Controllers/Webhooks/XenditWebhookController.php`) — **not** behind `auth:sanctum`; the `x-callback-token` comparison (`XenditGateway::verifyWebhookSignature()`) is the auth mechanism (PAY-1). An invalid signature is logged and discarded (401), never actioned. Looks up `Order` by `payment_ref`, updates `payment_status`, and on a Paid event calls `OrderFulfillmentService::fulfill()` — this is the first real caller of that service, closing the loop this whole session built toward. PAY-2 idempotency: an already-Paid order short-circuits to an acknowledgment instead of reprocessing; `fulfill()`'s own row lock (added in the security-review fix) is the second, structural layer of protection if a duplicate somehow gets past that check | `tests/Feature/Http/Controllers/Webhooks/XenditWebhookControllerTest.php` (5) |
| **`CheckoutController` (HTTP endpoint for `CheckoutService`)** | ⬜ Blocked, not started — `CheckoutRequest` needs `cost_price`/`reseller_cost_price`/`supplier_product_ref` etc., which ORD-9 requires to come from server-side `Package` lookup, never client input. Building this endpoint now would mean either trusting client-submitted prices (a direct ORD-9 violation) or faking a data source — genuinely blocked on the `Game`/`Package` models, which are themselves blocked on real Gamevion catalog data (see Supplier Adapter layer row) | — | — |
| **Admin/Middleware/Storefront UI** | ⬜ Scaffolded only (placeholders, no logic wired) — see `admin/` | — |

**Manual smoke-test tools** (not part of the automated suite — deliberately hit real external APIs): `php artisan app:xendit-smoke-test` and `php artisan app:gamevion-smoke-test` (`app/Console/Commands/Smoke/`). Xendit run confirmed against the real sandbox: Basic Auth accepted, sen→ringgit conversion accepted, and three rounds of real `API_VALIDATION_ERROR` responses parsed correctly by `XenditGateway`'s error normalization — proves the adapter plumbing works end-to-end. Stopped short of a full `201 Created` only because `DUITNOW_PAY`'s exact `issuer_code` values aren't known yet (needs Xendit Dashboard, not a code question). Gamevion smoke test is written but **not yet run** — waiting on real `GAMEVION_BEARER_TOKEN`/`GAMEVION_API_KEY`.

**Security review, 2026-07-24:** ran a full manual audit of all backend money-critical code (no `origin` remote configured, so the `/security-review` skill's git-diff step couldn't run — reviewed by hand instead). Found and fixed 4 issues, most severely a **critical** one: `OrderFulfillmentService::fulfill()` had no row lock, so two near-simultaneous calls for the same Order (e.g. a duplicate webhook delivery — explicitly expected behavior per Xendit's own docs, exactly what PAY-2 exists to prevent) could each generate a *different* `reference_number` and submit two separate orders to Gamevion, double-delivering game credits and double-crediting the ledger for one payment. Fixed with `DB::transaction()` + `lockForUpdate()`, mirroring `LedgerService::withdraw()`/`VoucherService::redeem()`'s existing pattern exactly, and proven with a new real two-OS-process test (`tests/Concurrency/OrderFulfillmentConcurrencyTest.php`, backed by `app:order-fulfillment-test-fulfill` in `app/Console/Commands/Testing/`) — not just trusted by inspection. Also fixed: `CheckoutService::initiate()` no longer wraps the outbound Xendit call in a DB transaction (previously risked orphaning a real, payable Xendit payment link with no matching Order if the transaction failed to commit after Xendit had already accepted the request — now the Order commits first, so the only failure mode is the safe direction: an Order stuck at Pending with no `payment_ref`); `OrderFulfillmentService` now fails fast with a new `OrderFulfillmentException` when `supplier_product_ref` is missing, instead of silently sending an empty `product_code` to the supplier; `CheckoutTotalService::calculate()` now rejects a negative `voucher_discount` instead of letting it inflate `final_amount` above the correct price.

**Test suite total: 92 passing** (89 in the default sqlite suite + 3 in the MySQL concurrency suite).

**Storefront auth decision, 2026-07-24:** confirmed via live comparison (browser) that keroxshop.com (our own site) is 100% guest-checkout — no login/register anywhere, only "Track Order" by order number. gamevion.com does have customer accounts, but for a reason that doesn't apply to us: their site runs a customer wallet (pre-funded balance) plus a reseller/membership-tier program, both of which require persistent identity. Our PRD has no Customer entity and no customer-facing wallet concept — Order already only stores `customer_email`/`customer_phone` as plain strings. Decision: MVP storefront stays guest-checkout; auth (Sanctum) is for `AdminUser` only. Revisit only if a customer wallet/loyalty feature is deliberately added later (would be its own ADR-level decision, not a default).

**How to run tests:**
- Fast, everyday suite (sqlite, no Docker needed): `cd backend && php artisan test`
- Concurrency suite (needs the dockerized MySQL): `cd backend && docker compose up -d && php artisan test -c phpunit.concurrency.xml`

**Known gotcha to remember:** tests that spawn a real subprocess to prove locking (the two `tests/Concurrency/*ConcurrencyTest.php` files) must use `DatabaseMigrations`, not `RefreshDatabase` — `RefreshDatabase` wraps a test in one uncommitted transaction that's rolled back at the end, so a spawned subprocess (a separate DB connection) would never see the seeded data.

---

*This document serves as a blueprint for building the new system. v0.3 reflects a pre-build security/financial-integrity hardening pass — see `adr.md` for why each major decision was made, and `foundation-security.md` for the actionable checklist derived from those decisions. Technical details may evolve as architectural decisions are finalized, but any change to a recorded ADR should be deliberate and documented, not incidental.*
