<?php

namespace App\Http\Controllers\Affiliate\Concerns;

/**
 * ADR-060 PR-5/PR-6: a deactivated (RES-5) affiliate's portal is
 * read-only. `affiliate.status` is the RES-5 admin flag — a *lapsed
 * subscription* is a separate concept (it re-prices the storefront, per
 * ADR-056, but never freezes the portal), so this guard deliberately
 * only checks `status === 'active'`.
 *
 * Extracted from `DomainController`'s original private method so every
 * PR-6 storefront-config controller shares one definition.
 */
trait AssertsAffiliateWritable
{
    private function assertWritable(object $affiliate): void
    {
        abort_if($affiliate->status !== 'active', 403, 'Your account is not active.');
    }
}
