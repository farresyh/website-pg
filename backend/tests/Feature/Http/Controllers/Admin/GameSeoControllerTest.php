<?php

namespace Tests\Feature\Http\Controllers\Admin;

use App\Models\AdminUser;
use App\Models\Game;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** ADR-029 decision 11: per-game SEO list+edit screen — data stays on Game. */
class GameSeoControllerTest extends TestCase
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

    // ADR-140 decision 8: the list's status is the content status, not "title and
    // description filled" (which read "missing" for every game after ADR-120).
    public function test_index_marks_the_content_status_of_each_game(): void
    {
        $this->actingAsSuperAdmin();
        $this->game('empty-game');
        $this->game('draft-game', ['content_summary' => 'Ringkasan']);
        $this->game('published-game', ['content_summary' => 'Ringkasan', 'content_published_at' => '2026-11-12 03:00:00']);

        $response = $this->getJson('/api/seo/games');

        $response->assertOk();
        $byStatus = collect($response->json())->pluck('content_status', 'slug');
        $this->assertSame('empty', $byStatus['empty-game']);
        $this->assertSame('draft', $byStatus['draft-game']);
        $this->assertSame('published', $byStatus['published-game']);
        $this->assertArrayNotHasKey('seo_status', $response->json('0'));
        $this->assertArrayNotHasKey('content_about', $response->json('0'), 'the list never carries the bodies');
    }

    public function test_index_filters_by_content_status(): void
    {
        $this->actingAsSuperAdmin();
        $this->game('empty-game');
        $this->game('draft-game', ['content_summary' => 'Ringkasan']);

        $response = $this->getJson('/api/seo/games?filter=empty');

        $response->assertOk();
        $this->assertCount(1, $response->json());
        $this->assertSame('empty-game', $response->json('0.slug'));
    }

    public function test_show_carries_the_content_and_its_status(): void
    {
        $this->actingAsSuperAdmin();
        $game = $this->game('valorant', ['content_summary' => 'Ringkasan', 'content_faq' => [['question' => 'Q?', 'answer' => 'A.']]]);

        $this->getJson("/api/seo/games/{$game->id}")
            ->assertOk()
            ->assertJsonPath('content_status', 'draft')
            ->assertJsonPath('content_summary', 'Ringkasan')
            ->assertJsonPath('content_faq.0.question', 'Q?');
    }

    public function test_index_search_matches_by_name(): void
    {
        $this->actingAsSuperAdmin();
        $this->game('mobile-legends', ['name' => 'Mobile Legends']);
        $this->game('free-fire', ['name' => 'Free Fire']);

        $response = $this->getJson('/api/seo/games?search=Mobile');

        $response->assertOk();
        $this->assertCount(1, $response->json());
        $this->assertSame('Mobile Legends', $response->json('0.name'));
    }

    public function test_show_returns_the_full_game_row(): void
    {
        $this->actingAsSuperAdmin();
        $game = $this->game('mobile-legends', ['seo_title' => 'ML Top Up']);

        $response = $this->getJson("/api/seo/games/{$game->id}");

        $response->assertOk();
        $this->assertSame('ML Top Up', $response->json('seo_title'));
    }

    public function test_update_persists_seo_fields(): void
    {
        $this->actingAsSuperAdmin();
        $game = $this->game('mobile-legends');

        $response = $this->putJson("/api/seo/games/{$game->id}", [
            'seo_title' => 'Top Up ML Murah',
            'seo_description' => 'Best MLBB diamond top up.',
            'no_index' => true,
        ]);

        $response->assertOk();
        $this->assertSame('Top Up ML Murah', $response->json('seo_title'));
        $this->assertTrue($response->json('no_index'));
        $this->assertDatabaseHas('games', ['id' => $game->id, 'seo_title' => 'Top Up ML Murah', 'no_index' => 1]);
    }

    public function test_update_rejects_a_seo_title_over_seventy_characters(): void
    {
        $this->actingAsSuperAdmin();
        $game = $this->game('mobile-legends');

        $this->putJson("/api/seo/games/{$game->id}", ['seo_title' => str_repeat('a', 71)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('seo_title');
    }

    public function test_update_rejects_a_seo_description_over_one_hundred_sixty_characters(): void
    {
        $this->actingAsSuperAdmin();
        $game = $this->game('mobile-legends');

        $this->putJson("/api/seo/games/{$game->id}", ['seo_description' => str_repeat('a', 161)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('seo_description');
    }
}
