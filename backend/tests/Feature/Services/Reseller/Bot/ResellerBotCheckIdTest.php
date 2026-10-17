<?php

namespace Tests\Feature\Services\Reseller\Bot;

use App\Models\Game;
use App\Models\Order;
use App\Models\PlayerRegionMapping;
use App\Models\PlayerValidation;
use App\Models\PlayerValidatorProfile;
use App\Models\Reseller;
use App\Models\ResellerBotCommandLog;
use App\Services\PlayerValidation\PlayerValidationResult;
use App\Services\PlayerValidation\PlayerValidator;
use App\Services\Reseller\Bot\ResellerBotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Support\ResellerBotFixtures;
use Tests\TestCase;

/**
 * ADR-075 / PR-F build addendum — same money/catalog seams the Reseller
 * API (PR-E) already tests, over the WhatsApp command surface instead of
 * HTTP. `OpenWaClient::sendText()` is unconfigured in tests (no
 * session_id/api_key), so it no-ops rather than making a real HTTP
 * call — every assertion here goes through the DB/ledger, never a
 * mocked reply.
 *
 * This file: the .checkid command.
 */
class ResellerBotCheckIdTest extends TestCase
{
    use RefreshDatabase;
    use ResellerBotFixtures;

    /** ADR-093 decision 3 — the auto-check inside .order consumes the exact same bucket .checkid itself does. */
    public function test_order_validation_is_rejected_once_the_shared_checkid_rate_limit_is_exhausted(): void
    {
        $profile = PlayerValidatorProfile::query()->create(['name' => 'ML Validator', 'key' => 'mlbb']);
        $game = $this->makePackage(resellerCode: 'MLMY')->game;
        $game->update(['player_validator_enabled' => true, 'player_validator_profile_id' => $profile->id]);
        $reseller = $this->makeLinkedReseller();

        for ($i = 0; $i < 10; $i++) {
            RateLimiter::hit("reseller-bot-checkid:{$reseller->id}", 60);
        }

        app(ResellerBotService::class)->handle(self::GROUP_ID, '.order MLMY-14 51049607 2005', 'msg-1');

        $this->assertSame(0, Order::query()->count());
    }

    public function test_checkid_reports_unsupported_when_game_has_no_validator_profile(): void
    {
        $this->makePackage(resellerCode: 'MLMY');
        $this->makeLinkedReseller();

        // No crash/log-as-failure expected — checkIdUnsupported() is a
        // plain informational reply, not a logged failure.
        app(ResellerBotService::class)->handle(self::GROUP_ID, '.checkid MLMY 51049607 2005', 'msg-1');

        $this->assertSame(0, ResellerBotCommandLog::query()->count());
    }

    public function test_checkid_with_unknown_game_code_is_rejected_and_logged(): void
    {
        $reseller = $this->makeLinkedReseller();

        app(ResellerBotService::class)->handle(self::GROUP_ID, '.checkid NOPE 51049607', 'msg-1');

        $this->assertDatabaseHas('reseller_bot_command_logs', [
            'reseller_id' => $reseller->id,
            'failure_reason' => 'unknown_game_code',
        ]);
    }

    public function test_checkid_records_a_valid_result_via_the_shared_validator_registry(): void
    {
        $fake = new class implements PlayerValidator
        {
            public function validate(string $playerId, ?string $serverId): PlayerValidationResult
            {
                return PlayerValidationResult::valid('mlbb', 'TestNick', 'MY');
            }
        };
        $this->app->bind('player-validator.mlbb', fn () => $fake);

        $game = $this->makePackage(resellerCode: 'MLMY')->game;
        $profile = PlayerValidatorProfile::query()->create(['name' => 'ML Validator', 'key' => 'mlbb']);
        $game->update(['player_validator_enabled' => true, 'player_validator_profile_id' => $profile->id]);
        $this->makeLinkedReseller();

        app(ResellerBotService::class)->handle(self::GROUP_ID, '.checkid MLMY 51049607 2005', 'msg-1');

        $this->assertDatabaseHas('player_validations', [
            'game_id' => $game->id, 'player_id' => '51049607', 'status' => 'valid', 'nickname' => 'TestNick',
        ]);
    }

    public function test_checkid_flags_a_player_whose_region_maps_to_a_different_game_code(): void
    {
        $fake = new class implements PlayerValidator
        {
            public function validate(string $playerId, ?string $serverId): PlayerValidationResult
            {
                return PlayerValidationResult::valid('mlbb', 'TestNick', 'MY');
            }
        };
        $this->app->bind('player-validator.mlbb', fn () => $fake);

        $profile = PlayerValidatorProfile::query()->create(['name' => 'ML Validator', 'key' => 'mlbb']);
        $mlmy = Game::query()->create(['name' => 'Mobile Legends Malaysia', 'slug' => 'ml-my', 'reseller_code' => 'MLMY', 'is_active' => true]);
        $mlid = Game::query()->create([
            'name' => 'Mobile Legends Indonesia', 'slug' => 'ml-id', 'reseller_code' => 'MLID', 'is_active' => true,
            'player_validator_enabled' => true, 'player_validator_profile_id' => $profile->id,
        ]);
        // The player's real country (MY) maps to the MLMY game, not MLID.
        PlayerRegionMapping::query()->create([
            'player_validator_profile_id' => $profile->id, 'country_code' => 'MY',
            'country_name' => 'Malaysia', 'game_id' => $mlmy->id,
        ]);
        $this->makeLinkedReseller();

        // `.checkid MLID` on a Malaysian player — must land as wrong_region,
        // not a plain "valid".
        app(ResellerBotService::class)->handle(self::GROUP_ID, '.checkid MLID 51049607', 'msg-1');

        $this->assertDatabaseHas('player_validations', [
            'game_id' => $mlid->id, 'player_id' => '51049607', 'status' => 'wrong_region',
        ]);
    }

    /** ADR-005 2026-10-16 third addendum — same ZoneCheck as the storefront. */
    public function test_checkid_flags_a_uid_whose_server_is_not_the_given_zone(): void
    {
        $game = $this->makeZoneGame('GSMY');
        $this->makeLinkedReseller();

        app(ResellerBotService::class)->handle(self::GROUP_ID, '.checkid GSMY 834140081 America', 'msg-1');

        $this->assertDatabaseHas('player_validations', [
            'game_id' => $game->id, 'player_id' => '834140081', 'server_id' => 'America', 'status' => 'wrong_zone',
        ]);
    }

    public function test_checkid_is_valid_when_the_uids_server_is_the_given_zone(): void
    {
        $game = $this->makeZoneGame('GSMY');
        $this->makeLinkedReseller();

        app(ResellerBotService::class)->handle(self::GROUP_ID, '.checkid GSMY 834140081 Asia', 'msg-1');

        $this->assertDatabaseHas('player_validations', ['game_id' => $game->id, 'status' => 'valid']);
    }

    public function test_checkid_stays_valid_when_the_region_maps_to_the_same_game_code(): void
    {
        $fake = new class implements PlayerValidator
        {
            public function validate(string $playerId, ?string $serverId): PlayerValidationResult
            {
                return PlayerValidationResult::valid('mlbb', 'TestNick', 'MY');
            }
        };
        $this->app->bind('player-validator.mlbb', fn () => $fake);

        $profile = PlayerValidatorProfile::query()->create(['name' => 'ML Validator', 'key' => 'mlbb']);
        $mlmy = $this->makePackage(resellerCode: 'MLMY')->game;
        $mlmy->update(['player_validator_enabled' => true, 'player_validator_profile_id' => $profile->id]);
        PlayerRegionMapping::query()->create([
            'player_validator_profile_id' => $profile->id, 'country_code' => 'MY',
            'country_name' => 'Malaysia', 'game_id' => $mlmy->id,
        ]);
        $this->makeLinkedReseller();

        app(ResellerBotService::class)->handle(self::GROUP_ID, '.checkid MLMY 51049607 2005', 'msg-1');

        $this->assertDatabaseHas('player_validations', [
            'game_id' => $mlmy->id, 'player_id' => '51049607', 'status' => 'valid',
        ]);
    }

    public function test_checkid_is_rate_limited_more_strictly_than_other_commands(): void
    {
        $fake = new class implements PlayerValidator
        {
            public function validate(string $playerId, ?string $serverId): PlayerValidationResult
            {
                return PlayerValidationResult::valid('mlbb', 'TestNick', 'MY');
            }
        };
        $this->app->bind('player-validator.mlbb', fn () => $fake);

        $game = $this->makePackage(resellerCode: 'MLMY')->game;
        $profile = PlayerValidatorProfile::query()->create(['name' => 'ML Validator', 'key' => 'mlbb']);
        $game->update(['player_validator_enabled' => true, 'player_validator_profile_id' => $profile->id]);
        $reseller = $this->makeLinkedReseller();
        RateLimiter::clear("reseller-bot-checkid:{$reseller->id}");

        // 11 calls, decision 8's limit is 10/min — the 11th must be
        // blocked before ever reaching the validator, so only 10
        // `player_validations` rows should ever get written.
        for ($i = 0; $i < 11; $i++) {
            app(ResellerBotService::class)->handle(self::GROUP_ID, '.checkid MLMY 51049607 2005', "msg-{$i}");
        }

        $this->assertSame(10, PlayerValidation::query()->count());
    }

    public function test_checkid_rejects_a_malformed_id_before_the_paid_validator(): void
    {
        $game = $this->makePackage(requiresServerId: false)->game;
        $profile = PlayerValidatorProfile::query()->create(['name' => 'ML Validator', 'key' => 'mlbb']);
        $game->update(['player_validator_enabled' => true, 'player_validator_profile_id' => $profile->id]);
        $this->app->bind('player-validator.mlbb', fn () => throw new \LogicException('the paid validator must not be called'));
        $this->makeLinkedReseller();

        app(ResellerBotService::class)->handle(self::GROUP_ID, '.checkid MLMY 1234(5678)', 'msg-1');

        $this->assertSame(0, PlayerValidation::query()->count());
        $this->assertDatabaseHas('reseller_bot_command_logs', ['failure_reason' => 'player_id_digits_only']);
    }
}
