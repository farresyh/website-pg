<?php

namespace App\Observers;

use App\Http\Controllers\CatalogController;
use App\Models\AffiliateSubscription;

final class AffiliateSubscriptionObserver
{
    public function saved(AffiliateSubscription $subscription): void
    {
        CatalogController::forgetCacheForBrand($subscription->affiliate_id);
    }

    public function deleted(AffiliateSubscription $subscription): void
    {
        CatalogController::forgetCacheForBrand($subscription->affiliate_id);
    }
}
