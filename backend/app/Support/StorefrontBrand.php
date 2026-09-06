<?php

namespace App\Support;

use App\Models\Affiliate;

/**
 * ADR-060 (2026-09-06 "domain lifecycle" addendum, section B): the
 * request-scoped "which affiliate brand is this storefront request for".
 *
 * Bound `scoped` in AppServiceProvider — reset between HTTP requests and
 * between queue jobs, never a process-lifetime singleton.
 *
 * `ResolveStorefrontBrand` middleware calls `set()` once per request
 * after resolving the `X-Storefront-Host` header against `affiliate_domains`
 * (the storefront calls `api.pekangame.space` directly from both SSR and
 * the browser, so the affiliate's own domain never reaches the backend as
 * the HTTP `Host` — it is forwarded in this explicit header instead).
 *
 * When the middleware did not run, or ran with no header (console, queue,
 * tests, a non-storefront caller, Postman), `get()` lazily falls back to
 * `Affiliate::primary()` — the exact behaviour every call site had before
 * this seam (ADR-061 `Affiliate::primary()`).
 */
class StorefrontBrand
{
    private ?Affiliate $brand = null;

    public function set(Affiliate $brand): void
    {
        $this->brand = $brand;
    }

    /**
     * Reset to "unresolved". `ResolveStorefrontBrand` calls this at the
     * top of every request so a persistent-worker context (Octane, and
     * sequential requests inside one test) can't carry one request's
     * brand into the next — same discipline `SetAffiliateContext` uses
     * for `CurrentAffiliate`.
     */
    public function clear(): void
    {
        $this->brand = null;
    }

    public function isResolved(): bool
    {
        return $this->brand !== null;
    }

    public function get(): Affiliate
    {
        return $this->brand ??= Affiliate::primary();
    }
}
