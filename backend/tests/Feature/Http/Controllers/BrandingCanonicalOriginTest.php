<?php

namespace Tests\Feature\Http\Controllers;

use App\Models\Affiliate;
use App\Models\AffiliateBranding;
use App\Models\AffiliateDomain;
use App\Services\Affiliate\AffiliateDomainStatus;
use App\Services\Affiliate\Domain\AffiliateDomainService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * ADR-120 decisions 2–3: the branding payload carries the brand's
 * `canonical_origin` — the origin every storefront canonical tag,
 * sitemap, robots `Sitemap:` line, llms.txt and JSON-LD URL is built
 * from. An alias (`www.`) must never become its own canonical.
 */
class BrandingCanonicalOriginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        config(['services.storefront.url' => 'https://pekangame.com']);

        AffiliateBranding::query()->create([
            'affiliate_id' => $this->primaryAffiliate()->id,
            'store_name' => 'PekanGame',
        ]);
    }

    private function affiliate(): Affiliate
    {
        $affiliate = Affiliate::query()->create([
            'business_name' => 'Acme Resell',
            'markup_pct' => 10,
            'status' => 'active',
        ]);
        AffiliateBranding::query()->create(['affiliate_id' => $affiliate->id, 'store_name' => 'Acme']);

        return $affiliate;
    }

    private function domain(Affiliate $affiliate, string $hostname, bool $primary, AffiliateDomainStatus $status = AffiliateDomainStatus::Active): AffiliateDomain
    {
        return AffiliateDomain::query()->create([
            'affiliate_id' => $affiliate->id,
            'hostname' => $hostname,
            'status' => $status,
            'is_primary' => $primary,
        ]);
    }

    public function test_the_platform_brand_uses_the_configured_storefront_url(): void
    {
        $this->getJson('/api/catalog/branding')
            ->assertOk()
            ->assertJsonPath('canonical_origin', 'https://pekangame.com');
    }

    public function test_an_alias_host_reports_the_affiliates_primary_domain(): void
    {
        $affiliate = $this->affiliate();
        $this->domain($affiliate, 'acme.com', true);
        $this->domain($affiliate, 'www.acme.com', false);

        $this->getJson('/api/catalog/branding', ['X-Storefront-Host' => 'www.acme.com'])
            ->assertOk()
            ->assertJsonPath('canonical_origin', 'https://acme.com');
    }

    public function test_an_inactive_primary_flag_falls_back_to_an_active_domain(): void
    {
        $affiliate = $this->affiliate();
        $this->domain($affiliate, 'old.acme.com', true, AffiliateDomainStatus::Suspended);
        $this->domain($affiliate, 'shop.acme.com', false);

        $this->assertSame('https://shop.acme.com', $affiliate->canonicalOrigin());
    }

    public function test_an_affiliate_with_no_active_domain_falls_back_to_the_platform(): void
    {
        $this->assertSame('https://pekangame.com', $this->affiliate()->canonicalOrigin());
    }

    public function test_re_pointing_the_primary_busts_the_cached_origin(): void
    {
        $affiliate = $this->affiliate();
        $this->domain($affiliate, 'acme.com', true);
        $www = $this->domain($affiliate, 'www.acme.com', false);

        $this->getJson('/api/catalog/branding', ['X-Storefront-Host' => 'acme.com'])
            ->assertJsonPath('canonical_origin', 'https://acme.com');

        app(AffiliateDomainService::class)->setPrimary($www);

        $this->getJson('/api/catalog/branding', ['X-Storefront-Host' => 'acme.com'])
            ->assertJsonPath('canonical_origin', 'https://www.acme.com');
    }
}
