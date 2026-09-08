<?php

namespace App\Http\Middleware;

use App\Support\StorefrontBrand;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ADR-080 decision 1/2 — the single gate for the /membership sales &
 * write surface. Returns 403 when the `Host`-resolved storefront brand
 * (ADR-060, `storefront.brand` middleware; `Affiliate::primary()` with
 * no `X-Storefront-Host`) does not have Membership enabled — i.e.
 * `Affiliate::membershipEnabledEffective()` is false because either the
 * brand's own `membership_enabled` toggle (ADR-061 decision 4/5) or the
 * global `PlatformSettings.membership_enabled` kill switch is off.
 *
 * Applied to: `POST /membership/otp/send`, `POST /membership/otp/verify`,
 * `GET /membership/subscribe-options`, `POST /membership/subscribe` —
 * and always BEFORE each route's `throttle:*` middleware, so a disabled
 * brand's requests fail fast without consuming a rate-limit bucket.
 *
 * Deliberately NOT applied to `GET /membership/plans` (returns `[]` by
 * its own logic — a single contract shape the storefront renders as "no
 * card") or `GET /membership/me` (ADR-080 decision 1: a member's
 * read-only view of their own membership state + order history stays
 * reachable for any still-valid session token, so a temporary global
 * kill switch or a per-brand disable never locks an existing member out
 * of their own record). Both exceptions are visible at the route
 * definition rather than buried in a controller body — the default for
 * a new /membership endpoint is "inside this gate unless there is a
 * reason not to be".
 */
class EnsureBrandMembershipEnabled
{
    public function __construct(private readonly StorefrontBrand $brand) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->brand->get()->membershipEnabledEffective()) {
            return response()->json(['message' => 'Membership is not available.'], 403);
        }

        return $next($request);
    }
}
