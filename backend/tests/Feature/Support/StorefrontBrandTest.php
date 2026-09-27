<?php

namespace Tests\Feature\Support;

use App\Models\Affiliate;
use App\Models\AffiliateBranding;
use App\Support\StorefrontBrand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Item 39 (2026-09-27 money-critical audit) — `displayName()` is what
 * CHIP's purchase description now uses instead of a hardcoded
 * `'PekanGame'` (CheckoutService / MembershipSubscriptionService), so an
 * affiliate whitelabel storefront's own store name shows on its members'
 * CHIP checkout page/receipt.
 */
class StorefrontBrandTest extends TestCase
{
    use RefreshDatabase;

    public function test_display_name_reads_the_resolved_affiliates_own_store_name(): void
    {
        $affiliate = Affiliate::query()->create([
            'business_name' => 'Acme Resell', 'markup_pct' => 10, 'status' => 'active',
        ]);
        AffiliateBranding::query()->create(['affiliate_id' => $affiliate->id, 'store_name' => 'Acme Store']);

        $brand = new StorefrontBrand;
        $brand->set($affiliate);

        $this->assertSame('Acme Store', $brand->displayName());
    }

    public function test_display_name_falls_back_to_pekangame_when_no_branding_row_exists(): void
    {
        $affiliate = Affiliate::query()->create([
            'business_name' => 'No Branding Yet', 'markup_pct' => 10, 'status' => 'active',
        ]);

        $brand = new StorefrontBrand;
        $brand->set($affiliate);

        $this->assertSame('PekanGame', $brand->displayName());
    }
}
