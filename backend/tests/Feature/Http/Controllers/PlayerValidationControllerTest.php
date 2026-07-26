<?php

namespace Tests\Feature\Http\Controllers;

use App\Models\Game;
use App\Models\PlayerValidation;
use App\Models\PlayerValidatorProfile;
use App\Services\PlayerValidation\PlayerValidationResult;
use App\Services\PlayerValidation\PlayerValidator;
use App\Services\PlayerValidation\ProviderUnavailableException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlayerValidationControllerTest extends TestCase
{
    use RefreshDatabase;

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

    private function profile(): PlayerValidatorProfile
    {
        return PlayerValidatorProfile::query()->create(['name' => 'Mobile Legends Validator', 'key' => 'mlbb']);
    }

    private function game(array $overrides = []): Game
    {
        return Game::query()->create(array_merge([
            'name' => 'Mobile Legends (Malaysia)',
            'slug' => 'mobile-legends-malaysia',
        ], $overrides));
    }

    public function test_returns_422_when_game_does_not_have_validation_enabled(): void
    {
        $game = $this->game();

        $response = $this->postJson("/api/games/{$game->id}/validate-player", ['player_id' => '51049607']);

        $response->assertUnprocessable();
        $this->assertSame(0, PlayerValidation::query()->count());
    }

    public function test_returns_422_when_enabled_but_no_profile_assigned(): void
    {
        $game = $this->game(['player_validator_enabled' => true]);

        $response = $this->postJson("/api/games/{$game->id}/validate-player", ['player_id' => '51049607']);

        $response->assertUnprocessable();
    }

    public function test_valid_id_in_the_correct_region_returns_valid(): void
    {
        $profile = $this->profile();
        $game = $this->game(['player_validator_enabled' => true, 'player_validator_profile_id' => $profile->id]);
        $profile->mappings()->create(['country_code' => 'MY', 'country_name' => 'Malaysia', 'game_id' => $game->id]);
        $this->bindFakeValidator(PlayerValidationResult::valid('AcidGameShop', 'TestNickname', 'MY'));

        $response = $this->postJson("/api/games/{$game->id}/validate-player", [
            'player_id' => '51049607', 'server_id' => '2005',
        ]);

        $response->assertOk();
        $response->assertJson(['status' => 'valid', 'nickname' => 'TestNickname', 'country_code' => 'MY', 'redirect_game' => null]);

        $row = PlayerValidation::query()->sole();
        $this->assertSame($game->id, $row->game_id);
        $this->assertSame('51049607', $row->player_id);
        $this->assertSame('valid', $row->status);
        $this->assertSame('AcidGameShop', $row->provider);
    }

    public function test_invalid_id_returns_invalid_and_records_it(): void
    {
        $profile = $this->profile();
        $game = $this->game(['player_validator_enabled' => true, 'player_validator_profile_id' => $profile->id]);
        $this->bindFakeValidator(PlayerValidationResult::invalid('AcidGameShop'));

        $response = $this->postJson("/api/games/{$game->id}/validate-player", ['player_id' => 'bad-id']);

        $response->assertOk();
        $response->assertJson(['status' => 'invalid', 'nickname' => null, 'country_code' => null]);
        $this->assertSame('invalid', PlayerValidation::query()->sole()->status);
    }

    public function test_valid_id_mapped_to_a_different_game_returns_wrong_region_with_redirect(): void
    {
        $profile = $this->profile();
        $game = $this->game(['player_validator_enabled' => true, 'player_validator_profile_id' => $profile->id]);
        $otherGame = Game::query()->create(['name' => 'Mobile Legends (Philippines)', 'slug' => 'mobile-legends-philippines']);
        $profile->mappings()->create(['country_code' => 'PH', 'country_name' => 'Philippines', 'game_id' => $otherGame->id]);
        $this->bindFakeValidator(PlayerValidationResult::valid('AcidGameShop', 'TestNickname', 'PH'));

        $response = $this->postJson("/api/games/{$game->id}/validate-player", ['player_id' => '51049607']);

        $response->assertOk();
        $response->assertJson([
            'status' => 'wrong_region',
            'redirect_game' => ['slug' => 'mobile-legends-philippines', 'name' => 'Mobile Legends (Philippines)'],
        ]);
        $this->assertSame('wrong_region', PlayerValidation::query()->sole()->status);
    }

    public function test_valid_id_with_no_country_data_returns_region_unknown(): void
    {
        $profile = $this->profile();
        $game = $this->game(['player_validator_enabled' => true, 'player_validator_profile_id' => $profile->id]);
        $this->bindFakeValidator(PlayerValidationResult::valid('MooGold', 'TestNickname', null));

        $response = $this->postJson("/api/games/{$game->id}/validate-player", ['player_id' => '51049607']);

        $response->assertOk();
        $response->assertJson(['status' => 'region_unknown']);
    }

    public function test_valid_id_with_an_unmapped_country_returns_region_unknown(): void
    {
        $profile = $this->profile();
        $game = $this->game(['player_validator_enabled' => true, 'player_validator_profile_id' => $profile->id]);
        $this->bindFakeValidator(PlayerValidationResult::valid('AcidGameShop', 'TestNickname', 'ID'));

        $response = $this->postJson("/api/games/{$game->id}/validate-player", ['player_id' => '51049607']);

        $response->assertOk();
        $response->assertJson(['status' => 'region_unknown']);
    }

    public function test_all_providers_unavailable_returns_region_unknown_and_still_records_the_attempt(): void
    {
        $profile = $this->profile();
        $game = $this->game(['player_validator_enabled' => true, 'player_validator_profile_id' => $profile->id]);
        $this->bindFakeValidator(new ProviderUnavailableException('all down'));

        $response = $this->postJson("/api/games/{$game->id}/validate-player", ['player_id' => '51049607']);

        $response->assertOk();
        $response->assertJson(['status' => 'region_unknown', 'nickname' => null, 'country_code' => null]);
        $row = PlayerValidation::query()->sole();
        $this->assertSame('region_unknown', $row->status);
        $this->assertNull($row->provider);
    }

    public function test_does_not_require_authentication(): void
    {
        $profile = $this->profile();
        $game = $this->game(['player_validator_enabled' => true, 'player_validator_profile_id' => $profile->id]);
        $this->bindFakeValidator(PlayerValidationResult::invalid('AcidGameShop'));

        $this->postJson("/api/games/{$game->id}/validate-player", ['player_id' => '51049607'])->assertOk();
    }
}
