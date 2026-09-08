<?php

namespace Tests\Feature\Http\Controllers;

use App\Http\Controllers\SeoController;
use App\Models\Affiliate;
use App\Models\AffiliateSeoSettings;
use App\Models\CrawlerRule;
use App\Models\Redirect;
use App\Models\SeoScript;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * ADR-029: public, guest-callable SEO data consumed by the storefront
 * (generateMetadata(), middleware.ts's redirect cache, script injection,
 * app/robots.ts). No admin auth on any of these routes, mirroring
 * BrandingControllerTest's same no-auth reasoning (ADR-011).
 */
class SeoControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_settings_returns_stored_values(): void
    {
        $affiliate = $this->primaryAffiliate();
        AffiliateSeoSettings::query()->create([
            'affiliate_id' => $affiliate->id,
            'default_meta_title' => 'PekanGame',
            'ga_measurement_id' => 'G-ABC123',
            'schema_organization_enabled' => false,
        ]);

        $response = $this->getJson('/api/catalog/seo/settings');

        $response->assertOk();
        $this->assertSame('PekanGame', $response->json('default_meta_title'));
        $this->assertSame('G-ABC123', $response->json('ga_measurement_id'));
        $this->assertFalse($response->json('schema_organization_enabled'));
    }

    public function test_settings_defaults_schema_toggles_to_true_when_nothing_saved_yet(): void
    {
        $this->primaryAffiliate();

        $response = $this->getJson('/api/catalog/seo/settings');

        $response->assertOk();
        $this->assertNull($response->json('default_meta_title'));
        $this->assertTrue($response->json('schema_organization_enabled'));
        $this->assertTrue($response->json('schema_product_enabled'));
        $this->assertTrue($response->json('schema_breadcrumb_enabled'));
    }

    public function test_settings_returns_the_same_payload_on_a_cached_second_request(): void
    {
        $affiliate = $this->primaryAffiliate();
        AffiliateSeoSettings::query()->create([
            'affiliate_id' => $affiliate->id,
            'default_meta_title' => 'First Read',
        ]);

        $first = $this->getJson('/api/catalog/seo/settings');
        $second = $this->getJson('/api/catalog/seo/settings');

        $first->assertOk();
        $second->assertOk();
        $this->assertSame('First Read', $first->json('default_meta_title'));
        $this->assertSame($first->json(), $second->json());
    }

    public function test_redirects_returns_only_the_current_affiliates_rows(): void
    {
        $affiliate = $this->primaryAffiliate();
        $otherAffiliate = Affiliate::query()->create(['business_name' => 'Other Affiliate']);

        Redirect::query()->create(['affiliate_id' => $affiliate->id, 'from_path' => '/old', 'to_path' => '/new', 'status_code' => 301]);
        Redirect::query()->create(['affiliate_id' => $otherAffiliate->id, 'from_path' => '/other', 'to_path' => '/elsewhere', 'status_code' => 302]);

        $response = $this->getJson('/api/catalog/seo/redirects');

        $response->assertOk();
        $this->assertCount(1, $response->json());
        $this->assertSame('/old', $response->json('0.from_path'));
    }

    public function test_record_redirect_hit_increments_the_matching_row_only(): void
    {
        $affiliate = $this->primaryAffiliate();
        $redirect = Redirect::query()->create(['affiliate_id' => $affiliate->id, 'from_path' => '/old', 'to_path' => '/new', 'status_code' => 301]);
        $other = Redirect::query()->create(['affiliate_id' => $affiliate->id, 'from_path' => '/other', 'to_path' => '/elsewhere', 'status_code' => 301]);

        $this->postJson('/api/catalog/seo/redirects/record-hit', ['from_path' => '/old'])->assertNoContent();

        $this->assertSame(1, $redirect->fresh()->hit_count);
        $this->assertSame(0, $other->fresh()->hit_count);
    }

    public function test_record_redirect_hit_silently_no_ops_for_an_unknown_path(): void
    {
        $this->primaryAffiliate();

        $this->postJson('/api/catalog/seo/redirects/record-hit', ['from_path' => '/does-not-exist'])->assertNoContent();
    }

    public function test_scripts_returns_only_active_global_and_affiliate_scoped_rows_ordered_by_priority(): void
    {
        $affiliate = $this->primaryAffiliate();
        SeoScript::query()->create(['affiliate_id' => null, 'name' => 'GA', 'location' => 'head', 'code' => '<script>ga()</script>', 'priority' => 2, 'is_active' => true]);
        SeoScript::query()->create(['affiliate_id' => $affiliate->id, 'name' => 'Pixel', 'location' => 'head', 'code' => '<script>fb()</script>', 'priority' => 1, 'is_active' => true]);
        SeoScript::query()->create(['affiliate_id' => null, 'name' => 'Disabled', 'location' => 'head', 'code' => '<script>x()</script>', 'priority' => 0, 'is_active' => false]);

        $response = $this->getJson('/api/catalog/seo/scripts');

        $response->assertOk();
        $this->assertCount(2, $response->json());
        $this->assertSame([1, 2], collect($response->json())->pluck('priority')->all());
    }

    public function test_robots_merges_default_disallow_paths_into_every_allowed_bot_but_not_disallowed_ones(): void
    {
        $affiliate = $this->primaryAffiliate();
        AffiliateSeoSettings::query()->create([
            'affiliate_id' => $affiliate->id,
            'crawler_default_disallow_paths' => ['/order/status', '/api'],
        ]);
        CrawlerRule::query()->create([
            'bot_name' => 'Googlebot', 'user_agent' => 'Googlebot', 'is_allowed' => true,
            'disallow_paths' => ['/admin'], 'sort_order' => 1,
        ]);
        CrawlerRule::query()->create([
            'bot_name' => 'BadBot', 'user_agent' => 'BadBot', 'is_allowed' => false,
            'disallow_paths' => [], 'sort_order' => 2,
        ]);

        $response = $this->getJson('/api/catalog/seo/robots');

        $response->assertOk();
        $google = collect($response->json())->firstWhere('bot_name', 'Googlebot');
        $bad = collect($response->json())->firstWhere('bot_name', 'BadBot');
        $this->assertEqualsCanonicalizing(['/admin', '/order/status', '/api'], $google['disallow_paths']);
        $this->assertSame([], $bad['disallow_paths']);
    }

    public function test_forget_cache_clears_settings_redirects_and_scripts_but_not_robots(): void
    {
        $affiliate = $this->primaryAffiliate();
        Cache::put("catalog.public.seo.{$affiliate->id}", ['stale' => true], 60);
        Cache::put("catalog.public.redirects.{$affiliate->id}", ['stale' => true], 60);
        Cache::put("catalog.public.seo_scripts.{$affiliate->id}", ['stale' => true], 60);
        Cache::put('catalog.public.crawler_rules', ['stale' => true], 60);

        SeoController::forgetCache($affiliate->id);

        $this->assertFalse(Cache::has("catalog.public.seo.{$affiliate->id}"));
        $this->assertFalse(Cache::has("catalog.public.redirects.{$affiliate->id}"));
        $this->assertFalse(Cache::has("catalog.public.seo_scripts.{$affiliate->id}"));
        $this->assertTrue(Cache::has('catalog.public.crawler_rules'));
    }

    public function test_forget_robots_cache_clears_only_the_robots_key(): void
    {
        Cache::put('catalog.public.crawler_rules', ['stale' => true], 60);

        SeoController::forgetRobotsCache();

        $this->assertFalse(Cache::has('catalog.public.crawler_rules'));
    }

    public function test_affiliate_seo_settings_inherits_primary_templates_with_brand_pixel_ids(): void
    {
        $primary = $this->primaryAffiliate();
        AffiliateSeoSettings::query()->create([
            'affiliate_id' => $primary->id,
            'default_meta_title' => 'PekanGame — Top Up',
            'meta_title_template' => 'Top Up {game_name} — {store_name}',
            'meta_description_template' => 'Instant top-up at {store_name}',
            'fb_pixel_id' => 'PRIMARY_FB_PIXEL',
        ]);

        $affiliate = \App\Models\Affiliate::query()->create([
            'business_name' => 'FixFast',
            'is_primary' => false,
            'status' => 'active',
            'markup_pct' => 0,
        ]);
        AffiliateSeoSettings::query()->create([
            'affiliate_id' => $affiliate->id,
            'fb_pixel_id' => 'FIXFAST_FB_PIXEL',
        ]);
        \App\Models\AffiliateDomain::query()->create([
            'affiliate_id' => $affiliate->id,
            'hostname' => 'fixfastapp.com',
            'status' => \App\Services\Affiliate\AffiliateDomainStatus::Active,
        ]);

        $response = $this->withHeaders(['X-Storefront-Host' => 'fixfastapp.com'])
            ->getJson('/api/catalog/seo/settings');

        $response->assertOk();
        $this->assertSame('Top Up {game_name} — {store_name}', $response->json('meta_title_template'));
        $this->assertSame('Instant top-up at {store_name}', $response->json('meta_description_template'));
        $this->assertSame('PekanGame — Top Up', $response->json('default_meta_title'));
        $this->assertSame('FIXFAST_FB_PIXEL', $response->json('fb_pixel_id'));
    }
}
