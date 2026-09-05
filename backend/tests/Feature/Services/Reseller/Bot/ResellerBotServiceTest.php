<?php

namespace Tests\Feature\Services\Reseller\Bot;

use App\Models\Game;
use App\Models\Order;
use App\Models\Package;
use App\Models\PlayerRegionMapping;
use App\Models\PlayerValidation;
use App\Models\PlayerValidatorProfile;
use App\Models\Reseller;
use App\Models\ResellerBotCommandLog;
use App\Models\ResellerTier;
use App\Models\ResellerWhatsAppGroup;
use App\Models\ResellerWhatsAppPendingLink;
use App\Models\Supplier;
use App\Services\Ledger\LedgerOwnerType;
use App\Services\Ledger\LedgerService;
use App\Services\PlayerValidation\PlayerValidationResult;
use App\Services\PlayerValidation\PlayerValidator;
use App\Services\Reseller\Bot\ResellerBotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * ADR-075 / PR-F build addendum — same money/catalog seams the Reseller
 * API (PR-E) already tests, over the WhatsApp command surface instead of
 * HTTP. `OpenWaClient::sendText()` is unconfigured in tests (no
 * session_id/api_key), so it no-ops rather than making a real HTTP
 * call — every assertion here goes through the DB/ledger, never a
 * mocked reply.
 */
class ResellerBotServiceTest extends TestCase
{
    use RefreshDatabase;

    private const GROUP_ID = 'g1@g.us';

    private function makePackage(int $costPrice = 1000, string $resellerCode = 'MLMY', bool $requiresServerId = true): Package
    {
        $supplier = Supplier::query()->create(['name' => 'Gamevion', 'slug' => 'gamevion', 'api_config' => [], 'currency' => 'MYR']);
        $game = Game::query()->create([
            'name' => 'Mobile Legends Malaysia', 'slug' => 'mlbb-my-'.strtolower($resellerCode),
            'reseller_code' => $resellerCode, 'is_active' => true,
            'validation_rules' => $requiresServerId ? ['extra_field' => 'Zone ID'] : null,
        ]);

        return Package::query()->create([
            'game_id' => $game->id, 'name' => '14 Diamond', 'denomination' => 14,
            'cost_price' => $costPrice, 'standard_selling_price' => $costPrice + 200,
            'supplier_id' => $supplier->id, 'supplier_package_ref' => 'A', 'is_active' => true,
        ]);
    }

    private function makeLinkedReseller(int $walletBalance = 10000, float $markupPercent = 10): Reseller
    {
        $this->primaryAffiliate();
        $tier = ResellerTier::query()->create(['name' => 'Gold', 'markup_percent' => $markupPercent, 'is_active' => true, 'sort_order' => 1]);
        $reseller = Reseller::query()->create(['business_name' => 'Acme', 'reseller_tier_id' => $tier->id, 'is_active' => true]);
        $ledger = app(LedgerService::class);
        $ledger->openAccount(LedgerOwnerType::ResellerWallet, $reseller->id);
        $ledger->credit(LedgerOwnerType::ResellerWallet, $reseller->id, $walletBalance, 'wallet_topup');
        ResellerWhatsAppGroup::query()->create(['reseller_id' => $reseller->id, 'whatsapp_group_id' => self::GROUP_ID, 'is_active' => true]);

        return $reseller;
    }

    public function test_a_message_from_an_unlinked_group_creates_a_pending_link(): void
    {
        app(ResellerBotService::class)->handle('unlinked@g.us', 'hi there', 'msg-1');

        $this->assertDatabaseHas('reseller_whatsapp_pending_links', ['whatsapp_group_id' => 'unlinked@g.us']);
    }

    public function test_balance_command_reports_the_wallet_balance(): void
    {
        $reseller = $this->makeLinkedReseller(walletBalance: 5000);

        // No exception, no exception-based assertion needed here — the
        // reply itself isn't observable without mocking OpenWaClient
        // (best-effort, no-ops when unconfigured, see class docblock).
        // The real assertion is that handle() completes cleanly and
        // never mutates the ledger for a read-only command.
        app(ResellerBotService::class)->handle(self::GROUP_ID, '.baki', 'msg-1');

        $this->assertSame(5000, app(LedgerService::class)->balance(LedgerOwnerType::ResellerWallet, $reseller->id));
    }

    public function test_unrecognized_command_is_logged(): void
    {
        $reseller = $this->makeLinkedReseller();

        app(ResellerBotService::class)->handle(self::GROUP_ID, '.blahblah', 'msg-1');

        $this->assertDatabaseHas('reseller_bot_command_logs', [
            'reseller_id' => $reseller->id,
            'failure_reason' => 'unrecognized_command',
        ]);
    }

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

    public function test_listgames_and_list_game_packages_commands_run_without_error(): void
    {
        $this->makePackage();
        $this->makeLinkedReseller();

        // Both are read-only formatting paths — the real regression risk
        // this locks in is that they run cleanly against real DB state
        // (a Game/Package/Reseller-tier join) without throwing, not the
        // exact reply text (which isn't observable without mocking
        // OpenWaClient).
        app(ResellerBotService::class)->handle(self::GROUP_ID, '.listharga', 'msg-1');
        app(ResellerBotService::class)->handle(self::GROUP_ID, '.list MLMY', 'msg-2');

        $this->assertSame(0, ResellerBotCommandLog::query()->count());
    }

    public function test_unknown_game_code_on_list_is_logged(): void
    {
        $reseller = $this->makeLinkedReseller();

        app(ResellerBotService::class)->handle(self::GROUP_ID, '.list NOPE', 'msg-1');

        $this->assertDatabaseHas('reseller_bot_command_logs', [
            'reseller_id' => $reseller->id,
            'failure_reason' => 'unknown_game_code',
        ]);
    }

    public function test_pending_link_is_not_created_for_an_already_linked_group(): void
    {
        $this->makeLinkedReseller();

        app(ResellerBotService::class)->handle(self::GROUP_ID, '.baki', 'msg-1');

        $this->assertSame(0, ResellerWhatsAppPendingLink::query()->count());
    }

    public function test_order_creates_a_bot_order_notification_row_for_message_2(): void
    {
        Queue::fake();
        $this->makePackage(costPrice: 1000, resellerCode: 'MLMY');
        $this->makeLinkedReseller(walletBalance: 10000);

        app(ResellerBotService::class)->handle(self::GROUP_ID, '.order MLMY-14 51049607 2005', 'msg-1');

        $order = Order::query()->firstOrFail();
        $this->assertDatabaseHas('reseller_bot_order_notifications', [
            'order_id' => $order->id,
            'whatsapp_group_id' => self::GROUP_ID,
            'last_notified_delivery_status' => null,
        ]);
    }

    public function test_info_command_runs_without_error(): void
    {
        $this->makeLinkedReseller();

        app(ResellerBotService::class)->handle(self::GROUP_ID, '.info', 'msg-1');

        $this->assertSame(0, ResellerBotCommandLog::query()->count());
    }

    public function test_trackorder_finds_the_resellers_own_order(): void
    {
        Queue::fake();
        $this->makePackage(costPrice: 1000, resellerCode: 'MLMY');
        $this->makeLinkedReseller(walletBalance: 10000);
        app(ResellerBotService::class)->handle(self::GROUP_ID, '.order MLMY-14 51049607 2005', 'msg-1');
        $order = Order::query()->firstOrFail();

        app(ResellerBotService::class)->handle(self::GROUP_ID, ".trackorder {$order->order_number}", 'msg-2');

        $this->assertSame(0, ResellerBotCommandLog::query()->where('failure_reason', 'order_not_found_or_not_owned')->count());
    }

    public function test_trackorder_rejects_a_wrong_order_number(): void
    {
        $reseller = $this->makeLinkedReseller();

        app(ResellerBotService::class)->handle(self::GROUP_ID, '.trackorder PG-DOESNOTEXIST', 'msg-1');

        $this->assertDatabaseHas('reseller_bot_command_logs', [
            'reseller_id' => $reseller->id,
            'failure_reason' => 'order_not_found_or_not_owned',
        ]);
    }

    public function test_trackorder_rejects_an_order_belonging_to_a_different_reseller(): void
    {
        Queue::fake();
        $this->makePackage(costPrice: 1000, resellerCode: 'MLMY');
        $this->makeLinkedReseller(walletBalance: 10000);
        app(ResellerBotService::class)->handle(self::GROUP_ID, '.order MLMY-14 51049607 2005', 'msg-1');
        $order = Order::query()->firstOrFail();

        // A second reseller, linked to a different group, tries to
        // track the first reseller's order number.
        $tier = ResellerTier::query()->create(['name' => 'Silver', 'markup_percent' => 5, 'is_active' => true, 'sort_order' => 2]);
        $otherReseller = Reseller::query()->create(['business_name' => 'Other', 'reseller_tier_id' => $tier->id, 'is_active' => true]);
        app(LedgerService::class)->openAccount(LedgerOwnerType::ResellerWallet, $otherReseller->id);
        ResellerWhatsAppGroup::query()->create(['reseller_id' => $otherReseller->id, 'whatsapp_group_id' => 'g2@g.us', 'is_active' => true]);

        app(ResellerBotService::class)->handle('g2@g.us', ".trackorder {$order->order_number}", 'msg-2');

        $this->assertDatabaseHas('reseller_bot_command_logs', [
            'reseller_id' => $otherReseller->id,
            'failure_reason' => 'order_not_found_or_not_owned',
        ]);
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
        app(ResellerBotService::class)->handle(self::GROUP_ID, '.checkid MLID 51049607 2005', 'msg-1');

        $this->assertDatabaseHas('player_validations', [
            'game_id' => $mlid->id, 'player_id' => '51049607', 'status' => 'wrong_region',
        ]);
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
            app(ResellerBotService::class)->handle(self::GROUP_ID, '.checkid MLMY 51049607', "msg-{$i}");
        }

        $this->assertSame(10, PlayerValidation::query()->count());
    }
}
