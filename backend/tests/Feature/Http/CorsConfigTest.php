<?php

namespace Tests\Feature\Http;

use App\Models\Affiliate;
use App\Models\AffiliateDomain;
use App\Services\Affiliate\AffiliateDomainStatus;
use App\Services\Cors\ActiveCustomDomainOrigins;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `config/cors.php`'s `allowed_origins` has silently broken a browser
 * app twice — storefront/ (checkout, player validation) and affiliate/
 * (every `/api/affiliate/*` read) — because a new Bearer-token Next app
 * on a new port was not added here, and a blocked CORS response never
 * surfaces to the page's own JS. This locks in all three origins so a
 * fourth app (or a config refactor) can't drop one unnoticed.
 *
 * ADR-078 decision 1: it broke a third time — a custom affiliate
 * storefront domain (ADR-060) is never in the static list, so every
 * `"use client"` call from that domain was blocked. `DynamicCorsService`
 * folds the active `affiliate_domains` rows into the allow-list; the
 * rest of this class locks that in.
 */
class CorsConfigTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_three_browser_app_origins_are_allowed(): void
    {
        $origins = config('cors.allowed_origins');

        $this->assertContains('http://localhost:3000', $origins, 'admin/ origin missing');
        $this->assertContains('http://localhost:3001', $origins, 'storefront/ origin missing');
        $this->assertContains('http://localhost:3002', $origins, 'affiliate/ portal origin missing');
    }

    public function test_a_affiliate_portal_preflight_gets_an_allow_origin_header(): void
    {
        $response = $this->call('OPTIONS', '/api/affiliate/dashboard', server: [
            'HTTP_ORIGIN' => 'http://localhost:3002',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
        ]);

        $response->assertHeader('Access-Control-Allow-Origin', 'http://localhost:3002');
    }

    public function test_an_active_custom_affiliate_domain_origin_is_allowed(): void
    {
        $this->makeDomain('shop.brand.com', AffiliateDomainStatus::Active);

        $response = $this->call('OPTIONS', '/api/checkout', server: [
            'HTTP_ORIGIN' => 'https://shop.brand.com',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        ]);

        $response->assertHeader('Access-Control-Allow-Origin', 'https://shop.brand.com');
    }

    public function test_an_actual_request_from_a_custom_domain_gets_the_header(): void
    {
        $this->makeDomain('toko.lain.my', AffiliateDomainStatus::Active);

        $response = $this->getJson('/api/catalog/storefront-status', [
            'Origin' => 'https://toko.lain.my',
        ]);

        $response->assertHeader('Access-Control-Allow-Origin', 'https://toko.lain.my');
    }

    public function test_an_unknown_origin_gets_no_allow_origin_header(): void
    {
        $this->makeDomain('shop.brand.com', AffiliateDomainStatus::Active);

        $response = $this->call('OPTIONS', '/api/checkout', server: [
            'HTTP_ORIGIN' => 'https://evil.example.com',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        ]);

        $this->assertNull($response->headers->get('Access-Control-Allow-Origin'));
    }

    public function test_a_non_active_custom_domain_origin_is_not_allowed(): void
    {
        $this->makeDomain('pending.brand.com', AffiliateDomainStatus::Pending);
        $this->makeDomain('suspended.brand.com', AffiliateDomainStatus::Suspended);
        $this->makeDomain('failed.brand.com', AffiliateDomainStatus::Failed);

        foreach (['pending', 'suspended', 'failed'] as $state) {
            $response = $this->call('OPTIONS', '/api/checkout', server: [
                'HTTP_ORIGIN' => "https://{$state}.brand.com",
                'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            ]);

            $this->assertNull(
                $response->headers->get('Access-Control-Allow-Origin'),
                "{$state} domain must not be a CORS origin",
            );
        }
    }

    public function test_the_origin_list_is_cached_and_flushable(): void
    {
        $origins = $this->app->make(ActiveCustomDomainOrigins::class);

        $this->makeDomain('one.brand.com', AffiliateDomainStatus::Active);
        $this->assertSame(['https://one.brand.com'], $origins->all());

        // Second active row is invisible until the cache is busted.
        $this->makeDomain('two.brand.com', AffiliateDomainStatus::Active);
        $this->assertSame(['https://one.brand.com'], $origins->all());

        $origins->flush();
        $this->assertSame(
            ['https://one.brand.com', 'https://two.brand.com'],
            $origins->all(),
        );
    }

    public function test_credentials_are_never_allowed_for_a_custom_domain(): void
    {
        $this->makeDomain('shop.brand.com', AffiliateDomainStatus::Active);

        $response = $this->getJson('/api/catalog/storefront-status', [
            'Origin' => 'https://shop.brand.com',
        ]);

        $this->assertNull($response->headers->get('Access-Control-Allow-Credentials'));
    }

    private function makeDomain(string $hostname, AffiliateDomainStatus $status): AffiliateDomain
    {
        $affiliate = Affiliate::query()->create([
            'business_name' => 'Brand '.$hostname,
            'markup_pct' => 10,
            'status' => 'active',
        ]);

        return AffiliateDomain::query()->create([
            'affiliate_id' => $affiliate->id,
            'hostname' => $hostname,
            'status' => $status,
            'is_primary' => false,
        ]);
    }
}
