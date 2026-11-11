<?php

namespace Tests\Feature\Http\Controllers\Admin;

use App\Models\AdminUser;
use App\Models\Affiliate;
use App\Models\Game;
use App\Models\Redirect;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ADR-029 decisions 2/4/6/10: Overview + Global Settings/Meta Templates
 * tabs. Same admin.role:super_admin tier as Settings.
 */
class SeoControllerTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsSuperAdmin(): void
    {
        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'super_admin']));
    }

    private function game(string $slug, array $overrides = []): Game
    {
        return Game::query()->create(array_merge([
            'name' => ucfirst(str_replace('-', ' ', $slug)),
            'slug' => $slug,
            'is_active' => true,
        ], $overrides));
    }

    private function tracked(string $slug, string $status): Game
    {
        return $this->game($slug, match ($status) {
            'published' => ['content_summary' => 'Ringkasan', 'content_published_at' => '2026-11-12 03:00:00'],
            'draft' => ['content_summary' => 'Ringkasan'],
            default => [],
        });
    }

    // ADR-140 decision 8: one status (empty, draft, published) over the "tracked"
    // games: active and not noindex, the set the sitemap lists.
    public function test_overview_counts_the_content_status_of_tracked_games_only(): void
    {
        $this->actingAsSuperAdmin();
        $this->primaryAffiliate();
        $this->tracked('published-game', 'published');
        $this->tracked('draft-game', 'draft');
        $this->tracked('empty-game', 'empty');
        $this->game('inactive-game', ['is_active' => false]);
        $this->game('noindex-game', ['no_index' => true]);

        $response = $this->getJson('/api/seo/overview');

        $response->assertOk();
        $this->assertSame(3, $response->json('games.total'));
        $this->assertSame(1, $response->json('games.published'));
        $this->assertSame(1, $response->json('games.draft'));
        $this->assertSame(1, $response->json('games.empty'));
    }

    public function test_overview_asks_for_content_not_for_the_seo_overrides(): void
    {
        $this->actingAsSuperAdmin();
        $this->primaryAffiliate();
        $this->tracked('published-game', 'published');
        $this->tracked('empty-game', 'empty');

        $recommendations = $this->getJson('/api/seo/overview')->assertOk()->json('recommendations');

        $this->assertCount(1, $recommendations);
        $this->assertSame('/admin/seo/games?filter=empty', $recommendations[0]['link']);
        $this->assertStringContainsString('no page content', $recommendations[0]['message']);
        $this->assertStringNotContainsString('SEO title', $recommendations[0]['message']);
    }

    public function test_overview_recommends_nothing_when_every_tracked_game_is_published(): void
    {
        $this->actingAsSuperAdmin();
        $this->primaryAffiliate();
        $this->tracked('published-game', 'published');

        $this->getJson('/api/seo/overview')->assertOk()->assertJsonPath('recommendations', []);
    }

    public function test_overview_sitemap_url_count_is_six_static_pages_plus_tracked_games(): void
    {
        $this->actingAsSuperAdmin();
        $this->primaryAffiliate();
        $this->game('game-one');
        $this->game('game-two');
        $this->game('noindex-game', ['no_index' => true]);

        $this->getJson('/api/seo/overview')->assertOk()->assertJsonPath('sitemap_url_count', 8);
    }

    public function test_settings_creates_a_default_row_on_first_access(): void
    {
        $this->actingAsSuperAdmin();
        $this->primaryAffiliate();

        $response = $this->getJson('/api/seo/settings');

        $response->assertOk();
        $this->assertDatabaseCount('affiliate_seo_settings', 1);
    }

    public function test_update_settings_persists_and_returns_the_new_values(): void
    {
        $this->actingAsSuperAdmin();
        $this->primaryAffiliate();

        $response = $this->putJson('/api/seo/settings', [
            'default_meta_title' => 'New Title',
            'ga_measurement_id' => 'G-XYZ999',
        ]);

        $response->assertOk();
        $this->assertSame('New Title', $response->json('default_meta_title'));
        $this->assertDatabaseHas('affiliate_seo_settings', ['default_meta_title' => 'New Title', 'ga_measurement_id' => 'G-XYZ999']);
    }

    public function test_update_settings_rejects_a_meta_title_over_seventy_characters(): void
    {
        $this->actingAsSuperAdmin();
        $this->primaryAffiliate();

        $this->putJson('/api/seo/settings', ['default_meta_title' => str_repeat('a', 71)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('default_meta_title');
    }

    public function test_update_settings_invalidates_both_the_public_seo_cache_and_the_robots_cache(): void
    {
        $this->actingAsSuperAdmin();
        $affiliate = $this->primaryAffiliate();
        Cache::put("catalog.public.seo.{$affiliate->id}", ['stale' => true], 60);
        Cache::put('catalog.public.crawler_rules', ['stale' => true], 60);

        $this->putJson('/api/seo/settings', ['default_meta_title' => 'Fresh'])->assertOk();

        $this->assertFalse(Cache::has("catalog.public.seo.{$affiliate->id}"));
        $this->assertFalse(Cache::has('catalog.public.crawler_rules'));
    }

    public function test_overview_redirect_total_counts_only_the_current_affiliates_rows(): void
    {
        $this->actingAsSuperAdmin();
        $affiliate = $this->primaryAffiliate();
        $other = Affiliate::query()->create(['business_name' => 'Other']);
        Redirect::query()->create(['affiliate_id' => $affiliate->id, 'from_path' => '/a', 'to_path' => '/b', 'status_code' => 301]);
        Redirect::query()->create(['affiliate_id' => $other->id, 'from_path' => '/c', 'to_path' => '/d', 'status_code' => 301]);

        $response = $this->getJson('/api/seo/overview');

        $response->assertOk();
        $this->assertSame(1, $response->json('redirects.total'));
    }
}
