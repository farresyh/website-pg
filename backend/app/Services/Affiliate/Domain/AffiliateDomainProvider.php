<?php

namespace App\Services\Affiliate\Domain;

/**
 * ADR-060 (2026-09-06 "domain lifecycle" addendum, section B): the one
 * seam to the frontend hosting provider that serves the certificate +
 * routing for an affiliate's custom domain.
 *
 * `provider` is a provider-neutral column on `affiliate_domains` so a
 * future swap needs no migration; today the only implementation is
 * `VercelDomainProvider` (bound in AppServiceProvider, or `NullDomainProvider`
 * when `services.vercel` is unconfigured — local dev / CI / tests).
 *
 * Implementations are stateless request-makers: they NEVER read or write
 * the `affiliate_domains` row. `AffiliateDomainService` owns the row and
 * maps the returned `DomainProviderState` onto it (single writer).
 * Failures throw `DomainProviderException` with an already-sanitised,
 * affiliate-readable message.
 */
interface AffiliateDomainProvider
{
    /**
     * Attach `$hostname` to the storefront project. Returns the
     * provider's view right after creation (usually `verified: false`
     * with a `verification` payload, occasionally `verified: true` when
     * the DNS is already correct).
     *
     * @throws DomainProviderException
     */
    public function attach(string $hostname): DomainProviderState;

    /**
     * Re-read (and re-trigger verification of) `$hostname`. Used by the
     * portal "Check now" button and the daily reconcile command.
     *
     * @throws DomainProviderException
     */
    public function refresh(string $hostname): DomainProviderState;

    /**
     * Remove `$hostname` from the project. Idempotent — a hostname the
     * provider no longer knows about is a success, not an error.
     *
     * @throws DomainProviderException
     */
    public function detach(string $hostname): void;
}
