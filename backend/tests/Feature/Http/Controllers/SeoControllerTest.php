<?php

namespace Tests\Feature\Http\Controllers;

use App\Http\Controllers\SeoController;
use App\Models\CrawlerRule;
use App\Models\Redirect;
use App\Models\Reseller;
use App\Models\ResellerSeoSettings;
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
        $reseller = $this->primaryReseller();
        ResellerSeoSettings::query()->create([
            'reseller_id' => $reseller->id,
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
        $this->primaryReseller();

        $response = $this->getJson('/api/catalog/seo/settings');

        $response->assertOk();
        $this->assertNull($response->json('default_meta_title'));
        $this->assertTrue($response->json('schema_organization_enabled'));
        $this->assertTrue($response->json('schema_product_enabled'));
        $this->assertTrue($response->json('schema_breadcrumb_enabled'));
    }

    public function test_settings_returns_the_same_payload_on_a_cached_second_request(): void
    {
        $reseller = $this->primaryReseller();
        ResellerSeoSettings::query()->create([
            'reseller_id' => $reseller->id,
            'default_meta_title' => 'First Read',
        ]);

        $first = $this->getJson('/api/catalog/seo/settings');
        $second = $this->getJson('/api/catalog/seo/settings');

        $first->assertOk();
        $second->assertOk();
        $this->assertSame('First Read', $first->json('default_meta_title'));
        $this->assertSame($first->json(), $second->json());
    }

    public function test_redirects_returns_only_the_current_resellers_rows(): void
    {
        $reseller = $this->primaryReseller();
        $otherReseller = Reseller::query()->create(['business_name' => 'Other Reseller']);

        Redirect::query()->create(['reseller_id' => $reseller->id, 'from_path' => '/old', 'to_path' => '/new', 'status_code' => 301]);
        Redirect::query()->create(['reseller_id' => $otherReseller->id, 'from_path' => '/other', 'to_path' => '/elsewhere', 'status_code' => 302]);

        $response = $this->getJson('/api/catalog/seo/redirects');

        $response->assertOk();
        $this->assertCount(1, $response->json());
        $this->assertSame('/old', $response->json('0.from_path'));
    }

    public function test_record_redirect_hit_increments_the_matching_row_only(): void
    {
        $reseller = $this->primaryReseller();
        $redirect = Redirect::query()->create(['reseller_id' => $reseller->id, 'from_path' => '/old', 'to_path' => '/new', 'status_code' => 301]);
        $other = Redirect::query()->create(['reseller_id' => $reseller->id, 'from_path' => '/other', 'to_path' => '/elsewhere', 'status_code' => 301]);

        $this->postJson('/api/catalog/seo/redirects/record-hit', ['from_path' => '/old'])->assertNoContent();

        $this->assertSame(1, $redirect->fresh()->hit_count);
        $this->assertSame(0, $other->fresh()->hit_count);
    }

    public function test_record_redirect_hit_silently_no_ops_for_an_unknown_path(): void
    {
        $this->primaryReseller();

        $this->postJson('/api/catalog/seo/redirects/record-hit', ['from_path' => '/does-not-exist'])->assertNoContent();
    }

    public function test_scripts_returns_only_active_global_and_reseller_scoped_rows_ordered_by_priority(): void
    {
        $reseller = $this->primaryReseller();
        SeoScript::query()->create(['reseller_id' => null, 'name' => 'GA', 'location' => 'head', 'code' => '<script>ga()</script>', 'priority' => 2, 'is_active' => true]);
        SeoScript::query()->create(['reseller_id' => $reseller->id, 'name' => 'Pixel', 'location' => 'head', 'code' => '<script>fb()</script>', 'priority' => 1, 'is_active' => true]);
        SeoScript::query()->create(['reseller_id' => null, 'name' => 'Disabled', 'location' => 'head', 'code' => '<script>x()</script>', 'priority' => 0, 'is_active' => false]);

        $response = $this->getJson('/api/catalog/seo/scripts');

        $response->assertOk();
        $this->assertCount(2, $response->json());
        $this->assertSame([1, 2], collect($response->json())->pluck('priority')->all());
    }

    public function test_robots_merges_default_disallow_paths_into_every_allowed_bot_but_not_disallowed_ones(): void
    {
        $reseller = $this->primaryReseller();
        ResellerSeoSettings::query()->create([
            'reseller_id' => $reseller->id,
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
        $reseller = $this->primaryReseller();
        Cache::put("catalog.public.seo.{$reseller->id}", ['stale' => true], 60);
        Cache::put("catalog.public.redirects.{$reseller->id}", ['stale' => true], 60);
        Cache::put("catalog.public.seo_scripts.{$reseller->id}", ['stale' => true], 60);
        Cache::put('catalog.public.crawler_rules', ['stale' => true], 60);

        SeoController::forgetCache($reseller->id);

        $this->assertFalse(Cache::has("catalog.public.seo.{$reseller->id}"));
        $this->assertFalse(Cache::has("catalog.public.redirects.{$reseller->id}"));
        $this->assertFalse(Cache::has("catalog.public.seo_scripts.{$reseller->id}"));
        $this->assertTrue(Cache::has('catalog.public.crawler_rules'));
    }

    public function test_forget_robots_cache_clears_only_the_robots_key(): void
    {
        Cache::put('catalog.public.crawler_rules', ['stale' => true], 60);

        SeoController::forgetRobotsCache();

        $this->assertFalse(Cache::has('catalog.public.crawler_rules'));
    }
}
