<?php

namespace Tests\Feature\Support;

use App\Models\Affiliate;
use App\Models\AffiliateBranding;
use App\Models\AffiliateDomain;
use App\Support\BrandIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** ADR-068 2026-10-13 addendum, decision 13: one brand lookup for email and WhatsApp. */
class BrandIdentityTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_affiliate_brand_uses_its_store_name_logo_and_active_domain(): void
    {
        $affiliate = Affiliate::query()->create([
            'business_name' => 'Ohaha Store', 'markup_pct' => 0, 'status' => 'active', 'is_owned' => true,
        ]);
        AffiliateBranding::withoutAffiliateScope()->create([
            'affiliate_id' => $affiliate->id, 'store_name' => 'FixFast', 'logo_path' => "affiliate-logos/{$affiliate->id}.webp",
        ]);
        AffiliateDomain::withoutAffiliateScope()->create([
            'affiliate_id' => $affiliate->id, 'hostname' => 'fixfastapp.com', 'status' => 'active', 'is_primary' => true,
        ]);

        $brand = BrandIdentity::of($affiliate->id);

        $this->assertSame('FixFast', $brand->name);
        $this->assertStringContainsString("affiliate-logos/{$affiliate->id}.webp", (string) $brand->logoUrl);
        $this->assertSame('https://fixfastapp.com', $brand->origin);
        $this->assertSame('fixfastapp.com', $brand->host());
    }

    public function test_a_brand_without_a_store_name_falls_back_to_its_business_name(): void
    {
        $affiliate = Affiliate::query()->create([
            'business_name' => 'Ohaha Store', 'markup_pct' => 0, 'status' => 'active', 'is_owned' => true,
        ]);

        $brand = BrandIdentity::of($affiliate->id);

        $this->assertSame('Ohaha Store', $brand->name);
        $this->assertNull($brand->logoUrl);
    }

    public function test_no_affiliate_is_the_platform_brand_on_the_storefront_url(): void
    {
        config(['services.storefront.url' => 'https://pekangame.com/']);

        $brand = BrandIdentity::of(null);

        $this->assertSame('PekanGame', $brand->name);
        $this->assertSame('https://pekangame.com', $brand->origin);
    }
}
