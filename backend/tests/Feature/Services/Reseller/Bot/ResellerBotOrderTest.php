<?php

namespace Tests\Feature\Services\Reseller\Bot;

use App\Models\Game;
use App\Models\Order;
use App\Models\Package;
use App\Models\PlayerRegionMapping;
use App\Models\PlayerValidation;
use App\Models\PlayerValidatorProfile;
use App\Models\Reseller;
use App\Models\Supplier;
use App\Services\Ledger\LedgerOwnerType;
use App\Services\Ledger\LedgerService;
use App\Services\PlayerValidation\PlayerValidationResult;
use App\Services\PlayerValidation\PlayerValidator;
use App\Services\PlayerValidation\ProviderUnavailableException;
use App\Services\Reseller\Bot\ResellerBotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
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
 * This file: placing an order through the bot.
 */
class ResellerBotOrderTest extends TestCase
{
    use RefreshDatabase;
    use ResellerBotFixtures;

    public function test_order_places_a_wallet_order_and_debits_the_balance(): void
    {
        Queue::fake();
        $this->makePackage(costPrice: 1000, resellerCode: 'MLMY');
        $reseller = $this->makeLinkedReseller(walletBalance: 10000, markupPercent: 10);

        app(ResellerBotService::class)->handle(self::GROUP_ID, '.order MLMY-14 51049607 2005', 'msg-1');

        $this->assertSame(10000 - 1100, app(LedgerService::class)->balance(LedgerOwnerType::ResellerWallet, $reseller->id));
        $this->assertDatabaseHas('orders', ['player_id' => '51049607', 'server_id' => '2005', 'wallet_reseller_id' => $reseller->id]);
    }

    public function test_order_is_idempotent_on_a_repeated_whatsapp_message_id(): void
    {
        Queue::fake();
        $this->makePackage();
        $reseller = $this->makeLinkedReseller(walletBalance: 10000);

        app(ResellerBotService::class)->handle(self::GROUP_ID, '.order MLMY-14 51049607 2005', 'msg-1');
        app(ResellerBotService::class)->handle(self::GROUP_ID, '.order MLMY-14 51049607 2005', 'msg-1');

        $this->assertSame(1, Order::query()->where('wallet_reseller_id', $reseller->id)->count());
    }

    public function test_order_missing_a_required_server_id_is_rejected_and_logged(): void
    {
        $this->makePackage(requiresServerId: true);
        $reseller = $this->makeLinkedReseller();

        app(ResellerBotService::class)->handle(self::GROUP_ID, '.order MLMY-14 51049607', 'msg-1');

        $this->assertDatabaseHas('reseller_bot_command_logs', [
            'reseller_id' => $reseller->id,
            'failure_reason' => 'missing_server_id',
        ]);
        $this->assertSame(0, Order::query()->count());
    }

    /** ADR-097 decision 10/19 — the Bot enforces the same zone value-restriction as storefront/Reseller API, not just presence. */
    public function test_order_with_a_zone_id_value_outside_the_defined_list_is_rejected_and_logged(): void
    {
        $supplier = Supplier::query()->create(['name' => 'Gamevion', 'slug' => 'gamevion-bot-zone', 'api_config' => [], 'currency' => 'MYR']);
        $game = Game::query()->create([
            'name' => 'MLBB', 'slug' => 'mlbb-bot-zone-test', 'reseller_code' => 'MLZONE', 'is_active' => true,
            'validation_rules' => ['extra_field' => 'zone_id', 'zone_options' => ['SouthEastAsia', 'MENA']],
        ]);
        Package::query()->create([
            'game_id' => $game->id, 'name' => '14 Diamond', 'denomination' => 14,
            'cost_price' => 1000, 'standard_selling_price' => 1200,
            'supplier_id' => $supplier->id, 'supplier_package_ref' => 'A', 'is_active' => true,
        ]);
        $reseller = $this->makeLinkedReseller();

        app(ResellerBotService::class)->handle(self::GROUP_ID, '.order MLZONE-14 51049607 SEA', 'msg-1');

        $this->assertDatabaseHas('reseller_bot_command_logs', [
            'reseller_id' => $reseller->id,
            'failure_reason' => 'invalid_zone_id',
        ]);
        $this->assertSame(0, Order::query()->count());
    }

    public function test_order_with_a_zone_id_value_matching_the_defined_list_is_placed(): void
    {
        Queue::fake();
        $supplier = Supplier::query()->create(['name' => 'Gamevion', 'slug' => 'gamevion-bot-zone-2', 'api_config' => [], 'currency' => 'MYR']);
        $game = Game::query()->create([
            'name' => 'MLBB', 'slug' => 'mlbb-bot-zone-test-2', 'reseller_code' => 'MLZONE2', 'is_active' => true,
            'validation_rules' => ['extra_field' => 'zone_id', 'zone_options' => ['SouthEastAsia', 'MENA']],
        ]);
        Package::query()->create([
            'game_id' => $game->id, 'name' => '14 Diamond', 'denomination' => 14,
            'cost_price' => 1000, 'standard_selling_price' => 1200,
            'supplier_id' => $supplier->id, 'supplier_package_ref' => 'A', 'is_active' => true,
        ]);
        $reseller = $this->makeLinkedReseller();

        app(ResellerBotService::class)->handle(self::GROUP_ID, '.order MLZONE2-14 51049607 SouthEastAsia', 'msg-1');

        $this->assertSame(1, Order::query()->where('wallet_reseller_id', $reseller->id)->count());
    }

    public function test_order_with_an_unknown_product_code_is_rejected_and_logged(): void
    {
        $reseller = $this->makeLinkedReseller();

        app(ResellerBotService::class)->handle(self::GROUP_ID, '.order NOPE-1 51049607', 'msg-1');

        $this->assertDatabaseHas('reseller_bot_command_logs', [
            'reseller_id' => $reseller->id,
            'failure_reason' => 'unknown_product_code',
        ]);
    }

    public function test_order_with_insufficient_balance_is_rejected_and_logged(): void
    {
        $this->makePackage(costPrice: 1000);
        $reseller = $this->makeLinkedReseller(walletBalance: 100, markupPercent: 10);

        app(ResellerBotService::class)->handle(self::GROUP_ID, '.order MLMY-14 51049607 2005', 'msg-1');

        $this->assertDatabaseHas('reseller_bot_command_logs', [
            'reseller_id' => $reseller->id,
            'failure_reason' => 'insufficient_balance',
        ]);
        $this->assertSame(0, Order::query()->count());
    }

    // --- ADR-093: .order auto player-ID/region validation ---

    public function test_order_rejects_an_invalid_player_id_when_the_game_has_a_validator(): void
    {
        $fake = new class implements PlayerValidator
        {
            public function validate(string $playerId, ?string $serverId): PlayerValidationResult
            {
                return PlayerValidationResult::invalid('mlbb');
            }
        };
        $this->app->bind('player-validator.mlbb', fn () => $fake);

        $game = $this->makePackage(resellerCode: 'MLMY')->game;
        $profile = PlayerValidatorProfile::query()->create(['name' => 'ML Validator', 'key' => 'mlbb']);
        $game->update(['player_validator_enabled' => true, 'player_validator_profile_id' => $profile->id]);
        $reseller = $this->makeLinkedReseller();

        app(ResellerBotService::class)->handle(self::GROUP_ID, '.order MLMY-14 51049607 2005', 'msg-1');

        $this->assertSame(0, Order::query()->count());
        $this->assertDatabaseHas('reseller_bot_command_logs', [
            'reseller_id' => $reseller->id,
            'failure_reason' => 'invalid_player_id',
        ]);
        $this->assertDatabaseHas('player_validations', ['game_id' => $game->id, 'status' => 'invalid']);
    }

    public function test_order_rejects_a_player_id_whose_region_maps_to_a_different_game(): void
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
        $mlid = $this->makePackage(resellerCode: 'MLID')->game;
        $mlid->update(['player_validator_enabled' => true, 'player_validator_profile_id' => $profile->id]);
        // The player's real country (MY) maps to MLMY, not the MLID game the order is placed against.
        PlayerRegionMapping::query()->create([
            'player_validator_profile_id' => $profile->id, 'country_code' => 'MY',
            'country_name' => 'Malaysia', 'game_id' => $mlmy->id,
        ]);
        $reseller = $this->makeLinkedReseller();

        app(ResellerBotService::class)->handle(self::GROUP_ID, '.order MLID-14 51049607 2005', 'msg-1');

        $this->assertSame(0, Order::query()->count());
        $this->assertDatabaseHas('reseller_bot_command_logs', [
            'reseller_id' => $reseller->id,
            'failure_reason' => 'wrong_region_player_id',
        ]);
        $this->assertDatabaseHas('player_validations', ['game_id' => $mlid->id, 'status' => 'wrong_region']);
    }

    public function test_order_proceeds_when_the_player_id_is_valid_for_the_correct_region(): void
    {
        Queue::fake();
        $fake = new class implements PlayerValidator
        {
            public function validate(string $playerId, ?string $serverId): PlayerValidationResult
            {
                return PlayerValidationResult::valid('mlbb', 'TestNick', 'MY');
            }
        };
        $this->app->bind('player-validator.mlbb', fn () => $fake);

        $profile = PlayerValidatorProfile::query()->create(['name' => 'ML Validator', 'key' => 'mlbb']);
        $game = $this->makePackage(resellerCode: 'MLMY')->game;
        $game->update(['player_validator_enabled' => true, 'player_validator_profile_id' => $profile->id]);
        PlayerRegionMapping::query()->create([
            'player_validator_profile_id' => $profile->id, 'country_code' => 'MY',
            'country_name' => 'Malaysia', 'game_id' => $game->id,
        ]);
        $reseller = $this->makeLinkedReseller();

        app(ResellerBotService::class)->handle(self::GROUP_ID, '.order MLMY-14 51049607 2005', 'msg-1');

        $this->assertDatabaseHas('orders', ['player_id' => '51049607', 'wallet_reseller_id' => $reseller->id]);
        $this->assertDatabaseHas('player_validations', ['game_id' => $game->id, 'status' => 'valid']);
    }

    /** ADR-093 decision 2 — an unofficial third-party outage must never block a legitimate paid order. */
    public function test_order_proceeds_when_the_validator_provider_is_unavailable(): void
    {
        Queue::fake();
        $fake = new class implements PlayerValidator
        {
            public function validate(string $playerId, ?string $serverId): PlayerValidationResult
            {
                throw new ProviderUnavailableException('source down');
            }
        };
        $this->app->bind('player-validator.mlbb', fn () => $fake);

        $profile = PlayerValidatorProfile::query()->create(['name' => 'ML Validator', 'key' => 'mlbb']);
        $game = $this->makePackage(resellerCode: 'MLMY')->game;
        $game->update(['player_validator_enabled' => true, 'player_validator_profile_id' => $profile->id]);
        $reseller = $this->makeLinkedReseller();

        app(ResellerBotService::class)->handle(self::GROUP_ID, '.order MLMY-14 51049607 2005', 'msg-1');

        $this->assertDatabaseHas('orders', ['player_id' => '51049607', 'wallet_reseller_id' => $reseller->id]);
        $this->assertSame(0, PlayerValidation::query()->count());
    }

    /** Same fail-open posture for a misconfigured/unbound validator key — a config error is not the reseller's fault either. */
    public function test_order_proceeds_when_the_validator_key_is_unsupported(): void
    {
        Queue::fake();
        $profile = PlayerValidatorProfile::query()->create(['name' => 'Unbound', 'key' => 'no-such-validator']);
        $game = $this->makePackage(resellerCode: 'MLMY')->game;
        $game->update(['player_validator_enabled' => true, 'player_validator_profile_id' => $profile->id]);
        $reseller = $this->makeLinkedReseller();

        app(ResellerBotService::class)->handle(self::GROUP_ID, '.order MLMY-14 51049607 2005', 'msg-1');

        $this->assertDatabaseHas('orders', ['player_id' => '51049607', 'wallet_reseller_id' => $reseller->id]);
    }

    public function test_order_skips_validation_entirely_when_the_game_has_no_validator_profile(): void
    {
        Queue::fake();
        $this->makePackage(resellerCode: 'MLMY');
        $reseller = $this->makeLinkedReseller();

        app(ResellerBotService::class)->handle(self::GROUP_ID, '.order MLMY-14 51049607 2005', 'msg-1');

        $this->assertDatabaseHas('orders', ['player_id' => '51049607', 'wallet_reseller_id' => $reseller->id]);
        $this->assertSame(0, PlayerValidation::query()->count());
    }

    public function test_order_creates_a_bot_order_notification_row_for_message_2(): void
    {
        Queue::fake();
        $this->makePackage(costPrice: 1000, resellerCode: 'MLMY');
        $this->makeLinkedReseller(walletBalance: 10000);

        app(ResellerBotService::class)->handle(self::GROUP_ID, '.order MLMY-14 51049607 2005', 'msg-1');

        $order = Order::query()->firstOrFail();
        $this->assertSame('reseller_bot', $order->placed_via->value);
        $this->assertDatabaseHas('reseller_bot_order_notifications', [
            'order_id' => $order->id,
            'whatsapp_group_id' => self::GROUP_ID,
            'last_notified_delivery_status' => null,
        ]);
    }

    public function test_order_rejects_a_uid_whose_server_is_not_the_given_zone(): void
    {
        $this->makeZoneGame('GSMY');
        $reseller = $this->makeLinkedReseller();

        app(ResellerBotService::class)->handle(self::GROUP_ID, '.order GSMY-14 834140081 America', 'msg-1');

        $this->assertSame(0, Order::query()->count());
        $this->assertDatabaseHas('reseller_bot_command_logs', [
            'reseller_id' => $reseller->id,
            'failure_reason' => 'wrong_zone_player_id',
        ]);
    }

    public function test_two_order_lines_in_one_message_place_nothing(): void
    {
        $this->captureReplies();
        $this->makePackage(requiresServerId: true);
        $reseller = $this->makeLinkedReseller();

        app(ResellerBotService::class)->handle(self::GROUP_ID, ".order MLMY-14 51049607 2005\n.order MLMY-14 11111111 2001", 'msg-1');

        $this->assertSame(0, Order::query()->count());
        $this->assertDatabaseHas('reseller_bot_command_logs', ['reseller_id' => $reseller->id, 'failure_reason' => 'extra_arguments']);
        $this->assertStringContainsString('satu arahan sahaja', $this->replies()[0]);
    }

    /** The trace's own example: `customer_no` would have been `123456tq`. */
    public function test_a_trailing_word_on_a_user_id_only_game_is_rejected_not_sent_as_a_server_id(): void
    {
        $this->captureReplies();
        $this->makePackage(requiresServerId: false);
        $reseller = $this->makeLinkedReseller();

        app(ResellerBotService::class)->handle(self::GROUP_ID, '.order MLMY-14 123456 tq', 'msg-1');

        $this->assertSame(0, Order::query()->count());
        $this->assertDatabaseHas('reseller_bot_command_logs', ['reseller_id' => $reseller->id, 'failure_reason' => 'server_id_not_taken']);
        $this->assertSame("Game ini tidak memerlukan Server ID.\nFormat: .order MLMY-14 {userId}", $this->replies()[0]);
    }

    public function test_a_non_numeric_user_id_is_rejected_with_the_games_format(): void
    {
        $this->captureReplies();
        $this->makePackage(requiresServerId: false);
        $this->makeLinkedReseller();

        app(ResellerBotService::class)->handle(self::GROUP_ID, '.order MLMY-14 12345abc', 'msg-1');

        $this->assertSame(0, Order::query()->count());
        $this->assertSame("User ID mesti nombor sahaja.\nFormat: .order MLMY-14 {userId}", $this->replies()[0]);
    }

    public function test_a_text_format_game_places_a_riot_id_order(): void
    {
        $package = $this->makePackage(requiresServerId: false);
        $package->game->update(['validation_rules' => ['player_id_format' => 'text']]);
        $this->makeLinkedReseller();

        app(ResellerBotService::class)->handle(self::GROUP_ID, '.order MLMY-14 JettMain#1234', 'msg-1');

        $this->assertSame('JettMain#1234', Order::query()->firstOrFail()->player_id);
    }
}
