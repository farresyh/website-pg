<?php

namespace Tests\Feature\Http\Middleware;

use App\Models\Affiliate;
use App\Models\AffiliateBranding;
use App\Models\AffiliateDomain;
use App\Models\AffiliateSeoSettings;
use App\Models\PlatformSettings;
use App\Services\Affiliate\AffiliateDomainStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * ADR-060 (2026-09-06 "domain lifecycle" addendum) PR-2: the storefront
 * calls `api.pekangame.space` directly, so the visitor's own domain
 * arrives in `X-Storefront-Host`, not the HTTP `Host`. `ResolveStorefrontBrand`
 * turns that header into the right `Affiliate` for branding / SEO /
 * membership — and 404s an unknown or non-servable host rather than
 * serving one brand under another's domain (decision 2).
 */
class ResolveStorefrontBrandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(['next-api.useplunk.com/*' => Http::response(['success' => true], 200)]);

        AffiliateBranding::query()->create([
            'affiliate_id' => $this->primaryAffiliate()->id,
            'store_name' => 'PekanGame',
        ]);
    }

    /**
     * @return array{0: Affiliate, 1: AffiliateDomain}
     */
    private function brandWithDomain(string $hostname, array $affiliateOverrides = [], array $domainOverrides = []): array
    {
        $affiliate = Affiliate::query()->create(array_merge([
            'business_name' => 'Acme Resell',
            'markup_pct' => 10,
            'status' => 'active',
        ], $affiliateOverrides));

        AffiliateBranding::query()->create([
            'affiliate_id' => $affiliate->id,
            'store_name' => 'Acme Store',
        ]);

        $domain = AffiliateDomain::query()->create(array_merge([
            'affiliate_id' => $affiliate->id,
            'hostname' => $hostname,
            'status' => AffiliateDomainStatus::Active,
            'is_primary' => true,
        ], $domainOverrides));

        return [$affiliate, $domain];
    }

    public function test_no_header_falls_back_to_the_primary_brand(): void
    {
        $this->brandWithDomain('shop.acme.com');

        $this->getJson('/api/catalog/branding')
            ->assertOk()
            ->assertJsonPath('store_name', 'PekanGame');
    }

    public function test_a_known_active_host_resolves_that_brand(): void
    {
        $this->brandWithDomain('shop.acme.com');

        $this->getJson('/api/catalog/branding', ['X-Storefront-Host' => 'shop.acme.com'])
            ->assertOk()
            ->assertJsonPath('store_name', 'Acme Store');
    }

    public function test_a_configured_primary_host_resolves_to_the_primary_with_no_domain_row(): void
    {
        config(['services.storefront.primary_hosts' => ['pekangame.space', 'www.pekangame.space']]);
        $this->brandWithDomain('shop.acme.com'); // a different, unrelated brand

        $this->getJson('/api/catalog/branding', ['X-Storefront-Host' => 'www.pekangame.space'])
            ->assertOk()
            ->assertJsonPath('store_name', 'PekanGame');

        // No affiliate_domains row exists for either primary host.
        $this->assertDatabaseMissing('affiliate_domains', ['hostname' => 'pekangame.space']);
    }

    public function test_a_configured_primary_host_wins_over_a_domain_row_of_the_same_name(): void
    {
        config(['services.storefront.primary_hosts' => ['localhost']]);
        // even if somehow a row exists, the config short-circuit runs first
        $this->brandWithDomain('localhost');

        $this->getJson('/api/catalog/branding', ['X-Storefront-Host' => 'localhost'])
            ->assertOk()
            ->assertJsonPath('store_name', 'PekanGame');
    }

    public function test_the_host_header_is_lower_cased_and_port_stripped(): void
    {
        $this->brandWithDomain('shop.acme.com');

        $this->getJson('/api/catalog/branding', ['X-Storefront-Host' => 'SHOP.ACME.COM:443'])
            ->assertOk()
            ->assertJsonPath('store_name', 'Acme Store');
    }

    public function test_an_unknown_host_404s(): void
    {
        $this->brandWithDomain('shop.acme.com');

        $this->getJson('/api/catalog/branding', ['X-Storefront-Host' => 'not-a-brand.example'])
            ->assertNotFound();
    }

    public function test_an_unknown_host_404_carries_the_coded_body(): void
    {
        $this->getJson('/api/catalog/storefront-status', ['X-Storefront-Host' => 'not-a-brand.example'])
            ->assertNotFound()
            ->assertJsonPath('code', 'unknown_storefront_host');
    }

    public function test_storefront_status_is_ok_for_a_known_host(): void
    {
        $this->brandWithDomain('shop.acme.com');

        $this->getJson('/api/catalog/storefront-status', ['X-Storefront-Host' => 'shop.acme.com'])
            ->assertOk()
            ->assertJsonPath('ok', true);
    }

    public function test_a_pending_domain_404s(): void
    {
        $this->brandWithDomain('shop.acme.com', domainOverrides: ['status' => AffiliateDomainStatus::Pending]);

        $this->getJson('/api/catalog/branding', ['X-Storefront-Host' => 'shop.acme.com'])
            ->assertNotFound();
    }

    public function test_a_suspended_domain_404s(): void
    {
        $this->brandWithDomain('shop.acme.com', domainOverrides: ['status' => AffiliateDomainStatus::Suspended]);

        $this->getJson('/api/catalog/branding', ['X-Storefront-Host' => 'shop.acme.com'])
            ->assertNotFound();
    }

    public function test_an_active_domain_of_a_deactivated_affiliate_404s(): void
    {
        $this->brandWithDomain('shop.acme.com', affiliateOverrides: ['status' => 'deactivated']);

        $this->getJson('/api/catalog/branding', ['X-Storefront-Host' => 'shop.acme.com'])
            ->assertNotFound();
    }

    public function test_a_domain_of_a_soft_deleted_affiliate_404s(): void
    {
        [$affiliate] = $this->brandWithDomain('shop.acme.com');
        $affiliate->delete();

        $this->getJson('/api/catalog/branding', ['X-Storefront-Host' => 'shop.acme.com'])
            ->assertNotFound();
    }

    public function test_seo_settings_resolve_per_brand_with_no_cross_tenant_bleed(): void
    {
        AffiliateSeoSettings::query()->create([
            'affiliate_id' => $this->primaryAffiliate()->id,
            'fb_pixel_id' => 'PRIMARY-PIXEL',
        ]);
        [$acme] = $this->brandWithDomain('shop.acme.com');
        AffiliateSeoSettings::query()->create([
            'affiliate_id' => $acme->id,
            'fb_pixel_id' => 'ACME-PIXEL',
        ]);

        $this->getJson('/api/catalog/seo/settings', ['X-Storefront-Host' => 'shop.acme.com'])
            ->assertOk()
            ->assertJsonPath('fb_pixel_id', 'ACME-PIXEL');

        $this->getJson('/api/catalog/seo/settings')
            ->assertOk()
            ->assertJsonPath('fb_pixel_id', 'PRIMARY-PIXEL');
    }

    public function test_membership_otp_is_issued_for_the_resolved_brand(): void
    {
        // ADR-080 decision 2: OTP send is gated on
        // `membershipEnabledEffective()` — enable it for this brand.
        [$acme] = $this->brandWithDomain('shop.acme.com', affiliateOverrides: ['is_owned' => true, 'membership_enabled' => true]);
        PlatformSettings::current()->update(['membership_enabled' => true]);

        $this->postJson(
            '/api/membership/otp/send',
            ['email' => 'buyer@example.com'],
            ['X-Storefront-Host' => 'shop.acme.com'],
        )->assertOk();

        $this->assertDatabaseHas('membership_otp_codes', [
            'email' => 'buyer@example.com',
            'affiliate_id' => $acme->id,
        ]);
    }
}
