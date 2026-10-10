# Architecture Decision Log — Game Top-Up Reseller Platform

Each decision is one file in [`docs/adr/`](./adr/), named `ADR-NNN-short-slug.md`. This file is the index only. An ADR records *why*; the living spec is [`prd.md`](./prd.md) and the security checklist is [`foundation-security.md`](./foundation-security.md).

## How to use and maintain this log

- **Is it still in force?** Read **Standing** in the index below, then the ADR file. The same Standing line sits at the top of each file.
- **Is it built or live?** Read [`prd.md`](./prd.md) §15. That is the only place build and live status is tracked. This index never says "built" or "live", so it can't contradict §15.
- **Never rewrite a past decision.** Each file is a dated record.
  - **Revising a recorded decision:** append a `### YYYY-MM-DD addendum — <what changed>` section at the end of that ADR's file. A later addendum overrides earlier text in the same file, including its original **Status** line.
  - **Filling an undocumented gap:** add a new ADR.
- **New ADR:**
  1. Take the next free number.
  2. Create `docs/adr/ADR-NNN-short-slug.md`, starting `# ADR-NNN: Title`.
  3. Add the Standing banner, then **Status**, Context, Decision, Rationale and Consequence to track.
  4. Add one row to the index, in number order.
- **When an ADR revises or retires another:** update the Standing of both, in this index and in each file's banner, in the same change.
- **Index rows stay short:** the title as the decision, Standing in a few words, and the date of the last addendum. Detail belongs in the ADR file.
- **Links between ADRs:** `[ADR-NNN](./ADR-NNN-slug.md)` from inside `docs/adr/`, `[ADR-NNN](./adr/ADR-NNN-slug.md)` from `docs/`. Code comments cite an ADR by number (`ADR-104`), not by path.
- **Old pointers:** text written before 2026-10-09 may say "`docs/adr.md`, ADR-NNN" or cite a `prd.md` §14 date entry. Find the ADR by number in the index below, and the dated entry in [`build-log.md`](./build-log.md) or [`build-log-archive.md`](./build-log-archive.md).

## Index

| ADR | Decision | Standing | Last addendum |
| --- | --- | --- | --- |
| [ADR-001](./adr/ADR-001-payment-gateway.md) | Payment gateway — Xendit | Superseded — CHIP-only (ADR-022 2026-09-01 addendum); xenPlatform dropped (ADR-059) | 2026-08-30 |
| [ADR-002](./adr/ADR-002-ledger-based-balance-not-a-mutable-column.md) | Ledger-based balance, not a mutable column | In force | 2026-09-29 |
| [ADR-003](./adr/ADR-003-tenant-aware-schema-platform-owner-only-mvp.md) | Tenant-aware schema, platform-owner-only MVP features | Partly superseded by ADR-057 (tenant scoping) |  |
| [ADR-004](./adr/ADR-004-no-cash-refunds-ever.md) | No cash refunds, ever | In force |  |
| [ADR-005](./adr/ADR-005-player-id-pre-payment-validation-is-not.md) | Player-ID pre-payment validation is not universal | In force | 2026-07-27 |
| [ADR-006](./adr/ADR-006-supplier-adapter-normalizer-layer-is-mvp-not.md) | Supplier Adapter/Normalizer layer is MVP, not Phase 2 | In force | 2026-07-25 |
| [ADR-007](./adr/ADR-007-internal-fraud-blacklist-is-first-class-mvp.md) | Internal fraud blacklist is first-class MVP | In force | 2026-07-29 |
| [ADR-008](./adr/ADR-008-split-frontend-stack.md) | Split frontend stack — Vue for Admin, Next.js for Storefront | Superseded by ADR-009 |  |
| [ADR-009](./adr/ADR-009-unify-frontend-stack.md) | Unify frontend stack — Next.js (React) for both Admin Panel and Storefront | In force | 2026-07-26 |
| [ADR-010](./adr/ADR-010-docker-for-mysql-only-not-full-laravel-sail.md) | Docker for MySQL only, not full Laravel Sail | In force |  |
| [ADR-011](./adr/ADR-011-storefront-stays-guest-checkout.md) | Storefront stays guest-checkout — no Customer account/auth in MVP | In force |  |
| [ADR-012](./adr/ADR-012-admin-panel-ui-built-on-the-free-tailadmin.md) | Admin Panel UI built on the free TailAdmin template as a component/layout reference | In force |  |
| [ADR-013](./adr/ADR-013-platform-owner-is-the-first-reseller-row-not-a.md) | Platform owner is the first Reseller row, not a parallel single-tenant concept | Superseded by ADR-061 |  |
| [ADR-014](./adr/ADR-014-24-7-burst-resilience-baseline.md) | 24/7 burst-resilience baseline — queue, retry, indexing, caching, rate-limiting, observability | In force; queue driver revised by ADR-048, cache driver by ADR-077 | 2026-09-29 |
| [ADR-015](./adr/ADR-015-price-sync.md) | Price Sync — cost propagation + deactivation detection | In force; decision 3 (manual-only reactivation) reversed by ADR-100 | 2026-07-26 |
| [ADR-016](./adr/ADR-016-price-sync-center.md) | Price Sync Center — admin UI design | In force | 2026-07-27 |
| [ADR-017](./adr/ADR-017-order-resend-delivery.md) | Order Resend Delivery — same-game package swap + live price reconciliation | In force; decisions 2/4 revised by ADR-105 (2026-10-06) | 2026-10-06 |
| [ADR-018](./adr/ADR-018-sandbox-test-orders.md) | Sandbox Test Orders — a middleware-only tool for exercising the real Order lifecycle without touching real data or real money | In force | 2026-07-30 |
| [ADR-019](./adr/ADR-019-second-pass-resilience-security-audit.md) | Second-pass resilience & security audit — reconciling ADR-014 against real code, docs restructure | In force; cache deferral closed by ADR-077 | 2026-09-29 |
| [ADR-020](./adr/ADR-020-production-host.md) | Production host — DigitalOcean Basic Droplet + Managed MySQL, Docker Compose, Cloudflare-fronted | Partly superseded by ADR-066 (Forge deploy, Vercel frontends) | 2026-09-05 |
| [ADR-021](./adr/ADR-021-next-session-priority-re-sequencing.md) | Next-session priority re-sequencing — payment reconciliation before deployment infra | In force | 2026-07-30 |
| [ADR-022](./adr/ADR-022-multi-gateway-payment-strategy.md) | Multi-gateway payment strategy — CHIP for Malaysia-local, Xendit retained for international + split-payment | In force as CHIP-only; Xendit decisions reversed by its own 2026-09-01 addendum | 2026-09-01 |
| [ADR-023](./adr/ADR-023-playwright-e2e-policy.md) | Playwright E2E policy — golden-path scope, growth triggers, and suite-hygiene rules | In force | 2026-08-21 |
| [ADR-024](./adr/ADR-024-voucher-at-checkout.md) | Voucher-at-Checkout — redemption timing, wallet model, and admin visibility | In force | 2026-10-04 |
| [ADR-025](./adr/ADR-025-supplier-price-sync-sanity-guard.md) | Supplier price-sync sanity guard — floor + swing checks on Gamevion's incoming price | In force | 2026-08-21 |
| [ADR-026](./adr/ADR-026-delivery-side-reconciliation-ord-10.md) | Delivery-side reconciliation (ORD-10) — `NeedsReview` state for ambiguous Gamevion order-creation failures | In force | 2026-09-16 |
| [ADR-027](./adr/ADR-027-vip-membership.md) | VIP Membership — Costco-style spend-quota subscription, email+OTP lightweight identity, member pricing | In force | 2026-09-22 |
| [ADR-028](./adr/ADR-028-platform-reseller-settings.md) | Platform & Reseller Settings — reseller-scoped branding vs. platform-wide config split | In force | 2026-10-02 |
| [ADR-029](./adr/ADR-029-seo-management.md) | SEO Management — reseller-scoped SEO settings, redirects, and per-game wiring | In force; decision 8 revised by ADR-120 | 2026-10-08 |
| [ADR-030](./adr/ADR-030-digiflazz-supplier-integration.md) | Digiflazz supplier integration — buyer-role adapter, prepaid games only | In force; decision 2 modified by ADR-067, rc table reopened by ADR-098/102 | 2026-09-03 |
| [ADR-031](./adr/ADR-031-multi-supplier-routing.md) | Multi-supplier routing — `SupplierAdapterFactory` + one-Package-one-supplier | In force |  |
| [ADR-032](./adr/ADR-032-async-supplier-delivery.md) | Async supplier delivery — `DeliveryStatus::Pending` + normalized `SupplierResponse` outcome + webhook & poll finalization | In force |  |
| [ADR-033](./adr/ADR-033-foreign-currency-supplier-pricing.md) | Foreign-currency supplier pricing — FX-rate conversion at sync time | In force | 2026-09-29 |
| [ADR-034](./adr/ADR-034-storefront-best-price-selection.md) | Storefront best-price selection — `packages.denomination` | In force |  |
| [ADR-035](./adr/ADR-035-voucher-path-a-double-submission-guard.md) | Voucher Path A double-submission guard — idempotency key, mirroring checkout | In force |  |
| [ADR-036](./adr/ADR-036-voucher-merge.md) | Voucher Merge — admin-triggered consolidation into a new code, no ledger write | In force |  |
| [ADR-037](./adr/ADR-037-staging-production-git-branch-model-staging.md) | Staging/production git branch model + staging environment infra | In force | 2026-09-02 |
| [ADR-038](./adr/ADR-038-admin-ui-component-library.md) | Admin UI component library — adopt PrimeReact (Tailwind mode), opportunistic migration off hand-rolled TailAdmin primitives | In force | 2026-08-26 |
| [ADR-039](./adr/ADR-039-database-backups.md) | Database backups — app-level, host-agnostic backup/restore mechanism (BAK-1..5) | In force | 2026-08-26 |
| [ADR-040](./adr/ADR-040-gamevion-sandbox-integration.md) | Gamevion sandbox integration — investigation saga, `telp` normalization, `callback_url` fix (2026-07-25 – 2026-08-14) | In force | 2026-08-13 |
| [ADR-041](./adr/ADR-041-checkout-level-idempotency-key.md) | Checkout-level idempotency key — client-generated key, unique DB constraint, replay/resume branching | In force | 2026-07-29 |
| [ADR-042](./adr/ADR-042-seo-module-scope-widened.md) | SEO module scope widened — structured data, scripts manager, crawler/robots management, sitemap & overview panels, per-game SEO UX | In force | 2026-10-08 |
| [ADR-043](./adr/ADR-043-.md) | `llms.txt` — auto-generated AI/LLM-context route | In force | 2026-08-22 |
| [ADR-044](./adr/ADR-044-zod-runtime-schema-validation-at-the-admin.md) | Zod runtime schema validation at the admin/storefront API boundary | In force |  |
| [ADR-045](./adr/ADR-045-admin-dashboard.md) | Admin Dashboard — DASH-1..6, `orders.delivered_at`, and a per-metric self-documenting `definition` convention | In force |  |
| [ADR-046](./adr/ADR-046-supplier-management-screen.md) | Supplier Management screen — trimmed to config/health (SUPP-1/CRUD/SUPP-5), `Supplier.api_config` becomes the live credential source, per-supplier/per-game bulk deactivation | In force | 2026-09-24 |
| [ADR-047](./adr/ADR-047-realtime-broadcasting.md) | Realtime broadcasting — Laravel Reverb + Echo, replacing application-level polling | In force | 2026-09-28 |
| [ADR-048](./adr/ADR-048-queue-driver-migration-to-redis-plus-horizon.md) | Queue driver migration to Redis, plus Horizon + Pulse — reverses ADR-014 decision #1 / ADR-020 decision #3's "queue stays on `database`" | In force | 2026-09-29 |
| [ADR-049](./adr/ADR-049-customer-analytics-anl-1-4.md) | Customer Analytics (ANL-1..4) — identity as a derived grouping, segmentation rules, and date-range scope | In force | 2026-09-18 |
| [ADR-050](./adr/ADR-050-customer-detail-drill-down-anl-5.md) | Customer Detail drill-down (ANL-5) — profit-panel scoping, kept consistent with the delivery-gated ledger rule | In force | 2026-09-18 |
| [ADR-051](./adr/ADR-051-middleware-request-logs-mid-10-11-mui-9.md) | Middleware Request Logs (MID-10/11, MUI-9) — HTTP-client-event hook, redact-then-queue write path, and split retention | In force | 2026-08-28 |
| [ADR-052](./adr/ADR-052-middleware-panel-closure-batch.md) | Middleware Panel closure batch — MUI-7 merged into Validators, MUI-1 as a thin `/middleware` landing page, MUI-4 dropped | In force |  |
| [ADR-053](./adr/ADR-053-reviews-rev-1-5.md) | Reviews (REV-1..5) — guest, order-linked submission via a real-time delivery-status trigger, admin-only moderation | In force; decision 6 (no public reviews) reversed by ADR-082 |  |
| [ADR-054](./adr/ADR-054-developer-raw-api-tester-dev-1-2-mui-11.md) | Developer raw API tester (DEV-1/2, MUI-11) — typed-DTO editor over the real adapter seam, backend-driven dry-run, production-mode block on `createOrder` | In force |  |
| [ADR-055](./adr/ADR-055-membership-upsell-promo-card.md) | Membership upsell promo card — storefront Order Summary, Tier 2 teaser | In force | 2026-09-18 |
| [ADR-056](./adr/ADR-056-reseller-wholesale-pricing-subscription-tiers.md) | Reseller wholesale pricing & subscription tiers — `reseller_membership_tiers`, cost-anchored wholesale rate, tier fee from earnings | In force |  |
| [ADR-057](./adr/ADR-057-tenant-isolation-mechanism.md) | Tenant isolation mechanism — `BelongsToReseller` trait + Eloquent global scope, retrofitted before any reseller-scoped endpoint | In force |  |
| [ADR-058](./adr/ADR-058-reseller-authentication-admin-reseller.md) | Reseller authentication + admin Reseller Management (RES-1..6) | In force | 2026-09-29 |
| [ADR-059](./adr/ADR-059-reseller-portal.md) | Reseller portal — `reseller/` app, earnings ledger, withdrawals, self-service storefront config | In force | 2026-09-26 |
| [ADR-060](./adr/ADR-060-multi-tenant-branded-storefront-custom-domain.md) | Multi-tenant branded storefront + custom-domain infrastructure (decision 3 reversed: Vercel-native custom domains, NOT Cloudflare for SaaS) | In force | 2026-10-02 |
| [ADR-061](./adr/ADR-061-every-storefront-is-a-reseller.md) | Every storefront is a Reseller — abolish the platform-owner special-case | In force; decision 7's platform withdrawal retired by ADR-083 (2026-10-10) | 2026-08-31 |
| [ADR-062](./adr/ADR-062-rebrand-the-primary-storefront-to-pekangame.md) | Rebrand the primary storefront to "PekanGame" | In force | 2026-09-01 |
| [ADR-063](./adr/ADR-063-storefront-visual-system-replacement.md) | Storefront visual system replacement — light neo-brutalist "Digital Architect" world | In force |  |
| [ADR-064](./adr/ADR-064-storefront-per-surface-redesign-component.md) | Storefront per-surface redesign + component rebuild | In force | 2026-09-19 |
| [ADR-065](./adr/ADR-065-guest-order-status-detail.md) | Guest order-status detail — masked contact + payment breakdown on a track-by-number view | In force |  |
| [ADR-066](./adr/ADR-066-production-deploy-via-laravel-forge.md) | Production deploy via Laravel Forge — reverses ADR-020's Docker Compose containerisation | In force |  |
| [ADR-067](./adr/ADR-067-digiflazz-adapter-completion.md) | Digiflazz adapter completion — buyer-area scoping, dual product status, per-supplier Product Manager grouping | In force | 2026-09-02 |
| [ADR-068](./adr/ADR-068-self-serve-membership-subscription-payment.md) | Self-serve membership subscription payment — customer pays for a tier through the CHIP checkout, membership auto-created on webhook (ADR-027 Phase 7, payment half) | In force | 2026-09-03 |
| [ADR-069](./adr/ADR-069-digiflazz-supplier-hardening.md) | Digiflazz supplier hardening — inbound webhook, credential-rotation surfacing, raw-price visibility, balance freshness | In force | 2026-09-03 |
| [ADR-070](./adr/ADR-070-reserved.md) | RESERVED — supplier-deposit / FX-history ledger → subsumed by ADR-083 | Reserved number — subsumed by ADR-083 |  |
| [ADR-071](./adr/ADR-071-storefront-perceived-performance.md) | Storefront perceived-performance — loading states, prefetchable routes, tag-based revalidation, and mobile buy-flow layout fixes | In force | 2026-09-24 |
| [ADR-072](./adr/ADR-072-reseller-system-split.md) | Reseller-system split — `Affiliate` (whitelabel) vs `Reseller` (prepaid wallet), full rename, shared portal architecture | In force | 2026-09-04 |
| [ADR-073](./adr/ADR-073-reseller-wallet.md) | Reseller wallet — fee-less tiers, prepaid-deposit ledger, order-placement contract, profit booking, delivery-failure handling | In force | 2026-10-04 |
| [ADR-074](./adr/ADR-074-reseller-api-channel.md) | Reseller API channel — per-tenant API keys, order-placement/status endpoints | In force | 2026-10-08 |
| [ADR-075](./adr/ADR-075-reseller-bot-channel.md) | Reseller Bot channel — OpenWA WhatsApp gateway, group-identity mapping, deploy topology | In force | 2026-10-02 |
| [ADR-076](./adr/ADR-076-reseller-bot-v2.md) | Reseller Bot v2 — two-stage order-completion messaging, `.trackorder`/`.checkid`/`.info`, reply styling | In force | 2026-09-06 |
| [ADR-077](./adr/ADR-077-storefront-read-path.md) | Storefront read-path — Redis cache cutover, eviction policy, invalidation fan-out, propagation freshness | In force |  |
| [ADR-078](./adr/ADR-078-custom-domain-storefront-gaps.md) | Custom-domain storefront gaps — dynamic CORS, edge-cache staleness, membership-toggle visibility | In force | 2026-09-08 |
| [ADR-079](./adr/ADR-079-storefront-conversion-polish.md) | Storefront Conversion & Polish — Real Product Artwork, Dynamic Payment Channels & Official SVG Logos, Denomination-vs-Pass Package Tabs, and Guest Checkout Convenience | In force | 2026-09-08 |
| [ADR-080](./adr/ADR-080-membership-per-brand.md) | Membership × per-brand — close the `/membership` surface consistently on a membership-disabled brand | In force |  |
| [ADR-081](./adr/ADR-081-affiliate-storefront-theme-presets.md) | Affiliate storefront theme presets — a curated fixed set, NOT the THM-1..4 custom theme system | In force |  |
| [ADR-082](./adr/ADR-082-public-facing-review-display.md) | Public-facing review display — homepage marquee + per-game reviews on the product page | In force |  |
| [ADR-083](./adr/ADR-083-internal-accounting-financial-reconciliation.md) | Internal Accounting & Financial Reconciliation — Supplier Funding Ledger, CHIP Settlement Reconciliation, and a Monthly Accounting Summary for an external SaaS | In force; 2026-10-10 addendum reshapes the Envelope Ledger (postings, director loans, month close) and retires the platform withdrawal | 2026-10-10 |
| [ADR-084](./adr/ADR-084-reseller-api.md) | Reseller API — developer documentation site, plus the surface hardening that must land first | In force | 2026-09-29 |
| [ADR-086](./adr/ADR-086-reports-restructure.md) | Reports restructure — dimensional rebuild-from-scratch audit + grouped-SQL rewrite | In force; PR-3 closure (no chart library) reversed by ADR-104 | 2026-09-21 |
| [ADR-087](./adr/ADR-087-admin-reports-llm-assistant.md) | Admin Reports LLM Assistant — Gemini-backed, curated read-only SQL views, additive to the Reports tabs | In force | 2026-10-08 |
| [ADR-088](./adr/ADR-088-reports.md) | Reports — unified date-range filter (reverses RPT-2's decoupled-trend rule) + export widening | In force |  |
| [ADR-089](./adr/ADR-089-brand-asset-pipeline.md) | Brand asset pipeline — aspect-preserving logo sizing, a primary-brand admin Logo/Favicon panel, per-brand dynamic favicon | In force |  |
| [ADR-090](./adr/ADR-090-theme-preset-background-ink-tokens-a-per.md) | Theme preset background/ink tokens + a per-affiliate Site Mode (light/dark), the first slice of ADR-081's pinned dark-mode requirement | In force |  |
| [ADR-091](./adr/ADR-091-public-reseller-price-list-page.md) | Public Reseller Price List page — a sales/acquisition surface on `is_owned` storefronts, admin-selected tiers | In force | 2026-09-13 |
| [ADR-092](./adr/ADR-092-admin-orders-kpi-cards.md) | Admin Orders KPI cards — persistent, clickable status counts, closing the reseller-family audit's "no admin alert" gap without reopening ADR-073's manual-refund policy | In force |  |
| [ADR-093](./adr/ADR-093-reseller-bot-fat-finger-safety-net.md) | Reseller Bot `.order` fat-finger safety net — auto player-ID/region validation before placing, player ID echoed in the confirmation reply, no mandatory 2-step confirm | In force |  |
| [ADR-094](./adr/ADR-094-combo-package.md) | Combo Package — assembling several existing catalog Packages into one opaque, sellable SKU above a game's native max denomination | In force | 2026-10-04 |
| [ADR-095](./adr/ADR-095-cloudflare-r2-storage-cutover.md) | Cloudflare R2 storage cutover — gallery images + DB backups, WebP-at-upload, delete referential safety | In force | 2026-09-14 |
| [ADR-096](./adr/ADR-096-admin-check-from-supplier-check-from-gateway.md) | Admin "Check from Supplier" / "Check from Gateway" manual poll — a deliberate, scoped ADR-014 exception, shared logic with the existing reconcile jobs, cache-based cooldown | In force |  |
| [ADR-097](./adr/ADR-097-per-game-checkout-input.md) | Per-game checkout input — Digiflazz `customer_no` separator moves from per-supplier to per-game, and a Zone ID picklist replaces free text, with matching validation added to every channel | In force | 2026-10-05 |
| [ADR-098](./adr/ADR-098-digiflazz-code-terminal-vs-retriable.md) | Digiflazz `rc`-code terminal-vs-retriable classification — `SupplierResponse::$transactionAlreadyFormed`, generic across suppliers | In force; decision 6 superseded by ADR-102 |  |
| [ADR-099](./adr/ADR-099-backup-dump.md) | Backup dump `useSingleTransaction` — stop the daily backup from locking the whole live schema | In force |  |
| [ADR-100](./adr/ADR-100-pending-reactivation-auto-approve.md) | Pending Reactivation auto-approve — reverses ADR-015 decision #3's manual-only gate | In force | 2026-09-24 |
| [ADR-101](./adr/ADR-101-security-hardening.md) | Security hardening — external pentest response (SPF/DMARC, CSP, OTP throttle, admin script-injection reversal) | In force | 2026-09-16 |
| [ADR-102](./adr/ADR-102-order-delivery-failure-guard-unification.md) | Order delivery-failure guard unification + Digiflazz `rc`-classification correction + admin refund-clarity UI | In force; decision 12 reversed by ADR-108 | 2026-10-05 |
| [ADR-103](./adr/ADR-103-combo-order-per-leg-independence.md) | Combo order per-leg `reference_number` independence | In force | 2026-09-17 |
| [ADR-104](./adr/ADR-104-admin-panel-visual-redesign.md) | Admin panel visual redesign — retire TailAdmin token layer, adopt "PekanGame Admin" design system | In force | 2026-10-09 |
| [ADR-105](./adr/ADR-105-order-resend-profit-recompute-correctness.md) | Order resend profit-recompute correctness — reconcile against the order's own frozen snapshot, not the target package's live pricing | In force | 2026-10-06 |
| [ADR-106](./adr/ADR-106-durable-delivery-attempt-audit-record.md) | Durable delivery-attempt audit record — every fulfillment attempt gets its own immutable row, not just resends | In force | 2026-09-21 |
| [ADR-107](./adr/ADR-107-combo-order-profit-reconciliation-on-retry.md) | Combo order profit reconciliation on retry + partial-delivery voucher accuracy | In force | 2026-09-17 |
| [ADR-108](./adr/ADR-108-orders-list-correctness-need-action-compensation.md) | Orders list correctness (Need Action + compensation-tag decluttering) + toolbar overhaul (Source/Game/date-range/Columns/Export) | In force | 2026-10-09 |
| [ADR-109](./adr/ADR-109-game-info-modal-card.md) | Game info modal card — customer-facing description/important-notes popup + admin-editable per-game instant-delivery badge | In force | 2026-10-08 |
| [ADR-110](./adr/ADR-110-chip-integration-hardening.md) | CHIP Integration Hardening — Purchase Status Mapping & Expiry, Settlement Reconciliation (fills ADR-083 PR-2), and Credential Migration | In force | 2026-10-04 |
| [ADR-111](./adr/ADR-111-real-cost-profit-reconciliation-at-delivery-time.md) | Real-cost profit reconciliation at delivery time — widens ADR-033's FX conversion boundary to a second call site | In force |  |
| [ADR-112](./adr/ADR-112-affiliate-reseller-portal-mobile-first-redesign.md) | Affiliate/Reseller portal mobile-first redesign — role-aware shell, dashboards, and Orders | In force |  |
| [ADR-113](./adr/ADR-113-affiliate-theme-preset-dark-mode-for-all-4.md) | Affiliate theme-preset dark mode for all 4 affiliate-selectable presets, `storefront/DESIGN.md`, Digital Architect retired from the affiliate picker | In force |  |
| [ADR-114](./adr/ADR-114-production-infra-split-off-digitalocean-s-shared.md) | Production infra split off DigitalOcean's shared team — droplet + managed MySQL rebuilt into a new DO team (`LWF Group Sdn Bhd`) | In force | 2026-10-08 |
| [ADR-115](./adr/ADR-115-supplier-balance-comfortable-buffer-forecast.md) | Supplier balance comfortable-buffer forecast — learned, per-supplier top-up threshold | Parked (designed, build deferred by founder) |  |
| [ADR-116](./adr/ADR-116-customer-order-notifications-over-whatsapp.md) | Customer order notifications over WhatsApp (OpenWA), not email — closes audit M-11 | In force | 2026-09-30 |
| [ADR-117](./adr/ADR-117-prod-db-least-privilege.md) | Prod DB least-privilege — `doadmin` superuser replaced by a dedicated scoped app user | In force |  |
| [ADR-118](./adr/ADR-118-marketing-campaigns.md) | Marketing Campaigns — multi-use KOL discount codes, separate from `Voucher` | Accepted, design only (not built) | 2026-10-02 |
| [ADR-119](./adr/ADR-119-package-delete-guards.md) | Package delete guards — block a hard-delete that already has order history or is a live combo component | In force |  |
| [ADR-120](./adr/ADR-120-seo-geo-overhaul.md) | SEO/GEO overhaul — brand-correct meta, per-brand canonical origin, machine-readable catalog | In force |  |
