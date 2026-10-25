<?php

namespace App\Http\Middleware;

use App\Support\StorefrontBrand;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ADR-126 addendum decision 1: the gate on the `/joki` page's endpoints,
 * like EnsureBrandMembershipEnabled. 403 unless the `Host`-resolved brand
 * may sell joki (`Affiliate::jokiEnabledEffective()`: an owned brand with
 * its own switch and the global one on). Checkout re-checks the same rule
 * in JokiCheckoutService.
 */
class EnsureBrandJokiEnabled
{
    public function __construct(private readonly StorefrontBrand $brand) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->brand->get()->jokiEnabledEffective()) {
            return response()->json(['message' => 'Joki is not available on this store.'], 403);
        }

        return $next($request);
    }
}
