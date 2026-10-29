<?php

namespace App\Support;

use App\Models\Affiliate;
use App\Models\AffiliateBranding;

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
 *
 * Also carries the literal `X-Storefront-Host` the middleware verified for
 * this request (bug fix, 2026-09-24): any redirect built back OUT to the
 * storefront (CHIP's `success_return_url`/`failure_return_url` — see
 * `CheckoutService`/`MembershipSubscriptionService`) must land the customer
 * on the SAME domain they checked out from, not the platform default —
 * otherwise a `fixfastapp.com` order redirects to `pekangame.space`, whose
 * `/track-order` is itself brand-scoped and 404s on that order.
 */
class StorefrontBrand
{
    private ?Affiliate $brand = null;

    private ?string $hostname = null;

    private bool $paymentsApproved = true;

    /** @param  bool  $paymentsApproved  ADR-131: only meaningful with a hostname (a custom domain row) */
    public function set(Affiliate $brand, ?string $hostname = null, bool $paymentsApproved = true): void
    {
        $this->brand = $brand;
        $this->hostname = $hostname;
        $this->paymentsApproved = $paymentsApproved;
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
        $this->hostname = null;
        $this->paymentsApproved = true;
    }

    /**
     * ADR-131 decision 19: may this request start a CHIP payment? Only a resolved custom domain
     * can say no. The primary host resolves with no hostname, and so does every non-storefront
     * caller (console, queue, the API and bot order channels): they are never gated.
     */
    public function paymentsEnabled(): bool
    {
        return $this->hostname === null || $this->paymentsApproved;
    }

    /** @throws PaymentsNotEnabledException */
    public function assertPaymentsEnabled(): void
    {
        if (! $this->paymentsEnabled()) {
            throw new PaymentsNotEnabledException;
        }
    }

    public function isResolved(): bool
    {
        return $this->brand !== null;
    }

    public function get(): Affiliate
    {
        return $this->brand ??= Affiliate::primary();
    }

    /**
     * The storefront origin to redirect back to for this request: the
     * literal verified `X-Storefront-Host` when one was resolved, else the
     * platform default (`STOREFRONT_URL`) — no header ever reaches here
     * outside a real storefront request (console, queue, tests, bot/API
     * order channels), so the default is the correct answer there too.
     */
    public function url(): string
    {
        if ($this->hostname !== null) {
            return 'https://'.$this->hostname;
        }

        return rtrim((string) config('services.storefront.url'), '/');
    }

    /**
     * The customer-facing brand name for this request — `AffiliateBranding`
     * is a separate table (not an eager-loadable relation, ADR-028), so this
     * is the one place that resolves it, rather than every payment-facing
     * caller (CHIP purchase description, etc.) repeating the query. Falls
     * back to `'PekanGame'` for the primary affiliate or an affiliate with
     * no `store_name` set yet.
     */
    public function displayName(): string
    {
        return AffiliateBranding::query()
            ->where('affiliate_id', $this->get()->id)
            ->value('store_name') ?? 'PekanGame';
    }
}
