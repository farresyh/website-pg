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

    public function test_regular_admin_is_forbidden(): void
    {
        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'admin']));

        $this->getJson('/api/seo/overview')->assertForbidden();
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/seo/overview')->assertUnauthorized();
    }

    public function test_overview_counts_missing_seo_fields_across_active_games_only(): void
    {
        $this->actingAsSuperAdmin();
        $this->primaryAffiliate();
        $this->game('complete-game', ['seo_title' => 'Title', 'seo_description' => 'Desc']);
        $this->game('missing-title', ['seo_title' => null, 'seo_description' => 'Desc']);
        $this->game('missing-everything', ['seo_title' => null, 'seo_description' => null]);
        $this->game('inactive-game', ['is_active' => false, 'seo_title' => null, 'seo_description' => null]);

        $response = $this->getJson('/api/seo/overview');

        $response->assertOk();
        $this->assertSame(3, $response->json('games.total'));
        $this->assertSame(2, $response->json('games.missing_title'));
        $this->assertSame(1, $response->json('games.missing_description'));
    }

    public function test_overview_recommends_nothing_when_every_active_game_is_complete(): void
    {
        $this->actingAsSuperAdmin();
        $this->primaryAffiliate();
        $this->game('complete-game', ['seo_title' => 'Title', 'seo_description' => 'Desc', 'seo_og_image' => 'https://example.com/img.png']);

        $response = $this->getJson('/api/seo/overview');

        $response->assertOk();
        $this->assertSame([], $response->json('recommendations'));
    }

    public function test_overview_sitemap_url_count_is_five_static_pages_plus_active_games(): void
    {
        $this->actingAsSuperAdmin();
        $this->primaryAffiliate();
        $this->game('game-one');
        $this->game('game-two');

        $response = $this->getJson('/api/seo/overview');

        $response->assertOk();
        $this->assertSame(7, $response->json('sitemap_url_count'));
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
