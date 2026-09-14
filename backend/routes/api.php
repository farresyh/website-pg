<?php

use App\Http\Controllers\Admin\AdminUserController;
use App\Http\Controllers\Admin\AffiliateController;
use App\Http\Controllers\Admin\AffiliateImpersonationController;
use App\Http\Controllers\Admin\AffiliateMembershipTierController;
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
use App\Http\Controllers\Admin\ReportAssistantController;
use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\Admin\ResellerApiKeyController;
use App\Http\Controllers\Admin\ResellerController;
use App\Http\Controllers\Admin\ResellerTierController;
use App\Http\Controllers\Admin\ResellerWalletController;
use App\Http\Controllers\Admin\ResellerWebhookController;
use App\Http\Controllers\Admin\ResellerWhatsAppGroupController;
use App\Http\Controllers\Admin\ReviewController as AdminReviewController;
use App\Http\Controllers\Admin\SeoController as AdminSeoController;
use App\Http\Controllers\Admin\SeoScriptController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\SupplierTransferController;
use App\Http\Controllers\Admin\TransactionRegisterController;
use App\Http\Controllers\Admin\VoucherController;
use App\Http\Controllers\Admin\WithdrawalController;
use App\Http\Controllers\Affiliate\AffiliateAuthController;
use App\Http\Controllers\Affiliate\DashboardController as AffiliateDashboardController;
use App\Http\Controllers\Affiliate\DomainController as AffiliateDomainController;
use App\Http\Controllers\Affiliate\EarningsController as AffiliateEarningsController;
use App\Http\Controllers\Affiliate\ImpersonationController as AffiliateImpersonationEndController;
use App\Http\Controllers\Affiliate\OrderController as AffiliateOrderController;
use App\Http\Controllers\Affiliate\ProfileController as AffiliateProfileController;
use App\Http\Controllers\Affiliate\Storefront\BrandingController as AffiliateStorefrontBrandingController;
use App\Http\Controllers\Affiliate\Storefront\HeroSlideController as AffiliateStorefrontHeroSlideController;
use App\Http\Controllers\Affiliate\Storefront\PricingController as AffiliateStorefrontPricingController;
use App\Http\Controllers\Affiliate\Storefront\SeoController as AffiliateStorefrontSeoController;
use App\Http\Controllers\Affiliate\Storefront\StorefrontGameController as AffiliateStorefrontGameController;
use App\Http\Controllers\Affiliate\SubscriptionController as AffiliateSubscriptionController;
use App\Http\Controllers\Affiliate\WithdrawalController as AffiliateWithdrawalController;
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
use App\Http\Controllers\Middleware\DeveloperToolController;
use App\Http\Controllers\Middleware\DismissedPackageController;
use App\Http\Controllers\Middleware\OpsAccessController;
use App\Http\Controllers\Middleware\PaymentMethodController;
use App\Http\Controllers\Middleware\PendingPriceChangeController;
use App\Http\Controllers\Middleware\PendingReactivationController;
use App\Http\Controllers\Middleware\PlayerRegionMappingController;
use App\Http\Controllers\Middleware\PlayerValidatorProfileController;
use App\Http\Controllers\Middleware\PriceSyncController;
use App\Http\Controllers\Middleware\RequestLogController;
use App\Http\Controllers\Middleware\SandboxOrderController;
use App\Http\Controllers\Middleware\SupplierController;
use App\Http\Controllers\Middleware\SupplierProductController;
use App\Http\Controllers\PackageController;
use App\Http\Controllers\PaymentMethodCatalogController;
use App\Http\Controllers\PlayerValidationController;
use App\Http\Controllers\PublicResellerPriceListController;
use App\Http\Controllers\ResellerApi\BalanceController as ResellerApiBalanceController;
use App\Http\Controllers\ResellerApi\CatalogController as ResellerApiCatalogController;
use App\Http\Controllers\ResellerApi\OrderController as ResellerApiOrderController;
use App\Http\Controllers\ResellerPortal\ApiKeyController as ResellerPortalApiKeyController;
use App\Http\Controllers\ResellerPortal\OrderController as ResellerPortalOrderController;
use App\Http\Controllers\ResellerPortal\ProfileController as ResellerPortalProfileController;
use App\Http\Controllers\ResellerPortal\WalletController as ResellerPortalWalletController;
use App\Http\Controllers\ResellerPortal\WebhookController as ResellerPortalWebhookController;
use App\Http\Controllers\ReviewCatalogController;
use App\Http\Controllers\ReviewController;
use App\Http\Controllers\SeoController;
use App\Http\Controllers\TrackOrderController;
use App\Http\Controllers\VoucherPreviewController;
use App\Http\Controllers\Webhooks\ChipWebhookController;
use App\Http\Controllers\Webhooks\DigiflazzWebhookController;
use App\Http\Controllers\Webhooks\OpenWaWebhookController;
use App\Http\Middleware\EnsureResellerApiKey;
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
//
// ADR-060 PR-4c: `storefront.brand` resolves the `X-Storefront-Host`
// brand so checkout prices against THAT brand's wholesale tier +
// markup and attributes the order (hence the ledger profit split) to
// it. No header / a `primary_hosts` header → `Affiliate::primary()`,
// byte-identical to before. An unknown/suspended host 404s here too.
Route::post('/checkout', [CheckoutController::class, 'store'])->middleware(['throttle:10,1,checkout', 'storefront.brand']);

// ADR-024 decision #1's "Apply" button — read-only preview, never
// locks or spends a voucher's remaining balance (VoucherService::
// redeem() only ever runs inside the real /checkout call above). Same
// throttle as checkout: a bearer-code-guessing probe is exactly the
// abuse this rate limit exists to blunt, and the ownership-lock check
// inside VoucherService::preview() already keeps a wrong guess from
// revealing anything either way.
// ADR-060 PR-4c: `storefront.brand` — the discount preview prices the
// package against the `Host`-resolved brand, same as the real checkout.
Route::post('/vouchers/preview', [VoucherPreviewController::class, 'store'])->middleware(['throttle:10,1,voucher-preview', 'storefront.brand']);

// Bug fix, 2026-08-30: read-only Package Price/Transaction Fee/Voucher
// Discount/Total breakdown, fetched by the storefront's Order Summary
// sidebar and Review Modal on every package/channel/voucher change —
// see CheckoutController::previewTotal()'s own doc comment. Looser
// than checkout/voucher-preview's 10/min: this is normal browsing
// telemetry (no gateway call, no guessable secret, unlike a voucher
// code), debounced client-side, but still worth a limit since it's a
// public unauthenticated endpoint doing real DB work.
// ADR-060 PR-4c: `storefront.brand` — the summary total must match what
// the real checkout charges for this brand (anti-divergence).
Route::post('/checkout/preview-totals', [CheckoutController::class, 'previewTotal'])->middleware(['throttle:30,1,checkout-preview-totals', 'storefront.brand']);

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
// `storefront.brand` (ADR-060) scopes the lookup to the brand whose
// storefront the request came in on — an order number is only valid on
// the storefront it was placed on (see Order::scopeForStorefrontBrand).
Route::get('/track-order/{orderNumber}', [TrackOrderController::class, 'show'])->middleware(['throttle:20,1,track-order', 'storefront.brand']);

// ADR-053 (REV-1..5) — public guest review submission, same
// order_number-as-proof-of-ownership trust model as track-order above.
// reviews.order_id's own unique index is the real one-per-order
// guarantee; this throttle only blunts a flood, same convention as
// checkout/validate-player.
Route::post('/orders/{orderNumber}/review', [ReviewController::class, 'store'])->middleware(['throttle:10,1,review', 'storefront.brand']);

// ADR-027's 2026-08-29 addendum, decisions 23/26/27 — membership
// identity verification (email + OTP, no login/account). `send` uses
// the named `otp-request` limiter (registered in AppServiceProvider,
// 3/hour keyed by email — not IP, unlike every other throttle: route
// in this file, since the abuse case is flooding one target inbox).
// `verify` gets a plain IP throttle same shape as checkout/validate
// -player; OtpService's own 5-attempt lockout is the real brute-force
// defense for a submitted code.
// ADR-060 (2026-09-06 addendum): every membership route resolves the
// storefront brand — the session-token brand check (MembershipController)
// compares against the resolved brand, not always the primary.
// ADR-080 decision 1/2: the sales & write surface (OTP send/verify,
// subscribe-options, subscribe) is gated by `membership.enabled` —
// listed BEFORE `throttle:*` so a disabled brand fails fast without
// consuming a rate-limit bucket. `plans` (returns []) and `me` (a
// member's read-only self-view) are deliberately left un-gated.
Route::middleware('storefront.brand')->group(function () {
    Route::post('/membership/otp/send', [MembershipOtpController::class, 'send'])
        ->middleware(['membership.enabled', 'throttle:otp-request']);
    Route::post('/membership/otp/verify', [MembershipOtpController::class, 'verify'])
        ->middleware(['membership.enabled', 'throttle:10,1,membership-verify']);
    // ADR-055 decision 3: the upsell card's tier data — public (no session
    // token), returns [] when the kill switch is off. Deliberately separate
    // from the admin-only membership-plans prefix (same controller family,
    // different gate — this route is on the public MembershipController).
    Route::get('/membership/plans', [MembershipController::class, 'plans']);

    // Decisions 13/24/25 — the /membership dashboard's data. Auth is the
    // session token (Authorization: Bearer), not auth:sanctum — resolved
    // inside the controller itself, same reasoning as the OTP routes above.
    // ADR-080 decision 1: intentionally NOT behind `membership.enabled` —
    // an existing member keeps read-only visibility of their own
    // membership state + order history even when the brand's Membership
    // toggle (or the global kill switch) is off.
    Route::get('/membership/me', [MembershipController::class, 'me']);

    // ADR-068 — self-serve subscription payment. Both session-token gated
    // inside the controller. `subscribe` carries the `membership-subscribe`
    // limiter (registered in AppServiceProvider — keyed on the bearer
    // token, one bucket per member session, so a shared NAT can't starve
    // other members).
    Route::get('/membership/subscribe-options', [MembershipController::class, 'subscribeOptions'])
        ->middleware('membership.enabled');
    Route::post('/membership/subscribe', [MembershipController::class, 'subscribe'])
        ->middleware(['membership.enabled', 'throttle:membership-subscribe']);
});

// Public game/package catalog (ADR-011) — the storefront's real data
// source, replacing storefront/src/lib/placeholder-data.ts (docs/prd.md
// §14/§15 NEXT SESSION pointer). A distinct `catalog/` prefix, not
// `/games`: that path is already GameController's admin-only,
// numeric-{id}-bound resource — same method+path can't serve two
// different auth rules, and public lookup is by slug, not id. No
// throttle: unlike checkout/validate-player this hits no third-party
// API and isn't money-moving, just a normal public read listing.
//
// ADR-060 (2026-09-06 addendum) — `storefront.brand` resolves the brand
// from `X-Storefront-Host`: branding / SEO (PR-2) and, since PR-4c, the
// per-brand `selling_price_sen` / `price_from_sen` in the catalog
// listings (priced against the brand's wholesale tier + markup, cached
// per brand). Inert without the header (falls back to Affiliate::primary());
// a header for an unknown/inactive host 404s before the controller
// (decision 2).
Route::prefix('catalog')->middleware('storefront.brand')->group(function () {
    Route::get('/games', [CatalogController::class, 'index']);
    Route::get('/games/{slug}', [CatalogController::class, 'show']);
    Route::get('/games/{slug}/packages', [CatalogController::class, 'packages']);
    Route::get('/games/{slug}/reviews', [ReviewCatalogController::class, 'gameReviews']);

    // Hero Banner (docs/prd.md §14/§15 backlog) — no secret-field
    // concern here (no cost/margin data on this model), grouped under
    // the same public "storefront content" prefix as games/packages.
    Route::get('/hero-slides', [HeroSlideController::class, 'index']);

    // ADR-022's newest addendum, decision 5 — gateway-agnostic active
    // channel listing, replacing the storefront's hardcoded
    // PLACEHOLDER_PAYMENT_CHANNELS.
    Route::get('/payment-methods', [PaymentMethodCatalogController::class, 'index']);

    // ADR-091 — public Reseller Price List page. Content is
    // platform-wide (reseller_tiers isn't an affiliate concept); the
    // brand resolution here only gates is_owned, never re-prices.
    Route::get('/reseller-price-list', [PublicResellerPriceListController::class, 'index']);

    // Public approved reviews for storefront homepage
    Route::get('/reviews', [ReviewCatalogController::class, 'index']);

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

    // ADR-060 PR-5 — the storefront `proxy.ts` hits this once per Host to
    // decide whether to serve the brand or the hard "store unavailable"
    // page. `storefront.brand` returns 200 for a primary / known-active
    // host and a coded 404 for an unknown / suspended one.
    Route::get('/storefront-status', fn () => response()->json(['ok' => true]));
});

// ADR-058 (58a) — affiliate portal auth, on the separate `affiliate`
// Sanctum guard (config/auth.php). Own throttle buckets from day one:
// per the lesson above every throttle:N route needs its own prefix or
// it silently shares one per-IP bucket with every other prefixless one.
Route::prefix('affiliate')->group(function () {
    Route::post('/login', [AffiliateAuthController::class, 'login'])->middleware('throttle:5,1,affiliate-login');
    Route::post('/set-password', [AffiliateAuthController::class, 'setPassword'])->middleware('throttle:6,1,affiliate-set-password');

    // `auth:affiliate` rejects any token whose tokenable is not a
    // affiliate_users model; `affiliate.context` then activates ADR-057's
    // tenant scope from the authenticated user's owner_id.
    //
    // ADR-072 decision 4 / PR-G: `account.type:affiliate` is the
    // mandatory backend gate on every route below — a Reseller (wallet)
    // account's token can authenticate `auth:affiliate` (same guard,
    // decision 6) but must never reach an Affiliate-only endpoint like
    // Withdrawal/Subscription. `/logout` and `/me` are shared by both
    // account types (PR-G planning addendum decision 6) so stay outside
    // this gate.
    Route::middleware(['auth:affiliate'])->group(function () {
        Route::post('/logout', [AffiliateAuthController::class, 'logout']);
        Route::get('/me', [AffiliateAuthController::class, 'me']);

        Route::middleware(['account.type:affiliate', 'affiliate.context'])->group(function () {
            // ADR-059 (59a) — affiliate portal read layer. Every route here
            // is scoped to the authenticated affiliate_user's own tenant by
            // `affiliate.context`; money reads go through
            // AffiliateEarningsService (decision 5).
            Route::get('/dashboard', [AffiliateDashboardController::class, 'show']);
            Route::get('/orders', [AffiliateOrderController::class, 'index']);
            Route::get('/orders/{orderNumber}', [AffiliateOrderController::class, 'show']);
            Route::get('/earnings', [AffiliateEarningsController::class, 'index']);
            Route::get('/subscription', [AffiliateSubscriptionController::class, 'show']);

            // ADR-059 (59c) — write surface: profile bank details + WTH-1..5
            // request side (approval stays admin) + the portal "Exit
            // impersonation" close.
            Route::get('/profile', [AffiliateProfileController::class, 'show']);
            Route::put('/profile', [AffiliateProfileController::class, 'update']);
            Route::get('/withdrawals', [AffiliateWithdrawalController::class, 'index']);
            Route::post('/withdrawals', [AffiliateWithdrawalController::class, 'store']);
            Route::post('/impersonation/end', [AffiliateImpersonationEndController::class, 'end']);

            // ADR-060 PR-5 — self-serve custom-domain management (branded
            // storefront). Provider-opaque (addendum section C).
            Route::get('/domains', [AffiliateDomainController::class, 'index']);
            Route::post('/domains', [AffiliateDomainController::class, 'store']);
            Route::post('/domains/{domain}/recheck', [AffiliateDomainController::class, 'recheck']);
            Route::post('/domains/{domain}/primary', [AffiliateDomainController::class, 'setPrimary']);
            Route::delete('/domains/{domain}', [AffiliateDomainController::class, 'destroy']);

            // ADR-060 PR-6 — the portal "Storefront" screen's four tabs:
            // branding text + logo + pixels, hero slides, catalog
            // visibility, retail markup + live preview. Every write
            // is `assertWritable`-gated (deactivated = read-only).
            Route::prefix('storefront')->group(function () {
                Route::get('/branding', [AffiliateStorefrontBrandingController::class, 'show']);
                Route::put('/branding', [AffiliateStorefrontBrandingController::class, 'update']);
                Route::post('/branding/logo', [AffiliateStorefrontBrandingController::class, 'uploadLogo']);
                Route::delete('/branding/logo', [AffiliateStorefrontBrandingController::class, 'destroyLogo']);
                Route::post('/branding/favicon', [AffiliateStorefrontBrandingController::class, 'uploadFavicon']);
                Route::delete('/branding/favicon', [AffiliateStorefrontBrandingController::class, 'destroyFavicon']);

                Route::put('/seo', [AffiliateStorefrontSeoController::class, 'update']);

                Route::get('/hero-slides', [AffiliateStorefrontHeroSlideController::class, 'index']);
                Route::post('/hero-slides', [AffiliateStorefrontHeroSlideController::class, 'store']);
                Route::put('/hero-slides/{heroSlide}', [AffiliateStorefrontHeroSlideController::class, 'update']);
                Route::patch('/hero-slides/{heroSlide}/status', [AffiliateStorefrontHeroSlideController::class, 'updateStatus']);
                Route::delete('/hero-slides/{heroSlide}', [AffiliateStorefrontHeroSlideController::class, 'destroy']);

                Route::get('/games', [AffiliateStorefrontGameController::class, 'index']);
                Route::put('/games/{game}', [AffiliateStorefrontGameController::class, 'update']);

                Route::get('/pricing', [AffiliateStorefrontPricingController::class, 'show']);
                Route::put('/pricing', [AffiliateStorefrontPricingController::class, 'update']);
                Route::post('/pricing/preview', [AffiliateStorefrontPricingController::class, 'preview']);
            });
        });
    });
});

// ADR-072/073 PR-G — the Reseller (wallet) portal's own screens, on the
// SAME `affiliate` guard/login endpoint as above (decision 6) —
// `account.type:reseller` is the only new gate, layered on after auth,
// never a parallel auth mechanism. Scope is view + top-up + history
// only (planning addendum decision 2) — order *placement* stays
// exclusively the API (PR-E)/Bot (PR-F) channels.
Route::prefix('reseller-portal')->middleware(['auth:affiliate', 'account.type:reseller'])->group(function () {
    Route::get('/wallet', [ResellerPortalWalletController::class, 'show']);
    Route::post('/wallet/topup', [ResellerPortalWalletController::class, 'topup']);

    Route::get('/orders', [ResellerPortalOrderController::class, 'index']);
    Route::get('/orders/{orderNumber}', [ResellerPortalOrderController::class, 'show']);

    Route::get('/profile', [ResellerPortalProfileController::class, 'show']);

    // Full self-service (planning addendum decision 7) — capped at 5
    // active keys. Admin retains the same capability in parallel
    // (`/admin/resellers/{reseller}/api-keys*`), not removed.
    Route::get('/api-keys', [ResellerPortalApiKeyController::class, 'index']);
    Route::post('/api-keys', [ResellerPortalApiKeyController::class, 'store']);
    Route::patch('/api-keys/{api_key}', [ResellerPortalApiKeyController::class, 'update']);
    Route::delete('/api-keys/{api_key}', [ResellerPortalApiKeyController::class, 'destroy']);

    // ADR-084 PR-3 decision 4/10 — the single delivery-webhook endpoint:
    // URL + secret (shown once, rotatable), active toggle, dead-letter
    // delivery log. Admin has the same capability for support
    // (`/admin/resellers/{reseller}/webhook*`).
    Route::get('/webhook', [ResellerPortalWebhookController::class, 'show']);
    Route::post('/webhook', [ResellerPortalWebhookController::class, 'store']);
    Route::post('/webhook/rotate-secret', [ResellerPortalWebhookController::class, 'rotateSecret']);
    Route::patch('/webhook/status', [ResellerPortalWebhookController::class, 'updateStatus']);
    Route::delete('/webhook', [ResellerPortalWebhookController::class, 'destroy']);
    Route::get('/webhook/deliveries', [ResellerPortalWebhookController::class, 'deliveries']);
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
        Route::get('/affiliates', [ReportController::class, 'affiliates']);
        Route::get('/summary', [ReportController::class, 'summary']);
        Route::get('/trend', [ReportController::class, 'trend']);
        Route::get('/daily-breakdown', [ReportController::class, 'dailyBreakdown']);
        Route::get('/top-games', [ReportController::class, 'topGames']);
        Route::get('/breakdown/games', [ReportController::class, 'gameBreakdown']);
        Route::get('/breakdown/payment-methods', [ReportController::class, 'paymentMethodBreakdown']);
        Route::get('/breakdown/affiliates', [ReportController::class, 'affiliateBreakdown']);
        Route::get('/breakdown/resellers', [ReportController::class, 'resellerBreakdown']);
        Route::get('/order-status-funnel', [ReportController::class, 'orderStatusFunnel']);
        Route::get('/membership-breakdown', [ReportController::class, 'membershipBreakdown']);
        Route::get('/export', [ReportController::class, 'export']);
    });

    // ADR-087 decision 6/9 — super_admin only (stricter than the Reports
    // group above: this surface's read access is broader than any
    // single existing screen), own route rather than a Reports tab.
    Route::middleware('admin.role:super_admin')->prefix('reports/assistant')->group(function () {
        Route::post('/ask', [ReportAssistantController::class, 'ask']);
    });

    Route::middleware('admin.role:super_admin,admin')->prefix('customer-analytics')->group(function () {
        Route::get('/summary', [CustomerAnalyticsController::class, 'summary']);
        Route::get('/customers', [CustomerAnalyticsController::class, 'customers']);
        Route::get('/customers/{email}', [CustomerAnalyticsController::class, 'show']);
        Route::get('/export', [CustomerAnalyticsController::class, 'export']);
    });

    Route::middleware('admin.role:super_admin,admin')->prefix('orders')->group(function () {
        Route::get('/', [OrderController::class, 'index']);
        // ADR-092: registered before {order} so the literal segment
        // never gets swallowed by route-model binding.
        Route::get('/summary', [OrderController::class, 'summary']);
        Route::get('/{order}', [OrderController::class, 'show']);
        Route::post('/{order}/retry-delivery', [OrderController::class, 'retryDelivery']);
        Route::post('/{order}/resend', [OrderController::class, 'resend']);
        // ADR-026 decision 4a — the one needs_review exit that isn't a retry.
        Route::post('/{order}/mark-delivered', [OrderController::class, 'markDelivered']);
        // ADR-073 decision 7 — the wallet-order counterpart to
        // /vouchers/{order} (VoucherController::storeFromOrder), never
        // both offered for the same order.
        Route::post('/{order}/refund-to-wallet', [OrderController::class, 'refundToWallet']);
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
        // ADR-089: primary brand's logo/favicon — same upload pipeline
        // as the affiliate portal, gated admin-side instead.
        Route::post('/branding/logo', [SettingsController::class, 'uploadLogo']);
        Route::delete('/branding/logo', [SettingsController::class, 'destroyLogo']);
        Route::post('/branding/favicon', [SettingsController::class, 'uploadFavicon']);
        Route::delete('/branding/favicon', [SettingsController::class, 'destroyFavicon']);
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
        // ADR-061 decision 5: the brands a membership can be recorded
        // against — internal, membership-enabled affiliates only.
        Route::get('/brands', [AdminMembershipController::class, 'brands']);
        Route::post('/record-payment', [AdminMembershipController::class, 'recordPayment']);
        // ADR-068 decisions 14/15 — the per-member detail (state, fee
        // history, checkout attempts incl. pending/failed). Declared
        // after /brands so the static segment still wins.
        Route::get('/{membership}', [AdminMembershipController::class, 'show']);
    });

    // ADR-083 decision 2 (PR-1) — "Record Supplier Transfer": the supplier
    // funding ledger. Deliberately under /accounting, not /middleware —
    // this is a bookkeeping action (money we sent to fund a supplier's
    // account), not a supplier-integration one, same reasoning as
    // Membership above. Nested under {supplier} like the reseller wallet
    // is under {reseller}.
    Route::middleware('admin.role:super_admin')->prefix('accounting')->group(function () {
        Route::prefix('suppliers/{supplier}/transfers')->group(function () {
            Route::get('/', [SupplierTransferController::class, 'index']);
            Route::post('/', [SupplierTransferController::class, 'store']);
        });

        Route::get('/supplier-transfers/{supplierTransfer}/receipt', [SupplierTransferController::class, 'downloadReceipt']);

        // ADR-083 decision 9 — Transaction Register: read-only, plus a
        // CSV export, across orders/supplier transfers/supplier
        // REFUND entries/Path-B vouchers.
        Route::get('/transactions', [TransactionRegisterController::class, 'index']);
        Route::get('/transactions/export', [TransactionRegisterController::class, 'export']);
    });

    // ADR-058 58b (RES-1..6) — admin Affiliate Management. Same
    // super_admin tier as Settings / Membership (platform-wide business
    // config, no supplier-integration dependency so deliberately not
    // under /middleware). The affiliate PORTAL auth (58a) is a separate
    // guard entirely, see the /affiliate prefix above.
    Route::middleware('admin.role:super_admin')->group(function () {
        Route::prefix('affiliates')->group(function () {
            Route::get('/', [AffiliateController::class, 'index']);
            Route::post('/', [AffiliateController::class, 'store']);
            Route::get('/{affiliate}', [AffiliateController::class, 'show']);
            Route::put('/{affiliate}', [AffiliateController::class, 'update']);
            Route::patch('/{affiliate}/status', [AffiliateController::class, 'updateStatus']);
            Route::delete('/{affiliate}', [AffiliateController::class, 'destroy']);

            // ADR-056 decision 8 — wholesale-tier assignment + fee actions.
            Route::post('/{affiliate}/tier', [AffiliateController::class, 'assignTier']);
            Route::post('/{affiliate}/tier/charge', [AffiliateController::class, 'chargeTierFee']);
            Route::post('/{affiliate}/tier/reactivate', [AffiliateController::class, 'reactivateSubscription']);

            // Staff logins + the set-password invite (58a's AffiliateInviteService).
            Route::post('/{affiliate}/users', [AffiliateController::class, 'storeUser']);
            Route::post('/{affiliate}/users/{affiliateUser}/resend-invite', [AffiliateController::class, 'resendInvite']);

            // RES-4 impersonation.
            Route::post('/{affiliate}/impersonate', [AffiliateImpersonationController::class, 'store']);

            // ADR-060 PR-5 — custom-domain break-glass. Read-only list is
            // folded into `show`; the single write path is affiliate
            // self-serve (or the founder via RES-4 impersonation). No
            // add-domain form here (addendum section F).
            Route::post('/{affiliate}/domains/{affiliateDomain}/recheck', [AffiliateController::class, 'recheckDomain']);
            Route::delete('/{affiliate}/domains/{affiliateDomain}', [AffiliateController::class, 'removeDomain']);
        });

        Route::get('/affiliate-impersonation-sessions', [AffiliateImpersonationController::class, 'index']);
        Route::post('/affiliate-impersonation-sessions/{impersonation_session}/end', [AffiliateImpersonationController::class, 'end']);

        // ADR-056 decision 1 — the affiliate_membership_tiers CRUD ladder.
        Route::prefix('affiliate-tiers')->group(function () {
            Route::get('/', [AffiliateMembershipTierController::class, 'index']);
            Route::post('/', [AffiliateMembershipTierController::class, 'store']);
            Route::put('/{affiliate_tier}', [AffiliateMembershipTierController::class, 'update']);
            Route::delete('/{affiliate_tier}', [AffiliateMembershipTierController::class, 'destroy']);
        });
    });

    // ADR-072/073 PR-B — admin Reseller (prepaid-wallet) account
    // management: register account, assign tier, activate/deactivate.
    // Same super_admin tier as Affiliate Management above. Distinct from
    // `Affiliate` (whitelabel storefront partner) — see ADR-072 decision 1.
    Route::middleware('admin.role:super_admin')->group(function () {
        Route::prefix('resellers')->group(function () {
            Route::get('/', [ResellerController::class, 'index']);
            Route::post('/', [ResellerController::class, 'store']);
            Route::get('/{reseller}', [ResellerController::class, 'show']);
            Route::put('/{reseller}', [ResellerController::class, 'update']);
            Route::patch('/{reseller}/status', [ResellerController::class, 'updateStatus']);
            Route::post('/{reseller}/tier', [ResellerController::class, 'assignTier']);
            Route::delete('/{reseller}', [ResellerController::class, 'destroy']);

            // PR-G — this account's portal login (ADR-072 decision 5),
            // mirrors AffiliateController's own staff-login actions.
            Route::post('/{reseller}/users', [ResellerController::class, 'storeUser']);
            Route::post('/{reseller}/users/{affiliateUser}/resend-invite', [ResellerController::class, 'resendInvite']);

            // ADR-073 decision 3(b) (PR-C) — admin manual-credit. Self-serve
            // CHIP top-up (decision 3a) deferred to whichever PR first gives
            // a Reseller its own entry point (see ADR-073's build addendum).
            Route::get('/{reseller}/wallet', [ResellerWalletController::class, 'index']);
            Route::post('/{reseller}/wallet/credit', [ResellerWalletController::class, 'credit']);

            // ADR-074 decision 1 (PR-E) — issue/revoke a Reseller API
            // credential. The plaintext key is only ever in store()'s
            // response.
            Route::get('/{reseller}/api-keys', [ResellerApiKeyController::class, 'index']);
            Route::post('/{reseller}/api-keys', [ResellerApiKeyController::class, 'store']);
            Route::patch('/{reseller}/api-keys/{api_key}', [ResellerApiKeyController::class, 'update']);
            Route::delete('/{reseller}/api-keys/{api_key}', [ResellerApiKeyController::class, 'destroy']);

            // ADR-084 PR-3 decision 10 — support-side view/set/rotate/
            // disable of this Reseller's delivery webhook + its delivery
            // log. The reseller self-manages the same from the portal.
            Route::get('/{reseller}/webhook', [ResellerWebhookController::class, 'show']);
            Route::post('/{reseller}/webhook', [ResellerWebhookController::class, 'store']);
            Route::post('/{reseller}/webhook/rotate-secret', [ResellerWebhookController::class, 'rotateSecret']);
            Route::patch('/{reseller}/webhook/status', [ResellerWebhookController::class, 'updateStatus']);
            Route::delete('/{reseller}/webhook', [ResellerWebhookController::class, 'destroy']);
            Route::get('/{reseller}/webhook/deliveries', [ResellerWebhookController::class, 'deliveries']);

            // ADR-075 / PR-F build addendum decision 3 — link/unlink a
            // WhatsApp group to this Reseller account. 'pending' (below,
            // outside this {reseller} prefix) is platform-wide.
            Route::get('/{reseller}/whatsapp-groups', [ResellerWhatsAppGroupController::class, 'index']);
            Route::post('/{reseller}/whatsapp-groups', [ResellerWhatsAppGroupController::class, 'store']);
            Route::patch('/{reseller}/whatsapp-groups/{group}/status', [ResellerWhatsAppGroupController::class, 'updateStatus']);
        });

        Route::get('/reseller-whatsapp-groups/pending', [ResellerWhatsAppGroupController::class, 'pending']);

        // ADR-073 decision 1 — the reseller_tiers CRUD ladder.
        Route::prefix('reseller-tiers')->group(function () {
            Route::get('/', [ResellerTierController::class, 'index']);
            Route::post('/', [ResellerTierController::class, 'store']);
            Route::put('/{reseller_tier}', [ResellerTierController::class, 'update']);
            Route::delete('/{reseller_tier}', [ResellerTierController::class, 'destroy']);
        });

        Route::get('/wallet-topup-receipts/{walletTopupReceipt}/download', [ResellerWalletController::class, 'downloadReceipt']);
    });

    // ADR-029 — SEO Management: Overview, Global Settings/Meta
    // Templates (one table, decision 2), Game SEO (decision 11),
    // Redirects (decision 3/9), Scripts (addendum 2 decision 13),
    // Crawler (addendum 2 decision 14). Same tier as Settings above —
    // decision 6, no affiliate self-service portal yet.
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
        Route::get('/{gallery_image}/references', [GalleryImageController::class, 'references']);
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

    // GAME-1..5/6/7 — Admin Games & Packages management.
    Route::middleware('admin.role:super_admin,admin')->group(function () {
        Route::get('/games', [GameController::class, 'index']);
        Route::post('/games/reorder', [GameController::class, 'reorder']);
        Route::get('/games/{game}', [GameController::class, 'show']);
        Route::put('/games/{game}', [GameController::class, 'update']);
        Route::delete('/games/{game}', [GameController::class, 'destroy']);
        Route::get('/games/{game}/packages', [GameController::class, 'packages']);

        Route::put('/packages/{package}', [PackageController::class, 'update']);
        Route::patch('/packages/{package}/markup', [PackageController::class, 'updateMarkup']);
        Route::patch('/packages/{package}/status', [PackageController::class, 'updateStatus']);
        Route::patch('/packages/{package}/denomination', [PackageController::class, 'updateDenomination']);
        Route::patch('/packages/{package}/catalog-code', [PackageController::class, 'updateCatalogCode']);
        Route::delete('/packages/{package}', [PackageController::class, 'destroy']);
    });
});

// Not behind auth:sanctum — CHIP isn't an admin user. Signature
// verification inside the controller is the auth mechanism (PAY-1).
// Rate-limited distinct from the general `api` group (which has no throttle
// enabled at all, per bootstrap/app.php) — bounds the cost of an unsigned
// flood before signature verification runs, without risking a real gateway
// retry burst getting throttled. Found absent, fresh audit, 2026-08-14.
Route::post('/webhooks/chip', [ChipWebhookController::class, 'handle'])
    ->middleware('throttle:120,1,webhook-chip')
    ->name('webhooks.chip');

// ADR-069 — Digiflazz async-delivery finalization (the ADR-032
// decision 4 half, deferred through ADR-032's own build and ADR-067).
// Not behind auth:sanctum: the X-Hub-Signature HMAC check IS the auth,
// with a config-driven IP allowlist as a second gate. Same throttle
// rationale as the CHIP route above. The reconcile poll
// (ReconcilePendingDeliveriesCommand::checkStalePending) remains the
// backstop for any missed callback.
Route::post('/webhooks/digiflazz', [DigiflazzWebhookController::class, 'handle'])
    ->middleware('throttle:120,1,webhook-digiflazz')
    ->name('webhooks.digiflazz');

// ADR-075 / PR-F build addendum — the Reseller Bot channel's inbound
// half (self-hosted OpenWA). Not behind auth:sanctum: the
// X-Webhook-Signature HMAC check IS the auth, additionally hard-
// restricted to 127.0.0.1 at the nginx layer (decision 4 — OpenWA is
// co-located on the same droplet, a strictly stronger posture than
// Digiflazz's soft/log-only IP check). Same throttle rationale as the
// webhook routes above.
Route::post('/webhooks/openwa', [OpenWaWebhookController::class, 'handle'])
    ->middleware('throttle:120,1,webhook-openwa')
    ->name('webhooks.openwa');

// ADR-074 — Reseller API channel. Not behind auth:sanctum:
// EnsureResellerApiKey (a bearer `reseller_api_keys` credential) is its
// own, deliberately separate auth boundary (decision 1) — never the
// portal-login `reseller` Sanctum guard. Rate-limited per API key, not
// IP (decision 4), via the `reseller-api` named limiter
// (AppServiceProvider).
Route::prefix('reseller/v1')->middleware(['throttle:reseller-api', EnsureResellerApiKey::class])->group(function () {
    Route::get('/catalog', [ResellerApiCatalogController::class, 'index']);
    Route::get('/balance', [ResellerApiBalanceController::class, 'show']);
    Route::get('/orders', [ResellerApiOrderController::class, 'index']);
    Route::post('/orders', [ResellerApiOrderController::class, 'store']);
    Route::get('/orders/{orderNumber}', [ResellerApiOrderController::class, 'show']);
});
