<?php

namespace App\Services\Affiliate\Domain;

/**
 * ADR-060 (2026-09-06 "domain lifecycle" addendum, section B/H): the
 * provider's current view of one custom domain, as returned by
 * `AffiliateDomainProvider::attach()` / `refresh()`.
 *
 * The provider never touches the `affiliate_domains` row — it reports
 * state and `AffiliateDomainService` maps it onto the row (single
 * writer). `verified` drives `pending` → `active`; `missing` (the
 * hostname is not attached to the project at all) drives `→ failed`.
 */
final class DomainProviderState
{
    public function __construct(
        /** The provider's identifier for this domain-on-project (the hostname, for Vercel). */
        public readonly string $providerRef,
        /** Certificate issued + DNS verified — the storefront may serve this brand here. */
        public readonly bool $verified,
        /**
         * The DNS records the affiliate still has to set, when the
         * provider requires an explicit ownership TXT (rare — only when
         * the hostname is already attached elsewhere on the provider).
         *
         * @var array<int, array<string, mixed>>|null
         */
        public readonly ?array $verification = null,
        /** The provider has no record of this hostname on the project (deleted, never created). */
        public readonly bool $missing = false,
    ) {}
}
