<?php

namespace App\Services\Affiliate;

/**
 * ADR-060 (2026-09-06 "domain lifecycle" addendum, section A + G): the
 * lifecycle of one custom domain attached to an affiliate's branded
 * storefront.
 *
 *  - Pending    added in the portal, waiting on DNS + the frontend
 *               provider's own verification. The storefront does NOT
 *               serve this brand on this hostname yet.
 *  - Active     verified, certificate issued — the `Host` resolver
 *               serves the brand here.
 *  - Failed     verification never completed (14-day stuck-pending
 *               sweep) or the DNS record was removed after going active.
 *  - Suspended  the affiliate was deactivated (RES-5) — the row is kept
 *               but the storefront returns 503 for this Host.
 *
 * Only `Active` is servable. The seeded rows for the primary affiliate's
 * own domain(s) are `Active` with a null `provider_ref` — every
 * provider-side lifecycle step (PR-5) skips a null-`provider_ref` row.
 */
enum AffiliateDomainStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Failed = 'failed';
    case Suspended = 'suspended';

    public function isServable(): bool
    {
        return $this === self::Active;
    }
}
