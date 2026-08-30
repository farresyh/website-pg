<?php

use App\Http\Controllers\Admin\AdminUserController;
use App\Http\Controllers\Admin\BlacklistController;
use App\Http\Controllers\Admin\CrawlerRuleController;
use App\Http\Controllers\Admin\CustomerAnalyticsController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\GalleryImageController;
use App\Http\Controllers\Admin\GameSeoController;
use App\Http\Controllers\Admin\HeroSlideController as AdminHeroSlideController;
use App\Http\Controllers\Admin\MembershipController as AdminMembershipController;
use App\Http\Controllers\Admin\MembershipPlanController;
use App\Http\Controllers\Admin\OrderController;
use App\Http\Controllers\Admin\RedirectController;
use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\Admin\ReviewController as AdminReviewController;
use App\Http\Controllers\Admin\SeoController as AdminSeoController;
use App\Http\Controllers\Admin\SeoScriptController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\VoucherController;
use App\Http\Controllers\Admin\WithdrawalController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\BrandingController;
use App\Http\Controllers\CatalogController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\ClientErrorController;
use App\Http\Controllers\GameController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\HeroSlideController;
use App\Http\Controllers\MembershipController;
use App\Http\Controllers\MembershipOtpController;
use App\Http\Controllers\Middleware\BackupController;
use App\Http\Controllers\Middleware\CurrencyRateController;
use App\Http\Controllers\Middleware\DismissedPackageController;
use App\Http\Controllers\Middleware\OpsAccessController;
use App\Http\Controllers\Middleware\PaymentMethodController;
use App\Http\Controllers\Middleware\PendingPriceChangeController;
use App\Http\Controllers\Middleware\PendingReactivationController;
use App\Http\Controllers\Middleware\PlayerRegionMappingController;
use App\Http\Controllers\Middleware\DeveloperToolController;
use App\Http\Controllers\Middleware\PlayerValidatorProfileController;
use App\Http\Controllers\Middleware\PriceSyncController;
use App\Http\Controllers\Middleware\RequestLogController;
use App\Http\Controllers\Middleware\SandboxOrderController;
use App\Http\Controllers\Middleware\SupplierController;
use App\Http\Controllers\Middleware\SupplierProductController;
use App\Http\Controllers\PackageController;
use App\Http\Controllers\PaymentMethodCatalogController;
use App\Http\Controllers\PlayerValidationController;
use App\Http\Controllers\ReviewController;
use App\Http\Controllers\SeoController;
use App\Http\Controllers\TrackOrderController;
use App\Http\Controllers\VoucherPreviewController;
use App\Http\Controllers\Webhooks\ChipWebhookController;
use App\Http\Controllers\Webhooks\XenditWebhookController;
use Illuminate\Support\Facades\Route;

// ADR-019: the only mutating auth-adjacent route with no throttle,
// unlike /checkout and /validate-player below. Tighter than either
// (5/minute/IP, not 10) — this is a brute-force/credential-stuffing
// target, not a genuine-retry-tolerant customer action.
//
// Explicit `login` prefix — found live, 2026-08-27: ThrottleRequests'
// default key is sha1($route->getDomain().'|'.$request->ip()), which
// never includes the route path/URI at all. Every throttle:N,1 route
// below with no prefix of its own shares that exact same bucket per
// IP, regardless of each route's own configured maxAttempts — a guest
// hitting /checkout, /client-errors, /validate-player etc. from the
// same IP silently eats into /login's 5/minute budget (and vice
// versa). Surfaced by the storefront-checkout E2E spec's own admin
// API login getting a genuine 429 after only 1 real login attempt,
// once the earlier playwright webServer-boot bug (see
// e2e/scripts/boot-backend.sh) stopped masking it. Every throttle:
// route in this file now gets its own prefix for the same reason,
// not just this one.
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1,login');

// ADR-014: unauthenticated infra probe, DB + queue connection only —
// no order/customer data ever touches this endpoint.
Route::get('/health', [HealthController::class, 'check']);

// ADR-044 decision 8 — public (both admin's Bearer-token and
// storefront's guest context report here), throttled log sink for a
// zod response-schema mismatch. Not a general error-monitoring
// endpoint — see ClientErrorController's own doc comment.
Route::post('/client-errors', [ClientErrorController::class, 'store'])->middleware('throttle:30,1,client-errors');

// Guest checkout (ADR-011) — no Customer auth exists, deliberately not
// behind auth:sanctum. Money fields are still never client-trusted
// (ORD-9): CheckoutController resolves everything from stored
// Game/Package config, never from this request's own body.
// ADR-014: throttle:10,1 — 10/minute/IP, loose enough for a genuine
// customer retrying a failed attempt, tight enough to blunt a flood.
Route::post('/checkout', [CheckoutController::class, 'store'])->middleware('throttle:10,1,checkout');

// ADR-024 decision #1's "Apply" button — read-only preview, never
// locks or spends a voucher's remaining balance (VoucherService::
// redeem() only ever runs inside the real /checkout call above). Same
// throttle as checkout: a bearer-code-guessing probe is exactly the
// abuse this rate limit exists to blunt, and the ownership-lock check
// inside VoucherService::preview() already keeps a wrong guess from
// revealing anything either way.
Route::post('/vouchers/preview', [VoucherPreviewController::class, 'store'])->middleware('throttle:10,1,voucher-preview');

// Bug fix, 2026-08-30: read-only Package Price/Transaction Fee/Voucher
// Discount/Total breakdown, fetched by the storefront's Order Summary
// sidebar and Review Modal on every package/channel/voucher change —
// see CheckoutController::previewTotal()'s own doc comment. Looser
// than checkout/voucher-preview's 10/min: this is normal browsing
// telemetry (no gateway call, no guessable secret, unlike a voucher
// code), debounced client-side, but still worth a limit since it's a
// public unauthenticated endpoint doing real DB work.
Route::post('/checkout/preview-totals', [CheckoutController::class, 'previewTotal'])->middleware('throttle:30,1,checkout-preview-totals');

// Public "Validate Player ID" lookup (ADR-011, same no-auth reasoning
// as checkout above) — backend half of the Player-ID Validation
// follow-up, docs/prd.md §14. Same throttle as checkout: this hits
// unofficial third-party provider APIs (ADR-005 addendum), tighter
// abuse-blunting matters more here than for a normal read endpoint.
Route::post('/games/{game}/validate-player', [PlayerValidationController::class, 'store'])->middleware('throttle:10,1,validate-player');

// Public "Track Order" lookup (ADR-011) — order_number (a ULID) is
// high-entropy enough to be treated as proof of ownership on its own,
// same trust model as a courier tracking number. Read-only, but still
// throttled — a bit looser than checkout/validate since it's not
// hitting a third-party API, just blunting scraping/enumeration.
Route::get('/track-order/{orderNumber}', [TrackOrderController::class, 'show'])->middleware('throttle:20,1,track-order');

// ADR-053 (REV-1..5) — public guest review submission, same
// order_number-as-proof-of-ownership trust model as track-order above.
// reviews.order_id's own unique index is the real one-per-order
// guarantee; this throttle only blunts a flood, same convention as
// checkout/validate-player.
Route::post('/orders/{orderNumber}/review', [ReviewController::class, 'store'])->middleware('throttle:10,1,review');

// ADR-027's 2026-08-29 addendum, decisions 23/26/27 — membership
// identity verification (email + OTP, no login/account). `send` uses
// the named `otp-request` limiter (registered in AppServiceProvider,
// 3/hour keyed by email — not IP, unlike every other throttle: route
// in this file, since the abuse case is flooding one target inbox).
// `verify` gets a plain IP throttle same shape as checkout/validate
// -player; OtpService's own 5-attempt lockout is the real brute-force
// defense for a submitted code.
Route::post('/membership/otp/send', [MembershipOtpController::class, 'send'])->middleware('throttle:otp-request');
Route::post('/membership/otp/verify', [MembershipOtpController::class, 'verify'])->middleware('throttle:10,1,membership-verify');
// ADR-055 decision 3: the upsell card's tier data — public (no session
// token), returns [] when the kill switch is off. Deliberately separate
// from the admin-only membership-plans prefix (same controller family,
// different gate — this route is on the public MembershipController).
Route::get('/membership/plans', [MembershipController::class, 'plans']);

// Decisions 13/24/25 — the /membership dashboard's data. Auth is the
// session token (Authorization: Bearer), not auth:sanctum — resolved
// inside the controller itself, same reasoning as the OTP routes above.
Route::get('/membership/me', [MembershipController::class, 'me']);

// Public game/package catalog (ADR-011) — the storefront's real data
// source, replacing storefront/src/lib/placeholder-data.ts (docs/prd.md
// §14/§15 NEXT SESSION pointer). A distinct `catalog/` prefix, not
// `/games`: that path is already GameController's admin-only,
// numeric-{id}-bound resource — same method+path can't serve two
// different auth rules, and public lookup is by slug, not id. No
// throttle: unlike checkout/validate-player this hits no third-party
// API and isn't money-moving, just a normal public read listing.
Route::prefix('catalog')->group(function () {
    Route::get('/games', [CatalogController::class, 'index']);
    Route::get('/games/{slug}', [CatalogController::class, 'show']);
    Route::get('/games/{slug}/packages', [CatalogController::class, 'packages']);

    // Hero Banner (docs/prd.md §14/§15 backlog) — no secret-field
    // concern here (no cost/margin data on this model), grouped under
    // the same public "storefront content" prefix as games/packages.
    Route::get('/hero-slides', [HeroSlideController::class, 'index']);

    // ADR-022's newest addendum, decision 5 — gateway-agnostic active
    // channel listing, replacing the storefront's hardcoded
    // PLACEHOLDER_PAYMENT_CHANNELS.
    Route::get('/payment-methods', [PaymentMethodCatalogController::class, 'index']);

    // ADR-028 + its 2026-08-22 addendum — branding/footer/legal
    // content, replacing SiteFooter.tsx's hardcoded FOOTER_COLUMNS/
    // SOCIAL_LINKS and the three previously-nonexistent legal pages.
    Route::get('/branding', [BrandingController::class, 'show']);
    Route::get('/legal/{page}', [BrandingController::class, 'legal']);

    // ADR-029 — public SEO data: settings/templates/pixel IDs for
    // generateMetadata(), redirects for middleware.ts's in-memory
    // cache, scripts for layout injection, crawler rules for
    // app/robots.ts.
    Route::get('/seo/settings', [SeoController::class, 'settings']);
    Route::get('/seo/redirects', [SeoController::class, 'redirects']);
    Route::post('/seo/redirects/record-hit', [SeoController::class, 'recordRedirectHit'])->middleware('throttle:60,1,redirect-hit');
    Route::get('/seo/scripts', [SeoController::class, 'scripts']);
    Route::get('/seo/robots', [SeoController::class, 'robots']);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);

    // AUTH-4 — Super Admin only, per PRD §3 Users & Roles.
    Route::middleware('admin.role:super_admin')->prefix('admin-users')->group(function () {
        Route::get('/', [AdminUserController::class, 'index']);
        Route::post('/', [AdminUserController::class, 'store']);
        Route::put('/{admin_user}', [AdminUserController::class, 'update']);
        Route::patch('/{admin_user}/status', [AdminUserController::class, 'updateStatus']);
    });

    // WTH-1..5 — Admin and Super Admin both operate this; the
    // maker-checker threshold check (WTH-5) is enforced inside
    // WithdrawalController::approve(), not at the route level, since
    // it depends on each withdrawal's own amount.
    Route::middleware('admin.role:super_admin,admin')->prefix('withdrawals')->group(function () {
        Route::get('/', [WithdrawalController::class, 'index']);
        Route::post('/', [WithdrawalController::class, 'store']);
        Route::patch('/{withdrawal}/approve', [WithdrawalController::class, 'approve']);
        Route::patch('/{withdrawal}/reject', [WithdrawalController::class, 'reject']);
        Route::patch('/{withdrawal}/complete', [WithdrawalController::class, 'complete']);
    });

    // REV-1..5 (ADR-053) — PRD §3: "Admin ... manages orders, reports,
    // reviews, withdrawals (below threshold), vouchers (below
    // threshold)", so both roles, same tier as Orders/Vouchers.
    Route::middleware('admin.role:super_admin,admin')->prefix('reviews')->group(function () {
        Route::get('/', [AdminReviewController::class, 'index']);
        Route::post('/bulk-approve', [AdminReviewController::class, 'bulkApprove']);
        Route::patch('/{review}/approve', [AdminReviewController::class, 'approve']);
        Route::patch('/{review}/reject', [AdminReviewController::class, 'reject']);
    });

    // VCH-1..6 — Path A (store) is threshold-gated inside the
    // controller (VCH-6); Path B (storeFromOrder) never is, per the
    // founder's decision (docs/prd.md §14) — its amount is bounded by
    // what the customer actually paid, not an open admin choice.
    Route::middleware('admin.role:super_admin,admin')->group(function () {
        Route::prefix('vouchers')->group(function () {
            Route::get('/', [VoucherController::class, 'index']);
            // ADR-036 — admin-triggered voucher consolidation.
            Route::post('/merge', [VoucherController::class, 'merge']);
            Route::get('/{voucher}', [VoucherController::class, 'show']);
            Route::post('/', [VoucherController::class, 'store']);
            Route::patch('/{voucher}/revoke', [VoucherController::class, 'revoke']);
        });
        Route::post('/orders/{order}/voucher', [VoucherController::class, 'storeFromOrder']);
    });

    // ADR-007 / FRAUD-1..3 - internal blacklist, independent of any
    // supplier-provided one. "Remove" is deactivate(), never a hard
    // delete (see the migration's own doc comment for why).
    // Super Admin only, per PRD §3 Users & Roles ("Admin ... Cannot
    // modify ... blacklist rules") — fixed 2026-08-14, this route group
    // previously also let a regular Admin through.
    Route::middleware('admin.role:super_admin')->prefix('blacklist')->group(function () {
        Route::get('/', [BlacklistController::class, 'index']);
        Route::post('/', [BlacklistController::class, 'store']);
        Route::get('/{blacklist_entry}', [BlacklistController::class, 'show']);
        Route::patch('/{blacklist_entry}/deactivate', [BlacklistController::class, 'deactivate']);
    });

    // ORD-1..7 — list + detail, plus retryDelivery (ORD-7's resolve
    // action, ADR-014). Makes a real order's outcome visible in the
    // Admin Panel and gives an operator a way to act on a failure.
    // RPT-1..3 — see ReportService's doc comment for the grilled/pinned
    // sales & profit definitions. Same role tier as Orders/Withdrawals:
    // both already expose profit fields, Reports is read-only on top.
    // DASH-1..6 (ADR-045) — same read-only-overview role tier as
    // Reports, no reason to restrict further.
    Route::middleware('admin.role:super_admin,admin')->prefix('dashboard')->group(function () {
        Route::get('/summary', [DashboardController::class, 'summary']);
        Route::get('/health', [DashboardController::class, 'health']);
        Route::get('/funnel', [DashboardController::class, 'funnel']);
        Route::get('/top-games', [DashboardController::class, 'topGames']);
        Route::get('/hourly-activity', [DashboardController::class, 'hourlyActivity']);
    });

    Route::middleware('admin.role:super_admin,admin')->prefix('reports')->group(function () {
        Route::get('/resellers', [ReportController::class, 'resellers']);
        Route::get('/summary', [ReportController::class, 'summary']);
        Route::get('/trend', [ReportController::class, 'trend']);
        Route::get('/daily-breakdown', [ReportController::class, 'dailyBreakdown']);
        Route::get('/top-games', [ReportController::class, 'topGames']);
        Route::get('/breakdown/games', [ReportController::class, 'gameBreakdown']);
        Route::get('/breakdown/payment-methods', [ReportController::class, 'paymentMethodBreakdown']);
        Route::get('/breakdown/resellers', [ReportController::class, 'resellerBreakdown']);
        Route::get('/order-status-funnel', [ReportController::class, 'orderStatusFunnel']);
        Route::get('/membership-breakdown', [ReportController::class, 'membershipBreakdown']);
        Route::get('/export', [ReportController::class, 'export']);
    });

    Route::middleware('admin.role:super_admin,admin')->prefix('customer-analytics')->group(function () {
        Route::get('/summary', [CustomerAnalyticsController::class, 'summary']);
        Route::get('/customers', [CustomerAnalyticsController::class, 'customers']);
        Route::get('/customers/{email}', [CustomerAnalyticsController::class, 'show']);
        Route::get('/export', [CustomerAnalyticsController::class, 'export']);
    });

    Route::middleware('admin.role:super_admin,admin')->prefix('orders')->group(function () {
        Route::get('/', [OrderController::class, 'index']);
        Route::get('/{order}', [OrderController::class, 'show']);
        Route::post('/{order}/retry-delivery', [OrderController::class, 'retryDelivery']);
        Route::post('/{order}/resend', [OrderController::class, 'resend']);
        // ADR-026 decision 4a — the one needs_review exit that isn't a retry.
        Route::post('/{order}/mark-delivered', [OrderController::class, 'markDelivered']);
    });

    // ADR-018: a middleware-only sandbox for exercising the real Order
    // lifecycle (status transitions, resend, Delivery Logs) without
    // touching real data or real money — fully separate from
    // /orders above, every query unconditionally scoped to is_test.
    // Super Admin only — this whole /middleware/* area is per PRD §3
    // (see admin/src/app/middleware/layout.tsx's own doc comment);
    // fixed 2026-08-14, previously also let a regular Admin through.
    Route::middleware('admin.role:super_admin')->prefix('middleware/sandbox')->group(function () {
        Route::get('/', [SandboxOrderController::class, 'index']);
        Route::post('/', [SandboxOrderController::class, 'store']);
        Route::get('/{order}', [SandboxOrderController::class, 'show']);
        Route::post('/{order}/resend', [SandboxOrderController::class, 'resend']);
        // ADR-026 decision 4a's sandbox counterpart.
        Route::post('/{order}/mark-delivered', [SandboxOrderController::class, 'markDelivered']);
        Route::delete('/{order}', [SandboxOrderController::class, 'destroy']);
        Route::delete('/', [SandboxOrderController::class, 'destroyAll']);
    });

    // ADR-046 — Supplier Management: SUPP-1/CRUD/SUPP-5 only (SUPP-2/3/4
    // deliberately not built here, already covered by Product Manager
    // below — see ADR-046's Context). Super Admin only — supplier
    // config, per PRD §3.
    Route::middleware('admin.role:super_admin')->prefix('middleware/suppliers')->group(function () {
        Route::get('/', [SupplierController::class, 'index']);
        Route::get('/available-slugs', [SupplierController::class, 'availableSlugs']);
        Route::post('/', [SupplierController::class, 'store']);
        Route::put('/{supplier}', [SupplierController::class, 'update']);
        Route::patch('/{supplier}/status', [SupplierController::class, 'updateStatus']);
        Route::delete('/{supplier}', [SupplierController::class, 'destroy']);
        Route::post('/{supplier}/refresh-balance', [SupplierController::class, 'refreshBalance']);
        Route::patch('/{supplier}/packages/status', [SupplierController::class, 'updatePackagesStatus']);
    });

    // ADR-051 (MUI-9) — read-only Request Logs viewer. Super Admin
    // only, same boundary as every other supplier-facing screen here.
    Route::middleware('admin.role:super_admin')->prefix('middleware/request-logs')->group(function () {
        Route::get('/', [RequestLogController::class, 'index']);
        Route::get('/{request_log}', [RequestLogController::class, 'show']);
    });

    // ADR-054 (DEV-1/2, MUI-11) — Developer raw API tester. Super
    // Admin only, same boundary as every other supplier-facing screen
    // here; also the one screen in this area whose real (non-dry-run)
    // calls can reach a live supplier API on demand.
    Route::middleware('admin.role:super_admin')->prefix('middleware/developer-tools')->group(function () {
        Route::post('/test', [DeveloperToolController::class, 'test']);
    });

    // MID-1..6/SUPP-3 — Price Sync Stage 2: browse the raw Gamevion
    // mirror (supplier_products), link a category group to a Game
    // once, then promote individual rows into real Packages.
    // Super Admin only — supplier config, per PRD §3; fixed 2026-08-14.
    Route::middleware('admin.role:super_admin')->prefix('middleware/supplier-products')->group(function () {
        Route::get('/categories', [SupplierProductController::class, 'categories']);
        Route::post('/categories/link', [SupplierProductController::class, 'linkCategory']);
        Route::get('/', [SupplierProductController::class, 'index']);
        Route::post('/{supplier_product}/promote', [SupplierProductController::class, 'promote']);
    });

    // ADR-015/ADR-016 — Price Sync: manual trigger + status polling,
    // the Pending Reactivation queue (SYNC-5/6), and the full Price
    // Sync Center (stat cards, Sync History + Sync Details modal,
    // Manually Dismissed Packages).
    // Super Admin only — supplier config, per PRD §3; fixed 2026-08-14.
    Route::middleware('admin.role:super_admin')->prefix('middleware/price-sync')->group(function () {
        Route::post('/', [PriceSyncController::class, 'store']);
        Route::get('/stats', [PriceSyncController::class, 'stats']);
        Route::get('/runs', [PriceSyncController::class, 'index']);
        Route::get('/runs/{price_sync_run}', [PriceSyncController::class, 'show']);
        Route::get('/runs/{price_sync_run}/details', [PriceSyncController::class, 'details']);

        Route::get('/pending-reactivations', [PendingReactivationController::class, 'index']);
        Route::patch('/pending-reactivations/{package}/approve', [PendingReactivationController::class, 'approve']);
        Route::patch('/pending-reactivations/{package}/dismiss', [PendingReactivationController::class, 'dismiss']);
        Route::post('/pending-reactivations/bulk-approve', [PendingReactivationController::class, 'bulkApprove']);
        Route::post('/pending-reactivations/bulk-dismiss', [PendingReactivationController::class, 'bulkDismiss']);

        Route::get('/dismissed-packages', [DismissedPackageController::class, 'index']);
        Route::patch('/dismissed-packages/{package}/restore', [DismissedPackageController::class, 'restore']);

        // ADR-025 decision #8
        Route::get('/pending-price-changes', [PendingPriceChangeController::class, 'index']);
        Route::patch('/pending-price-changes/{pending_price_change}/approve', [PendingPriceChangeController::class, 'approve']);
        Route::patch('/pending-price-changes/{pending_price_change}/dismiss', [PendingPriceChangeController::class, 'dismiss']);

        // ADR-033 addendum decision 1 — FX Rate History section.
        Route::get('/fx-rates', [CurrencyRateController::class, 'index']);
    });

    // ADR-028 + its 2026-08-22 addendum — Store Branding / Footer
    // Settings / Platform Settings, the three Settings-screen tabs.
    // Super Admin only, same tier as Price Sync/Payment Methods (both
    // are supplier/gateway/platform-wide config, per PRD §3).
    Route::middleware('admin.role:super_admin')->prefix('settings')->group(function () {
        Route::get('/', [SettingsController::class, 'index']);
        Route::put('/branding', [SettingsController::class, 'updateBranding']);
        Route::put('/footer', [SettingsController::class, 'updateFooter']);
        Route::put('/platform', [SettingsController::class, 'updatePlatform']);
        Route::post('/platform/bulk-markup', [SettingsController::class, 'bulkMarkup']);
    });

    // ADR-027's 2026-08-29 addendum, decisions 14/15: /admin/membership's
    // backend — edit-only against the two fixed membership_plans rows,
    // same super_admin tier as Settings/Price Sync (deliberately not
    // under /middleware — no supplier-integration dependency).
    Route::middleware('admin.role:super_admin')->prefix('membership-plans')->group(function () {
        Route::get('/', [MembershipPlanController::class, 'index']);
        Route::get('/preview', [MembershipPlanController::class, 'preview']);
        Route::put('/{membershipPlan}', [MembershipPlanController::class, 'update']);
        Route::patch('/enabled', [MembershipPlanController::class, 'updateEnabled']);
    });

    // ADR-027 continued addendum decision 15 / Phase 6.5 (grilled
    // 2026-08-29): the member registry + fee collection half of
    // /admin/membership. Same super_admin tier as membership-plans.
    Route::middleware('admin.role:super_admin')->prefix('memberships')->group(function () {
        Route::get('/', [AdminMembershipController::class, 'index']);
        Route::post('/record-payment', [AdminMembershipController::class, 'recordPayment']);
    });

    // ADR-029 — SEO Management: Overview, Global Settings/Meta
    // Templates (one table, decision 2), Game SEO (decision 11),
    // Redirects (decision 3/9), Scripts (addendum 2 decision 13),
    // Crawler (addendum 2 decision 14). Same tier as Settings above —
    // decision 6, no reseller self-service portal yet.
    Route::middleware('admin.role:super_admin')->prefix('seo')->group(function () {
        Route::get('/overview', [AdminSeoController::class, 'overview']);
        Route::get('/settings', [AdminSeoController::class, 'settings']);
        Route::put('/settings', [AdminSeoController::class, 'updateSettings']);

        Route::get('/games', [GameSeoController::class, 'index']);
        Route::get('/games/{game}', [GameSeoController::class, 'show']);
        Route::put('/games/{game}', [GameSeoController::class, 'update']);

        Route::get('/redirects', [RedirectController::class, 'index']);
        Route::post('/redirects', [RedirectController::class, 'store']);
        Route::put('/redirects/{redirect}', [RedirectController::class, 'update']);
        Route::delete('/redirects/{redirect}', [RedirectController::class, 'destroy']);

        Route::get('/scripts', [SeoScriptController::class, 'index']);
        Route::post('/scripts', [SeoScriptController::class, 'store']);
        Route::put('/scripts/{seo_script}', [SeoScriptController::class, 'update']);
        Route::delete('/scripts/{seo_script}', [SeoScriptController::class, 'destroy']);

        Route::get('/crawler-rules', [CrawlerRuleController::class, 'index']);
        Route::post('/crawler-rules', [CrawlerRuleController::class, 'store']);
        Route::put('/crawler-rules/{crawler_rule}', [CrawlerRuleController::class, 'update']);
        Route::delete('/crawler-rules/{crawler_rule}', [CrawlerRuleController::class, 'destroy']);
    });

    // ADR-039 decision 9/10 — Database Backups: unified run history
    // (BAK-1/2), manual "Backup Now" trigger (BAK-3), download/delete
    // (BAK-4). Super Admin only, same tier as Settings/Price Sync — no
    // restore endpoint anywhere here (decision 5, CLI/artisan-only).
    Route::middleware('admin.role:super_admin')->prefix('middleware/backups')->group(function () {
        Route::get('/', [BackupController::class, 'index']);
        Route::get('/stats', [BackupController::class, 'stats']);
        Route::post('/', [BackupController::class, 'store']);
        Route::get('/{backup_run}', [BackupController::class, 'show']);
        Route::get('/{backup_run}/download', [BackupController::class, 'download']);
        Route::delete('/{backup_run}', [BackupController::class, 'destroy']);
    });

    // ADR-048 addendum — mints a short-lived (5 min) signed URL that
    // bootstraps the one `web`-guard session this backend ever creates
    // (OpsAccessController's own doc comment has the full story). Super
    // Admin only, same tier as every other /middleware/* route.
    Route::middleware('admin.role:super_admin')->prefix('middleware/ops')->group(function () {
        Route::post('/{target}/link', [OpsAccessController::class, 'mint']);
    });

    // SET-7/SET-11 — Payment Methods: per-channel activation/fee/gateway
    // management, replaces the config/checkout.php stopgap.
    // Super Admin only — payment gateway config, per PRD §3; fixed 2026-08-14.
    Route::middleware('admin.role:super_admin')->prefix('middleware/payment-methods')->group(function () {
        Route::get('/', [PaymentMethodController::class, 'index']);
        Route::patch('/{payment_method}/status', [PaymentMethodController::class, 'updateStatus']);
        Route::patch('/{payment_method}/fee', [PaymentMethodController::class, 'updateFee']);
        Route::post('/{payment_method}/test', [PaymentMethodController::class, 'test']);
    });

    // MUI-5 — Validators: admin-created profiles, each bound to a real
    // backend implementation via `key` (PlayerValidatorRegistry).
    // Region-routing mappings are nested under the profile they
    // belong to, not a flat list — see PlayerValidatorProfileController's
    // doc comment for why (founder correction, 2026-07-25).
    // Super Admin only — supplier validator config, per PRD §3; fixed 2026-08-14.
    Route::middleware('admin.role:super_admin')->prefix('middleware/validators')->group(function () {
        Route::get('/', [PlayerValidatorProfileController::class, 'index']);
        Route::get('/available-keys', [PlayerValidatorProfileController::class, 'availableKeys']);
        Route::post('/', [PlayerValidatorProfileController::class, 'store']);
        Route::put('/{validator}', [PlayerValidatorProfileController::class, 'update']);
        Route::delete('/{validator}', [PlayerValidatorProfileController::class, 'destroy']);
        Route::post('/{validator}/test', [PlayerValidatorProfileController::class, 'test']);

        Route::post('/{validator}/mappings', [PlayerRegionMappingController::class, 'store']);
        Route::put('/{validator}/mappings/{mapping}', [PlayerRegionMappingController::class, 'update']);
        Route::delete('/{validator}/mappings/{mapping}', [PlayerRegionMappingController::class, 'destroy']);
    });

    // IMG-1/IMG-2 — Image Gallery: upload/browse/search/delete, feeds
    // Game.image_url / HeroSlide.image_url via copy-URL (see
    // GalleryImageController's doc comment).
    Route::middleware('admin.role:super_admin,admin')->prefix('gallery/images')->group(function () {
        Route::get('/', [GalleryImageController::class, 'index']);
        Route::post('/', [GalleryImageController::class, 'store']);
        Route::delete('/{gallery_image}', [GalleryImageController::class, 'destroy']);
    });

    // Hero Banner admin CRUD (docs/prd.md §14/§15 backlog).
    Route::middleware('admin.role:super_admin,admin')->prefix('hero-slides')->group(function () {
        Route::get('/', [AdminHeroSlideController::class, 'index']);
        Route::post('/', [AdminHeroSlideController::class, 'store']);
        Route::put('/{hero_slide}', [AdminHeroSlideController::class, 'update']);
        Route::patch('/{hero_slide}/status', [AdminHeroSlideController::class, 'updateStatus']);
        Route::delete('/{hero_slide}', [AdminHeroSlideController::class, 'destroy']);
    });

    // GAME-1..5/7 — Admin Games & Packages management.
    Route::middleware('admin.role:super_admin,admin')->group(function () {
        Route::get('/games', [GameController::class, 'index']);
        Route::get('/games/{game}', [GameController::class, 'show']);
        Route::put('/games/{game}', [GameController::class, 'update']);
        Route::delete('/games/{game}', [GameController::class, 'destroy']);
        Route::get('/games/{game}/packages', [GameController::class, 'packages']);

        Route::put('/packages/{package}', [PackageController::class, 'update']);
        Route::patch('/packages/{package}/markup', [PackageController::class, 'updateMarkup']);
        Route::patch('/packages/{package}/status', [PackageController::class, 'updateStatus']);
        Route::patch('/packages/{package}/denomination', [PackageController::class, 'updateDenomination']);
        Route::delete('/packages/{package}', [PackageController::class, 'destroy']);
    });
});

// Not behind auth:sanctum — Xendit isn't an admin user. Signature
// verification inside the controller is the auth mechanism (PAY-1).
// Rate-limited distinct from the general `api` group (which has no throttle
// enabled at all, per bootstrap/app.php) — bounds the cost of an unsigned
// flood before signature verification runs, without risking a real gateway
// retry burst getting throttled. Found absent, fresh audit, 2026-08-14.
Route::post('/webhooks/xendit', [XenditWebhookController::class, 'handle'])->middleware('throttle:120,1,webhook-xendit');
Route::post('/webhooks/chip', [ChipWebhookController::class, 'handle'])->middleware('throttle:120,1,webhook-chip');
