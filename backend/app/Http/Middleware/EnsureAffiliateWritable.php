<?php

namespace App\Http\Middleware;

use App\Models\AffiliateUser;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * RES-5 (ADR-058 58b, 2026-11-16 addendum): a deactivated affiliate's portal
 * is read-only. Applied to the whole affiliate portal group, so every write
 * route is covered by default, including routes added later. A route that
 * writes nothing of the affiliate's own (the pricing preview, ending an
 * impersonation) opts out with `withoutMiddleware('affiliate.writable')`,
 * and `RouteAccessTest` pins that list.
 *
 * A lapsed subscription is a different thing (it re-prices the storefront,
 * ADR-056) and never freezes the portal.
 */
class EnsureAffiliateWritable
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethodSafe()) {
            return $next($request);
        }

        $user = $request->user();
        $affiliate = $user instanceof AffiliateUser ? $user->affiliateOwner() : null;

        if ($affiliate === null || ! $affiliate->isActive()) {
            throw new HttpException(403, 'Your account is not active.');
        }

        return $next($request);
    }
}
