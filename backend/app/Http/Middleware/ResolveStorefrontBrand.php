<?php

namespace App\Http\Middleware;

use App\Models\AffiliateDomain;
use App\Services\Affiliate\AffiliateDomainStatus;
use App\Support\StorefrontBrand;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ADR-060 (2026-09-06 "domain lifecycle" addendum, section B/D/G):
 * resolves which affiliate brand a public storefront request belongs to.
 *
 * The storefront (`storefront/` on Vercel) calls `api.pekangame.space`
 * directly — from SSR and from the browser — so the visitor's own domain
 * (`shop.acme.com`) never arrives as the HTTP `Host`. The storefront
 * forwards it in `X-Storefront-Host` instead (PR-3 wires the frontend
 * side; PR-2 is the backend half).
 *
 * Trust model (addendum section B): the header is spoofable, but a brand
 * only controls branding display, catalog visibility, and markup — and a
 * third-party affiliate's price is always >= the primary's (they add
 * margin on top), so there is no price arbitrage. Worst case is a
 * mis-attributed `affiliate_id` on an order, which grieves an affiliate's
 * margin for no attacker gain. A future hardening (signed header / trust
 * only the Vercel egress) can tighten this without touching the resolver.
 *
 *  - no header            → nothing set; `StorefrontBrand::get()` lazily
 *                           falls back to `Affiliate::primary()`. Covers
 *                           the primary storefront when it sends no
 *                           header, plus every non-storefront caller.
 *  - header, active row    → that brand (with `subscription.tier` eager
 *                           loaded for checkout pricing).
 *  - header, unknown/……    → 404. Never serve one brand's storefront
 *                           under an unrecognised or suspended host
 *                           (addendum section D / decision 2).
 */
class ResolveStorefrontBrand
{
    public function __construct(private readonly StorefrontBrand $brand) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Persistent-worker safety: never inherit the previous request's
        // brand (Octane, sequential test requests).
        $this->brand->clear();

        $host = $this->normalise($request->header('X-Storefront-Host'));

        if ($host === null) {
            return $next($request);
        }

        $domain = AffiliateDomain::query()
            ->where('hostname', $host)
            ->where('status', AffiliateDomainStatus::Active)
            ->with(['affiliate' => fn ($q) => $q->with('subscription.tier')])
            ->first();

        // A soft-deleted affiliate makes `->affiliate` null (SoftDeletes);
        // a deactivated one (RES-5) is filtered by status here.
        if ($domain?->affiliate === null || $domain->affiliate->status !== 'active') {
            abort(404);
        }

        $this->brand->set($domain->affiliate);

        return $next($request);
    }

    /**
     * Lower-case, strip any `:port`, trim. `www.` is NOT stripped —
     * `www.acme.com` and `acme.com` are distinct `affiliate_domains` rows
     * by design (multi-domain, addendum section E).
     */
    private function normalise(?string $header): ?string
    {
        $host = strtolower(trim((string) $header));

        if ($host === '') {
            return null;
        }

        return explode(':', $host, 2)[0];
    }
}
