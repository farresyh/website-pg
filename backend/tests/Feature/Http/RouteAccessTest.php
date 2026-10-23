<?php

namespace Tests\Feature\Http;

use App\Http\Middleware\EnsureAccountType;
use App\Http\Middleware\EnsureAdminRole;
use App\Http\Middleware\EnsureAdminSessionFresh;
use App\Http\Middleware\EnsureResellerApiKey;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * Who can reach every `/api` route, pinned in one place. A new route
 * fails here until it is placed in a guard group or added to one of the
 * lists on purpose, and a route moved to a weaker guard fails too.
 *
 * This replaces one "regular admin is forbidden" / "requires
 * authentication" test per controller, which checked the routes that
 * existed when they were written and could never catch a new route left
 * unguarded. The middleware's own behaviour is tested once each:
 * `EnsureAdminRoleTest`, `EnsureAccountTypeTest`,
 * `UnauthenticatedApiRequestTest`.
 */
class RouteAccessTest extends TestCase
{
    private const SANCTUM = Authenticate::class.':sanctum';

    private const PORTAL = Authenticate::class.':affiliate';

    /** @return Collection<string, list<string>> "METHOD uri" => resolved middleware */
    private function apiRoutes(): Collection
    {
        $router = app(Router::class);

        return collect($router->getRoutes()->getRoutes())
            ->filter(fn (Route $route) => str_starts_with($route->uri(), 'api/'))
            ->mapWithKeys(fn (Route $route) => [
                implode('|', array_diff($route->methods(), ['HEAD'])).' '.$route->uri() => $router->gatherRouteMiddleware($route),
            ]);
    }

    private static function has(array $middleware, string $prefix): bool
    {
        return collect($middleware)->contains(fn (string $m) => $m === $prefix || str_starts_with($m, $prefix.':'));
    }

    /** Storefront, login, webhooks (each verifies its own signature) and health. */
    public function test_the_public_routes_are_exactly_these(): void
    {
        $public = $this->apiRoutes()
            ->reject(fn (array $m) => self::has($m, Authenticate::class) || self::has($m, EnsureResellerApiKey::class))
            ->keys()->sort()->values()->all();

        $this->assertSame([
            'GET api/catalog/branding',
            'GET api/catalog/games',
            'GET api/catalog/games/{slug}',
            'GET api/catalog/games/{slug}/packages',
            'GET api/catalog/games/{slug}/reviews',
            'GET api/catalog/hero-slides',
            'GET api/catalog/legal/{page}',
            'GET api/catalog/payment-methods',
            'GET api/catalog/player-check/families',
            'GET api/catalog/player-check/families/{slug}',
            'GET api/catalog/reseller-price-list',
            'GET api/catalog/reviews',
            'GET api/catalog/seo/faqs',
            'GET api/catalog/seo/redirects',
            'GET api/catalog/seo/robots',
            'GET api/catalog/seo/settings',
            'GET api/catalog/storefront-status',
            'GET api/csrf-token',
            'GET api/health',
            'GET api/health/whatsapp',
            'GET api/joki/catalog',
            'GET api/membership/me',
            'GET api/membership/plans',
            'GET api/membership/subscribe-options',
            'GET api/track-order/{orderNumber}',
            'POST api/affiliate/login',
            'POST api/affiliate/set-password',
            'POST api/catalog/seo/redirects/record-hit',
            'POST api/checkout',
            'POST api/checkout/preview-totals',
            'POST api/client-errors',
            'POST api/games/{game}/validate-player',
            'POST api/joki/quote',
            'POST api/login',
            'POST api/login/mfa',
            'POST api/membership/otp/send',
            'POST api/membership/otp/verify',
            'POST api/membership/subscribe',
            'POST api/orders/{orderNumber}/review',
            'POST api/player-check/{slug}',
            'POST api/vouchers/preview',
            'POST api/webhooks/chip',
            'POST api/webhooks/digiflazz',
            'POST api/webhooks/openwa',
        ], $public);
    }

    /** The signed-in admin's own session endpoints: no role needed. */
    public function test_admin_routes_without_a_role_check_are_exactly_these(): void
    {
        $selfOnly = $this->apiRoutes()
            ->filter(fn (array $m) => in_array(self::SANCTUM, $m, true) && ! self::has($m, EnsureAdminRole::class))
            ->keys()->sort()->values()->all();

        $this->assertSame([
            'GET api/me',
            'GET|POST api/broadcasting/auth',
            'POST api/logout',
            'POST api/mfa/recovery-codes',
            'POST api/mfa/step-up',
        ], $selfOnly);
    }

    /** The signed-in portal user's own session endpoints: either account type. */
    public function test_portal_routes_without_an_account_type_check_are_exactly_these(): void
    {
        $selfOnly = $this->apiRoutes()
            ->filter(fn (array $m) => in_array(self::PORTAL, $m, true) && ! self::has($m, EnsureAccountType::class))
            ->keys()->sort()->values()->all();

        $this->assertSame([
            'GET api/affiliate/me',
            'POST api/affiliate/logout',
        ], $selfOnly);
    }

    /** Every other admin route is super_admin only; these are the ones a regular admin may use. */
    public function test_the_routes_open_to_a_regular_admin_are_exactly_these(): void
    {
        $roles = $this->apiRoutes()
            ->map(fn (array $m) => collect($m)->first(fn (string $x) => str_starts_with($x, EnsureAdminRole::class.':')))
            ->filter();

        $this->assertSame(
            [EnsureAdminRole::class.':super_admin', EnsureAdminRole::class.':super_admin,admin'],
            $roles->unique()->sort()->values()->all(),
            'a new role combination must be pinned here on purpose',
        );
        $this->assertSame([
            'DELETE api/gallery/images/{gallery_image}',
            'DELETE api/games/{game}',
            'DELETE api/hero-slides/{hero_slide}',
            'DELETE api/packages/{package}',
            'GET api/customer-analytics/customers',
            'GET api/customer-analytics/customers/{email}',
            'GET api/customer-analytics/export',
            'GET api/customer-analytics/summary',
            'GET api/dashboard/health',
            'GET api/gallery/images',
            'GET api/gallery/images/{gallery_image}/references',
            'GET api/games',
            'GET api/games/{game}',
            'GET api/games/{game}/packages',
            'GET api/hero-slides',
            'GET api/manual-queue',
            'GET api/manual-suppliers',
            'GET api/orders',
            'GET api/orders/export',
            'GET api/orders/summary',
            'GET api/orders/{order}',
            'GET api/orders/{order}/resend-options',
            'GET api/packages/{package}/price-history',
            'GET api/reports/accounting-bridge',
            'GET api/reports/affiliates',
            'GET api/reports/breakdown/affiliates',
            'GET api/reports/breakdown/channels',
            'GET api/reports/breakdown/delivery-by-game',
            'GET api/reports/breakdown/games',
            'GET api/reports/breakdown/payment-methods',
            'GET api/reports/breakdown/resellers',
            'GET api/reports/busy-hours',
            'GET api/reports/daily-breakdown',
            'GET api/reports/export',
            'GET api/reports/failed-compensated',
            'GET api/reports/highlights',
            'GET api/reports/membership-breakdown',
            'GET api/reports/order-status-funnel',
            'GET api/reports/summary',
            'GET api/reports/top-games',
            'GET api/reports/trend',
            'GET api/reviews',
            'GET api/vouchers',
            'GET api/vouchers/{voucher}',
            'GET api/withdrawals',
            'PATCH api/hero-slides/{hero_slide}/status',
            'PATCH api/packages/{package}/catalog-code',
            'PATCH api/packages/{package}/combo-override',
            'PATCH api/packages/{package}/cost',
            'PATCH api/packages/{package}/denomination',
            'PATCH api/packages/{package}/markup',
            'PATCH api/packages/{package}/status',
            'PATCH api/reviews/{review}/approve',
            'PATCH api/reviews/{review}/reject',
            'PATCH api/vouchers/{voucher}/revoke',
            'PATCH api/withdrawals/{withdrawal}/approve',
            'PATCH api/withdrawals/{withdrawal}/complete',
            'PATCH api/withdrawals/{withdrawal}/reject',
            'POST api/gallery/images',
            'POST api/games',
            'POST api/games/reorder',
            'POST api/games/{game}/packages/combo',
            'POST api/games/{game}/packages/manual',
            'POST api/hero-slides',
            'POST api/orders/{order}/check-gateway',
            'POST api/orders/{order}/check-supplier',
            'POST api/orders/{order}/confirm-failed',
            'POST api/orders/{order}/joki/assign',
            'POST api/orders/{order}/manual/correct-to-delivered',
            'POST api/orders/{order}/manual/delivered',
            'POST api/orders/{order}/manual/failed',
            'POST api/orders/{order}/mark-delivered',
            'POST api/orders/{order}/refund-to-wallet',
            'POST api/orders/{order}/resend',
            'POST api/orders/{order}/retry-delivery',
            'POST api/orders/{order}/voucher',
            'POST api/reviews/bulk-approve',
            'POST api/vouchers',
            'POST api/vouchers/merge',
            'PUT api/games/{game}',
            'PUT api/games/{game}/credential-schema',
            'PUT api/hero-slides/{hero_slide}',
            'PUT api/packages/{package}',
        ], $roles->filter(fn (string $r) => str_ends_with($r, ':super_admin,admin'))->keys()->sort()->values()->all());
    }

    public function test_every_admin_role_route_is_a_fresh_sanctum_session(): void
    {
        $this->apiRoutes()
            ->filter(fn (array $m) => self::has($m, EnsureAdminRole::class))
            ->each(function (array $m, string $route) {
                $this->assertContains(self::SANCTUM, $m, $route);
                $this->assertContains(EnsureAdminSessionFresh::class, $m, $route);
            });
    }

    /** ADR-072: each account type's routes live under its own prefix and nowhere else. */
    public function test_portal_and_api_key_routes_carry_the_guard_for_their_prefix(): void
    {
        $prefixes = [
            'api/affiliate/' => [self::PORTAL, EnsureAccountType::class.':affiliate'],
            'api/reseller-portal/' => [self::PORTAL, EnsureAccountType::class.':reseller'],
            'api/reseller/v1/' => [EnsureResellerApiKey::class],
        ];

        foreach ($this->apiRoutes() as $route => $m) {
            $uri = explode(' ', $route)[1];
            foreach ($prefixes as $prefix => $guards) {
                $carriesGuard = in_array(end($guards), $m, true);
                if ($carriesGuard) {
                    $this->assertStringStartsWith($prefix, $uri, "{$route} carries the {$prefix} guard outside its prefix");
                    foreach ($guards as $guard) {
                        $this->assertContains($guard, $m, $route);
                    }
                } elseif (str_starts_with($uri, $prefix)) {
                    // Only a public route (login) or a pinned session route may skip it.
                    $isPublic = ! self::has($m, Authenticate::class) && ! self::has($m, EnsureResellerApiKey::class);
                    $isPortalSession = in_array(self::PORTAL, $m, true) && ! self::has($m, EnsureAccountType::class);
                    $this->assertTrue($isPublic || $isPortalSession, "{$route} is under {$prefix} without its guard");
                }
            }
        }
    }

    /** Laravel's local-disk file routes are reachable only with a signed URL. */
    public function test_the_storage_routes_refuse_an_unsigned_request(): void
    {
        $this->get('/storage/anything.txt')->assertForbidden();
        $this->put('/storage/anything.txt')->assertForbidden();
    }
}
