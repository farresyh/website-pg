<?php

namespace App\Services\Affiliate;

/**
 * RES-5 (ADR-058 58b): the admin's activate/deactivate switch on an
 * affiliate, cast on `affiliates.status`. Inactive: every custom domain is
 * suspended (the storefront shows its "store unavailable" page), the portal
 * is read-only (`EnsureAffiliateWritable`), earnings stay withdrawable.
 *
 * Read it through `Affiliate::isActive()`, never by comparing strings
 * (ADR-058 2026-11-16 addendum).
 */
enum AffiliateStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
}
