<?php

namespace Tests\Feature\Http\Controllers;

use App\Models\AffiliateBranding;
use App\Models\AffiliateFooterSettings;
use App\Models\Game;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-028 + its 2026-08-22 addendum — public, guest-callable branding/
 * footer/legal endpoints (ADR-011, same no-auth reasoning as
 * CatalogController/HeroSlideController).
 */
class BrandingControllerTest extends TestCase
{
    use RefreshDatabase;

    private function seedBrandingAndFooter(array $brandingOverrides = [], array $footerOverrides = []): void
    {
        $affiliate = $this->primaryAffiliate();

        AffiliateBranding::query()->create(array_merge([
            'affiliate_id' => $affiliate->id,
            'store_name' => 'PekanGame',
        ], $brandingOverrides));

        AffiliateFooterSettings::query()->create(array_merge([
            'affiliate_id' => $affiliate->id,
        ], $footerOverrides));
    }

    public function test_show_returns_branding_and_substitutes_store_name_in_footer_text(): void
    {
        $this->seedBrandingAndFooter([], ['footer_text' => '© 2026 {store_name}. All rights reserved.']);

        $response = $this->getJson('/api/catalog/branding');

        $response->assertOk();
        $this->assertSame('PekanGame', $response->json('store_name'));
        $this->assertSame('© 2026 PekanGame. All rights reserved.', $response->json('footer_text'));
    }

    public function test_show_defaults_favicon_and_theme_mode_when_unset(): void
    {
        $this->seedBrandingAndFooter();

        $response = $this->getJson('/api/catalog/branding');

        $response->assertOk();
        $this->assertNull($response->json('favicon_url'));
        $this->assertSame('light', $response->json('theme_mode'));
    }

    public function test_show_resolves_footer_games_in_the_stored_order(): void
    {
        $affiliate = $this->primaryAffiliate();
        $ml = Game::query()->create(['name' => 'Mobile Legends', 'slug' => 'mobile-legends', 'is_active' => true]);
        $ff = Game::query()->create(['name' => 'Free Fire', 'slug' => 'free-fire', 'is_active' => true]);

        AffiliateBranding::query()->create(['affiliate_id' => $affiliate->id, 'store_name' => 'KRS']);
        AffiliateFooterSettings::query()->create(['affiliate_id' => $affiliate->id, 'footer_game_ids' => [$ff->id, $ml->id]]);

        $response = $this->getJson('/api/catalog/branding');

        $response->assertOk();
        $this->assertSame(['Free Fire', 'Mobile Legends'], collect($response->json('footer_games'))->pluck('name')->all());
    }

    /**
     * Regression test for a real bug found during manual browser
     * verification, 2026-08-22: `BrandingController::show()` originally
     * cached a `Collection` of `Game` models directly inside the
     * `Cache::remember()` payload — a documented violation of backend/
     * CLAUDE.md's own "Cache::remember() values must be plain arrays"
     * convention (ADR-014's addendum). The database cache driver
     * silently corrupted it on the *next* read (`__PHP_Incomplete_Class`
     * on unserialize), so only the very first request after a cache
     * miss ever returned the correct footer games — every request
     * after that, for the rest of the 60s TTL, returned a truncated
     * list. A single-request test never caught this; this one
     * deliberately calls twice to exercise the cache-HIT path.
     */
    public function test_show_returns_the_same_footer_games_on_a_cached_second_request(): void
    {
        $affiliate = $this->primaryAffiliate();
        $ml = Game::query()->create(['name' => 'Mobile Legends', 'slug' => 'mobile-legends', 'is_active' => true]);
        $ff = Game::query()->create(['name' => 'Free Fire', 'slug' => 'free-fire', 'is_active' => true]);

        AffiliateBranding::query()->create(['affiliate_id' => $affiliate->id, 'store_name' => 'KRS']);
        AffiliateFooterSettings::query()->create(['affiliate_id' => $affiliate->id, 'footer_game_ids' => [$ff->id, $ml->id]]);

        $first = $this->getJson('/api/catalog/branding');
        $second = $this->getJson('/api/catalog/branding');

        $first->assertOk();
        $second->assertOk();
        $this->assertCount(2, $first->json('footer_games'));
        $this->assertCount(2, $second->json('footer_games'));
        $this->assertSame($first->json('footer_games'), $second->json('footer_games'));
    }

    public function test_show_falls_back_to_affiliate_business_name_when_branding_is_not_set(): void
    {
        $this->primaryAffiliate();

        $response = $this->getJson('/api/catalog/branding');

        $response->assertOk();
        $this->assertSame('Platform Owner', $response->json('store_name'));
        $this->assertSame([], $response->json('footer_games'));
    }

    /**
     * Regression test, 2026-08-28: `show()` used to default a missing
     * `social_links` to `[]`, which `json_encode`s as a JSON *array*.
     * The storefront's zod schema expects an object (or `null`) — an
     * empty array failed validation on every request with no branding
     * saved yet, logged repeatedly via `/client-errors`. See
     * `docs/prd.md` §14's 2026-08-27 addendum for how this was found.
     */
    public function test_show_returns_null_social_links_when_not_set(): void
    {
        $this->seedBrandingAndFooter();

        $response = $this->getJson('/api/catalog/branding');

        $response->assertOk();
        $this->assertNull($response->json('social_links'));
    }

    public function test_legal_returns_sanitized_content_with_store_name_substituted(): void
    {
        $this->seedBrandingAndFooter([], ['terms_content' => '<p>Welcome to {store_name}</p><script>alert(1)</script>']);

        $response = $this->getJson('/api/catalog/legal/terms');

        $response->assertOk();
        $this->assertStringContainsString('Welcome to PekanGame', $response->json('content'));
        $this->assertStringNotContainsString('<script>', $response->json('content'));
    }

    public function test_legal_returns_404_for_an_unknown_page(): void
    {
        $this->getJson('/api/catalog/legal/refund-policy')->assertNotFound();
    }

    public function test_legal_returns_null_content_when_nothing_saved_yet(): void
    {
        $this->primaryAffiliate();

        $response = $this->getJson('/api/catalog/legal/privacy');

        $response->assertOk();
        $this->assertNull($response->json('content'));
    }

    public function test_legal_substitutes_resolved_affiliate_store_name_when_x_storefront_host_is_provided(): void
    {
        $primary = $this->primaryAffiliate();
        AffiliateBranding::query()->create([
            'affiliate_id' => $primary->id,
            'store_name' => 'PekanGame',
        ]);
        AffiliateFooterSettings::query()->create([
            'affiliate_id' => $primary->id,
            'terms_content' => '<p>Welcome to {store_name}</p>',
        ]);

        $affiliate = \App\Models\Affiliate::query()->create([
            'business_name' => 'FixFast',
            'is_primary' => false,
            'status' => 'active',
            'markup_pct' => 0,
        ]);
        AffiliateBranding::query()->create([
            'affiliate_id' => $affiliate->id,
            'store_name' => 'FixFast',
        ]);
        \App\Models\AffiliateDomain::query()->create([
            'affiliate_id' => $affiliate->id,
            'hostname' => 'fixfastapp.com',
            'status' => \App\Services\Affiliate\AffiliateDomainStatus::Active,
        ]);

        $response = $this->withHeaders(['X-Storefront-Host' => 'fixfastapp.com'])
            ->getJson('/api/catalog/legal/terms');

        $response->assertOk();
        $this->assertStringContainsString('Welcome to FixFast', $response->json('content'));
        $this->assertStringNotContainsString('PekanGame', $response->json('content'));
    }
}
