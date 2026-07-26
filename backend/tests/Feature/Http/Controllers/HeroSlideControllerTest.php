<?php

namespace Tests\Feature\Http\Controllers;

use App\Models\HeroSlide;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Public, no-auth hero-banner listing (ADR-011) — docs/prd.md §14/§15
 * backlog: "Hero Banner / Campaign management."
 */
class HeroSlideControllerTest extends TestCase
{
    use RefreshDatabase;

    private function make(array $overrides = []): HeroSlide
    {
        return HeroSlide::query()->create(array_merge([
            'title' => 'Top up your favorite games in seconds',
            'primary_cta_label' => 'Find Games',
            'primary_cta_href' => '#popular-picks',
            'is_active' => true,
            'sort_order' => 0,
        ], $overrides));
    }

    public function test_index_lists_only_active_slides(): void
    {
        $this->make(['title' => 'Active Slide', 'is_active' => true]);
        $this->make(['title' => 'Inactive Slide', 'is_active' => false]);

        $response = $this->getJson('/api/catalog/hero-slides');

        $response->assertOk();
        $this->assertSame(['Active Slide'], collect($response->json())->pluck('title')->all());
    }

    public function test_index_excludes_a_slide_before_its_start_date(): void
    {
        $this->make(['title' => 'Future Slide', 'starts_at' => now()->addDay()]);

        $response = $this->getJson('/api/catalog/hero-slides');

        $response->assertOk();
        $response->assertJsonCount(0);
    }

    public function test_index_excludes_a_slide_past_its_end_date(): void
    {
        $this->make(['title' => 'Expired Slide', 'ends_at' => now()->subDay()]);

        $response = $this->getJson('/api/catalog/hero-slides');

        $response->assertOk();
        $this->assertCount(0, $response->json());
    }

    public function test_index_includes_a_slide_within_its_active_window(): void
    {
        $this->make(['title' => 'Weekend Promo', 'starts_at' => now()->subHour(), 'ends_at' => now()->addHour()]);

        $response = $this->getJson('/api/catalog/hero-slides');

        $response->assertOk();
        $this->assertSame(['Weekend Promo'], collect($response->json())->pluck('title')->all());
    }

    public function test_index_orders_by_sort_order(): void
    {
        $this->make(['title' => 'Second', 'sort_order' => 2]);
        $this->make(['title' => 'First', 'sort_order' => 1]);

        $response = $this->getJson('/api/catalog/hero-slides');

        $response->assertOk();
        $this->assertSame(['First', 'Second'], collect($response->json())->pluck('title')->all());
    }

    /**
     * Regression test for a real bug found live, 2026-07-26: this
     * app's default cache store is `database` (config/cache.php),
     * which serializes the cached value on write and `unserialize()`s
     * it on every read. Caching a raw Eloquent Collection/Model there
     * reliably corrupted on a warm-cache read against a real running
     * server (surfaced as a broken `__PHP_Incomplete_Class_Name` JSON
     * body — confirmed live, repeated requests, not a one-off).
     * PHPUnit's `array` cache driver (phpunit.xml) never serializes at
     * all, so it can't catch this — this test forces the real
     * `database` store and asserts the raw cached value is a plain
     * array, not a model/Collection object, which is the actual
     * invariant the fix depends on.
     */
    public function test_index_survives_a_real_database_cache_round_trip(): void
    {
        config(['cache.default' => 'database']);
        $this->make(['title' => 'Cached Slide']);

        $first = $this->getJson('/api/catalog/hero-slides');
        $first->assertOk();
        $this->assertSame(['Cached Slide'], collect($first->json())->pluck('title')->all());

        // Forces a genuine unserialize() of what's actually stored,
        // not whatever object graph still lives in this process's memory.
        $cached = Cache::store('database')->get('catalog.public.hero_slides');
        $this->assertIsArray($cached);
        $this->assertSame('Cached Slide', $cached[0]['title']);

        $second = $this->getJson('/api/catalog/hero-slides');
        $second->assertOk();
        $this->assertSame(['Cached Slide'], collect($second->json())->pluck('title')->all());
    }
}
