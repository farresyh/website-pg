<?php

namespace Tests\Feature\Http\Controllers\Middleware;

use App\Models\AdminUser;
use App\Models\Game;
use App\Models\PlayerValidatorProfile;
use App\Services\PlayerValidation\PlayerValidationResult;
use App\Services\PlayerValidation\PlayerValidator;
use App\Services\PlayerValidation\ProviderUnavailableException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PlayerValidatorProfileControllerTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): void
    {
        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'admin']));
    }

    private function profile(array $overrides = []): PlayerValidatorProfile
    {
        return PlayerValidatorProfile::query()->create(array_merge([
            'name' => 'Mobile Legends Validator',
            'key' => 'mlbb',
        ], $overrides));
    }

    private function bindFakeValidator(PlayerValidationResult|\Throwable $outcome): void
    {
        $fake = new class($outcome) implements PlayerValidator
        {
            public function __construct(private readonly PlayerValidationResult|\Throwable $outcome) {}

            public function validate(string $playerId, ?string $serverId): PlayerValidationResult
            {
                if ($this->outcome instanceof \Throwable) {
                    throw $this->outcome;
                }

                return $this->outcome;
            }
        };

        $this->app->bind('player-validator.mlbb', fn () => $fake);
    }

    public function test_index_requires_authentication(): void
    {
        $this->getJson('/api/middleware/validators')->assertUnauthorized();
    }

    public function test_index_lists_profiles_with_nested_mappings(): void
    {
        $game = Game::query()->create(['name' => 'Mobile Legends (Malaysia)', 'slug' => 'mobile-legends-malaysia']);
        $profile = $this->profile();
        $profile->mappings()->create([
            'country_code' => 'MY', 'country_name' => 'Malaysia', 'game_id' => $game->id,
        ]);
        $this->actingAsAdmin();

        $response = $this->getJson('/api/middleware/validators');

        $response->assertOk();
        $this->assertCount(1, $response->json());
        $this->assertSame('mobile-legends-malaysia', $response->json('0.mappings.0.game.slug'));
    }

    public function test_available_keys_lists_the_real_bound_implementations(): void
    {
        $this->actingAsAdmin();

        $response = $this->getJson('/api/middleware/validators/available-keys');

        $response->assertOk();
        $this->assertContains('mlbb', collect($response->json())->pluck('key')->all());
    }

    public function test_store_creates_a_profile(): void
    {
        $this->actingAsAdmin();

        $response = $this->postJson('/api/middleware/validators', [
            'name' => 'Mobile Legends Validator',
            'key' => 'mlbb',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('player_validator_profiles', ['key' => 'mlbb']);
    }

    public function test_store_rejects_a_key_with_no_real_implementation(): void
    {
        $this->actingAsAdmin();

        $response = $this->postJson('/api/middleware/validators', [
            'name' => 'Made Up Validator',
            'key' => 'not-a-real-key',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['key']);
    }

    public function test_store_rejects_a_duplicate_key(): void
    {
        $this->profile();
        $this->actingAsAdmin();

        $response = $this->postJson('/api/middleware/validators', [
            'name' => 'Another Mobile Legends Validator',
            'key' => 'mlbb',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['key']);
    }

    public function test_update_changes_only_the_name(): void
    {
        $profile = $this->profile();
        $this->actingAsAdmin();

        $response = $this->putJson("/api/middleware/validators/{$profile->id}", [
            'name' => 'MLBB Region Validator',
        ]);

        $response->assertOk();
        $this->assertSame('MLBB Region Validator', $response->json('name'));
        $this->assertSame('mlbb', $response->json('key'));
    }

    public function test_destroy_removes_the_profile_and_cascades_its_mappings(): void
    {
        $game = Game::query()->create(['name' => 'Mobile Legends (Malaysia)', 'slug' => 'mobile-legends-malaysia']);
        $profile = $this->profile();
        $mapping = $profile->mappings()->create([
            'country_code' => 'MY', 'country_name' => 'Malaysia', 'game_id' => $game->id,
        ]);
        $this->actingAsAdmin();

        $response = $this->deleteJson("/api/middleware/validators/{$profile->id}");

        $response->assertNoContent();
        $this->assertDatabaseMissing('player_validator_profiles', ['id' => $profile->id]);
        $this->assertDatabaseMissing('player_region_mappings', ['id' => $mapping->id]);
    }

    public function test_destroy_clears_but_does_not_break_games_that_used_this_profile(): void
    {
        $profile = $this->profile();
        $game = Game::query()->create([
            'name' => 'Mobile Legends (Malaysia)',
            'slug' => 'mobile-legends-malaysia',
            'player_validator_profile_id' => $profile->id,
        ]);
        $this->actingAsAdmin();

        $this->deleteJson("/api/middleware/validators/{$profile->id}")->assertNoContent();

        $this->assertNull($game->fresh()->player_validator_profile_id);
    }

    public function test_test_action_returns_the_live_normalized_result_on_success(): void
    {
        $profile = $this->profile();
        $this->bindFakeValidator(PlayerValidationResult::valid('acidgameshop', 'Prime.', 'MY'));
        $this->actingAsAdmin();

        $response = $this->postJson("/api/middleware/validators/{$profile->id}/test", [
            'player_id' => '51049607',
            'server_id' => '2005',
        ]);

        $response->assertOk();
        $this->assertTrue($response->json('success'));
        $this->assertTrue($response->json('result.valid'));
        $this->assertSame('Prime.', $response->json('result.nickname'));
        $this->assertSame('MY', $response->json('result.country_code'));
        $this->assertNotNull($response->json('validator.last_tested_at'));
    }

    /**
     * Regression: the returned `validator` must still carry its
     * `mappings` relation — omitting it (an unloaded relation simply
     * doesn't serialize, it doesn't come back as `[]`) crashed the
     * frontend's optimistic state merge, since it replaces the whole
     * profile object with whatever this endpoint returns.
     */
    public function test_test_action_response_still_includes_the_validators_mappings(): void
    {
        $game = Game::query()->create(['name' => 'Mobile Legends (Malaysia)', 'slug' => 'mobile-legends-malaysia']);
        $profile = $this->profile();
        $profile->mappings()->create(['country_code' => 'MY', 'country_name' => 'Malaysia', 'game_id' => $game->id]);
        $this->bindFakeValidator(PlayerValidationResult::valid('acidgameshop', 'Prime.', 'MY'));
        $this->actingAsAdmin();

        $response = $this->postJson("/api/middleware/validators/{$profile->id}/test", [
            'player_id' => '51049607',
        ]);

        $response->assertOk();
        $this->assertCount(1, $response->json('validator.mappings'));
        $this->assertSame('mobile-legends-malaysia', $response->json('validator.mappings.0.game.slug'));
    }

    public function test_test_action_reports_unsupported_when_the_key_has_no_binding(): void
    {
        $profile = $this->profile();
        // Simulate a key that was valid at creation time but whose
        // binding has since been removed — the exact "not actually
        // plugged in" scenario the founder was worried about.
        $this->app->offsetUnset('player-validator.mlbb');
        $this->actingAsAdmin();

        $response = $this->postJson("/api/middleware/validators/{$profile->id}/test", [
            'player_id' => '51049607',
        ]);

        $response->assertOk();
        $this->assertFalse($response->json('success'));
        $this->assertStringContainsString('not plugged in', $response->json('validator.last_test_result'));
    }

    public function test_test_action_reports_provider_unavailable(): void
    {
        $profile = $this->profile();
        $this->bindFakeValidator(new ProviderUnavailableException('all three providers timed out'));
        $this->actingAsAdmin();

        $response = $this->postJson("/api/middleware/validators/{$profile->id}/test", [
            'player_id' => '51049607',
        ]);

        $response->assertOk();
        $this->assertFalse($response->json('success'));
        $this->assertStringContainsString('all providers unavailable', $response->json('validator.last_test_result'));
    }
}
