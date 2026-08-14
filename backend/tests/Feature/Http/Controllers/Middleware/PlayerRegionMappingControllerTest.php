<?php

namespace Tests\Feature\Http\Controllers\Middleware;

use App\Models\AdminUser;
use App\Models\Game;
use App\Models\PlayerValidatorProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PlayerRegionMappingControllerTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): void
    {
        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'super_admin']));
    }

    public function test_regular_admin_is_forbidden(): void
    {
        $validator = $this->validator();
        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'admin']));

        $this->postJson("/api/middleware/validators/{$validator->id}/mappings", [])->assertForbidden();
    }

    private function game(array $overrides = []): Game
    {
        return Game::query()->create(array_merge([
            'name' => 'Mobile Legends (Malaysia)',
            'slug' => 'mobile-legends-malaysia',
        ], $overrides));
    }

    private function validator(): PlayerValidatorProfile
    {
        return PlayerValidatorProfile::query()->create([
            'name' => 'Mobile Legends Validator',
            'key' => 'mlbb',
        ]);
    }

    public function test_store_requires_authentication(): void
    {
        $validator = $this->validator();

        $this->postJson("/api/middleware/validators/{$validator->id}/mappings", [])->assertUnauthorized();
    }

    public function test_store_creates_a_mapping_under_the_validator(): void
    {
        $validator = $this->validator();
        $game = $this->game(['name' => 'Mobile Legends (Cambodia)', 'slug' => 'mobile-legends-cambodia']);
        $this->actingAsAdmin();

        $response = $this->postJson("/api/middleware/validators/{$validator->id}/mappings", [
            'country_code' => 'kh',
            'country_name' => 'Cambodia',
            'game_id' => $game->id,
        ]);

        $response->assertCreated();
        $this->assertSame('KH', $response->json('country_code'));
        $this->assertDatabaseHas('player_region_mappings', [
            'player_validator_profile_id' => $validator->id,
            'country_code' => 'KH',
            'game_id' => $game->id,
        ]);
    }

    public function test_store_rejects_a_duplicate_country_code_under_the_same_validator(): void
    {
        $validator = $this->validator();
        $game = $this->game();
        $validator->mappings()->create(['country_code' => 'MY', 'country_name' => 'Malaysia', 'game_id' => $game->id]);
        $this->actingAsAdmin();

        $response = $this->postJson("/api/middleware/validators/{$validator->id}/mappings", [
            'country_code' => 'MY',
            'country_name' => 'Malaysia (duplicate)',
            'game_id' => $game->id,
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['country_code']);
    }

    public function test_store_allows_the_same_country_code_under_a_different_validator(): void
    {
        $firstValidator = $this->validator();
        $game = $this->game();
        $firstValidator->mappings()->create(['country_code' => 'MY', 'country_name' => 'Malaysia', 'game_id' => $game->id]);

        $secondValidator = PlayerValidatorProfile::query()->create(['name' => 'Another Validator', 'key' => 'mlbb-2']);
        $this->actingAsAdmin();

        // Only real bound keys pass Store validation on the profile itself,
        // but mappings only check uniqueness against player_validator_profile_id,
        // so a second profile row (however it was created) is a valid target.
        $response = $this->postJson("/api/middleware/validators/{$secondValidator->id}/mappings", [
            'country_code' => 'MY',
            'country_name' => 'Malaysia',
            'game_id' => $game->id,
        ]);

        $response->assertCreated();
    }

    public function test_store_rejects_a_nonexistent_game(): void
    {
        $validator = $this->validator();
        $this->actingAsAdmin();

        $response = $this->postJson("/api/middleware/validators/{$validator->id}/mappings", [
            'country_code' => 'MY',
            'country_name' => 'Malaysia',
            'game_id' => 999999,
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['game_id']);
    }

    public function test_update_changes_the_country_name_and_linked_game(): void
    {
        $validator = $this->validator();
        $originalGame = $this->game();
        $newGame = $this->game(['name' => 'Mobile Legends (Global)', 'slug' => 'mobile-legends-global']);
        $mapping = $validator->mappings()->create(['country_code' => 'MY', 'country_name' => 'Malaysia', 'game_id' => $originalGame->id]);
        $this->actingAsAdmin();

        $response = $this->putJson("/api/middleware/validators/{$validator->id}/mappings/{$mapping->id}", [
            'country_name' => 'Malaysia (corrected)',
            'game_id' => $newGame->id,
        ]);

        $response->assertOk();
        $this->assertSame('Malaysia (corrected)', $response->json('country_name'));
        $this->assertSame('mobile-legends-global', $response->json('game.slug'));
    }

    public function test_destroy_removes_the_mapping(): void
    {
        $validator = $this->validator();
        $game = $this->game();
        $mapping = $validator->mappings()->create(['country_code' => 'MY', 'country_name' => 'Malaysia', 'game_id' => $game->id]);
        $this->actingAsAdmin();

        $response = $this->deleteJson("/api/middleware/validators/{$validator->id}/mappings/{$mapping->id}");

        $response->assertNoContent();
        $this->assertDatabaseMissing('player_region_mappings', ['id' => $mapping->id]);
    }

    public function test_destroy_rejects_a_mapping_belonging_to_a_different_validator(): void
    {
        $validator = $this->validator();
        $otherValidator = PlayerValidatorProfile::query()->create(['name' => 'Another', 'key' => 'mlbb-2']);
        $game = $this->game();
        $mapping = $otherValidator->mappings()->create(['country_code' => 'MY', 'country_name' => 'Malaysia', 'game_id' => $game->id]);
        $this->actingAsAdmin();

        $response = $this->deleteJson("/api/middleware/validators/{$validator->id}/mappings/{$mapping->id}");

        $response->assertNotFound();
        $this->assertDatabaseHas('player_region_mappings', ['id' => $mapping->id]);
    }

    public function test_update_rejects_a_mapping_belonging_to_a_different_validator(): void
    {
        $validator = $this->validator();
        $otherValidator = PlayerValidatorProfile::query()->create(['name' => 'Another', 'key' => 'mlbb-2']);
        $game = $this->game();
        $mapping = $otherValidator->mappings()->create(['country_code' => 'MY', 'country_name' => 'Malaysia', 'game_id' => $game->id]);
        $this->actingAsAdmin();

        $response = $this->putJson("/api/middleware/validators/{$validator->id}/mappings/{$mapping->id}", [
            'country_name' => 'Hijacked',
            'game_id' => $game->id,
        ]);

        $response->assertNotFound();
        $this->assertSame('Malaysia', $mapping->fresh()->country_name);
    }
}
