<?php

namespace App\Http\Middleware;

use App\Models\Affiliate;
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
 *  - no header                    → nothing set; `StorefrontBrand::get()`
 *                                   lazily falls back to `Affiliate::primary()`
 *                                   (non-storefront callers, console, tests).
 *  - header in `primary_hosts`     → `Affiliate::primary()`, no DB lookup.
 *                                   Our own apex/`www` (and `localhost` in
 *                                   dev / E2E) are deploy config
 *                                   (`STOREFRONT_PRIMARY_HOSTS`), never rows
 *                                   in `affiliate_domains` — "our infra
 *                                   hostnames are config; customer domains
 *                                   are data" (PR-3 addendum).
 *  - header, active `affiliate_domains` row → that brand (with
 *                                   `subscription.tier` eager-loaded for
 *                                   PR-4 pricing).
 *  - header, unknown / suspended / deactivated / soft-deleted → 404. Never
 *                                   serve one brand's storefront under an
 *                                   unrecognised host (decision 2).
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

        // The primary brand's own hostnames are deploy config, not rows.
        if (in_array($host, config('services.storefront.primary_hosts'), true)) {
            $this->brand->set(Affiliate::primary());

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
            // A coded body so the storefront `proxy.ts` (ADR-060 PR-5)
            // can tell "unknown storefront host" apart from any other
            // 404 and show its hard "store unavailable" page.
            abort(response()->json([
                'code' => 'unknown_storefront_host',
                'message' => 'This storefront address is not recognised.',
            ], 404));
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
