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
- **(Live) Enable rapid partner onboarding** — branded storefronts with custom domains; the platform collects all retail into one company account and the ledger splits it (no xenPlatform — [ADR-059](./adr/ADR-059-reseller-portal.md)). Affiliate payout: the platform opens one withdrawal ticket per affiliate on the 1st and the admin transfers it by the 7th (WTH-6, [ADR-132](./adr/ADR-132-affiliate-monthly-auto-payout.md)).

---

# 3. Users & Roles

- **Super Admin :** Full system access — manages games, packages, suppliers, admin users, all orders, withdrawals, vouchers, blacklist, settings, and developer tools. Only role that can approve withdrawals above the maker-checker threshold, and the only role that can create a voucher above its own threshold (see WTH-5, VCH-6 — these are no longer the same mechanism, see VCH-6's own row for why).
- **Admin :** Operational access — manages orders, reports, reviews, withdrawals (below threshold), vouchers (below threshold), and customer analytics. Cannot modify system settings, supplier credentials, or blacklist rules.
- **Affiliate (live, renamed from "Reseller" by ADR-072):** Owns a branded storefront with custom domain. Sets own markup within limits, manages own orders, views own reports and analytics, and is paid out monthly: the platform opens one withdrawal ticket per affiliate on the 1st, settled against the ledger by manual bank transfer (WTH-1..6, ADR-132 — no xenPlatform).
- **Reseller (live, ADR-072):** Prepaid-wallet account (shown to resellers as "Purchase Balance", ADR-131), not a storefront owner. Places wholesale orders against a deposited balance through the portal, the REST API (ADR-074) or the WhatsApp bot (ADR-075). Never withdraws: the only money it receives is a refund or a rebate, both credited to Purchase Balance (§13 "`Reseller` (wallet)").
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
- ~~Xendit xenPlatform sub-account fund-splitting~~ — dropped, not deferred (ADR-059). The ledger does the split; affiliate payout is a bank transfer of the monthly ticket (WTH-6, ADR-132).
- ~~The API / H2H "pull supply" channel + prepaid deposit wallet + top-up flow~~ — **shipped as the `Reseller` (prepaid wallet) system** (ADR-072..076: wallet + REST API keys + WhatsApp bot).
- Mobile native apps, loyalty/rewards program, affiliate marketing module, cross-affiliate inventory sharing. (The automated customer-support assistant moved into scope as ADR-136.)
- Automated refund decisions for **retail** orders — a failed retail order's voucher or retry is an admin decision (ADR-004). A failed **wallet** order is refunded automatically since [ADR-139](./adr/ADR-139-reseller-milestone-rebate.md) (decisions 26–29, a one-minute sweep through `OrderSettlementService::settle()`); a partially delivered one stays an admin decision. ~~Automated reactivation decisions~~ — **shipped** as `PendingReactivationAutoApprover` (ADR-100, live prod since 2026-09-16, streak-only gate since its 2026-09-24 addendum) plus the two 2026-09-24 addenda that made a dependent combo's own reactivation cascade automatically too (ADR-094 for single-package reactivation call sites, ADR-046 for the supplier bulk toggle).

---

# 5. Assumptions & Constraints

- **Technology stack**: Laravel REST API for backend (proven pattern from legacy system). Frontends are a **unified Next.js (React) stack** (ADR-009, superseding the earlier Vue/Next split in ADR-008) for admin, storefront and the partner portal, plus an Astro public API docs site (ADR-084): **Admin Panel = Next.js in client-rendered/SPA mode** — calls the Laravel API directly from the browser with a Sanctum cookie session (ADR-121), no SSR needed behind auth. **Storefront = Next.js**, using SSR/React Server Components where it genuinely benefits SEO-relevant public pages (§6.16).
- **Database** assumed to be relational (MySQL/PostgreSQL). All data models in Chapter 8 reflect this assumption, including the ledger table.
- **Hosting:** the backend runs on a Laravel Forge-managed DigitalOcean droplet behind Cloudflare, and the four frontends on Vercel (ADR-066, ADR-114).
- **Multi-tenant architecture**: schema tenant-aware (`affiliate_id`, `reseller_id` before ADR-072) from day 1 per ADR-003; the ORM global scope it promised was actually built in [ADR-057](./adr/ADR-057-tenant-isolation-mechanism.md) (2026-08-30). Every storefront — ours and third-party — is an `Affiliate` row ([ADR-061](./adr/ADR-061-every-storefront-is-a-reseller.md), superseding ADR-013's platform-owner special-case).
- **Supplier APIs**: assumed RESTful/JSON, but **must not be assumed to have a uniform response shape, auth style, or validation capability.** Confirmed by direct research (Gamevion API, MLBB-validator sample store) that these vary per supplier and even per game. All supplier calls go through a normalizing Adapter layer (see 6.21) — no business logic touches raw supplier response shapes.
- **Payment gateway: CHIP only** (`PaymentGatewayFactory` kept for a future multi-region second gateway — [ADR-022's 2026-09-01 addendum](./adr/ADR-022-multi-gateway-payment-strategy.md) removed Xendit; FPX proven live with real money; which methods are switched on: §14). Single company CHIP account; the platform collects 100% of every payment (including affiliate storefronts) and the ledger does the split. A branded affiliate domain takes no CHIP payment until an admin approves it (ADR-131 decisions 19–21, CHIP's condition 5). **xenPlatform dropped entirely** — not deferred ([ADR-059](./adr/ADR-059-reseller-portal.md) decision 6, superseding ADR-001's addendum).
- **Currency** assumed MYR as base, with dynamic exchange rate support for foreign-currency suppliers.
- **Auth** Role middleware on the backend — frontend routing is convenience, not security. Admins use a Sanctum cookie session with opt-in MFA (AUTH-7, ADR-121); affiliate/reseller-portal users use bearer tokens.
- **Refund policy**: no cash refunds (ADR-004). On 2026-10-22 the published Refund Policy (`/refund-policy`) was worded to offer a refund to the original payment method, for CHIP's merchant review; the founder withdrew that on 2026-11-01 (ADR-004 addendum): a failed storefront order resolves by retry-delivery or voucher only. The published text was changed to voucher-only on 2026-11-01 (§16 item 129, closed).
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
| ~~**GAME-12**~~ | ~~Each supplier mapping on a Game stores its own `supports_validation` flag~~ — **❌ Dropped, 2026-09-13.** Neither supplier integrated then (Gamevion, Digiflazz) exposed player-validation via its own API — both `validatePlayer()` unconditionally throw `ValidationNotSupportedException` (ADR-005's own "validation happens implicitly at order time"), and no code path anywhere ever read the flag this row described. Not a capability to build an admin toggle for. Gamevion's v2 API (ADR-137) has `/api/validate`, unused because the adapter interface carries no game code; player validation is supplier-independent (MID-8). | — |

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
| **RES-2** | Admin can add affiliate with business info, markup % + max markup %, domains, an initial wholesale tier, the first portal user (set-password invite), and an **"Our own brand" flag** (`is_owned`, ADR-061) — when set, the brand has no markup and no wholesale tier (markup forced 0 and a tier refused, ADR-061 2026-10-30 addendum) and a per-brand consumer-Membership toggle is shown. **No xenPlatform sub-account** — dropped entirely (ADR-059 decision 6); all money lands in the one company CHIP account | ADR-058 58b; `is_owned` + Membership toggle ADR-061 |
| **RES-3** | Admin can edit affiliate details + change tier (writes an `affiliate_tier_changes` audit row; refused for an owned brand) and every markup change (an `affiliate_markup_changes` row); can toggle `is_owned` / per-brand `membership_enabled` (ADR-061) | ADR-058 58b; flags ADR-061 |
| **RES-4** | Admin can impersonate affiliate — mints a short-lived `affiliate`-guard Sanctum token scoped with an `impersonate` ability, audited in `affiliate_impersonation_sessions` (real admin identity, portal identity, token id, IP, start/end). The persistent "impersonating" banner is the portal's job (ADR-059 59c, `reseller/src/components/ImpersonationBanner.tsx`). 58b owns the mint/end endpoints, the audit table, and auto-ends sessions on affiliate deactivate/delete | backend + admin UI ADR-058 58b; portal entry + banner ADR-059 59c |
| **RES-5** | Admin can activate/deactivate affiliate (deactivate ends any live impersonation; ADR-060 storefront 503 + Cloudflare suspend is ADR-060) | ADR-058 58b |
| **RES-6** | Admin can delete affiliate — **soft-delete only**, blocked unless earnings balance = 0 and no pending/approved withdrawal; the **`is_primary`** row cannot be deleted (ADR-061 — was "platform-owner row") | ADR-058 58b; `is_primary` guard ADR-061 |

**Since ADR-061** every storefront is an `Affiliate`, told apart by `is_owned` / `is_primary`, and consumer Membership is per-brand. The admin screens are `/admin/affiliates` (`/admin/resellers` is the wallet Reseller screen since ADR-072 PR-A), and the portal app keeps its `reseller/` name on purpose (ADR-072 decision 3). The branded `Host`-resolved storefront, per-brand pricing and ledger split, custom domains and the portal Storefront screen are ADR-060. Build and live status: §15's Affiliates row.

## 6.8 Admin — Orders Management

| ID | Functional Requirement | Priority |
| --- | --- | --- |
| **ORD-1** | System displays order table: order #, customer, game, package, price, fee, profit, status, delivery status, date | **MVP** |
| **ORD-2** | System provides status filters: Need Action, Processing, Completed, Today, All | **MVP** |
| **ORD-3** | System provides advanced filters: Needs Resolution, Pending Delivery, Failed Delivery, date range, game | **MVP** |
| **ORD-4** | System provides pagination with configurable per-page (10/25/50/100) | **MVP** |
| **ORD-5** | Admin can export orders to CSV and Excel | **MVP** |
| **ORD-6** | Admin can view order details: customer info, game/package, payment info, delivery attempts, supplier responses, status history timeline | **MVP** |
| **ORD-7** | Admin can resolve an order via retry-delivery or voucher issuance only — never a refund to the original payment method (ADR-004). A Super Admin may pay a failed order's voucher balance out by bank transfer, which closes the voucher (ADR-004 2026-11-12 addendum) | **MVP** |
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
| **VCH-4** | Admin can revoke active voucher, unless an order is still using it (ADR-036 2026-11-07 addendum) | **MVP** |
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
| **ADAPT-4** | Each adapter also normalizes the **outgoing request**, not just the response: our canonical `reference_number` is transformed into whatever field name/format/charset a given supplier's API expects (e.g. Gamevion's `client_ref`, Digiflazz's `ref_id`) — business logic generates one canonical reference and never constructs a supplier-specific request shape itself | **MVP** |

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
| **JOKI-10** | A penjoki is paid through the existing pay-after flow: `.done` records the drawdown (the amount owed), and the founder records each bank payment as a `SupplierTransfer` with a receipt. Revenue for a joki order paid in one month and delivered in the next follows the dated-facts rule of ADR-127 (§16 item 131, closed 2026-10-25) | **Critical** |


## 6.26 Marketing Campaigns — KOL promo codes (per [ADR-118](./adr/ADR-118-marketing-campaigns.md))

A KOL (influencer) is paid a monthly fee to bring new customers to PekanGame with their own promo code. The platform answers one question per KOL and month: is this KOL worth paying again? v1 is the ADR's 2026-11-04 addendum (V1–V20): PekanGame only, new customers only.

| ID | Functional Requirement | Priority |
| --- | --- | --- |
| **CAMP-1** | A campaign is created with its first monthly fee row (RM0 allowed) on the primary brand. A renegotiation adds a row from its month; only a row's last month can change, and nothing in a closed accounting month can change. | Must |
| **CAMP-2** | A promo code is chosen by the admin: 3–20 letters and digits, stored uppercase, unique forever. Its percentage is fixed once redeemed; its budget, optional per-order maximum, window and on/off stay editable. No delete; pause it instead. Ending a campaign stops its codes. | Must |
| **CAMP-3** | At checkout the code takes a percentage off the Standard price (never Member, ResellerWallet, Joki or a membership purchase), shown to the customer as RM saved, never the percentage. It is refused when the order would keep less than the panel minimum margin (default 2%) or exceed the code's budget. | Must |
| **CAMP-4** | One KOL discount per customer per brand: refused when the email, phone or player account matches a delivered order on the brand, a live redemption, or a reseller's own email or phone. The customer sees one generic message. An identical customer's abandoned unpaid checkout is cancelled through CHIP and replaced. | Must |
| **CAMP-5** | The discount lowers `selling_price` and `platform_profit` before the order is written, is reserved with the voucher and quota, committed on delivery and restored on failure (a partial delivery restores its undelivered share). | Must |
| **CAMP-6** | `?promo=CODE` on any storefront page prefills the code for 7 days in the browser and logs one click per tab. It never attributes an order. | Should |
| **CAMP-7** | Every later order of a KOL customer is attributed to that KOL's code at creation and never rewritten. | Must |
| **CAMP-8** | Accounting: the discount is contra-revenue (gross − promo = net); the KOL fee is an advertising expense in its month of service (accrued from the schedule); each payment is an Advertising Envelope posting tagged with the campaign; the prepaid / owed balance is computed. Neither changes operating profit or the month-close allocation. | Must |
| **CAMP-9** | Admin → Customers → Campaigns (super admin): per campaign and range, clicks, new and qualified new customers, median first order, came back and 30-day repeat, sales and ledger profit (redeemed and attributed-only apart), discount given, accrued fees, CAC and net; all time beside it; per code the same basics. Panel settings: minimum margin, qualified first order (RM10), target CAC and trial months (no verdict until both are set). | Must |

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
6. Admin resolves it by **(a) retry or resend delivery** (same or different supplier), or **(b) compensation**: a store-credit voucher, restoring the checkout voucher, or a refund to a reseller's own wallet. No refund to the original payment method exists, and none will be built (ADR-004 addendum, 2026-11-01). A rare exception, released 2026-11-12 via #791 (ADR-004 addendum, §16 item 179, closed): a super_admin pays a failed-order voucher's balance out by bank transfer, which closes the voucher; the published Refund Policy was changed to voucher-only on 2026-11-01 (§16 item 129, closed).
7. Customer notified of the outcome.

## 7.3 Supplier Price Sync

1. Admin or scheduler triggers Price Sync.
2. Supplier Middleware queries all supplier APIs (via Adapter layer) for latest product catalog and prices.
3. Middleware compares with local data: updates prices, creates new packages, deactivates removed ones.
4. Packages that were previously inactive but now available are flagged as "Pending Reactivation".
5. System records sync details: games processed, packages created/updated/deactivated.
6. Admin reviews Pending Reactivation table and approves/dismisses packages.
7. Approved packages are reactivated in the catalog with updated prices.

**Price swing ([ADR-138](./adr/ADR-138-package-sale-lifecycle.md)):** step 3's price update goes by direction. A rise applies at once. A drop of 30% or more is held: the package keeps selling at the old cost, and the highest price seen applies after 24 hours, or sooner if an admin Approves. A price back in range, or a product not seen active, closes the hold. More than 10 packages of one supplier swinging 30% or more in one run is treated as bad data: both directions are held, nothing applies by itself, and one email goes out. Each limit is set per supplier in `/middleware`.

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
1. Admin creates voucher: enters customer email, **sets the amount themselves**, records reason, optional expiry (a gift can expire; compensation never does). Amount at/above threshold can only be created by a Super Admin (VCH-6 — single-step role gate, not the same two-person maker-checker as WTH-5; see VCH-6's own row for why).
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
| **Package** | `id`, `game_id`, `name`, `cost_price` (API suppliers: from the supplier sync at the landed cost rate, [ADR-134](./adr/ADR-134-landed-cost-rate.md), never admin-editable; manual packages: agreed cost, admin-edited and audited, ADR-125), `markup_percent` (admin-set per package, decimal — added 2026-07-25), `standard_selling_price` (= `cost_price` × (1 + `markup_percent` / 100), rounded to the nearest sen — **stored**, recomputed only when `markup_percent` changes via `PackageMarkupService`, never recomputed live at order time; renamed from `reseller_cost_price` 2026-08-29, [ADR-027](./adr/ADR-027-vip-membership.md)), `is_active`, `supplier_id`, `supplier_package_ref`, `sort_order` | A specific top-up denomination within a game. `standard_selling_price` is the platform owner's own guest/standard price — it is **not** the customer-facing price for every buyer type; a real affiliate's own wholesale rate is a separate mechanism (`affiliate_membership_tiers`, see the Affiliate row below and [ADR-056](./adr/ADR-056-reseller-wholesale-pricing-subscription-tiers.md) — live, checkout wiring [ADR-060](./adr/ADR-060-multi-tenant-branded-storefront-custom-domain.md)). Today it happens to equal the customer-facing price (the primary brand runs at `markup_pct = 0`), but the schema/formula never assumes that. |
| **Supplier** | `id`, `name`, `slug`, `driver` (the adapter: an API driver, where `slug = driver`, or `manual`), `funding_mode` (`prepaid`/`pay_after`), `joki_role` (null, `pool` or `penjoki`, JOKI-5), `logo_url`, `is_active`, `api_config` (JSON — credentials, endpoints, or for a manual supplier `manual.reply_sla_minutes`; **encrypted at rest**), `balance`, `currency` | A game credit supplier integrated via Middleware, accessed only through its Adapter (6.21). A manual supplier (MSUP-1) is panel data, not code; capabilities (`scopeApiBacked`, `pollsStatus`, `displayBalance`) are asked of the row, never inferred from the slug. |
| **Affiliate** | `id`, `business_name`, `contact_name`, `email`, `phone`, `markup_pct`, `max_markup_pct`, `domains` (JSON array, nullable), `status`, `is_owned` (bool — our own brand, [ADR-061](./adr/ADR-061-every-storefront-is-a-reseller.md)), `is_primary` (nullable bool, DB-enforced-single — the console/job/non-`Host` fallback tenant, never deletable), `membership_enabled` (bool — per-brand consumer-Membership toggle, effective only when the global `PlatformSettings.membership_enabled` master is also on), `joki_enabled` (bool — per-brand Joki toggle, owned brands only, effective only with the global Joki switch, JOKI-1), `deleted_at` (soft-delete, ADR-058 58b), `notes` — **no `balance` column**; balance is derived from `LedgerEntry` — **renamed from `Reseller`/`resellers` 2026-09-04, [ADR-072](./adr/ADR-072-reseller-system-split.md) PR-A, zero behaviour change** (vacates the `Reseller` name for a future prepaid-wallet entity, ADR-073..075) | A branded storefront owner. **Every storefront is a row here** — ours and third-party alike, with no special code path ([ADR-061](./adr/ADR-061-every-storefront-is-a-reseller.md), superseding ADR-013's `business_name = 'Platform Owner'` magic string). `Affiliate::primary()` resolves the one `is_primary` row for any context with no `Host` to resolve a brand from. `markup_pct`/`max_markup_pct` = the affiliate's own margin on top of what they pay the platform (an `is_owned` brand defaults 0 — all margin books as `platform_profit`). **What an affiliate pays the platform** depends on which `affiliate_membership_tiers` row they're subscribed to (or `standard_selling_price` if none active) — [ADR-056](./adr/ADR-056-reseller-wholesale-pricing-subscription-tiers.md), live, checkout wiring ADR-060. |
| **Reseller** *([ADR-072/073](./adr/ADR-072-reseller-system-split.md))* | `id`, `business_name`, `contact_name`, `email`, `phone`, `reseller_tier_id` (nullable FK → `reseller_tiers`, restrict-on-delete, direct swap — no subscription state machine), `is_active` (bool, default true — account-level order-placement kill switch, doesn't freeze the balance), `notes`, `balance_cap_sen` (nullable, ADR-131: this reseller's Purchase Balance cap, null = the platform default `platform_settings.balance_cap_default_sen`), `deleted_at` (soft-delete; set only by the Close account action, ADR-131) — **no `balance` column**; wallet balance is derived from `LedgerEntry`, owner type `reseller_wallet` | The prepaid-wallet wholesale-buyer entity the Reseller API (ADR-074) and Reseller Bot (ADR-075) channels share — reclaims the `Reseller` name vacated by ADR-072's `Affiliate` rename. Structurally the opposite of `Affiliate`: only ever **spends** against a deposited balance, never earns. `reseller_tiers.markup_percent` (over `cost_price`, no monthly fee) prices every wallet order. Orders are placed through `ResellerOrderPlacementService` by the API (`reseller_api_keys`) and the Bot; portal login via polymorphic `affiliate_users`. |
| **LedgerEntry** | `id`, `owner_type` (platform/affiliate/reseller_wallet — `'affiliate'` since ADR-072 PR-A, was `'reseller'`), `owner_id`, `type` (platform and affiliate: order_profit/withdrawal/voucher_issued/membership_fee/affiliate_tier_fee, plus `adjustment`, designed but with no writer yet; reseller_wallet: the `WalletEntryType` enum, the one list), `amount` (signed sen: credit positive, debit negative), `reference_type`, `reference_id`, `created_at`, `created_by`, `reason` (required for `adjustment`) | **Immutable, append-only.** Sole source of truth for any balance. Never updated or deleted, only inserted (ADR-002). **Every delivered order writes exactly two `order_profit` credit entries** — one for `platform_profit` (owner_type=platform) and one for `affiliate_profit` (owner_type=affiliate, the order's `affiliate_id`) — kept as two rows even when both resolve to the same owner (an `is_owned` brand), so the ledger logic is identical for every affiliate. |
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
| **CustomerNotification** | `event`, `dedupe_key`, `order_id`, `voucher_id`, `session` (`customer_support`/`bot`), `phone` or `chat_id`, `message`, `status` (`queued`/`sent`/`failed`/`skipped`/`unknown`/`expired`/`superseded`), `due_at`, `attempts`, `error`, `sent_at` | The one persisted WhatsApp outbox: customer receipts on the customer-support session (ADR-116), supplier-group posts and reminders (MSUP-4, MSUP-6) and, since the ADR-116 2026-11-14 addendum, every reseller and supplier bot reply on the bot session. A joki progress message (JOKI-7) is queued with an earliest send time and worded when it is sent. |
| **JokiSetting** | one row: `is_enabled`, `payout_share_pct`, `min_stars`, `grading_price_rm`, `grading_express_price_rm`, `grading_lands_at_stars`, `max_mythic_stars`, `roles`, `express_description`, `terms_html`, `progress_message_interval_minutes` | Joki's panel settings (JOKI-1, 2, 3, 7). Whole RM |
| **JokiTier** | `name`, `sort_order`, `kind` (`divisional`/`mythic`), `divisions`, `stars_per_division`, `mythic_from_stars`, `rate_rm`, `express_rate_rm`, `icon_url` | The rank model, bottom first (JOKI-2) |
| **JokiRoute** | `package_id` (unique), from/to tier, division and stars, `price_rm` (nullable), `is_quotation` | A route package's climb and promo price, or a quotation (JOKI-2) |
| **JokiOrder** | `order_id` (unique), `from_rank`, `to_rank`, `current_rank`, `current_rank_at`, `is_express`, `hero_request`, `role`, `play_window`, `quote` (snapshot), `payout_share_pct`, `terms_snapshot` | What a joki order bought and its progress (JOKI-3, JOKI-6) |
| **JokiAssignment** | `order_id`, `from_supplier_id`, `to_supplier_id`, `assigned_by`, `created_at` | One row per Assign (JOKI-5). Never edited |
| **JokiProgressUpdate** | `order_id`, `rank`, `actor`, `channel` (`admin`/`supplier_bot`), `created_at` | One row per progress update (JOKI-6). Never edited |
| **Campaign** *([ADR-118](./adr/ADR-118-marketing-campaigns.md))* | `affiliate_id` (the primary brand in v1), `name`, `ended_at`, `created_by` | One KOL's push; parent of its codes and fee rows (CAMP-1). |
| **CampaignFee** | `campaign_id`, `monthly_fee_sen`, `from_month`, `to_month` (nullable) | The KOL's agreed monthly fee; append-only except `to_month` (CAMP-1, CAMP-8). |
| **PromoCode** | `campaign_id`, `code` (unique forever, uppercase), `discount_percent`, `max_discount_sen` (nullable), `budget_sen`, `is_active`, `starts_at`, `ends_at` | A KOL code (CAMP-2, CAMP-3). Budget used is computed from its redemptions, never a counter. |
| **PromoCodeRedemption** | `promo_code_id`, `order_id` (unique), `amount_sen`, `restored_sen`, `status` (reserved/committed/restored), `over_cap` | One code use on one order (CAMP-5). The order carries `promo_code_id`, `promo_discount_sen` and `attributed_promo_code_id` (CAMP-7). |
| **PromoCodeClick** | `promo_code_id`, `created_at` | One `?promo=` visit (CAMP-6). |

Not modelled here; each ADR holds its own schema: the reseller rebate tables ([ADR-139](./adr/ADR-139-reseller-milestone-rebate.md)), the CS assistant ([ADR-136](./adr/ADR-136-customer-support-assistant.md)), partner applications ([ADR-133](./adr/ADR-133-partner-onboarding.md)), and the supplier ledger with its landed cost pool ([ADR-083](./adr/ADR-083-internal-accounting-financial-reconciliation.md), [ADR-134](./adr/ADR-134-landed-cost-rate.md)).

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
- **Error Handling :** All supplier API errors must be logged with full request/response payload, credentials and excess PII redacted. Admins are emailed (Plunk, every active admin) for a failed backup, a long Horizon queue wait and a stale FX rate. Stuck orders, supplier balance and circuit-breaker state are monitored on the Dashboard (System Health), not pushed. A low-supplier-balance email (one a day per supplier while it is below its buffer, ADR-115 2026-11-05 addendum) is live since release #699 (2026-11-05; §16 item 28, closed). UptimeRobot polls `/api/health`, the OpenWA gateway's own health URL and `/api/health/whatsapp` (§16 item 11).
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
| **Gamevion** | Supplier — game credit catalog, price feed, order creation/fulfillment | The backup supplier, MYR-billed, on `gamevion.cloud` since release #705 (ADR-137; the old `api.gamevion.com` contract of ADR-040 is retired). One `gvk_` Bearer key from the IP `159.223.39.16`; references `{game}:{sku}`; our reference is its `client_ref` (a repeat never charges twice); delivery by a signed `order.updated` webhook or a read-first status check. Only the games ticked in `/middleware` sync. Unfunded (no deposit recorded, balance RM 0) and none of its packages on yet. ADR-006/137. |
| **Digiflazz** | Second supplier — Indonesian H2H aggregator (game and data SKUs) | **The only live supplier.** Prod key wired 2026-09-02, **funded 2026-09-15**, delivering real orders. IDR→MYR at the landed cost rate, what the deposit actually cost (ADR-134, revising ADR-033). Inbound `POST /api/webhooks/digiflazz` (HMAC-SHA1 `X-Hub-Signature` + IP allowlist). Topup has no rate limit; the full price list is limited to 1 call per 5 min (`rc 83`), per Digiflazz CS on 2026-09-29. Same-`ref_id` re-submit replays the stored outcome, which ADR-102's 2026-09-29 addendum relies on. ADR-030/067/069. |
| **Supplier API(s) — general** | The Adapter contract all suppliers implement | Confirmed to vary significantly per supplier (auth, response envelopes, validation availability per-game, idempotency). All access goes through the Adapter layer (§6.21), resolved per-order by `supplier_id` (`SupplierAdapterFactory`, ADR-031). Onboarding a new supplier = a new adapter, enforced from MVP (ADR-006). |
| **OpenWA** | WhatsApp gateway, two sessions. `reseller-bot` runs the Reseller Bot channel (`.order` / `.baki` / `.listharga` / `.topupbaki`; `.batalbaki` from ADR-135) and, since ADR-125, supplier-group posts and commands (`.done` / `.gagal` / `.pending`). `customer-support` (+60 11-4301 3150) sends customer voucher codes and opted-in Delivered receipts, and receives opt-in/`STOP`; a sender shown only as a privacy id (`@lid`) is answered in its own chat (ADR-116, 2026-11-01 addendum); the CS assistant reads its inbound chats to draft answers (ADR-136) | Self-hosted on the prod box, v0.27.1 since 2026-11-02 (v0.27.0 from 2026-11-01, v0.25.0 from 2026-10-22; runs from `~/openwa-deploy/releases/<tag>` with `data/` and `.env` shared, `~/OpenWA` a symlink to the live release, upgraded with `scripts/openwa-upgrade.sh`, ADR-114 2026-11-02 addendum; daemon runs `node dist/main`, no rebuild on restart; `data/` + `.env` backed up nightly to R2, encrypted, ADR-039 2026-10-22 addendum; `MESSAGE_RETENTION_DAYS=90` since 2026-10-25), Baileys engine, `AUTO_START_SESSIONS=true` since 2026-10-14 (sessions come back by themselves after a restart), `BAILEYS_MARK_ONLINE_ON_CONNECT=false` since 2026-10-17 (the paired phones keep their notifications). `SEND_PACING` is on (warm-up and cold caps) and `SIMULATE_TYPING` is on. Inbound `OpenWaWebhookController` (HMAC `X-OpenWA-Signature`) routes by `sessionId`. The CS key also answers the phone → `@lid` lookup (`GET /contacts/{number}/lid`, v0.27.1+). ADR-075/076/114/116. |
| **Plunk** | Transactional email — membership OTP, receipts and renewal reminders, partner-portal invites, domain-status mails, and ops alerts (backup failure, Horizon long-wait, stale FX rate). Customer order notifications go by WhatsApp instead (ADR-116) | API-based (`PLUNK_API_KEY`). Project tracking is **Marketing only** since 2026-10-30 (transactional mail carries no click redirects or open pixel); `pekangame.com` is a verified sender domain beside `pekangame.space` (build-log 2026-10-30). Replaced the generic "SMTP or API" placeholder. ADR-027/068. |
| **Laravel Reverb** | Realtime broadcasting — order-status push, replaces application-level polling on the customer buy-flow | Self-hosted WebSocket server + Echo client. ADR-047, ADR-071 PR4. |
| **Vercel** | Custom-domain provisioning for Affiliate whitelabel storefronts | `AffiliateDomainProvider` seam (Vercel / no-op). `affiliate_domains` table, self-serve onboarding, provider opacity in the portal; a domain may bring a `www` redirect twin (`redirects_to_domain_id`, ADR-060 second 2026-10-30 addendum). ADR-060 (reverses decision 3's Cloudflare-for-SaaS), ADR-078. |
| **Anthropic (Claude)** | CS assistant drafts on the CS WhatsApp number (Claude Haiku 5.5, chosen in run #3) | [ADR-136](./adr/ADR-136-customer-support-assistant.md); the answer and judge models are set in the panel; the API does not train on inputs. |
| **Google Gemini** | Admin Reports assistant over curated read-only SQL views; also selectable as a CS assistant model | [ADR-087](./adr/ADR-087-admin-reports-llm-assistant.md), ADR-136; paid tier, no training on inputs. |
| **Player validators (RedxGame, UniPlay)** | Pre-payment player-ID check where a game has a validator profile (MID-8) | Supplier-independent ([ADR-005](./adr/ADR-005-player-id-pre-payment-validation-is-not.md) addenda); the public Game ID Checker uses the same chain ([ADR-123](./adr/ADR-123-public-game-id-checker.md)). |
| **Cloudflare** | Proxy in front of `api.`; Turnstile (Game ID Checker, the public reseller application); R2 storage (gallery, backups, accounting receipts, KYC documents) | [ADR-095](./adr/ADR-095-cloudflare-r2-storage-cutover.md) (R2), [ADR-123](./adr/ADR-123-public-game-id-checker.md) and [ADR-133](./adr/ADR-133-partner-onboarding.md) (Turnstile). |
| **Google Analytics (GA4) / Facebook Pixel / TikTok Pixel** | Website analytics + ad conversion tracking | IDs from Settings (per-brand for Affiliates). |

---

# 11. Proposed Features / Future Phases

The scheduled roadmap lives in §16 and build status in §15; neither is restated here.

Unscheduled ideas, not on the backlog:

- ~~**Automated Customer Support Chatbot.**~~ Now on the roadmap as the CS WhatsApp assistant ([ADR-136](./adr/ADR-136-customer-support-assistant.md), §16 item 158; Shadow since 2026-11-04). The WhatsApp status card (ADR-116) and the Reseller bot (ADR-075) cover order tracking.
- **Mobile Native Apps.** React Native or Flutter apps for the customer storefront experience.
- **Loyalty & Rewards Program.** Points system for customers based on order frequency/value.
- **Affiliate Marketing Module.** Third-party affiliate referral with commission tracking.
- **Cross-Affiliate Inventory Sharing.** Route orders to best-available supplier when one affiliate's preferred supplier is low on balance.
- **Automated Refund Decisions for retail orders.** Move from manual admin approval (MVP default, per Open Question resolution below) to rules-based automation once enough fraud/failure pattern data exists. (A failed wallet order is already refunded automatically, ADR-139; reactivation is automated, ADR-100.)

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
- **Product (Reports) :** What a customer bought, as the storefront shows it: the same game and denomination, else the same catalog code, else the package alone (`Package::productKey()`, ADR-034's dedup). A combo and a native package of the same amount are one product. Each order keeps a snapshot (`orders.product_key`, `product_name`) taken when it is created, so a resend swap never moves it ([ADR-104](./adr/ADR-104-admin-panel-visual-redesign.md) 2026-11-03 addendum).
- **Prior-month adjustment :** How much earlier closed months' live operating profit has moved since they were closed; carried into the next close so a closed month is never rewritten.
- **Cash equation / gap :** At a close, what the company holds (cash accounts, supplier prepaid, CHIP not yet settled) minus what it owes or has set aside (envelopes, wallets, affiliate earnings and unpaid withdrawals, vouchers outstanding, undelivered orders, reseller rebates earned and not claimed). A gap above RM1 needs a written note.
- **Rebate milestone :** One step of a reseller's monthly rebate ladder for one month: reach the step's recognised sales, get its fixed RM reward, capped at a share of our actual profit on that reseller's orders. A step is earned when reached and claimed in the portal into Purchase Balance. An unclaimed step lapses at 00:00 KL on the 1st of the month after next ([ADR-139](./adr/ADR-139-reseller-milestone-rebate.md)).
- **Unspent rebate :** The part of a reseller's Purchase Balance that came from rebate claims and is not spent yet. It is derived by replaying the wallet ledger (a purchase spends rebate first), never stored, and forfeited when the account closes (`UnspentRebate`, ADR-139 decision 14).
- **Rebate breakage :** Unspent rebate forfeited at account closure, booked as income. A lapsed unclaimed reward is not breakage: it reverses the rebate line in the month it lapses (ADR-139 accountant addendum).
- **Maker-Checker :** A dual-control pattern where the person who initiates a sensitive action (large withdrawal, WTH-5; a large voucher is a role gate only, VCH-6) cannot also be the sole approver of that same action.
- **Cost Price :** What a package costs us from its supplier, in sen. For an API supplier billed in a foreign currency it is converted at the landed cost rate (below). Base for all markup calculations.
- **Landed cost rate :** What one unit of a supplier's currency actually cost us: a moving average over our deposits, prepaid transfer fees included. Used at both FX seams, Price Sync and delivery; the market mid-rate is display-only ([ADR-134](./adr/ADR-134-landed-cost-rate.md), revising ADR-033).
- **System Markup :** Profit percentage added on top of cost price (system's margin).
- **Affiliate Markup :** Profit percentage an affiliate adds on top of their wholesale base (`affiliates.markup_pct`, capped by `max_markup_pct`). Real since [ADR-061](./adr/ADR-061-every-storefront-is-a-reseller.md); an `is_owned` brand has none (forced 0 and ignored at pricing, ADR-061 2026-10-30 addendum: all margin books as `platform_profit`). Applied at checkout — ADR-060. Renamed from "Reseller Markup" 2026-09-04, [ADR-072](./adr/ADR-072-reseller-system-split.md) PR-A.
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
- **Voucher Merge :** An admin-triggered, opt-in action that consolidates two or more of one customer's active vouchers into a single new code, sized to the sources' combined `remaining` (not their original `amount`). The sources are voided (`status = 'merged'`, `remaining = 0`) — never deleted — and no `ledger_entries` row is written, since a merge creates no new cost: an admin-issued source's cost was booked at its own issuance, and a compensation source is customer cash that never debits the ledger (ADR-024 2026-10-12 addendum). An expired source cannot be merged (ADR-036 2026-10-12 addendum), nor can a voucher an order is still using, and if any source has no expiry the merged voucher can't have one (ADR-036 2026-11-07 addendum). Distinct from *redemption* (spending a voucher at checkout) and from Path B's *restore* (giving a reserved amount back to `remaining`) — a merge never happens automatically and is never part of the checkout flow itself (ADR-036).
- **Voucher Expiry :** A voucher is expired when `expires_at` is set and past (`Voucher::isExpired()`). Nothing sets `status` to `expired`; the stored status stays `active`. Checkout and merge refuse it, and admin screens show it as expired through `display_status` (§16 item 84). The Monthly Summary's breakage reads `expires_at` too. Only gift credit (Path A) can expire: a compensation voucher never does (ADR-036 2026-11-07 addendum).
- **Membership Renewal Reminder :** An email to a member 3 days before (`before`) and 1 day after (`after`) their paid-through date, counted in Malaysia days, one of each per paid-through period (`membership_reminders`). Not marketing; no unsubscribe. WhatsApp reminders are designed, not built (ADR-068 2026-10-13 addendum, §16 item 85).
- **Customer (Customer Analytics) :** Not a stored entity — a derived grouping of `Order` rows by `customer_email`, computed at query time. This does not reopen ADR-011 (guest checkout, no `Customer` model/login); it's the same field Reports already surfaces as `latest_order.customer_email`, now used as a grouping key (ADR-049).
- **Standard Selling Price :** The platform owner's own guest/non-member retail price — `cost_price × (1 + package.markup_percent)`. Renamed from `reseller_cost_price` 2026-08-29 once its usage was recognized as never affiliate-specific — see Affiliate Wholesale Tier below and [ADR-027](./adr/ADR-027-vip-membership.md).
- **Affiliate Wholesale Tier :** A paid, admin-CRUD-managed row in `affiliate_membership_tiers` that an affiliate subscribes to monthly to unlock a lower `cost_price`-based wholesale rate for their own purchases from the platform. Fully independent of consumer VIP Membership (ADR-027) — no membership feature exists on any *third-party* affiliate storefront, and an affiliate's tier is never derived from anything their own downstream customers do. An unpaid or lapsed affiliate (past a ~3-day grace period) falls back to Standard Selling Price, the same price a guest pays — not a shutdown, just the walk-in rate, self-correcting on payment. Code: `affiliate_membership_tiers` + `affiliate_subscriptions`, `PricingService::calculateForAffiliate()`, `AffiliateTierFeeService` + grace/lapse state machine, `ChargeAffiliateTierFeesCommand` ([ADR-056](./adr/ADR-056-reseller-wholesale-pricing-subscription-tiers.md); admin CRUD ADR-058 58b; checkout ADR-060). Renamed from "Reseller Wholesale Tier" (and `calculateForReseller()`) 2026-09-04, [ADR-072](./adr/ADR-072-reseller-system-split.md) PR-A.
- **`is_owned` affiliate :** A storefront the founder owns (our brand), flagged `affiliates.is_owned` ([ADR-061](./adr/ADR-061-every-storefront-is-a-reseller.md); entity renamed from `Reseller`/`resellers` 2026-09-04, [ADR-072](./adr/ADR-072-reseller-system-split.md) PR-A). Its contribution is internal money, not a payable; it has no markup and no wholesale tier (ADR-061 2026-10-30 addendum): it prices on the standard chain and every margin books as `platform_profit`; it may run consumer Membership. Contrast a third-party affiliate (`is_owned = false`) — feature-limited, **no consumer Membership** (the founder's chosen differentiator). Replaces the `business_name = 'Platform Owner'` magic string.
- **`is_primary` affiliate :** The single `is_owned` row (`affiliates.is_primary`, DB-enforced as exactly one via a nullable-unique index) that every non-`Host` code path resolves to — console commands, queue jobs, migrations, not-yet-brand-aware admin screens — via `Affiliate::primary()`. Never deletable. [ADR-061](./adr/ADR-061-every-storefront-is-a-reseller.md). Since ADR-060, storefront requests resolve their brand by `Host`; `is_primary` stays only the fallback.
- **`Reseller` (wallet) :** Distinct from `Affiliate` (whitelabel storefront partner, above) — a `Reseller` spends from a prepaid deposit balance and never withdraws it; the only money it receives is a refund or a claimed rebate, both credited to that balance ([ADR-139](./adr/ADR-139-reseller-milestone-rebate.md)); buffers the platform's own working capital against Digiflazz/Gamevion running dry. Split out of the original single `Reseller` table and renamed from it 2026-09-04 ([ADR-072](./adr/ADR-072-reseller-system-split.md)/[ADR-073](./adr/ADR-073-reseller-wallet.md)). Assigned a `reseller_tiers` markup tier (admin-managed, zero self-serve pricing); orders it places carry `orders.wallet_reseller_id` and `pricing_basis = 'reseller-wallet'`, with `affiliate_profit` always `0` by construction (`PricingService::calculateForAffiliate(affiliateMarkupPct: 0.0)`, [ADR-073](./adr/ADR-073-reseller-wallet.md) decision 1) — the markup is realized as ordinary `platform_profit` instead. Two ordering interfaces share one order-placement contract (`ResellerOrderPlacementService::placeOrder()`, [ADR-073](./adr/ADR-073-reseller-wallet.md) decision 4): the **Reseller API** (per-tenant `reseller_api_keys`, [ADR-074](./adr/ADR-074-reseller-api-channel.md)) and the **Reseller Bot** (WhatsApp via OpenWA, group-identity mapping, [ADR-075](./adr/ADR-075-reseller-bot-channel.md)).
- **Purchase Balance :** The name a Reseller sees for the wallet below (portal, Reseller API text, bot, receipts), and "Add Balance" for a top-up, per CHIP's activation condition (ADR-131 decision 1): "wallet" and "e-wallet" suggest a regulated e-money product. Display name only: the code, the admin panel, the ledger types and the API field names keep "wallet".
- **Wallet ledger types :** The `ledger_entries.type` values a `Reseller` (wallet) account's own ledger carries (owner type `reseller_wallet`). The one list is the `WalletEntryType` enum; every writer takes its type from there. **Wallet Top-up** — a credit, written by either of two equal-standing paths: an admin's manual credit (`ResellerWalletService::manualCredit()`, PR-C, with an optional receipt/proof file) or the reseller's own self-serve CHIP checkout (`ResellerWalletService::completeTopup()`, PR-G, ADR-073 decision 3(a) — a `wallet_topup_attempts` row, `pending → paid/failed/expired`, capped at one `pending` attempt at a time). **Wallet Debit** — the synchronous spend at order-placement time (`ResellerOrderPlacementService::placeOrder()`, PR-D), the tier-priced cost of one order, debited from the same `LedgerService::debit()` primitive every other debit in this codebase uses. **Wallet Refund** — a credit reversing a specific debit, written only by `OrderSettlementService::settle()`: automatically within a minute for a Failed order (ADR-139 decisions 26–29), by an admin for a partial delivery or a Failed order held for a late supplier success. An internal-credit reversal, not a reopening of [ADR-004](./adr/ADR-004-no-cash-refunds-ever.md)'s "no cash refund" policy: the money stays in Purchase Balance. **Wallet Rebate** — a credit when the reseller claims a rebate milestone (`RebateClaimService`, ADR-139). **Wallet Rebate Forfeit** and **Wallet Closure Refund** — the two debits at account closure (`ResellerClosureService`, ADR-131 decisions 12–16): unspent rebate is forfeited, then the remaining balance is refunded by bank transfer.
- **`reseller_code` / `catalog_code` :** The stable, human-readable product-code scheme both Reseller channels order against, since a raw `Package.id` is unstable across a supplier swap (ADR-031/SYNC-5/6). `games.reseller_code` (`[A-Z]{2,10}`, globally unique) identifies the game; a package resolves via `{reseller_code}-{denomination}` for a numeric-value package (e.g. `MLMY-14`) or `{reseller_code}-{catalog_code}` for a bundle/pass with no inherent numeric value (e.g. `MLMY-P1`, `packages.catalog_code` — must contain a letter, mutually exclusive with `denomination`). Two independent equivalence-key spaces by construction, so a bundle can never falsely dedup against an unrelated real-value package. Resolved by the shared `Package::cheapestActiveFor()` / `ResellerCatalogService::resolveByCode()` seam ([ADR-074](./adr/ADR-074-reseller-api-channel.md)/[ADR-075](./adr/ADR-075-reseller-bot-channel.md) catalog-code addendum, built PR-E0).
- **Campaign :** A KOL/influencer marketing push — the grouping parent for one-or-more `PromoCode`s, with its own monthly fee schedule, and the unit `ReportService::campaignBreakdown()` rolls a KOL's attributed sales and ledger profit up against (Customer Analytics has no campaign filter in v1). Distinct from `Affiliate` (a branded storefront the KOL would have to actively run) — a `Campaign` lets the platform pay a KOL without them operating any storefront at all ([ADR-118](./adr/ADR-118-marketing-campaigns.md)).
- **PromoCode :** A multi-use discount code under one `Campaign`, redeemable by many different customers (contrast `Voucher`, below, which is locked to one named customer). A percentage off the Standard price only (never Member pricing, ResellerWallet or Joki), shown to the customer as RM saved, one per customer per brand, for new customers only, with its own budget cap — never shares a budget with a sibling `PromoCode` under the same `Campaign`, so multiple codes (e.g. one per KOL or platform) can be compared fairly ([ADR-118](./adr/ADR-118-marketing-campaigns.md)).
- **Attributed order :** An order stamped once, at creation, with the KOL code that brought its customer (`orders.attributed_promo_code_id`): the code redeemed on it, or for a later order the code that customer redeemed before on that brand. Never rewritten; the scorecard shows redeemed and attributed-only orders apart ([ADR-118](./adr/ADR-118-marketing-campaigns.md) V6).
- **Qualified new customer :** A customer a KOL code brought in whose first order is at least the panel minimum (RM10) or who bought again. The scorecard's CAC divides by this count, so tiny one-off orders can't pad it ([ADR-118](./adr/ADR-118-marketing-campaigns.md) V14).
- **CAC (campaign) :** A campaign's accrued KOL fees for the period ÷ its qualified new customers. Fees by month of service, never payment date. With a panel target and trial length set, the campaign page gives a verdict after the trial ([ADR-118](./adr/ADR-118-marketing-campaigns.md) V14, PR 4 notes).
- **Order Credential :** A customer's game-account login held for one order in its own encrypted table (`order_credentials`), never on `orders`; revealed only to a super_admin with MFA step-up and an audit row, wiped once the order closes ([ADR-124](./adr/ADR-124-customer-account-credentials.md)). Written at checkout for a login game ([ADR-125](./adr/ADR-125-login-top-up-manual-supplier.md) PR 1b). Build status: §15.
- **Login Top-up :** A top-up the supplier performs by logging in to the customer's account (Roblox, eFootball), not by player ID; packages are entered by hand at an agreed cost ([ADR-125](./adr/ADR-125-login-top-up-manual-supplier.md)). Build status: §15.
- **Manual Supplier :** A supplier with no API (`suppliers.driver = manual`), behind the same `SupplierAdapter` seam; it confirms or fails each order from its WhatsApp group (`.done` / `.gagal`) or the admin Manual Queue fallback ([ADR-125](./adr/ADR-125-login-top-up-manual-supplier.md)). Build status: §15.
- **Manual Queue :** The admin list of paid orders waiting on a manual supplier (the fallback to the supplier's WhatsApp group, [ADR-125](./adr/ADR-125-login-top-up-manual-supplier.md) PR 2b); Delivered defaults to the agreed `cost_price` (a different real cost needs a reason, decision 36) and goes through `finalizePendingDelivery()`, like every other confirmation. Build status: §15.
- **Funding Mode :** `suppliers.funding_mode`, `prepaid` (we hold a balance with them; every API supplier) or `pay_after` (we owe them after delivery, a payable). A manual supplier picks one in the panel; every penjoki is `pay_after` (ADR-126 decision 8).
- **Joki / Penjoki :** Joki is a rank-boosting service (MLBB first, owned brands only): the customer picks a current and target rank and hands over their login. A penjoki is the player who does it, modelled as a `pay_after` manual supplier (`suppliers.joki_role = penjoki`) with their own WhatsApp group ([ADR-126](./adr/ADR-126-joki-mlbb.md)). Build status: §15.
- **Assignment pool :** The one supplier (`suppliers.joki_role = pool`) a paid joki order waits on until an admin assigns a penjoki. It posts to no group, mints no login link, gets no reminder, is never Delivered (Failed is allowed) and is switched only by the Joki switch (ADR-126 decision 15).
- **Mythic Grading :** The 10 placement matches an MLBB account plays when it first reaches Mythic. Joki sells it at one flat price that guarantees Mythic 15★ (`grading_lands_at_stars`); only stars past that are charged per star (ADR-126 2026-10-23 PR 2b addendum).
- **Pricing basis :** `orders.pricing_basis`, which price chain an order was priced on: Standard (guest), Member, Affiliate (a third-party brand's markup), ResellerWallet (tier markup) or Fixed. The one list is the `PricingBasis` enum.
- **Fixed pricing basis :** `PricingBasis::Fixed`, a joki order's price from the server's rate table, with cost = price × the penjoki's payout share and no affiliate markup (ADR-126 decision 2).

---

# 14. Build Status

**Where things stand (checked read-only on prod 2026-11-11, after release #777).** The platform
is feature-complete and live in production: storefront (plus every Affiliate
whitelabel brand), admin panel, Affiliate/Reseller portal, and the
developer-docs site. CHIP FPX and DuitNow QR payments and the CHIP + Digiflazz
webhooks are proven end-to-end with real money. **Digiflazz is the only live
supplier** (funded 2026-09-15). **Gamevion** (MYR-billed, the backup supplier)
runs on its new API at `gamevion.cloud` (ADR-137): switched on and syncing its
chosen games, but unfunded (RM 0) with none of its old packages on, and the
founder is not using it yet (§16 item 162). No customer order has ever been
routed to it.

**Customers** (who they are; the counts are in the snapshot line below).
- **Storefront brands:** PekanGame (the primary brand); **OhahaStore** (`ohahastore.com`, one of our own brands, `is_owned`, payments approved 2026-10-30); **PajoStore** (`pajostore.com`, a third-party affiliate, `is_owned = false`, created and payments approved 2026-11-09; the founder confirmed with CHIP that it may collect payments, 2026-11-11).
- **Reseller wallet:** Naeem Industries is the real ordering customer; AyrisShop (created 2026-10-31) has not ordered yet.
- **Test accounts:** **FixFast** (a reseller) and **FixFast Demo** (an affiliate on `fixfastapp.com`, payments not approved). The FixFast reseller's login was given to CHIP for its review. All-time order counts include the founder's early tests, so an analysis that needs the distinction still judges each order.
- **Snapshot (prod, 2026-11-11):** 166 paid retail orders from 91 customer emails (PekanGame 53, OhahaStore 91, PajoStore 18, FixFast Demo 4); 15 paid wallet orders (Naeem 11, FixFast 4); 2 active members; KOL codes 256 tracker-link clicks and 4 redeemed orders (KuruKuru 2, KLuq 2, the first 2026-11-07); CS assistant 49 drafts, 9 approved knowledge entries; 0 reseller applications; 0 paid joki orders. These numbers live only here; refresh the line rather than restating them elsewhere.

**Switched on by the founder.**
- **Public reseller applications** (ADR-133) are open.
- **The CS assistant** (ADR-136) runs in **Shadow** since 2026-11-04 03:53 UTC: it drafts and never sends. The founder keeps it there until a category meets the Live gate (2026-11-10, §16 item 158).
- **Joki MLBB** (ADR-126) is on for PekanGame.
- **Membership** is on.
- **The reseller rebate** (ADR-139): the first ladder is saved and takes effect 2026-12-01.

**KOL campaigns** (ADR-118) are live since release #694. Four campaigns, all 5% off with no per-order cap: "2ez4Jepv" (RM500 a month, code `2EZ4JEP`), "Mik4ca" (RM100 a month, code `4ARMY`), "KLuq" (RM300 a month, code `KLUQ`) and "KuruKuru" (RM100 a month, code `KURU`). The scorecard settings are a target CAC of RM15 and a 2-month trial. A KOL paid as a share of profit isn't supported yet (§16 item 165).

**Accounting:** September and October 2026 are closed (October on 2026-11-04 by the founder: operating profit RM129.22 allocated to Company Savings, gap −RM10.95 with its note).

**Payment methods (checked read-only on prod 2026-11-11):** `fpx` on,
`fpx_b2b1` off, `dnqr` (DuitNow QR) on. CHIP approved FPX and DuitNow QR for
use (recorded 2026-10-31). On 2026-11-05 CHIP's compliance team replied that
the additional methods will not be enabled for now, and that reseller or
affiliate payment collection is not supported under our account; PekanGame's
own sales stay supported. The founder has since confirmed with CHIP that
PajoStore may collect payments (2026-11-11). A reseller only buys stock
through a Purchase Balance top-up. The history of the switches is in the build-log and its archive
(2026-10-07 → 10-29; the 2026-11-03 headline is kept verbatim at the bottom of the archive). **Real payment proof (§16 item 138, closed
2026-10-30):** FPX through the bank grid (`PG-SOOP26HC3ZXL`, RM13.32,
Maybank2u) and DuitNow QR (`PG-L4RCBELJ9RE3`, RM25.70) each completed with
real money and were delivered.

**Latest release 2026-11-11 (`staging`→`main`, PR #783, founder's go-ahead).** Merge `dac033e1`, box release `82696074`, carrying #778–#782: the docs audit and `docs-check` (#778, #779), ADR-140 (#780) and its two builds, the SEO meta strategy (#781) and the per-game content with its admin editor (#782). One additive migration (`2026_11_15_110000`, four nullable `games` columns, batch 70), no env change. Recorded in `build-log.md`.
- **Checked after release:** box on the new release, the migration ran, `/api/health` ok, Vercel production for all four apps, the static page titles and descriptions new, no `lastmod` on static sitemap pages.
- **Since release (the founder's admin actions):** the home default meta description set; per-game content entered as drafts and 18 games published (all their pages checked live, no content on any other brand); Request Indexing sent for five game pages.

**Previous release 2026-11-10 (`staging`→`main`, PR #777, founder's go-ahead).** Merge `b1c2b459`, box release `82558237`, carrying #770–#776: the report date bounds that fix the 9 Nov memory exhaustion and the Symfony security patches (#771); price-sync and backup status broadcasts on the `default` lane, Horizon `waits` keyed on `redis-long`, and failed broadcasts logged on any queue (#774, ADR-047 addendum); the CS Merge modal fixes (#772, #775); storefront dark readability (popup scrim, payment logos on a white plate, #773, #775); docs (#770, #776). No migration, no env change. Recorded in `build-log.md`.
- **Checked after release:** box on the new release, `/api/health` ok (after a two-minute Horizon restart that waited for the 07:00 price sync), every supervisor running, the new waits keys and broadcast lane live, 0 paid orders in flight, Vercel production for admin, storefront and portal on that commit.
- **Earlier the same day:** release #769 (ADR-139 reseller rebate, Bumblebee Dark v3); the first rebate ladder was saved (effective 2026-12-01).

**Not yet seen on prod:**
- the first affiliate auto-payout ticket (ADR-132): due 2026-12-01 if PajoStore's payable (balance less the tier-fee reserve) is at least RM10; it is the first affiliate with bank details;
- the first rebate month and claim (ADR-139, from 2026-12-01);
- a step-up save by an MFA admin (ADR-131);
- Google crawling the new game pages (ADR-140; checks on 25 Nov, 9 Dec and 6 Jan 2027, §16 item 178);
- a KOL link in a real TikTok or Instagram browser (auto-apply, the saved note, the bank logos), and the membership page on a real phone;
- Approve all's cache forget on Redis (ADR-138): a held row was approved one at a time on 2026-11-07, not yet through Approve all;
- the Price Sync card showing "running" during a sync, and the Horizon long-wait email no longer arriving (#774).

**Earlier releases** are recorded release by release in `docs/build-log.md`
(2026-10-28 onward) and `docs/build-log-archive.md` (before that, including
PRD §14's own per-release paragraphs from #514 to #687, moved 2026-11-04).
This section keeps only the current state.

**Storefront domain:** `pekangame.com` (`pekangame.space` and both `www.`
hosts 308-redirect to it since 2026-10-08). `api.`, `admin.`, `reseller.`,
`onboarding.`, `docs.` and `cdn.` stay on `pekangame.space`.

**Infrastructure:**
- Backend runs on the `pekangame-prod-lwf` DigitalOcean droplet, with the
  managed MySQL cluster `topup-prod-mysql` in the same DO team (ADR-114). The
  old droplet, its old cluster and Reserved IP were destroyed 2026-09-30.
- The 4 frontends are on Vercel in `sin1`.
- Cloudflare sits in front of `api.`.

- **Per-feature-area status:** §15 below.
- **Full chronological build record** (every session, what shipped, the gotchas): `docs/build-log.md` (entries before 2026-10-28 in `docs/build-log-archive.md`).
- **Decision rationale** (Context → Decision → Rationale → Consequence): `docs/adr.md`.

---

# 15. MVP Scope Tracker

The coarse status-by-feature-area map. Read at a glance; the file-level detail and
the chronology are in `docs/build-log.md`, the *why* in `docs/adr.md`.

| Area | Status | Key ADRs |
| --- | --- | --- |
| Auth & Admin Users (AUTH-1..7) | ✅ Live. Login rate-limited + logged. Admin MFA (AUTH-7) and the cookie-session admin login live since 2026-10-14 (#437). **2026-10-19 (ADR-121 addendum, released via #517):** admin GETs carry no `Content-Type`, so they skip the CORS preflight; preflight answers are cached (`max_age` 7200). Verified on prod 2026-10-22: 0 OPTIONS on `/admin/orders` | ADR-019, ADR-121 |
| Admin Dashboard (DASH-1..6) | ✅ Live — **redesign (§16 item 102, ADR-104 2026-10-15), live 2026-10-15 via #462:** daily briefing with a period filter, status strip, trend, channels, top games, busy hours, highlights; the funnel and single-day hourly chart are removed. **Scheduler liveness (ADR-122, §16 item 98):** live 2026-10-15 via #449. Per-task heartbeat (`scheduled_task_heartbeats`); a stale critical task fails `/api/health` (`checks.scheduler`); a "Scheduled tasks" row on System Health **2026-11-08 (ADR-066 addendum), released via #737:** the code cache's size and hit rate in System details; a full cache fails `/api/health` (`php_code_cache`). | ADR-045, ADR-122 |
| Money core (Pricing, Ledger, Voucher, Order status, idempotency) | ✅ Live — full service layer, concurrency-proven. The most mature part of the codebase | ADR-002 |
| Affiliates / whitelabel (RES-1..6) | 🟢 Live in prod — a custom domain brings a `www` redirect twin (ADR-060 second 2026-10-30 addendum, #613, released in #618); owned brands price as the platform, no tier and no brand markup (ADR-061 2026-10-30 addendum, #612, released in #618) — wholesale tiers + subscription state machine, tenant isolation, `affiliate` guard + portal, platform-owner special-case abolished (`is_owned`/`is_primary`), per-brand Membership, `Host`-resolved branded storefront + Vercel-native custom domains, per-brand pricing + ledger split, brand-scoped vouchers. Real affiliate domain verified end-to-end. PR #273's invite/session and compensation visibility fixes, plus ADR-112's role-aware shell, dashboard recent orders, and responsive Orders presentation, shipped in PRs #273, #275 and #276, released to `main` via #278 (2026-09-24) **2026-10-29 (ADR-131, released via #599):** a custom domain takes no CHIP payment until an admin approves it (`payments_approved_at`, super_admin + step-up); the migration approved only the primary affiliate's own rows (none in prod), so `fixfastapp.com` cannot pay; Approve/Revoke in admin Affiliates, an "awaiting approval" status in the affiliate's portal. | ADR-056–061, 078, 112, 131 |
| Reseller (wallet) — Affiliate/API/Bot channels | 🟢 Live — prepaid wallet (admin manual credit + self-serve CHIP top-up, payable by FPX, §14), one order-placement contract (`ResellerOrderPlacementService`), `reseller_code`/`catalog_code`, REST API `/v1` (docs revision and changelog: the docs-site Versioning page; keys, IP allowlist, delivery webhook, optional `max_price_sen`, per-account rate limits: orders 60/min and reads 120/min, ADR-074 2026-10-13 addendum, live via #425), WhatsApp Bot (OpenWA), shared role-aware portal (ADR-112), per-game player-input contract (ADR-097), own queue lane (ADR-048). Real customer orders run on this channel. Detail: build-log + ADRs **2026-10-19 (ADR-125 PR 2a, released via #514):** the group registry is `whatsapp_groups` (owner reseller or supplier; the reseller rows moved as-is); the reseller bot is unchanged, and the webhook now drops the bot's own messages **2026-10-29 (ADR-131, released via #599):** shown to resellers as **Purchase Balance** ("Add Balance"; the admin, the code, the ledger types and the API field names keep "wallet"); RM10,000 per top-up and a RM100,000 default balance cap, overridable per reseller, enforced under the wallet mutex for the CHIP top-up (portal and bot) and the admin manual credit; a Payment Receipt PDF per paid top-up and per manual credit; one "Close account" action (transfer first, then the debit) replaces the bare DELETE; the portal route is `/balance` (`/wallet` redirects). **2026-11-02 (ADR-135 PR 1–4, released via #660):** one paid seam and one ended seam per top-up; the portal shows a pending top-up as a "Payment waiting" card with Pay now and Cancel (the cancel is decided by CHIP's re-read answer and never credits); a `.topupbaki` group is told when its top-up fails, expires or is cancelled from the portal; the top-up routes are rate-limited; reconcile re-checks top-ups ended in the last 24 hours; the bot's `.batalbaki` cancels the same way, and `.topupbaki` and `.baki` show a pending top-up's end time and how to cancel it. The CHIP Settlements detail screen tags a settled payment whose record here is not paid (worked out live) and offers Re-check with CHIP (super_admin, step-up), which records it through the record's own paid seam (PR 4, #658). **Released via #769, 2026-11-10 (ADR-139 PR 1):** a Failed wallet order is refunded to Purchase Balance automatically within a minute (`app:refund-failed-wallet-orders`, `CRITICAL`, through `OrderSettlementService::settle()` with the system as actor). Partial combos stay a manual Refund to Wallet; NeedsReview/Pending, sandbox orders, closed resellers and orders with a late supplier Success are skipped, and a late Success alerts every admin. **Released via #769, 2026-11-10 (ADR-139 PR 2):** a tier change on a reseller that already has one takes effect at 00:00 KL on the next 1st (`reseller_wallet_tier_changes` history, hourly `app:apply-reseller-tier-changes`); the admin list shows the waiting change; a tier's markup is fixed once created. **Released via #769, 2026-11-10 (ADR-139 PR 3a):** the rebate core: ladder versions, the entitlement function, the claim (the only writer of `wallet_rebate`, claims in order, terms acceptance) and a `WalletEntryType` enum. It is inert until a ladder version exists and PR 4 adds the screens. **Released via #769, 2026-11-10 (ADR-139 PR 3b-i):** month finalise and reward expiry (daily `CRITICAL` tasks), claims of finalised and adjustment rows, `UnspentRebate`, and the closure forfeit; the admin close modal shows the refund net of forfeited rebate. **Released via #769, 2026-11-10 (ADR-139 PR 3b-ii):** the rebate's accounting: a "Less: reseller rebates" line (a revenue reduction, live for the open month, reversed in the month an unclaimed reward lapses) and "Reseller rebate breakage" (credit forfeited at closure) in the Monthly Summary and operating profit, the rebate payable on the cash equation, both rows in the Reports profit Bridge, and a month cannot close until its rebate is finalised and every reward that lapsed in it is expired. A finalised month takes no claim for a step it did not write, and a ladder must reward the same tiers at every step. **Released via #769, 2026-11-10 (ADR-139 PR 4a):** the admin rebate API (ladder list and save behind the MFA step-up, a month view of each reseller's standing and rows, a CSV for the accountant, an eligibility switch behind the step-up that records a reason), the portal rebate API (own tier's ladder, progress, claim states, terms, unspent rebate and the upcoming version, never a share; a claim with the terms tick), rebate detail on statement lines, and WhatsApp messages to the reseller's groups for a reached step, a reward 3 days from lapsing and a new ladder. **Released via #769, 2026-11-10 (ADR-139 PR 4b):** Admin → Reseller Rebate (ladder versions and the editor with its effective date and ceiling check, a month view with its CSV), a Rebate button on Resellers for eligibility, rebate labels in the wallet history, and the rebate line in Reports (summary, the wallet channel, CSV and PDF; owner profit is labelled as before rebates). **Released via #769, 2026-11-10 (ADR-139 PR 4c):** the portal Rebate page (the ladder for its own tier, Claim buttons in step order and "Claim all", the terms on the first claim, the new-table notice, unspent rebate and history), a dashboard Rebate card ("RM X more to reach RM Y"), and statement labels for rebate lines. The build is complete; the ladder simulation is re-run; the first ladder was saved after release, on 2026-11-10 MYT (effective 2026-12-01). **Released via #769, 2026-11-10 (ADR-139 2026-11-09 review addendum):** the Transaction Register lists rebate claims and closure forfeits, and the Reports rebate note says which months it covers. **Released via #791, 2026-11-12 (ADR-076 addendum, §16 item 181):** `.topupbaki` uses the payment method chosen in Settings → Purchase Balance (stored by id; FPX after the migration), or the first method the portal offers if it is off; the reply names the method; `.batalbaki` on a top-up still at the bank says when an unpaid one ends. | ADR-072–076, 084, 091, 093, 112, 048, 097, 131, 135 |
| Supplier Adapter (ADAPT-1..4) | ✅ Digiflazz live (the only funded supplier). Gamevion runs on its new API (`gamevion.cloud`, ADR-137, released via #705 and #707) and is switched on, but unfunded and with no package on: not in use yet, by founder decision (§16 item 162). Per-supplier circuit breaker, `SupplierAdapterFactory` routing, async delivery state machine + poll backstop, inbound webhooks (HMAC). **ADR-097 PR-1 + PR-2 built 2026-09-16** — Digiflazz `customer_no` separator moves per-game (was wrongly supplier-wide); per-game Zone ID picklist replaces free text, with the same presence+value-validation now shared (`CheckoutInputValidator`) across storefront, Reseller API, and Bot. Both PRs live. **ADR-098 built 2026-09-16** — `SupplierResponse::$transactionAlreadyFormed` (supplier-agnostic) routes a non-retriable Digiflazz `rc` (Terbentuk Transaksi=Ya, 20 codes) or Gamevion `duplicate_reference` straight to `needs_review`, closing a real gap in the async webhook/poll finalize path a plain `Failed` resend could never resolve (later refined by ADR-102). **2026-09-29:** the breaker also counts `createOrder`/`checkStatus` timeouts (`listProducts` excluded). For a replay-safe supplier (Digiflazz), an ambiguous outcome now goes to Pending with a same-ref poll instead of NeedsReview (ADR-102 addendum). **2026-11-05 (ADR-137, item 162 PR 2), released via #705:** `GamevionAdapter` rewritten for gamevion.cloud (one `gvk_` key, `{game}:{sku}` references, `gametopup` games checkout can fill, pricelist paced under 40/min, read-first status with one same-`client_ref` post on a 404, Gamevion joins the replay-safe suppliers with a 30-day reference window); signed `POST /api/webhooks/gamevion`; a supplier's Base URL cannot change while it has unsettled deliveries. The founder entered the new key and switched Gamevion on the same day. **2026-11-05 (ADR-137 2026-11-05 addendum), released via #707:** only the games ticked in the `/middleware` games picker sync (74 seeded); a game Gamevion cannot answer is skipped and its packages go off through the vanished-product path, but the whole sync fails on a refused key, a failed game list, or more than a quarter of the games failing; each supplier syncs in its own job (`SyncSupplierCatalogJob`, one `Bus::batch` per run); Gamevion's per-game catalog calls are logged only when they fail. | ADR-006, 030–032, 067, 069, 097, 098, 102, 137 |
| Payment Gateway (CHIP only, PAY-1..4) | 🟢 Live in prod — real RM FPX payment + webhook proven end-to-end (order `PG-PYAYMRYNUYV0`). Methods `fpx`, `fpx_b2b1`, `dnqr` (DuitNow QR; was seeded as `duitnow_qr`, a wrong guess found 2026-10-28, fixed by #584, released in #589 on 2026-10-29). FPX has been on since 2026-10-22 and DuitNow QR since 2026-10-28 (§14); since #589 CHIP routes DuitNow to DuitNow (attempt `payment_method: dnqr`), and a DuitNow payment has completed with real money (`PG-L4RCBELJ9RE3`, 2026-10-30). Xendit deleted (archived). **FPX bank-direct (ADR-130) live since 2026-10-29** (#587 checkout and `/joki`, #588 membership and the portal's wallet top-up, released in #589; a 20-bank grid that goes straight to the bank). An FPX payment has completed through it with real money (`PG-SOOP26HC3ZXL`, Maybank2u, 2026-10-30; §16 item 138, closed). **A failed attempt is final (ADR-110 2026-10-29 addendum), released 2026-10-29 in #599** (#591, #592; §16 item 140). **ADR-110 PR-A built 2026-09-19** — `overdue`/`expired`/`blocked` now map to `Failed` (were silently stuck `Pending` forever); every purchase now sets `due`+`due_strict` so CHIP itself closes it after 30 min. **PR-C built 2026-09-19** — credentials moved to encrypted `payment_gateways` DB row + `/middleware/payment-gateways` admin screen, binding is DB-first; the founder completed the cutover 2026-09-24, and the `.env` fallback is kept deliberately (§16 Parked). **PR-B built 2026-09-19** — CHIP settlement `.xlsx` reconciliation + Monthly Accounting Summary, fills ADR-083 PR-2 (see §16 item 12). All three ADR-110 PRs now built **2026-10-29:** #591/#592 (a failed attempt is final, `check-payment`, "Payment Not Completed / Try Again") released via #599; the real cancel at a bank is §16 item 140. | ADR-022, ADR-110, ADR-130, 110 addendum |
| Games & Packages (GAME-1..11) | 🟢 Live — GAME-1..11 all shipped (list/detail, markup %, activate/deactivate, delete, bulk markup via `/admin/settings`, SEO fields via `/admin/seo/games`, drag-drop reorder via `/admin/games`'s "Reorder Games", folded with the storefront's Quick Top-Up widget). GAME-12 dropped, 2026-09-13 (dead requirement, see §16). **2026-10-02:** package delete now blocks a package with order history or live combo-component usage instead of silently orphaning order history or crashing with a raw DB error (ADR-119). **2026-10-18 (ADR-125 PR 1b, §16 item 113), released via #514:** "New Login Game" with its login-form editor, manual packages at an agreed cost (audited edit, per-package price history with the admin), a per-game member-pricing switch; a login game can't take a reseller code, validator, supplier product or combo. **2026-11-05 (ADR-119 addendum), released via #715:** a game or package with order history can no longer be deleted by any path (`packages.game_id`, `orders.package_id` and `orders.game_id` are `RESTRICT`); deleting a game asks to deactivate it instead, and an unused game goes with its packages and unlinks its Product Manager groups | ADR-029, ADR-119, ADR-125 |
| Combo Package | 🟢 Live — several catalog Packages sold as one opaque SKU (one FPX fee), sum-of-components pricing with optional `combo_override_price` (never below cost), up to 5 legs, per-leg references and attempt rows, `partially_delivered` + `OrderSettlementService` settlement (voucher or wallet refund), real-cost profit residual (ADR-107/111), component cascade on/off, bulk markup routed through `ComboPricingService` (ADR-028), component SKU in the Edit Combo modal. Per-leg attempt history shown under each leg on Order Detail (live via #406, 2026-10-12, item 66). **2026-10-13 (ADR-094 addendum, item 93):** the stored price stays current while a combo is off and is recomputed before the reactivation cascade; a component markup reprices its combos; a markup on a combo itself is rejected; live since 2026-10-14 (#430). Edit-composition parked 2026-10-14 (§16 Parked) | ADR-094, ADR-100, ADR-046, ADR-106, ADR-107, ADR-028 |
| Price Sync (SYNC-1..6) | ✅ Live — raw sync → promote-to-catalog, price propagation + deactivation detection (one switch-off seam, swing rules by direction, new packages through the activation seam, and Reactivate only after a recent sync, ADR-138, released via #715), sanity guard (floor, held drops, mass change breaker), FX conversion, best-price dedup, per-supplier grouping, stuck-run hardening. **Live 2026-10-17 (#490, item 106):** `partial` run status with failed suppliers listed, stale-supplier email (default 90 min). **Live 2026-11-01 (#642, item 155):** cost and per-order real cost at the deposit's landed rate instead of the market mid-rate, `orders.cost_rate`, unfunded supplier off under `unfunded`, transfer spread guard **2026-11-05 (ADR-015 2026-11-05 addendum, item 162 PR 1), released via #705:** a package is switched on only through `PackageActivation`, which keeps it off while a price swing is open, its supplier is off or unfunded, the supplier no longer lists it, or a combo component is off; the supplier's Reactivate reports how many it kept off. **2026-11-05 (ADR-137 decision 21, item 162 PR 3), released via #705:** Product Manager "Link to existing" repoints an existing package of the same supplier and game to a new supplier item (supplier system change): id, history, markup, name and combos kept; cost from the new price (`PackageCostService`, logged); an open price swing is closed as `superseded`; refused while the package has unsettled orders or legs; each change recorded in `package_ref_changes`. | ADR-015/016, 025, 033, 034, 067, 134 |
| Supplier Management (SUPP-1..5) | ✅ Live — SUPP-1/CRUD/SUPP-5; credentials in encrypted `Supplier.api_config`; balance refresh + low-balance chip; credential-rotation probe on save. **ADR-138, released via #715:** switching an API supplier off takes its packages and combos off sale behind a confirm dialog; switching it on restores nothing. **2026-11-05 (ADR-115 2026-11-05 addendum, §16 item 28), released via #699:** the low-balance chip, the Dashboard briefing, `/admin/balance` and a new once-a-day email all read one rule, `SupplierBufferService`. A supplier is short when it holds less than the higher of its floor (`low_balance_threshold`) and its busiest window of the last 60 Malaysia days (× `buffer_margin_pct`). The window is `topup_lead_days`, and from Thursday it runs through Monday. The balance compared is the lower of the polled balance and the ledger. It is checked after every drawdown, every polled balance and daily. **2026-09-24:** the bulk "Deactivate All"/"Deactivate by Game"/"Reactivate" toggle now cascades onto dependent combos (found while auditing the Pending Reactivation combo gap above — the bulk toggle had never called `ComboPricingService`'s cascade at all, deactivate or reactivate). Released `main` via PR #286, 2026-09-24 **2026-10-19 (ADR-125 PR 2a/2b, §16 item 113), released via #514:** a manual supplier is created inactive and can be switched on (here or in Partners → Suppliers) only with an active WhatsApp group; the Partners → Suppliers screen (`/admin/suppliers`: group link/unlink, reply SLA, active switch, super_admin, 2b); switching a manual supplier off takes its packages off sale through the ADR-046 `supplier_issue` seam and switching it on restores them (decision 64) | ADR-046, 069 |
| Orders Management (ORD-1..11) | ✅ Live — checkout + fulfillment, Resend (same-game swap, one resend seam, ADR-105), ORD-10 reconciliation, async `pending`, manual "Check from Supplier/Gateway" (ADR-096), one compensation guard `isAlreadyCompensated()` (ADR-102/103), restore-only voucher (ADR-024), six KPI cards (ADR-092), ADR-104 reskin, ADR-108 toolbar (Need Action excludes compensated, Source/Game/date filters, Columns, 2-sheet Excel export with earned profit) and a Failed pill (#385). **2026-10-18 (ADR-125 PR 1b), released via #514:** `/admin/manual-queue` and Order Detail actions for a manual supplier's order (Delivered at the agreed or a reasoned real cost, Failed, a supervised Failed → Delivered correction before any voucher), all through `finalizePendingDelivery()`; Retry/Resend refuse a wiped login; resend may correct a login's identifier (audited). **2026-10-23 (ADR-126 PR 1, item 114), released 2026-10-25 via #554:** a manual post's dedupe key carries the supplier; Delivered/Failed re-check the supplier under the finalize lock (the admin sends the one on screen); the stale clock is `orders.manual_activity_at` (stamped when the group post is sent), and the manual SLA max is 3 days. **2026-11-02 (ADR-106 addendum), PR #653, released via #660:** Delivery Logs shows "Cost vs checkout" (a delivered unit's cost against its checkout cost, on the delivering row, combo legs included) in place of the catalog Price Diff; the resend preview is labelled as a catalog estimate. Detail: build-log + ADRs **2026-11-05 (ADR-102 2026-11-05 addendum, item 162 PR 1), released via #705:** Retry and Resend from NeedsReview, Check supplier on a Pending order, and the re-submit itself are refused past the supplier's `max_reconcile_age_days`, as the poll and Confirm Failed already were. | ADR-017, 024, 026, 032, 092, 096, 102, 103, 104, 105, 106, 108, 125 |
| Reports (RPT-1..4) | 🟢 Live — **2026-10-25 (#556, released via #557):** an Affiliate's figures are its storefront orders only; a reseller-wallet order counts toward its Reseller only, on every tab and filter (ADR-086 addendum). redesigned per ADR-104 (2026-10-08 addendum R1–R21 + 2026-10-09 R8 addendum), live via #385 (2026-10-09). 7 tabs on Recharts. **Paid sales** (money collected, net of wallet refunds, `Order::netSalesSql()`) with a **Bridge to Accounting** to recognised revenue from one `RecognisedRevenue` seam shared with the Monthly Summary. Ledger-sourced profit, `paid_at` scoping, KL days. **Failed & compensated** per delivery status plus outstanding store credit. Compare period on every tab, sales by channel + API vs Bot (`orders.placed_via`), Delivery by game. CSV/PDF carry Paid / Wallet refund / Paid sales. Default range All time. LLM assistant at `/admin/reports/assistant` (ADR-087, `super_admin`) matches the page. History: ADR-086 grouped-SQL rewrite, ADR-088 unified date filter **Live via #462 (2026-10-15; #454/#455, ADR-104 2026-10-15):** Compare for a range ending today runs to the same time (`previous_range.until`, label "…, to 10:30 AM"), a Today preset, `/reports/busy-hours`, `/reports/highlights` and `/reports/top-games?compare=` (the new Dashboard reads them). **2026-10-24 (ADR-127 PR B1/C, #544, #546, released 2026-10-25 via #554):** profit is filed by recognition (a manual correction by its own date), sales stay on `paid_at`; the Bridge gains "recognised here, paid in another range"; a profit bridge (owner profit → operating profit) on the Profit tab; store credit equals Cash Position (Path A included); labels Sales / Owner profit (market rate). **2026-11-03 (ADR-104 addendum, §16 item 94), released via #668:** the Games tab's "Best-selling products" card groups sales, orders and profit by product as the storefront shows it (`Package::productKey()`, shared with the catalog dedup), from a snapshot on each order (`orders.product_key`/`product_name`, written by `OrderFactory`, backfilled from the checkout package), so resend swaps and renamed or deleted packages never move a sale. | ADR-086, 087, 088, 104 |
| Withdrawals (WTH-1..5) | ✅ Live. **Monthly auto-payout (WTH-6, ADR-132): live, released 2026-10-30 (#605 in #608).** The platform opens one ticket per affiliate per Malaysia month (balance at the 1st 00:00 MYT minus the tier-fee reserve, minimum RM10, bank details required; `app:open-affiliate-payouts` daily 00:10 MYT, unique `payout_period`); the manual request is removed; the admin page shows the affiliate and period, a "Needs attention" panel and a "this month only" toggle (`GET /api/withdrawals/payout-run`); the portal shows the schedule, the scheduled amount and the fee reserve. No ticket has opened on prod yet (no affiliate has bank details; checked 2026-11-04). Maker-checker threshold RM 2,000 (`WITHDRAWAL_MAKER_CHECKER_THRESHOLD_SEN`). **Live via #393 (2026-10-10):** the platform-owner request (button, "Available balance", `POST /api/withdrawals`) is removed; the screen reviews Affiliate requests only | ADR-059, ADR-083 |
| Vouchers (VCH-1..6) | ✅ Live — + voucher-at-checkout (wallet model, partial/full cover), a typed expiry date valid through that Malaysia day (ADR-024 2026-10-13 addendum, live via #425), Path A double-submit key, Voucher Merge. Maker-checker RM 500. **2026-10-04 (ADR-024 addendum, live via #354):** a lost voucher or member-quota reservation now fails the checkout closed (Failed order, coded 422 `checkout_closed`, link never handed out) on every path, including idempotent replay; admin "Voucher Paid" reads the redemption. **2026-10-12 (ADR-036 addendum, item 80):** a merge rejects an expired source voucher. **2026-10-12 (ADR-024 addendum, item 79):** only an admin-issued voucher debits the platform ledger; the 4 old compensation-voucher debits were reversed on release (live via #416, 2026-10-13, verified on prod). **2026-10-13 (item 84):** expiry stays `expires_at` alone; the admin list, detail and order Refund cards show an active voucher past it as expired (`display_status`), and it can't be ticked for merge (live via #425) **2026-10-24 (ADR-127 PR B2/C, #545, #546, released 2026-10-25 via #554):** a restore carries `restored_at`; a revoke is a locked `VoucherService::revoke()` with `revoked_at` (it was an undated status flip); breakage is the balance at expiry or revoke. **Released via #791, 2026-11-12 (ADR-004 addendum, §16 item 179):** a Super Admin can pay a failed order's voucher balance out by bank transfer (bank details, proof on the accounting disk, step-up); the voucher ends `refunded`, Cash Position's claim falls with the bank and profit never moves; `vouchers.source` (customer cash or gift), a merge never mixes them; revoke is gift credit only, with a confirmation, a reason and the step-up. | ADR-004, 024, 035, 036 |
| Marketing Campaigns — KOL promo codes (ADR-118) | 🟢 Live since 2026-11-04 (released via #694; built in #690–#693, §16 item 57 closed). **2026-11-07, released via #733:** a link's code applies itself once email and phone are in, and a note says it is saved. **Checkout (PR 1):** a percentage off the Standard price shown as RM saved; one KOL discount per customer per brand (email, phone or player account; wallet orders excluded; reseller identities refused); cancel-and-replace of the same customer's unpaid checkout; a panel-set margin floor (2%); reserve, commit and restore beside the voucher; `selling_price` and `platform_profit` after the promo. **Fees and accounting (PR 2):** a per-campaign monthly fee schedule (accrual), KOL payments as Advertising Envelope postings tagged with the campaign, Monthly Summary gross − promo = net and the KOL fee lines (never in operating profit or the allocation). **Storefront and readers (PR 3):** the Review Modal's promo field, `?promo=CODE` on any page (7-day prefill, one click per tab logged), the member-price warning, `promo_discount` on track-order, the affiliate API, the orders workbook, the Report Assistant view, admin Order Detail and the portal. **Admin (PR 4):** Admin → Customers → Campaigns (super admin): the scorecard (clicks, new and qualified customers, CAC on accrued fees, ledger profit, net, repeat), codes, fee schedule, settings and the verdict once a target CAC and a trial are set. Campaigns, codes and redemptions on prod: §14. | ADR-118 |
| Customer Analytics (ANL-1..5) | ✅ Live — **2026-10-25 (#557):** the Affiliate filter leaves reseller-wallet orders out. `/admin/customer-analytics`, derived `customer_email` grouping (no new entity), VIP/Frequent/Dormant/New/One-time segments. **Item 103, live 2026-10-15 via #462:** one SQL aggregate, server-paged list with sort, email search and the Reports date presets, mixed Source label, paged detail order history **2026-10-24 (ADR-127 PR C, #546, released 2026-10-25 via #554):** the detail total is labelled "Customer spend". | ADR-049, ADR-050 |
| Membership (VIP, per-brand) | 🟢 Live in prod (kill switch ON) — **2026-11-07, released via #733:** the signed-out `/membership` page explains the offer first, with a savings calculator and price comparison (ADR-055 2026-11-07 addendum). 2 fixed tiers, email-OTP identity, live member pricing + quota, self-serve subscribe + pay via CHIP, admin per-member detail. Real tier numbers set. Per-brand `/membership` fully gated. Renewal-reminder emails at H-3 and H+1 (Malaysia days), listed on the admin member page (item 64, live via #416 2026-10-13). WhatsApp reminders designed, waiting on a trigger (§16 item 85) **2026-10-30 (#615, released via #618):** the OTP email's subject carries the code and the brand and the code screen says to check Spam; the Plunk and DNS side is in the build-log. | ADR-027, 055, 068, 080 |
| Customer notifications (WhatsApp) | 🟢 Live in prod since 2026-09-30, switch **ON**. The CS-session webhook was added (its secret fixed by the model), and OpenWA `SEND_PACING` is on. Founder live-tested: two Delivered receipts sent, each branded by its own order (FixFast and PekanGame from one number), and `STOP` confirmed. The voucher path was live-tested 2026-10-06 (a failed order's Issue Voucher code arrived on WhatsApp), along with the status card and not-found reply. Voucher codes are sent proactively (Issue/Restore Voucher; a standalone voucher with a phone). Delivered receipts go to opted-in numbers. Sends go from the OpenWA `customer-support` session on a paced one-worker lane, recorded in `customer_notifications` and shown on the admin order and voucher pages. Email deliberately not used. **Follow-up #326 released to `main` 2026-10-01 via #332** (order number = opt-in, timeline status card, not-found reply, `START`, skipped revival, reordered help card; see §14). **2026-10-12 (§16 item 69):** every order-number message gets a card or a not-found reply; the silent repeat throttles are removed (live via #416; the founder confirmed on a real phone 2026-10-13) **2026-10-19 (ADR-125 PR 2a, released via #514):** the same persisted queue also carries supplier-group posts on the bot session (`session`/`chat_id`), with a send slot per session, so customer pacing and the customer switch are unchanged **2026-10-30 (ADR-116 addendum, #614, released via #618):** a Malaysian mobile typed without its leading 0 is normalised to `60…`, so its delivered receipt is no longer silently dropped. **2026-11-01 (ADR-116 addendum, #647, released via #649):** a sender WhatsApp shows only as a privacy id (`@lid`) is answered in its own chat (status card, not-found, STOP/START) instead of being ignored; a chat that sends an order number follows that order and gets its Delivered receipt and joki progress when the checkout number never wrote to us (a checkout number that did, opted in or STOP, alone decides); vouchers stay checkout-number only; the admin order page shows "WhatsApp chat (number hidden)". **2026-11-03 (ADR-116 addendum, §16 item 156), released via #668:** such a chat that sends an order number is checked against the checkout phone with OpenWA's `@lid` lookup (v0.27.1) in a queued job; on a match the two are tied on one contact row (two rows merge, STOP wins), so the number's later orders get their receipts without asking again; the admin shows "verified as <phone>". **Seen live (checked read-only 2026-11-04):** 2 `@lid` chats tied to their phone, 7 messages sent to `@lid` chats. **2026-11-14 (ADR-116 / ADR-075 2026-11-14 addenda), built in #793, not released:** every WhatsApp send, bot replies included, goes through the one outbox with an Idempotency-Key; replies get their own `whatsapp-reply` lane; a gateway outage is retried until it ends; stale read replies expire; a money command older than the panel limit (default 3 min) is not run; one webhook is handled once; a restricted session fails `/api/health/whatsapp`; stuck, failed or unknown messages email the admins; Middleware → WhatsApp lists the outbox; the reseller portal shows a banner while the bot is degraded. | ADR-116 |
| Customer-support assistant (CS WhatsApp) | 🟡 Building — **PR 1 (no AI), released 2026-11-03 via #672:** every personal-chat message on the CS number is kept masked in `cs_messages` (customer, staff, or our own send, told apart by `customer_notifications.wa_message_id`), from the `message.received` and `message.sent` webhooks (`message.sent` added to the CS webhook 2026-11-03); the history since 2026-09-25 is imported (192 messages, 20 chats). **PR 2 (no AI), released 2026-11-03 via #677:** the CS knowledge book (`cs_knowledge_entries`), nightly suggestions paired from finished chats (`cs:suggest-knowledge`, 03:00 MYT), the super_admin page `/admin/cs-knowledge` (approve, merge, reject, ignore chat; edit and archive entries), and the 2-year prune (`cs:prune-messages`). **PR 3a (model test), released 2026-11-03 via #677:** the `ChatModel` seam with a Gemini adapter, `CsAnswerer` (approved knowledge + FAQ + a `game_info` tool that gives the brand's own page link and no price; code-forced handovers for failed or late orders; a reply guard for cost, supplier, percentage and foreign-link leaks), and the Test cases / Model test tabs (blind A/B picks, Reveal with cost) on the `cs-assistant` lane. Released 2026-11-04 (#684): a Claude adapter (`ClaudeChatModel`, official SDK), run #3 candidates Gemini 3.1 / 3.5 Flash-Lite and Claude Haiku 5.5, and the run #2 prompt fixes; `ANTHROPIC_API_KEY` is set on Forge. Haiku 5.5 chosen in run #3. **PR 3b (Shadow) released 2026-11-04 via #687:** settings tab (mode Off/Shadow, models, thresholds, daily cost cap), one draft per customer burst that is never sent, nightly grading by Claude Sonnet 5.5 against staff's replies with admin overrule and per-category accuracy, model-written suggestions; one migration. **Shadow switched on by the founder 2026-11-04 (03:53 UTC)**; draft and knowledge counts: §14. PR 4 (Live) not built; it follows once a category meets the Live gate (20 graded drafts and 90%; the founder kept the gate, 2026-11-10). | ADR-136 |
| Reviews (REV-1..5) | ✅ Live — guest submit gated on Delivered, admin approve/reject/bulk, + public display (homepage marquee + per-game PDP section, brand-scoped). Released 2026-11-04 (#684): a game with only star-only reviews shows its score badge, and the marquee loops with no blank strip at any review count (ADR-082 2026-11-03 addendum) | ADR-053, 082 |
| Backups (BAK-1..5) | ✅ Live on Cloudflare R2 (`pekangame-backups`, private): full DB dump except `player_validations`, 7d/4w/6m retention, nightly at 02:07. **Encrypted only from 2026-10-22:** `BACKUP_ARCHIVE_PASSWORD` was unset from go-live until the founder set it on 2026-10-22, so every archive before then is a plain zip in the private bucket (deleted 2026-10-25, §16 item 96). Fail-closed encryption and the OpenWA source (ADR-039 2026-10-22 addendum) are live since release #526 (2026-10-22); OpenWA runs nightly at 19:37 with a passing restore test since 2026-10-22; the password-manager copy of the password decrypted the 2026-10-25 database archive (drill, 2026-10-25, §16 item 96). **Restore test verified again 2026-10-15** (run #47, `restore_test_passed = 1`, 72s, after release #449); nightly runs 2026-10-01..15 had failed (§16 item 97, closed). Fixed in code on 2026-10-14. The fix adds one restore path shared by the nightly test and a new `app:restore-backup` command; restores go only into an empty database; DEFINER clauses are dropped on restore; verification is real (no missing table, no pending migration); 300s timeout on `redis-long`; and killed runs close themselves. Released 2026-10-15 (#441, then the fixed-name follow-up #442 via #449) with the ADR-117 exact-name grant. Earlier history: the 2026-09-14 GTID fix, build-log-archive | ADR-039, ADR-095, ADR-117 |
| Image Gallery (IMG-1..2) | 🟢 Live in prod on Cloudflare R2 — upload/grid/search/copy-URL/delete, WebP-at-upload (2000px cap, reuses `ImageIngestService`) + delete referential-safety warning (live since #571, 2026-10-26, from #560: it also lists Joki rank icons, a brand's default OG image and Game ID Checker images, every `ImageUrlInput` consumer). `GALLERY_DISK=r2_gallery`/`BACKUP_DISK=r2_backups` live since 2026-09-14; every existing gallery/logo/favicon file migrated + verified 200 on `cdn.pekangame.space`. In-form picker (`ImageUrlInput`, live via #406, 2026-10-12) on Edit Game, Hero Slide and the two SEO OG-image fields; the URL stays a plain text field | ADR-095 |
| SEO (SEO-1..7) | ✅ Live — per-brand settings, meta templates, redirects (via `proxy.ts`), scripts, crawler rules, JSON-LD, native robots/sitemap/llms.txt. Full backend test coverage. **ADR-120 (live 2026-10-08):** tokens in every SEO field, per-brand canonical origin (canonical/sitemap/robots/llms/JSON-LD), all packages in HTML, Product `offers` + `aggregateRating`, fact line, admin FAQ + `FAQPage` Released 2026-11-04 (#684): the default OG image is per brand, so PekanGame's never shows on an affiliate storefront (ADR-060 2026-11-03 addendum); PekanGame's is set on prod, the affiliates have none (checked 2026-11-04; an affiliate editor is §16 item 160). **ADR-140 released 2026-11-11 (#783):** PR 1, the meta strategy: a unique title and description per static page from one registry, an honest sitemap `lastmod` with `noindex` games left out of the sitemap and `llms.txt`, Organization `alternateName` for the primary brand, three dead `seo_*` fields out of the code (their columns drop in a later PR). PR 2, the per-game content: four `content_*` columns, a content status (empty, draft, published) replacing `seo_status`, About + FAQ under the reviews on the primary brand only, the summary in the meta description, `llms.txt`, Product JSON-LD and the sitemap `lastmod`, and the rebuilt Admin → SEO → Games → [game] editor. Content is published per game in the admin; Google has not yet crawled the game pages (§16 item 178). | ADR-029, ADR-042, ADR-120, ADR-140 |
| Settings (SET-1..11) | ✅ Live — `/admin/settings` (branding / footer / platform), HTML sanitization, maintenance mode. SET-7/11 via the Payment Methods tool. SET-9 (Telegram) dropped and its fields removed (live via #406, 2026-10-12). Logo + favicon upload for the primary brand added (ADR-089) — previously only the affiliate portal had this. A re-upload shows at once (`?v=` on the URL, #409, live via #416; founder-checked 2026-10-13). **2026-10-22:** a fourth legal page, Refund Policy (`/refund-policy`, edited beside Terms/Privacy/About Us), for CHIP's merchant review, live via #528 **2026-10-29 (ADR-131, via #599):** a Purchase Balance tab: the limits (step-up) and the company details a receipt prints. | ADR-028, 089, 131 |
| Developer Tools (DEV-1..2) | ✅ Live — `/middleware/developer-tools`, all 5 adapter methods, typed-DTO editor, dry-run default. Same screen as MUI-11 | ADR-054 |
| Blacklist / Fraud (FRAUD-1..4) | ✅ Live — `BlacklistService` + `CheckoutVelocityGuard` wired into checkout; `/admin/blacklist` screen. `foundation-security.md` §4 fully checked | ADR-007 |
| Middleware Panel (MID-1..13, MUI-1..11) | ✅ Live — sync/matching/catalog (Product Manager; a game link belongs to the group, ADR-067 2026-11-07 addendum), price sync + FX, player validation, test orders, request logging, supplier credentials, landing page. MUI-4 (export) dropped. **On the ADR-104 tokens since item 164 (#740 to #751), released via #755 (2026-11-08).** **Player validation, live 2026-10-16 via #469** (ADR-005's three 2026-10-16 addenda): MLBB RedxGame → MooGold (**MooGold replaced by UniPlay 2026-10-28 (ADR-005 addendum, #583, released 2026-10-29 in #589; 3 `valid` UniPlay checks on prod since)**; it had been broken since 2026-10-10, when AcidGameShop and Nexone stopped answering); Free Fire (RedxGame, country mappings); Genshin / HSR / ZZZ (RedxGame, the UID's server must match the chosen zone, `wrong_zone`). Validators: MLBB, Free Fire, Genshin, HSR, ZZZ, all toggled on for their games **2026-10-18 (ADR-125 PR 1a, §16 item 113), released via #514:** `suppliers.driver`/`funding_mode`; a manual supplier (any number, MYR, prepaid or pay-after) can be added in Suppliers and is skipped by price/product sync, balance refresh, the staleness alert, status checks and the API tester; its balance is its funding ledger ("Owed" when pay-after); one Pending rule per supplier for the reconciler and Dashboard | ADR-005, 051, 052 |
| Storefront (checkout flow) | 🟢 Live in prod — all catalog/checkout/validate/track endpoints; server-side validation enforcement; PekanGame neo-brutalist redesign, mobile pass, read-path perf (Redis cache), dynamic payment SVGs + UX polish. Logo/favicon upload UI + aspect-preserving sizing + preset background/dark-mode groundwork shipped (ADR-089/090). Real logo/hero artwork uploaded 2026-09-14 (§16 item 2); the placeholder mark is only the fallback when no logo is set. Per-game "How to Buy" info popup (description/important notes) + admin-editable instant/manual delivery badge, `EditGameModal` gained Basic Info/Content tabs (ADR-109) — admin Content tab live-verified 2026-10-08 (§16 item 67). **2026-10-05 (ADR-097 addendum, #356 — released via #362, 2026-10-06):** the ID fields follow the game's contract — digits-only fields get the numeric keypad and block typed non-digits, a paste is kept with an inline error, a Text game (e.g. Riot ID) gets a normal keyboard; the backend refuses the same input on checkout and Check ID **2026-10-17 (ADR-071 addendum, #474, released via #476):** every route has a matched `loading.tsx` (home, price list, Check ID, membership, legal pages were missing one and froze on navigation), enforced by a CI guard. **2026-10-18 (ADR-125 PR 1b), released via #514:** a login game's Step 1 is its login form plus the consent box; the order sends the login, never a Player ID; Track Order, its live push, the WhatsApp card and the affiliate portal show the login masked, with a change-your-password line on delivery. **2026-10-30 (#606, #610, released in #608 and #610):** Step 2 (the package list) is browsable before the ID is verified (a tapped package is kept and, on a phone, the page scrolls up to Step 1; Step 3 needs both the ID and a package, ADR-064 addendum); the mobile bottom nav labels are readable on Cyber Bumblebee (they were ink on ink, ADR-090 addendum). **2026-11-03 (ADR-077 addendum), released via #668:** the storefront's Data Cache backstop is 300 s (was 30 s; hero slides 60 s); an admin save still shows at once through the purge webhook. **2026-11-04 (ADR-118 PR 3, #692, released via #694):** the Review Modal's promo field and `?promo=` capture (§15 Marketing Campaigns); the Review Modal mounts only while open (on the order page and `/joki`), so a reopened modal no longer shows a voucher it would not send. **2026-11-08 (ADR-078 addendum), released via #737:** the proxy serves an expired answer and refreshes behind it, with one load at a time and a 2 s timeout; `images/` skips the proxy; a `fill` Image must have `sizes` (ESLint). | ADR-062–065, 071, 077–079, 089, 090, 097, 109, 118, 125 |
| Reseller Price List (ADR-091, `/price-list`) | 🟢 Live (released 2026-11-01 in #642; families filled, item 154 closed). Browse by game then region (one region's table at a time, swipeable tiles and pills on a phone, search from 2 characters), an "Apply to become a reseller" button (the onboarding link from `ONBOARDING_URL`), grouping from two nullable display columns `games.family` / `games.region_label` set in the admin game modal, and a tiers-only `?summary=1` so the root layout and sitemap stop parsing every row. Family and region set on 21 games (Mobile Legends, Free Fire, Valorant); single-region games stay their own tile | ADR-091 |
| Game ID Checker (ADR-123) | ✅ Live 2026-10-16 via #472 — `/check-id` + `/check-id/<slug>` for the five families (Mobile Legends, Free Fire, Genshin Impact, Honkai Star Rail, Zenless Zone Zero), Turnstile (widget "PekanGame Game ID Checker"), 5/min + 30/hour per IP, 200/hour provider budget, RedxGame only (MLBB: Redx → UniPlay since #583, released 2026-10-29, ADR-123 2026-10-28 addendum), `public_player_checks`; in the sitemap and `llms.txt` on the primary brand, `noindex` on affiliates. Mobile More drawer; header highlight follows the page. A new affiliate custom domain must be added to the widget by hand until §16 item 108 | ADR-123 |
| Customer Login (ADR-124) | 🟢 Live, verified on prod 2026-10-23 with the first real Login Top-up order (FPX-paid Roblox, `.done` in the supplier group; build-log-archive 2026-10-23). Built 2026-10-18 (§16 item 112), released via #514 — `order_credentials` (encrypted secrets, out of DB dumps), `app:wipe-order-credentials` every 15 min (critical heartbeat), super_admin reveal/replace behind MFA step-up with a per-admin hourly limit and a `credential_reveals` audit (pruned after a year), Settings → Customer Login Policy (G, H, D, reveal limit, consent text), Order Detail "Customer Login" card. **2026-10-18 (ADR-125 PR 1b-i, §16 item 113), backend only, released via #514:** checkout writes the login with the order; the identifier is masked on every customer and affiliate surface; a resend's identifier correction is audited (`identifier`). **1b-ii:** the storefront login form with the consent box (the text the order snapshots); an admin-replaced password is no longer trimmed **2026-10-19 (ADR-125 PR 2a, released via #514):** the second reader is the supplier's short link `/s/<token>` (hash-only `supplier_login_links`, Pending attempt only, each reveal a `credential_reveals` row with actor supplier) **2026-10-23 (ADR-126 PR 1, item 114), released 2026-10-25 via #554:** each login stores its ceiling (`secrets_expire_at`): the game's own "keep login at most" days (Edit Game, login games), else D. Changing either moves the unwiped logins; the supplier link expires with the login | ADR-124, ADR-125 |
| Joki MLBB (ADR-126) | 🟢 Released 2026-10-25 (#554). **Switched on by the founder** (global switch last changed 2026-11-03 17:40 UTC; on for PekanGame, off for the other brands; no paid joki order yet, checked read-only 2026-11-04). PR 1 (#533): shared manual-order seams (post key per supplier, supplier re-check, explicit stale clock, per-login wipe ceiling). PR 2a (#534): `Fixed` basis, assignment pool, Assign under lock with audit, payout locks, joki game hidden, `not.joki` gate. PR 2b (#535): rank model and draft rates, pricing rule (Grading guarantees Mythic 15★), joki through the one checkout, `joki_orders` snapshot, `/api/joki/catalog` + `/quote`, `JokiCatalog::ensure()`. PR 3 (#536): `.progress` and the admin progress update, Track Order climb and current rank, paced WhatsApp progress. PR 4a: admin backend (`JokiBoard`, `/api/joki-admin/*`, F4 with "needs voucher" on the Joki card, `/orders` type filter, penjoki creation). PR 4b: Admin → Joki (Orders, Penjoki, Settings), Order Detail Joki card, Dashboard card, `/orders` type select, brand "Sell Joki", penjoki on supplier create. PR 4a/4b merged (#538, #539). PR 5 (#540): `/joki` page with the rank calculator, Joki Rank menu, sitemap, voucher preview priced like checkout, e2e golden path. Offered from Epic up (#552, 2026-10-25, ADR-126 addendum). All released 2026-10-25 via #554. Item 114 closed 2026-11-01 | ADR-126 |
| Partner portal UI (ADR-128) | 🟢 Live — released 2026-10-27 (#571). Layer 1: the ADR-104 tokens generated into `reseller/` (`scripts/sync-shared-files.mjs`, CI drift check), the "PekanGame Partner" shell (grouped navigation, nude account block, support contacts from the primary brand's branding, trust footer), both Dashboards, Orders, Order detail, Wallet, Earnings, Withdrawal, the settings pages, Login and Set password. Shared-token fixes that also reach the admin: dark `on-cyan` and `focus-ring` follow the artifact (admin primary buttons in dark mode were 2.09:1), and JetBrains Mono is now loaded in both apps. **The one shared component kit (ADR-128 2026-10-26 addendum, #567 + #568, live via #571):** the admin kit moved onto the semantic tokens (Button severities resolved to the `-surface`/`-ink` badge pairs, touch targets `min-h-11 sm:min-h-9`), then generated into `reseller/src/components/ui/` and the portal's hand-rolled `ui.tsx` deleted in favour of it; status buttons are soft `-surface`/`-ink` pairs (2026-10-27 addendum). Reviewed page by page before release (build-log-archive 2026-10-27). Layer 2 is items 24 and 25 **2026-10-29 (via #599):** the wallet screen is `/balance`, "Purchase Balance". **Released via #791, 2026-11-12 (ADR-058 addendum, §16 item 181):** Forgot password on the sign-in page (Reseller and Affiliate), and Send password reset on the admin's user lists; a reset ends the user's other sessions. | ADR-128, ADR-104, ADR-112, 131 |
| Partner onboarding (ADR-133, §16 item 146) | 🟢 Live in prod since release #633 (2026-11-01); the founder has turned the public application switch **on** (checked read-only 2026-11-04; no application yet): PR 1a (one bank, payments-approval and paid-order predicate, `ResellerProvisioningService`, `PartnerReadinessService`) and PR 1b (a checklist, banners, empty states and bot status in the portal, a Setup column on the admin Affiliates and Resellers lists, the payments-approved email, set-password in English). PR 2 (#623, 2026-10-31): a reseller with no tier cannot add balance, the admin create makes the login and requires an active tier, "Create internal account" with a reason and step-up. PR 4 (2026-10-31, branch `fix/max-markup-pct-null-no-ceiling`): a blank markup ceiling now opens no markup everywhere (pricing, portal, admin), ADR-061 2026-10-31 addendum. PR 3a (2026-11-01, branch `feature/partner-onboarding-pr3a-applications`): the application backend: `partner_applications` and its documents, `PartnerApplicationService` (every status change under a row lock, approval through `ResellerProvisioningService`), the public stage-1 and stage-2 endpoints behind a "Public applications open" switch and Turnstile, the admin API with 5-minute signed file links and a view log, the bilingual notices (recipients by class), the daily retention command. PR 3b (2026-11-01, branch `feature/partner-onboarding-pr3b-public-forms`): the public forms `/apply`, `/apply/verify` and `/onboard/[token]` in the portal app, served only on the `onboarding.` host (404 on the portal host, noindex), Turnstile allowed only on those pages; browser-checked end to end. PR 3c (2026-11-01, branch `feature/partner-onboarding-pr3c-admin-screen`): Admin → Partners → Applications (status tabs, detail cards, document links with a view log, send link, approve with a tier, decline, delete data, the open switch); "Add Reseller" replaced by "Invite directly" and the plain create route removed. Released in #633 | ADR-133 |
| Internal Accounting (supplier funding ledger + CHIP settlement recon) | 🟢 Live — PR-1 built 2026-09-11: `supplier_transfers`/`supplier_ledger_entries` (append-only, foreign-currency), Record Supplier Transfer UI (`/admin/accounting`), `ORDER_DRAWDOWN` capture (Digiflazz webhook + Gamevion sync response — a `Gagal` after `Pending` writes no `REFUND`, grilled), drift check + amber chip on Dashboard Health (watches only a supplier with `api_config.drift_threshold` set; DigiFlazz set to 50000 by the founder on 2026-11-01), Transaction Register + CSV export. **2026-09-15 addendum built:** `supplier_fee` field (the supplier's own deposit-side cut, e.g. Digiflazz's flat IDR fee — ledger now credits net, not gross) + Adjust/Void correction actions (never edits/deletes the append-only ledger, always a new `MANUAL_ADJUSTMENT` entry). **PR-2 built 2026-09-19 (ADR-110 PR-B)** — CHIP `.xlsx` settlement reconciliation (`payment_settlements` + `chip_settled_transactions` dedup guard) + Monthly Accounting Summary screen, verified against a real PekanGame CHIP settlement file. **2026-10-08 (ADR-083 addendum, live via #376):** neutral "Affiliate tier fees (from earnings, no cash)" line, also in the Envelope Ledger's rough P&L. **2026-10-10 (ADR-083 addendum, §16 item 78) PR-1 (#390), live via #393:** Envelope Ledger postings with typed lines (funding loan/share capital, transfer, expense, director-paid expense, repayment, distribution), per-director loan balances, whole-posting void, zero-balance archive; `supplier_transfers.transferred_on`; `LedgerEntry` append-only guard. **PR-2 (#391), live via #393:** month close on the Monthly Summary (exact operating-profit allocation per KL month from September 2026, prior-month drift carried forward, reopen of the latest close only), "where is the money" cash equation with a cash-account snapshot and gap note, operating profit + new lines (voucher breakage, admin-issued vouchers, supplier corrections, envelope expenses), FX rate as of month end; the manual "Allocate Monthly Profit" and the platform "Request Withdrawal" are gone. **2026-10-11 (ADR-083 addendum, item 75), live via #406 (2026-10-12):** the Monthly Summary's issued-voucher lines are split into "Admin-issued vouchers (no order)" (a profit cost) and "Failed-order store credit issued" (information only); a merge result is in neither. **2026-10-17 (ADR-083 addendum, items 115 and 121), live via #495:** COGS at funded cost with a bridge to the per-order cost and a drawdowns-not-in-COGS line; operating profit unchanged. **2026-10-18 (ADR-083 addendum, item 123), live via #500:** a manual order-profit correction is its own Transaction Register row (`order_profit_correction`, dated when made); the order row keeps the original credit. **2026-10-18 (ADR-083 addendum, item 120), live via #500:** the original CHIP settlement file is kept and downloadable, a file with nothing new is refused, old settlements can attach their original, and receipts are filed by accounting month and never orphaned or deleted. **2026-10-18 (ADR-125 PR 1a, §16 item 113), released via #514:** an MYR supplier's rate is 1.0 everywhere (COGS, supplier corrections, month-end position); the cash position splits each supplier's balance into the prepaid asset and a new `supplier_payable_sen` (owed to a pay-after supplier; the gap is unchanged); a sandbox order never writes a supplier drawdown **2026-10-24 (ADR-127, #543–#546, released 2026-10-25 via #554):** revenue/COGS/commission by `recognised_at`; Cash Position, wallet line, breakage and the Register read dated facts only, so a closed month cannot move (`FinancialScenarioTest` invariant 2); `AccountingPeriod::assertOpen` refuses dated writes into a closed month; CHIP `paid_on`/fee recorded and the fee shown on Settlements; membership revenue from the ledger. **ADR-127 PR E (#549, released 2026-10-25):** the Monthly Summary splits "Supplier payments — pay-after" (paying a penjoki or manual supplier what is owed) from "Supplier prepaid — top-up". **§16 item 132 (released 2026-10-25, #554):** from 1 October 2026 a foreign-currency supplier is costed at a moving weighted average (`MovingAveragePool`), so every ringgit sent is a cost in some month or still in the prepaid balance. **2026-11-01 (ADR-083 second 2026-11-01 addendum and ADR-024 addendum, #644, released via #645):** the month close nets each unsettled CHIP payment's customer-paid fee (no RM1 estimate); the profit bridge's "ledger profit vs order figures" replaces the double-counting "manual profit corrections" line; the DuitNow fee is grossed up to CHIP's 1% of the total. **2026-11-02 (ADR-135 PR 4, #658, released via #660):** a settlement's detail screen tags a transaction CHIP settled whose record here is not paid (worked out live, never stored) and offers Re-check with CHIP (super_admin, step-up), which records the payment through that record's own paid seam, never a manual credit. **2026-11-04 (ADR-118 PR 2, #691, released via #694):** a KOL campaign's fee schedule (accrued per month), campaign-tagged Advertising postings, and Monthly Summary lines for gross sales, KOL promo discounts and KOL fees accrued / paid / prepaid (never in operating profit or the allocation). | ADR-083, ADR-110, ADR-118, ADR-135 |
| Balance / Wallet Visibility | 🟢 Part A live (released 2026-09-29, #320) — standalone `/admin/balance` (deliberately not nested under Accounting — a Commerce-level item, since Part B grows it into operational monitoring, not bookkeeping): live-refreshable supplier balance per supplier + every Reseller's prepaid wallet balance (total + per-row), zero new backend, reuses ADR-046/073. Part B (learned "comfortable buffer" forecast, ADR-115) is live since release #699, 2026-11-05 (§16 item 28; the Supplier Management row has the rule). **2026-10-18 (ADR-125 PR 1a), released via #514:** a manual supplier shows its funding-ledger balance ("Owed: MYR …" when pay-after) with no Refresh, and the reseller-wallet coverage total counts API suppliers only | ADR-046, ADR-073, ADR-115 |
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

**Last full re-check: 2026-11-11, in the docs audit after release #777.** The
dated history of earlier re-checks is in `build-log-archive.md` ("PRD §16 text
moved 2026-11-11") and in each `build-log.md` docs-audit entry. This list
holds only **open** items. "Next up" is the one place they are sorted, one
short entry each; "Details" holds the full text once, by number. Everything
closed is one line under "Recently closed", pointing to where the full story
lives (`docs/build-log.md` / `docs/adr.md`); items closed more than 30 days
ago move verbatim to `build-log-archive.md` at the next docs audit. Item
numbers are kept stable so older build-log and memory references still
resolve. This list drifts easily: re-verify an "open" line against real code
or production before building on it.

## Next up

**Founder's rule (2026-10-08): clear the backlog below before any new
feature.** Every open item, sorted by what it needs to finish. Re-verify
before building.

**A. Buildable now: code, no new design**
- **178** SEO meta strategy and per-game "About this game" content (ADR-140, founder, 2026-11-11). Built and released in #783. Open: the founder's backlinks, the +2/+4/+8-week Search Console checks, and the release that drops three dead columns (built, not released). Details below.

- **180** One lookup for a payment method by channel code: about ten callers repeat `PaymentMethod::query()->where('channel_code', …)` (gateway or label: wallet top-up reconcile, membership, receipts, fee resolver). Found in the 2026-11-12 stress test; correct today, only repeated. Details below.

**B. Needs a short grill / ADR addendum first**
- **165** KOL fee as a share of profit (needs an ADR-118 addendum, money-critical). Until built, pay such a KOL as "Not a KOL fee". Details below.

**C. Founder actions (no code)**
- **182** OpenWA settings for the 2026-11-14 WhatsApp hardening, after its release: add `message.failed` to the customer-support webhook, `SIMULATE_TYPING_MAX_MS=1500`, Supervisor `stopwaitsecs` 75 for OpenWA, protect the public dashboard, `allowedIps` on both operator keys, `WEBHOOK_FAILURE_PAYLOAD_RETENTION_HOURS=72`. Details below.
- **186** Player-ID check for Free Fire, Genshin, HSR and ZZZ is down: RedxGame's Cloudflare answers 403 to our server since about 2026-11-06. Waiting on RedxGame's answer about official access. Details below.
- **158** Grade the CS assistant's drafts; PR 4 (Live) is built once a category meets the Live gate (ADR-136). Details below.
- **11** Sentry (error tracking) is still not set up. Details below.

**D. Waiting on a trigger: don't build early**
- **183** OpenWA v0.30.0 upgrade, after the 2026-11-14 hardening is on `main`; delete the retired `SendResellerBotReplyJob` in the same PR. Details below.
- **185** Ask OpenWA upstream for a per-message "skip typing" option, so bot replies skip the pause and customer messages keep it. A public issue: only with the founder's go-ahead.
- **162** Gamevion v2 API, when the founder decides to use Gamevion (ADR-137). The ordered steps and the "don't press Reactivate" warning are in Details.
- **177** Price-sync ceiling warning, when chosen Gamevion games approach 140 (ADR-137).
- **160** An admin editor for an affiliate storefront's default OG image (ADR-060 2026-11-03 addendum): today only the primary's row is editable, so an affiliate's homepage has no share image. When an affiliate asks for one.
- **139** FPX bank-down status on the bank grid (ADR-130 decision 9), only if real payments show customers failing at a down bank. Advisory and fail-open from `fpxstatus.chip-in.asia`'s JSON feed; never a hard dependency of checkout.
- **128** Shared admin loading skeleton, if "Loading…" still bothers the founder.
- **122** Store each combo leg's cost at delivery, at the first Monthly Summary fallback on a combo leg.
- **81** Month-end pack and the open accounting policies, when an accountant is engaged.
- **4** Bot-host resize, when capacity calls for it.
- **59** OpenWA engine switch, with item 4.
- **83** Give back an admin voucher's ledger debit on revoke/expiry, with the first admin-issued voucher.
- **85** Membership renewal reminder on WhatsApp (designed), at 10 active members or when members don't renew despite the email.
- **150** Membership pitch to a non-member after a delivery, at around ten active members.
- **108** Add affiliate custom domains to the Turnstile widget automatically (ADR-123), at about 5 active affiliate domains or when the manual step is tiresome.
- **111** Turnstile on the admin and portal logins (ADR-019 2026-10-17 addendum), at the first "Account locked" alert that isn't the founder.
- **105** Batch `Order::cashCompensationSen()`'s wallet-refund lookup, when the Customer Detail page is slow for a big reseller (Pulse > 500 ms).
- **169** Send `max_price` to suppliers ([ADR-137](./adr/ADR-137-gamevion-v2-api.md) 2026-11-07 addendum holds the design). Decided 2026-11-07 not to build yet: a selling price follows cost, and on production it would have saved RM0. Trigger: the first delivered order that loses money because the cost rose after payment with no admin decision behind it (`profit_reconciled_flagged`).
- **171** Campaign promos beyond new PekanGame customers (needs an [ADR-118](./adr/ADR-118-marketing-campaigns.md) addendum, not yet written; money-critical). A Campaign today is percent only, new customers only, primary brand only, so a promo on OhahaStore or a sale for existing customers isn't possible. When the founder wants one.

**E. New features: after the backlog** (each needs its own ADR + grill; the founder decides when)
- **184** Send every Delivered receipt without opt-in (founder, 2026-11-14). Reopens ADR-116 decision 5 and its cold-reachout rationale; needs an addendum with opt-in and volume data first. Details below.
- **104** Reseller wallet top-up KPIs (how often, how much). Its trigger (item 28) has fallen.
- **151** A partner sandbox (test-type reseller API key), its own money-critical ADR. Its trigger (item 146) has fallen.
- **152** Refresh the admin sandbox (`/middleware/sandbox`) for wallet and combo orders; do it with item 151, which shares `FakeSupplierAdapter` (its 21 tests pass as of 2026-10-31).

## Details

182. **OpenWA settings after the WhatsApp hardening (2026-11-14, ADR-116
    and ADR-075 2026-11-14 addenda).** Each is a production change, so the
    founder runs it with the exact command Claude prepares:
    - customer-support webhook events: add `message.failed` (today
      `message.received`, `message.sent`, `session.status`; the bot's
      webhook stays as it is);
    - OpenWA `.env`: `SIMULATE_TYPING_MAX_MS=1500` (the pause is global, so
      both numbers get it; customer messages keep our 10-30 s gap) and
      `WEBHOOK_FAILURE_PAYLOAD_RETENTION_HOURS=72` (at 0 a failed webhook
      cannot be redriven);
    - Forge daemon for OpenWA: `stopwaitsecs` 15 → 75 (OpenWA's documented
      teardown needs up to 70 s; 15 s kills it mid-write);
    - `bot.pekangame.space` answers 200 to anyone, and the dashboard logs in
      with an API key only: put Cloudflare Access or an IP allowlist in front;
    - `allowedIps: ["127.0.0.1"]` on the two operator keys (Laravel calls
      OpenWA on localhost).

183. **OpenWA v0.30.0 upgrade.** Brings the fix for the inbound-media crash
    (`TypeError: terminated`) and the `passkey_required` restriction kind,
    which `/api/health/whatsapp` already treats as restricted. Through
    `scripts/openwa-upgrade.sh`; never `npm ci --ignore-scripts` (the Baileys
    patches install from scripts). Delete `SendResellerBotReplyJob` and its
    test in the same PR, once no job of it is left in Horizon.

184. **Delivered receipts without opt-in (founder, 2026-11-14).** Today a
    receipt goes only to a customer who wrote to the CS number first
    (ADR-116 decision 5): every receipt to a stranger is a cold reachout,
    OpenWA's pacing allows 5→100 a day by session age, and a ban on the CS
    number also takes down human support. Before any build: the share of
    customers who opt in today, storefront orders per day, and a safe cold
    rate, then an ADR-116 addendum and a grill.

186. **RedxGame-only player checks are down (found 2026-11-13).** From the
    production box, RedxGame's checker page loads (200) but its
    `POST /tools/check` answers a Cloudflare 403; the same request from a
    home connection answers 200. MLBB is unaffected (UniPlay answers every
    check; 348 RedxGame 403s in the log). Free Fire has never had a valid
    check: 11 attempts in 7 days, all `region_unknown`, so every Free Fire
    customer is asked to contact support. Getting past the block (browser
    headers, proxies) is not an option. Ways forward: official access or an
    IP whitelist from RedxGame (the founder is asking), another official
    checker, or switching the check off for these games meanwhile
    (ADR-005 addendum).

180. **One payment-method lookup by channel code (2026-11-12, found in the
    stress test for the bot top-up method).** The callers:
    `ResellerWalletTopupService` (initiate, `gatewayFor`),
    `ReconcilePendingWalletTopupsCommand` (two),
    `ReconcilePendingMembershipPaymentsCommand`, `MembershipSubscriptionService`
    (four), `ResellerWalletReceiptService`, `PaymentMethodFeeResolver`,
    `CheckoutController`. Each reads `gateway`, `label` or the active row by
    `channel_code`. A shared lookup (a model method or scope) would keep a
    future rule, such as an inactive method still resolving its gateway for
    a pending attempt, in one place. No behaviour change. The list of offered
    methods itself is already one scope since item 181's PR
    (`PaymentMethod::offered()`).

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

59. **`ENGINE_TYPE=baileys` runs both OpenWA sessions — decided 2026-10-10: Baileys
    stays until the droplet is upgraded** ([ADR-075](./adr/ADR-075-reseller-bot-channel.md)'s
    2026-10-10 addendum). Waiting on a trigger: the host resize (item 4), a WhatsApp
    warning or ban, or `reseller-bot` volume that makes a ban cost more than the
    resize. Before any switch, check whether OpenWA can set the engine per session
    (`ENGINE_TYPE` is global, so a switch would also move `customer-support`), and
    plan the QR re-pairing of both numbers.

81. **Month-end pack for the external accountant — waiting on an
    accountant (founder, 2026-10-17).** The founder has no accountant yet.
    ChatGPT's answers to the questions below (2026-10-17) were used only to
    decide item 115 (COGS at real, funded cost). The rest waits for a real
    accountant: the pack's contents and format (it suggested one ZIP a month
    with six folders), revenue and COGS by **delivery** date instead of
    `paid_at` (paid-but-undelivered at month end shown as a liability; now
    done by item 131 / ADR-127 on 2026-10-25, ahead of the accountant because Joki needed it),
    vouchers treated by reason (compensation = liability, promotion =
    revenue reduction), and whether transfer fees belong in inventory cost
    (decided ahead of the accountant by ADR-134 on 2026-11-01: a prepaid
    supplier's fee is unit cost from 2026-10-01; the accountant may confirm or
    revert it).
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
    item 28 (two weeks after launch). That trigger fell when item 28 shipped
    (2026-11-05, #699), so the item waits on the founder and its own grill.

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
    covered. **Update 2026-11-01:** the widget now also needs `ohahastore.com`
    (miss found 2026-10-31, error 110200; added by the founder, checker works),
    so 4 hostnames of 10. `pajostore.com` (payment-approved 2026-11-09) was added by the
    founder on 2026-11-11, after the docs audit flagged it: 5 of 10. Until this is built, a new affiliate custom domain must be
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
    - **Trigger:** the first "Account locked" alert that isn't the founder.
      (Public portal signup was the second trigger; the founder ruled it out on
      2026-11-01: the ADR-133 application, with its own Turnstile, is the only
      way in.)
    - **For the grill then:** fail-open vs fail-closed when Cloudflare is
      down (fail-closed can lock the founder out of admin), E2E test keys,
      and whether checkout needs it too.

122. **Store each combo leg's cost at delivery (`order_delivery_legs.cost_sen`)** —
    `FundedCostOfSales`'s leg-level fallback (a combo leg with no drawdown)
    still uses today's catalog cost, because no per-leg cost is stored at
    delivery. Prod has none as of 2026-10-17. Store `cost_sen` on each leg
    at delivery or settlement when `FundedCostOfSales` first falls back on a
    combo leg in the Monthly Summary (ADR-083 2026-10-17 addendum).
    **Checked 2026-11-01 on prod:** October's `cogs_fallback_order_count` is 0; the
    8 delivered legs hold `real_cost_price_sen` on 3 and null on 5, and the
    fallback reads that column before today's catalog cost.
    The same column would let Delivery Logs show "Cost vs checkout" for a leg
    with no real cost (ADR-106 2026-11-02 addendum, decision 4).

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

150. **Membership pitch to a non-member after a delivery** (founder idea, 2026-10-30; design not started, needs its own ADR and grill). Detecting it is easy: `orders.pricing_basis` is `member` for a member order, so the pitch goes to a non-member order of a game with member pricing on a brand with membership on, and the saving on that order is computable server-side with `MembershipPricingService::calculateMemberPrice` (only the saving is shown, never cost or margin). Options, cheapest and safest first: (1) a banner on the order status page ("a member would have paid RM X for this order", with a link), which needs no consent and carries no ban risk; (2) one extra line inside the delivered receipt, not a second message; (3) a separate second WhatsApp message, only after an explicit marketing opt-in (for example replying `MEMBER`), with its own STOP and rate caps. Why the WhatsApp options wait: [ADR-116](./adr/ADR-116-customer-order-notifications-over-whatsapp.md) opts a number in for order updates only, a promotion needs its own consent (PDPA), and a customer's Report on the unofficial OpenWA number can ban it and with it the human support line. Why the whole item waits: on 2026-10-30 production had 42 real storefront orders from only 4 customer emails, 2 opted-in WhatsApp contacts and no active member. Build when there are real repeat customers (around ten active members, as for item 85; 1 active member on 2026-11-01 and still 1 on 2026-11-04). Recommended first step then: option 1.

151. **A partner sandbox** (a test-type reseller API key: orders flagged `is_test`, no Purchase Balance debit, `FakeSupplierAdapter` outcomes, webhook still sent), its own money-critical ADR (grill + code trace: wallet, ledger, reports, receipts, rate limits, bot) after item 146's PR 1 ships (it did: item 146 closed 2026-11-01, so the item now waits on the founder). The checklist's first-order step changes to "test in the sandbox, then order" when it exists ([ADR-133](./adr/ADR-133-partner-onboarding.md) "Not decided here"; the admin-only sandbox is [ADR-018](./adr/ADR-018-sandbox-test-orders.md)).

158. **Customer-support assistant on the CS number** ([ADR-136](./adr/ADR-136-customer-support-assistant.md),
     Accepted 2026-11-03 after the grill and a stress test). PR 1 (#672), PR 2
     and PR 3a (#677, fixes #680), the run #3 candidates (#684) and PR 3b Shadow
     (#687) are released; run #3 chose Claude Haiku 5.5. The founder switched
     the mode to Shadow on 2026-11-04 (03:53 UTC). **Founder, 2026-11-10: stay
     in Shadow and keep growing the knowledge book; the Live gate (20 graded
     drafts and 90% per category, `live_min_drafts` unchanged) is not lowered**
     (ADR-136 2026-11-10 addendum). Next: the founder grades drafts, then
     **PR 4 (Live)** once a category meets the gate: the CS Staff group, safety
     limits and local OCR (decision 32; §15 row). Decision 31 is met (lawyer's
     OK, Privacy Policy line live on every brand). OpenWA's first 90-day message
     prune is around 2026-12-24; PR 1 already keeps the chats.

162. **Gamevion v2 API** ([ADR-137](./adr/ADR-137-gamevion-v2-api.md), money-critical). All code is live: #701–#704 released via #705, and the sync scope fix (#706) via #707. **Trigger: the founder decides to use Gamevion.** Decided 2026-11-07: not yet, so the repoint stops at 6 of 71 packages, and all 71 stay off.

     **Proven without money, 2026-11-07:** one order sent through the real adapter at RM0 balance answered 402 `insufficient_balance` ("RM 0.00 < RM 0.71"), mapped to a confirmed failure, so the key, the IP allowlist, the order endpoint and our error mapping all work. The player ID was made up, so the balance check appears to come before the player check. **Not proven:** a delivery, its webhook, drawdown and real cost.

     **When the trigger comes, in order:**
     1. in Product Manager, link the new groups that have no old items, such as `Mobile Legends (Malaysia)` (item 172 linked the items in old groups, so Valorant, Free Fire and Honor of Kings are ready);
     2. repoint the other 65 packages with "Link to existing" (Claude's match list, 2026-11-05: all 71 have a match; First Topup 50+50 and 250+250 only to a smaller-bonus item, so check them on Gamevion or delete them);
     3. a small deposit, recorded in `/admin/accounting`, then one funded storefront test order on one cheap package (HOK 16 Tokens) before any other package is switched on.

     **Don't press Reactivate on the Gamevion supplier before that test order.** It switches on every repointed package at once (6 on 2026-11-07; the other 65 are blocked `supplier_sync`), and at RM0 balance a customer could pay for an order that can only fail.

     **Gamevion's catalog comes and goes.** Its `/api/games` list fell from 1,313 to 320 entries at 03:00 UTC on 2026-11-07 and was back at 1,315 by 05:30, with no error from them; our new-API rows went from 1,541 to 800 and back to 1,164, PUBG included. The founder may want to ask Gamevion why before depositing. A repointed package is safe: its item is kept, it goes off, and returns on the next good sync, or after 6 syncs if Gamevion marks it inactive ([ADR-137](./adr/ADR-137-gamevion-v2-api.md) 2026-11-07 addendum). Check HOK 16 Tokens still exists before the test order.

     The item closes after the funded test order is checked in the database.

165. **KOL fee as a share of profit** (needs an [ADR-118](./adr/ADR-118-marketing-campaigns.md) addendum, not yet written; money-critical; founder, 2026-11-05). A KOL may ask for no monthly fee but a share of the profit their sales bring (70/30 was only a proposal). Today a campaign knows only a fixed monthly fee schedule (`CampaignFeeSchedule`, RM0 allowed), so neither way of paying such a KOL is right: a payment tagged to an RM0 campaign leaves both the envelope expenses (`whereNull('campaign_id')`) and the accrued KOL fee line, so the cost disappears from the month-close net and shows as a growing "prepaid"; tagged "Not a KOL fee", the books are right on a cash basis but the scorecard counts no fee (net and CAC overstated). **Until built, pay such a KOL as "Not a KOL fee".** Proposed: a "profit share %" fee type whose accrued fee per Malaysia month is computed from the campaign's attributed ledger profit (the scorecard's own number), never typed. Open for the founder: orders that used the code only, or every attributed order; a loss month as 0 or carried; a monthly cap; simulate the share on real order data first.

177. **Price-sync ceiling for Gamevion** (founder, 2026-11-10; [ADR-137](./adr/ADR-137-gamevion-v2-api.md)
     2026-11-10 addendum). Gamevion allows 40 pricelist calls a minute, so each
     chosen game costs about 2 s (the forced 1.6 s pause plus the call): 74 games
     took ~152 s on 2026-11-10, and the 300 s job fits about 140. No bulk upsert: it would save seconds.
     Trigger: the chosen Gamevion games approach 140; then add a warning on the
     Gamevion game picker.

178. **SEO meta strategy and per-game "About this game" content** (founder,
     2026-11-11; [ADR-140](./adr/ADR-140-seo-meta-strategy-and-game-content.md)).
     Why: Search Console showed 0 of 37 game pages ever crawled and 0 external
     links, and the pages differed only by name. PR 1 (meta strategy, #781) and
     PR 2 (per-game content and the admin editor, #782) were released in #783 on
     2026-11-11; the content was entered and published per game in the admin the
     same day (what, when and the before/after: build-log 2026-11-11). Open:
     (1) **founder:** backlinks from the brand's social profiles to game pages;
     the home default title prints the brand twice (`… | {store_name}`); the
     game Meta Template's "harga diskaun ahli" also shows on brands without
     Membership (FixFast, PajoStore); the Aniimo pack "Lumin Cystals" typo;
     Zenless Zone Zero's content once it has packages.
     (2) **Dead columns:** built on `feature/drop-dead-seo-columns` (migration
     `2026_11_16_100000` drops `seo_title_local`, `seo_description_local` and
     `seo_keywords` from `games`; the Game SEO list's stale "keywords" subtitle is
     fixed). It reaches production with the next release; this part closes when
     it is on prod and recorded in the build-log.
     (3) **Checks** against the 2026-11-11 baseline (10 indexed, 41 Discovered, no
     game page crawled, Discovery 2%, 0 external links) on 25 Nov, 9 Dec and
     6 Jan 2027: a game's "Last crawled" no longer N/A, Discovered falling,
     Discovery % rising, external links above 0. Closes after the +8-week check
     is in `docs/build-log.md`.

## Recently closed (full detail in `docs/build-log.md` or, before 2026-10-28, `docs/build-log-archive.md`; why in `docs/adr.md`)

- **179** 2026-11-12: cash refund of a failed-order voucher by bank transfer, and revoke limited to gift credit ([ADR-004](./adr/ADR-004-no-cash-refunds-ever.md) 2026-11-12 addendum), released via #791. On production all 18 vouchers are `customer_cash`, the 5 active ones can't be revoked, and no cash refund has been recorded yet.
- **181** 2026-11-12: portal password reset for Reseller and Affiliate users ([ADR-058](./adr/ADR-058-reseller-authentication-admin-reseller.md) 2026-11-12 addendum) and the bot's top-up method chosen in the admin ([ADR-076](./adr/ADR-076-reseller-bot-v2.md) 2026-11-12 addendum), released via #791. On production the bot method is FPX, and `/forgot-password` is live.

- **161** 2026-11-11: reseller milestone rebate plus automatic refund ([ADR-139](./adr/ADR-139-reseller-milestone-rebate.md)), released via #769. The founder saved the first ladder on 2026-11-10 MYT (effective 2026-12-01), and its save sent the "JADUAL REBATE BARU" notice to every active, eligible reseller's group (`RebateNotifier::ladderSaved()`; checked read-only: FixFast, Naeem Industries and AyrisShop, one group each, all `sent` at 18:55–18:56 UTC). That is the announcement (founder, 2026-11-11). The first rebate month is a §14 "Not yet seen" line. The item's last Next-up text is in `build-log-archive.md`, "PRD §16 text moved 2026-11-11".
- **164** 2026-11-08: `/middleware` on the ADR-104 tokens, a pure reskin in one PR per page (#740 to #750): Suppliers, Price Sync, Dashboard, Payment Gateways, Validators, Payment Methods, Developer / API Tester, Backups, Request Logs, Sandbox, Product Manager, with their modals. No `gray-*` / `brand-*` / `white/[…]` class is left under `app/middleware`, `components/middleware` or `components/price-sync`; the admin Orders components the Sandbox reuses are not part of it. Benchmarked by screenshot against admin `/orders`, light and dark. Released via #755.
- **176** 2026-11-08: the OPcache fix on the box ([ADR-066](./adr/ADR-066-production-deploy-via-laravel-forge.md) 2026-11-08 addendum). Release #737 ran the new reload and the cache restarted with it, hit rate 99.18%.
- **159** 2026-11-07: OpenWA upgraded v0.27.1 to v0.29.1, so one-to-one chats stay in the dashboard list after a restart ([ADR-075](./adr/ADR-075-reseller-bot-channel.md) 2026-11-08 addendum). About 1m40s offline; both sessions back and the status webhook received. A message in and three out were then seen on the new version.
- **175** 2026-11-07: KOL attribution gaps ([ADR-118](./adr/ADR-118-marketing-campaigns.md) 2026-11-07 addendum, V21 to V25), released via #728. No code has been redeemed yet, so the first real redemption still needs checking in the database.
- **170** 2026-11-07: item 163's small findings, released via #728: a sandbox order is never compensated ([ADR-094](./adr/ADR-094-combo-package.md) addendum), membership checked as owned-brand-only on read and a brand's tier read only through `storefrontPriceTerms()` ([ADR-061](./adr/ADR-061-every-storefront-is-a-reseller.md) addendum), one wallet-refund SQL, one profit formula. The same-reference window stays ([ADR-102](./adr/ADR-102-order-delivery-failure-guard-unification.md) addendum).
- **163** 2026-11-07: seam audit finished, released via #728. Seams A, E, F and G were item 166, C item 167, D item 168, the small findings items 170 and 175, and B was downgraded to watch only. Its last two inputs: a package staying on its game is a deliberate rule (1 of 1,477 differs, package 516, by decision), and the Link dialog no longer changes an existing game's checkout ([ADR-067](./adr/ADR-067-digiflazz-adapter-completion.md) 2026-11-07 addendum).
- **173** 2026-11-07: no grace period for Gamevion ([ADR-137](./adr/ADR-137-gamevion-v2-api.md) 2026-11-07 addendum). A package skipped on a 503 is back on the next good sync, about 30 minutes later, proved by test through the real job.
- **174** 2026-11-07: closed with no code. The three packages (Free Fire Thailand 1052 Diamond, Blood Strike 200 and 600 Gold) are `inactive` at DigiFlazz, streak 0, so off is right. After sync 3375 no package was off with a streak of 6 or more, and Twilight Pass came back by itself at its sixth sync. The streak keeps no history, so the earlier reading can't be rechecked.
- **172** 2026-11-07: a game link belongs to the group ([ADR-067](./adr/ADR-067-digiflazz-adapter-completion.md) 2026-11-07 addendum), released via #723.
- **168** 2026-11-07: a voucher in use can't be closed ([ADR-036](./adr/ADR-036-voucher-merge.md) 2026-11-07 addendum), released via #723; no reservation sat on a merged or revoked voucher.
- **167** 2026-11-07: combo leg safety ([ADR-103](./adr/ADR-103-combo-order-per-leg-independence.md) 2026-11-07 addendum), released via #723; no leg at `Processing` at release.
- **166** 2026-11-06: ADR-138, package sale lifecycle, built in four PRs (#711–#714) and released via #715. `closeRow()`'s old-rule branch removed afterwards. Seams A, E, F and G closed.
- **28** 2026-11-05: supplier buffer forecast plus the low-balance email ([ADR-115](./adr/ADR-115-supplier-balance-comfortable-buffer-forecast.md) 2026-11-05 addendum), built after the trigger fell (real spend 22 Oct – 4 Nov: IDR 9.57 million). The buffer is the busiest window of the last 60 Malaysia days (the stress test showed p90 at RM184 against a real RM1,813 two-day burst). From Thursday the window runs through Monday. The required figure is the higher of the floor and the forecast. The page shows it on `/admin/balance` (restyled), the Dashboard chip reads it, and an email goes out once a day per supplier while short. Released via #699 (2026-11-05).
- **155** 2026-11-04: landed cost rate ([ADR-134](./adr/ADR-134-landed-cost-rate.md)), released 2026-11-01 in #642. The founder's two follow-ups are done: October was closed on 2026-11-04 (close #4, checked read-only: operating profit RM129.22 allocated to Company Savings, gap −RM10.95 with a written note, drift 0), and the resellers were told about the ~6.8% price rise.
- **57** 2026-11-04: Marketing Campaigns, KOL promo codes ([ADR-118](./adr/ADR-118-marketing-campaigns.md) 2026-11-04 addendum V1–V20), built in four PRs (#690 backend core, #691 fee schedule and accounting, #692 storefront, tracker link and readers, PR 4 Campaigns page and scorecard); released via #694 (2026-11-04). After release the founder creates each KOL's campaign and code on Admin → Customers → Campaigns, and records each KOL payment in the Envelope Ledger tagged with its campaign. Target CAC and trial length are set there when known.
- **157** 2026-11-03: OpenWA cleanup after the move to `~/openwa-deploy` (ADR-114 2026-11-02 addendum). After three passing nightly OpenWA backups (31 Oct, 1 and 2 Nov, restore test passed), the founder deleted `~/OpenWA-v0.25.0`, its pre-upgrade tarball and the two build logs; `releases/v0.27.0` stays as the rollback. `API_KEY_PEPPER` left unset: it only clears a boot warning and would force both OpenWA keys to be re-issued.
- **94** 2026-11-03: best-selling products per game in Reports ([ADR-104](./adr/ADR-104-admin-panel-visual-redesign.md) 2026-11-03 addendum): one product definition shared with the storefront, snapshotted on the order; released 2026-11-03 via #668.
- **156** 2026-11-03: a privacy-id (`@lid`) chat that sends an order number is checked against the checkout phone through OpenWA's lookup and tied to it on a match ([ADR-116](./adr/ADR-116-customer-order-notifications-over-whatsapp.md) 2026-11-03 addendum); released 2026-11-03 via #668.
- **24** 2026-11-02: resume or cancel a pending Purchase Balance top-up ([ADR-135](./adr/ADR-135-pending-topup-resume-cancel.md)), built in four PRs (#655, #656, #657, #658), released 2026-11-02 via #660. The portal and the WhatsApp group (`.batalbaki`) show, resume and cancel a pending top-up, with the cancel decided by CHIP. The settlement screen shows a settled payment whose record here is not paid, with Re-check with CHIP.
- **14** 2026-11-01: assistant chat history, closed as not needed (founder). It would serve a handful of `super_admin` accounts and reverse ADR-087 decision 7 (no stored conversations). Reopen only if the founder keeps losing a past assistant conversation; it needs its own grill (retention, per-admin isolation, the thread endpoints).
- **25** 2026-11-01: the portal "needs attention" aggregate, closed as not needed (founder). Only 2 resellers on prod and a failed order already shows in the orders list. Reopen when a real partner reports not seeing a failed order; it needs a backend aggregate with explicit payment, delivery and compensation semantics and its own ADR, never a sum of the paginated page in the browser.
- **129** 2026-11-01: voucher-only Refund Policy, closed (founder). The text was saved and read live on `pekangame.com/refund-policy` the same evening: a store voucher for the part not delivered, no refund to the original payment method for a storefront order, a membership that could not be activated fixed or given a voucher, and the reseller-balance lines unchanged. ADR-004 stays in force (its 2026-11-01 addendum).
- **141** 2026-11-01: CHIP activation conditions (ADR-131, released in #599), closed (founder). Done: the company details on prod; the reply to CHIP sent 2026-10-29 (CHIP then allowed FPX and DuitNow QR); OhahaStore's domain approved and a real purchase works. FixFast Demo stays active by the founder's choice (a test affiliate whose domain cannot take a payment; ADR-061's "rename or remove the Owned Brand tier after it is deactivated" stays conditional on that). The "Save Limits" and "Close account" browser checks were not seen live; backend tests cover them.
- **114** 2026-11-01: Joki MLBB (ADR-126), built and released 2026-10-25 (#554), closed as a backlog item (founder). Gates checked read-only on prod: FPX and DuitNow QR approved by CHIP; rank icons set on all three tiers (Epic, Legend, Mythic); rates set (Epic and Legend RM2 / RM3, Mythic RM4 / RM5); the Grading Express price is the founder's own choice (RM39, same as Grading). The last gate, the Refund Policy text (item 129), is closed; the founder turns the global switch on (`joki_settings.is_enabled`, 0 on 2026-11-01) when they choose. The PR-by-PR build record is in build-log-archive 2026-10-23 to 2026-10-25 and the ADR-126 addenda.
- **55** 2026-11-01: test/internal order flag, dropped (founder). Every order counts as sales revenue, because the platform does receive the diamonds or top-up (ADR-083 2026-11-01 addendum). `orders.is_test` stays for sandbox orders only.
- **74** 2026-11-01: ask the reviewer before a non-zero affiliate tier fee, dropped (founder): the fee stays RM0 on purpose. The neutral "Affiliate tier fees" line in the Monthly Summary stays. Reopen if a fee above RM0 is ever set (ADR-083 2026-11-01 addendum). Whether a Purchase Balance deposit needs an e-Invoice (ADR-131) stays with item 81.

- **153** 2026-11-01: the reseller application is open (founder). No link from the portal login page (founder): applicants arrive through the docs-site "Request access" and the `/price-list` "Apply" button; the whole portal is now `noindex` (build-log 2026-11-01).
- **154** 2026-11-01: Price List Family and Region filled by the founder after release #642. Checked read-only: 21 of 38 active games grouped (Mobile Legends 10 regions, Free Fire 6, Valorant 5). Genshin Impact, Honkai Star Rail and Rainbow Six Mobile have one region each and stay their own tile (founder).
- **146** 2026-11-01: Affiliate and Reseller onboarding ([ADR-133](./adr/ADR-133-partner-onboarding.md) and its addenda), built in PRs #621–#632 and released in #633. The readiness checklist, banners and empty states; the tier-less top-up guard and the admin create with a login; the `max_markup_pct` fix (ADR-061 2026-10-31 addendum); the reseller application with KYC (backend, public forms on `onboarding.pekangame.space`, Admin → Applications, "Invite directly"). The public application switch ships off; the founder reads the notice text and tries "Invite directly" before turning it on. Status: §15 "Partner onboarding".
- **13** 2026-11-01: self-serve reseller signup, closed into item 146. The public application with KYC is the way in; the portal itself never opens to public signup (founder, 2026-11-01).
- **147** 2026-10-30: OhahaStore go-live, done by the founder. `ohahastore.com` and `www.ohahastore.com` are active with valid certificates, and payments were approved in the admin at 07:53 UTC (CHIP's written approval is the founder's record outside the repo). The `www` row becomes a redirect from the portal after the next release (item 148). Still open: OhahaStore has no bank details, so the monthly payout skips it (item 146 would force them). Details: ADR-060's 2026-10-30 addenda, build-log 2026-10-30.
- **138** 2026-10-30: real-bank proof for release #589, closed. Seen on prod, read-only: an FPX order through the bank grid (`PG-SOOP26HC3ZXL`, RM13.32, paid and delivered; CHIP shows `paid`, not a test, one successful attempt at Maybank2u, whose code is in the grid) and a DuitNow QR order (`PG-L4RCBELJ9RE3`, RM25.70, paid and delivered; CHIP shows `paid`, not a test, method `dnqr`, one successful attempt). The **Test** action on `fpx` and `dnqr` stored `success` on 2026-10-29. No `FpxBankRedirect` revert is needed. Details: build-log 2026-10-30.
- **148** 2026-10-30: a custom domain brings a `www` redirect twin (ADR-060's second 2026-10-30 addendum, Accepted). "Also add www" on the add-domain form and an "Add www" / "Redirect www here" action; a twin row (`redirects_to_domain_id`) that 308-redirects to the typed domain and is never served, canonical, primary, CORS-allowed or payment-approved. **Merged as #613, released in #618 (2026-10-30).** Owed after the release (founder): press "Redirect www here" on OhahaStore's Domains screen, then `curl -I https://www.ohahastore.com` should answer 308 to `ohahastore.com`. Details: build-log 2026-10-30.
- **149** 2026-10-30: an owned brand prices as the platform: no tier, no brand markup, `affiliate_profit` always 0 (ADR-061's 2026-10-30 addendum, Accepted). One `Affiliate::storefrontPriceTerms()` seam now decides what every storefront path charges; the `max_markup_pct` ceiling is held at checkout too. **Merged as #612, released in #618 (2026-10-30).** Owed afterwards (founder): the RM0 OhahaStore tier subscription (leave or remove) and the "Owned Brand" tier's name once FixFast Demo is deactivated (item 141). Details: build-log 2026-10-30.
- **145** 2026-10-30: the affiliate monthly auto-payout (ADR-132), #605, released in #608. The platform opens one withdrawal ticket per affiliate per Malaysia month and the manual request is gone. On prod after the release: three affiliates, none at RM10 (balances 0, 15 sen, 0), no open ticket, `affiliate-payout-open` scheduled, `/api/health` ok. Follow-ups: items 147 and 149. Details: build-log 2026-10-30.
- **143** 2026-10-30: closed as not needed (founder). The partner portal footer keeps its hard-coded "LWF Group Sdn. Bhd. (202601038896)"; the company details do not change, and the footer also shows on the login page, where reading them from the admin setting would need a new public endpoint. Reopen only if the company name or SSM number changes.
- **142** 2026-10-30: the receipt layout and the LWF logo, built (ADR-131 2026-10-30 addendum, #603, released in #608). The other three parts (an admin download of a closed reseller's receipts, a stored snapshot of the company details, an automatic email) were closed as not needed (founder). Reopen each only at its trigger: an accountant's request or a change of company details, the first closed real reseller, a reseller asking for the email.
- **144** 2026-10-30: the failed-payment status page labels fixed (amount reads "Amount Due" until paid, one badge, the payment method as a label), in the same PR as the smaller FPX bank tiles and the removal of the "Other bank" link (ADR-130 2026-10-30 addendum). Details: build-log 2026-10-30. Released in #608.

- **140** 2026-10-29: a failed payment attempt is final (ADR-110 2026-10-29 addendum), #591 and #592, released in #599. Seen on prod the same evening: the founder cancelled one FPX and one DuitNow QR payment at the bank; both orders were Failed within 12 s and 20 s (no voucher, none left Pending) and the "Payment Not Completed / Try Again" page showed. The page's own copy has defects, item 144. Details: build-log 2026-10-29.

- **137** 2026-10-29: FPX bank-direct (ADR-130), #587 (checkout, `/joki`) and #588 (membership, partner-portal wallet top-up). The customer picks the bank on our page; one helper applies it where the checkout URL leaves the API; nothing is stored. Released 2026-10-29 in #589. Bank-down status split out as item 139; the real-bank proof is item 138. Details: build-log 2026-10-29.
- **134** 2026-10-29: the "BNM Compliant • 256-bit SSL" line removed from the shared payment picker (founder's decision), #588. Details: build-log 2026-10-29.
- **136** 2026-10-27: action feedback (toasts) across admin, middleware and the partner portal (ADR-129), #575–#580, released via #581. One PrimeReact headless toaster, one shared `components/ui/toast.tsx` (generated into `reseller/`), a `notify` facade, and the toast-vs-inline policy swept across every action-outcome screen; money-action failures are sticky. Details: build-log-archive 2026-10-27.
- **133** 2026-10-27: partner portal visual redesign, layer 1 (ADR-128) plus the shared component kit: #562–#565, #567, #568, reviewed page by page (Reseller + Affiliate, light/dark, desktop/phone; 12 admin pages), released via #571. Layer 2 stays as items 24 and 25 plus a new ADR. Details: build-log-archive 2026-10-27.
- **135** 2026-10-26: the Button severities `info`/`success`/`warn`/`danger`/`help` were mapped off Tailwind's stock colours onto the design system's `-surface`/`-ink` status pairs (soft fill + a border that intensifies on hover). The founder chose soft over solid when asked during shared-kit PR 2; the portal's `color-tokens.test.ts` (no `dark:` colour overrides) ruled out keeping the stock solid fills. ADR-128 2026-10-27 addendum; build-log-archive 2026-10-26.

- **132** 2026-10-25: IDR supplier COGS at a moving (perpetual) weighted average from 1 October 2026 (`MovingAveragePool`, `SupplierRowValuer`; `CashPositionService` reads the pool's month-end value). The two-month invariant was red first (October gap −33 sen). Prod October did not move (no deposit since September). ADR-083 2026-10-25 addendum; #551, released 2026-10-25 (#554).
- **131** 2026-10-25: financial truth from dated facts (ADR-127). Revenue, COGS and commission at `recognised_at`, closed months that cannot move (`AccountingPeriod::assertOpen`), CHIP `paid_on`/fee recorded, dated voucher restores and revokes, one store credit, profit by recognition on Reports and the Report Assistant, and `FinancialScenario` with 11 cross-page invariants. PRs A–E (#543–#546, #548, #549), released 2026-10-25 (#554).
- **96** 2026-10-25: OpenWA after the v0.25.0 upgrade. The `400` fix and the encrypted nightly OpenWA backup were live since #526 (2026-10-22); "Backup Now → OpenWA" ran 2026-10-22 and every nightly run passed its restore test. With the founder's go-ahead: the 10 plaintext database archives on R2, `~/OpenWA-v0.23.7` (with its `.env.bak-*` files) and `~/openwa-backups/` deleted; `MESSAGE_RETENTION_DAYS=90` set and OpenWA restarted (both sessions `ready`). Restore drill: the password from the password manager decrypted the 2026-10-25 database archive. Recheck the two `400` phrases on each OpenWA upgrade. Details: build-log-archive 2026-10-25.
- **71** 2026-10-25: GSC recheck (data to 16/10). The duplicate and canonical reasons ADR-120 targeted are gone. Left: 3 "Page with redirect" (`http://`, `www` → apex, as intended) and 42 "Discovered – currently not indexed", none crawled yet; robots, canonicals and the sitemap (50 URLs, read 25/10, Success) checked fine, so it is crawl priority on a new domain. Indexing requested for 5 top pages. `/order/call-of-duty-mobile` is `noindex` because the game is inactive (since 2026-10-18), which is correct. Details: build-log-archive 2026-10-25.
- **130** 2026-10-23: the cause was not health judging an unfunded supplier. A refused connection was thrown out of `checkBalance()`, which aborted the daily balance sweep (Digiflazz, after Gamevion, wasn't refreshed) and 500'd Refresh Balance, the credential probe and Developer Tools. The breaker decorator now returns it as a `CONNECTION_FAILED` response for `checkBalance` only (counted once; order calls still throw), and the daily refresh isolates each supplier, failing the task only on a real bug. ADR-069 2026-10-23 addendum; details: build-log-archive 2026-10-23. Released 2026-10-25 (#554). Prod hit the original failure once more that morning (a Gamevion SSL timeout at 00:00 UTC, before the release).
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

- **117 Storefront in more languages and currencies** (founder, 2026-10-17;
  parked 2026-11-01). The storefront is English-only and prices are MYR-only
  (CHIP is the one gateway; `PaymentGatewayFactory` stays for a second,
  regional one). Wanted when the business expands abroad, not soon. Needs its
  own ADR and grill: display vs charge currency, FX source, gateway per
  region, per-brand language, and how ledger and reports stay in MYR.

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
