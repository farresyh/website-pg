<?php

namespace App\Observers;

use App\Http\Controllers\CatalogController;
use App\Models\AffiliateMembershipTier;
use App\Models\AffiliateSubscription;

final class AffiliateMembershipTierObserver
{
    public function saved(AffiliateMembershipTier $tier): void
    {
        $this->flushAffiliates($tier->id);
    }

    public function deleted(AffiliateMembershipTier $tier): void
    {
        $this->flushAffiliates($tier->id);
    }

    private function flushAffiliates(int $tierId): void
    {
        $affiliateIds = AffiliateSubscription::query()
            ->where('affiliate_membership_tier_id', $tierId)
            ->pluck('affiliate_id');

        foreach ($affiliateIds as $affiliateId) {
            CatalogController::forgetCacheForBrand($affiliateId);
        }
    }
}
