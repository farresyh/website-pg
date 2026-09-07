<?php

namespace Tests\Feature\Observers;

use App\Models\Affiliate;
use App\Models\AffiliateMembershipTier;
use App\Models\AffiliateSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class AffiliateMembershipObserverTest extends TestCase
{
    use RefreshDatabase;

    public function test_subscription_changes_invalidate_cache(): void
    {
        $affiliate = Affiliate::query()->create(['business_name' => 'Brand A', 'markup_pct' => 5]);
        $tier = AffiliateMembershipTier::query()->create(['name' => 'Gold', 'monthly_fee_sen' => 5000, 'markup_percent' => 5]);

        // Put something in cache
        Cache::tags(['catalog.index', "catalog.index.brand.{$affiliate->id}"])->put('test_index_key', 'value');
        Cache::store(config('cache.catalog_packages_store'))->tags(["catalog.packages.brand.{$affiliate->id}"])->put('test_pkg_key', 'value');

        // Trigger saved event
        $sub = AffiliateSubscription::query()->create([
            'affiliate_id' => $affiliate->id,
            'affiliate_membership_tier_id' => $tier->id,
            'status' => 'active',
            'current_period_started_at' => now(),
            'next_charge_at' => now()->addMonth(),
        ]);

        // Check if cache is cleared
        $this->assertNull(Cache::tags(['catalog.index', "catalog.index.brand.{$affiliate->id}"])->get('test_index_key'));
        $this->assertNull(Cache::store(config('cache.catalog_packages_store'))->tags(["catalog.packages.brand.{$affiliate->id}"])->get('test_pkg_key'));

        // Put something again
        Cache::tags(['catalog.index', "catalog.index.brand.{$affiliate->id}"])->put('test_index_key2', 'value');
        
        $sub->update(['status' => 'lapsed']);

        $this->assertNull(Cache::tags(['catalog.index', "catalog.index.brand.{$affiliate->id}"])->get('test_index_key2'));
    }

    public function test_tier_changes_invalidate_cache_for_subscribed_affiliates(): void
    {
        $affiliate1 = Affiliate::query()->create(['business_name' => 'Brand 1', 'markup_pct' => 5]);
        $affiliate2 = Affiliate::query()->create(['business_name' => 'Brand 2', 'markup_pct' => 5]);
        $tier = AffiliateMembershipTier::query()->create(['name' => 'Gold', 'monthly_fee_sen' => 5000, 'markup_percent' => 5]);

        AffiliateSubscription::query()->create([
            'affiliate_id' => $affiliate1->id,
            'affiliate_membership_tier_id' => $tier->id,
            'status' => 'active',
            'current_period_started_at' => now(),
            'next_charge_at' => now()->addMonth(),
        ]);

        Cache::tags(['catalog.index', "catalog.index.brand.{$affiliate1->id}"])->put('test_1', 'value');
        Cache::tags(['catalog.index', "catalog.index.brand.{$affiliate2->id}"])->put('test_2', 'value');

        // Update the tier
        $tier->update(['markup_percent' => 6]);

        // Affiliate 1 (subscribed) should be flushed
        $this->assertNull(Cache::tags(['catalog.index', "catalog.index.brand.{$affiliate1->id}"])->get('test_1'));
        // Affiliate 2 (not subscribed) should not be flushed
        $this->assertSame('value', Cache::tags(['catalog.index', "catalog.index.brand.{$affiliate2->id}"])->get('test_2'));
    }
}
