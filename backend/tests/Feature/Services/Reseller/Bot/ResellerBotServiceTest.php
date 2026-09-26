<?php

namespace Tests\Feature\Services\Reseller\Bot;

use App\Jobs\Reseller\SendResellerBotReplyJob;
use App\Models\Game;
use App\Models\Order;
use App\Models\Package;
use App\Models\PaymentMethod;
use App\Models\PlayerRegionMapping;
use App\Models\PlayerValidation;
use App\Models\PlayerValidatorProfile;
use App\Models\Reseller;
use App\Models\ResellerBotCommandLog;
use App\Models\ResellerTier;
use App\Models\ResellerWhatsAppGroup;
use App\Models\ResellerWhatsAppPendingLink;
use App\Models\Supplier;
use App\Models\WalletTopupAttempt;
use App\Services\Ledger\LedgerOwnerType;
use App\Services\Ledger\LedgerService;
use App\Services\Payment\PaymentGateway;
use App\Services\Payment\PaymentRequest;
use App\Services\Payment\PaymentResponse;
use App\Services\Payment\PaymentWebhookEvent;
use App\Services\PlayerValidation\PlayerValidationResult;
use App\Services\PlayerValidation\PlayerValidator;
use App\Services\PlayerValidation\ProviderUnavailableException;
use App\Services\Reseller\Bot\ResellerBotService;
use App\Services\Reseller\WalletTopupAttemptStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use RuntimeException;
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

    private function activeFpx(): PaymentMethod
    {
        return PaymentMethod::query()->create([
            'channel_code' => 'fpx',
            'label' => 'Online Banking (FPX)',
            'category' => 'fpx',
            'gateway' => 'chip',
            'is_active' => true,
            'percentage_rate' => 0.0,
            'flat_fee_sen' => 100,
        ]);
    }

    private function bindGateway(bool $succeeds = true): void
    {
        $gateway = new class($succeeds) implements PaymentGateway
        {
            public function __construct(private readonly bool $succeeds) {}

            public function createPayment(PaymentRequest $request): PaymentResponse
            {
                return $this->succeeds
                    ? PaymentResponse::success([
                        'payment_request_id' => 'pr-'.$request->referenceId,
                        'actions' => [['type' => 'REDIRECT', 'value' => 'https://gate.chip-in.asia/p/'.$request->referenceId]],
                    ])
                    : PaymentResponse::failure('API_ERROR', 'gateway said no');
            }

            public function getPayment(string $paymentRequestId): PaymentResponse
            {
                return PaymentResponse::success(['payment_request_id' => $paymentRequestId], status: null);
            }

            public function verifyWebhookSignature(Request $request): bool
            {
                throw new RuntimeException('not used');
            }

            public function parseWebhookEvent(array $payload): PaymentWebhookEvent
            {
                throw new RuntimeException('not used');
            }
        };

        $this->app->bind('payment-gateway.chip', fn () => $gateway);
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

    // --- Item 36 (2026-09-26 audit): only a `.`-prefixed message in an
    // already-linked group gets a reply; ordinary chat, and anything at
    // all in an unlinked group, stays silent. ---

    public function test_ordinary_chat_in_a_linked_group_is_ignored_silently(): void
    {
        config(['services.openwa.session_id' => 'session-1', 'services.openwa.api_key' => 'key-1']);
        Queue::fake();
        $this->makeLinkedReseller();

        app(ResellerBotService::class)->handle(self::GROUP_ID, 'ok tq', 'msg-1');

        Queue::assertNotPushed(SendResellerBotReplyJob::class);
        $this->assertSame(0, ResellerBotCommandLog::query()->count());
    }

    public function test_unlinked_group_gets_no_reply_even_for_a_dot_prefixed_message(): void
    {
        config(['services.openwa.session_id' => 'session-1', 'services.openwa.api_key' => 'key-1']);
        Queue::fake();

        app(ResellerBotService::class)->handle('unlinked@g.us', '.baki', 'msg-1');

        Queue::assertNotPushed(SendResellerBotReplyJob::class);
        $this->assertDatabaseHas('reseller_whatsapp_pending_links', ['whatsapp_group_id' => 'unlinked@g.us']);
    }

    // --- Item 37 (2026-09-26 audit): `.list` must not leak cost price
    // (0% markup) for a reseller with no tier assigned. ---

    public function test_list_command_rejects_a_reseller_with_no_tier_assigned(): void
    {
        $this->makePackage();
        $this->primaryAffiliate();
        $reseller = Reseller::query()->create(['business_name' => 'No Tier Co', 'reseller_tier_id' => null, 'is_active' => true]);
        ResellerWhatsAppGroup::query()->create(['reseller_id' => $reseller->id, 'whatsapp_group_id' => self::GROUP_ID, 'is_active' => true]);

        app(ResellerBotService::class)->handle(self::GROUP_ID, '.list MLMY', 'msg-1');

        $this->assertDatabaseHas('reseller_bot_command_logs', [
            'reseller_id' => $reseller->id,
            'failure_reason' => 'no_tier_assigned',
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

    /**
     * Found 2026-09-13: a catalogue-sized `.list` reply (79 packages,
     * MLID's real count) built a >4096-char message that OpenWA's
     * `send-text` endpoint hard-rejects with a `400` — every retry
     * exhausted silently, the reseller got nothing back at all. This
     * pins the fix end-to-end: `ResellerBotService` now dispatches one
     * `SendResellerBotReplyJob` per chunk instead of one for the whole
     * listing, so no single message OpenWA is asked to send ever
     * crosses the cap.
     */
    public function test_a_long_catalogue_list_dispatches_one_reply_job_per_chunk(): void
    {
        config(['services.openwa.session_id' => 'session-1', 'services.openwa.api_key' => 'key-1']);
        Queue::fake();

        $supplier = Supplier::query()->create(['name' => 'Gamevion', 'slug' => 'gamevion', 'api_config' => [], 'currency' => 'MYR']);
        $game = Game::query()->create(['name' => 'Mobile Legends Indonesia', 'slug' => 'mlbb-id', 'reseller_code' => 'MLID', 'is_active' => true]);
        foreach (range(1, 79) as $n) {
            Package::query()->create([
                'game_id' => $game->id, 'name' => "{$n} Diamonds", 'denomination' => $n,
                'cost_price' => 1000 + $n, 'standard_selling_price' => 1100 + $n,
                'supplier_id' => $supplier->id, 'supplier_package_ref' => "sku-{$n}", 'is_active' => true,
            ]);
        }
        $this->makeLinkedReseller();

        app(ResellerBotService::class)->handle(self::GROUP_ID, '.list MLID', 'msg-1');

        $pushed = Queue::pushed(SendResellerBotReplyJob::class);

        $this->assertGreaterThan(1, $pushed->count());
        foreach ($pushed as $job) {
            $this->assertLessThanOrEqual(4096, strlen($job->text));
        }
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

    // --- ADR-076 PR-H: .topupbaki ---

    public function test_topupbaki_creates_a_pending_attempt_and_a_bot_topup_row(): void
    {
        $this->activeFpx();
        $this->bindGateway();
        $reseller = $this->makeLinkedReseller();

        app(ResellerBotService::class)->handle(self::GROUP_ID, '.topupbaki 50', 'msg-1');

        $attempt = WalletTopupAttempt::query()->firstOrFail();
        $this->assertSame(5000, $attempt->amount_sen);
        $this->assertSame(WalletTopupAttemptStatus::Pending, $attempt->status);
        $this->assertDatabaseHas('reseller_bot_wallet_topups', [
            'wallet_topup_attempt_id' => $attempt->id,
            'reseller_id' => $reseller->id,
            'whatsapp_group_id' => self::GROUP_ID,
            'notified_at' => null,
        ]);
    }

    public function test_topupbaki_below_the_minimum_is_rejected_without_a_gateway_call(): void
    {
        $this->activeFpx();
        $this->bindGateway();
        $reseller = $this->makeLinkedReseller();

        app(ResellerBotService::class)->handle(self::GROUP_ID, '.topupbaki 5', 'msg-1');

        $this->assertSame(0, WalletTopupAttempt::query()->count());
        $this->assertDatabaseHas('reseller_bot_command_logs', [
            'reseller_id' => $reseller->id,
            'failure_reason' => 'topup_below_minimum',
        ]);
    }

    public function test_topupbaki_with_a_non_numeric_amount_is_rejected(): void
    {
        $this->activeFpx();
        $this->bindGateway();
        $reseller = $this->makeLinkedReseller();

        app(ResellerBotService::class)->handle(self::GROUP_ID, '.topupbaki lima', 'msg-1');

        $this->assertSame(0, WalletTopupAttempt::query()->count());
        $this->assertDatabaseHas('reseller_bot_command_logs', [
            'reseller_id' => $reseller->id,
            'failure_reason' => 'topup_invalid_amount',
        ]);
    }

    public function test_topupbaki_hands_back_the_existing_link_when_one_is_already_pending(): void
    {
        $this->activeFpx();
        $this->bindGateway();
        $this->makeLinkedReseller();

        app(ResellerBotService::class)->handle(self::GROUP_ID, '.topupbaki 50', 'msg-1');
        // A second request while the first is still pending — decision 3:
        // reply with the existing attempt's link, no new attempt created.
        app(ResellerBotService::class)->handle(self::GROUP_ID, '.topupbaki 80', 'msg-2');

        $this->assertSame(1, WalletTopupAttempt::query()->count());
    }

    /**
     * E9 hardening (2026-09-10 reseller-family audit, `docs/build-log.md`):
     * `.topupbaki`'s default-channel pick ("first active method by
     * category, label") has no other test pinning it — a future admin
     * reorder or a new payment method could silently change which
     * channel a reseller gets top-up'd through. Pins today's expected
     * pick (`fpx` sorts before `wallet_qr` alphabetically) so a future
     * ordering change fails this test loudly instead of drifting silent.
     */
    public function test_topupbaki_picks_the_first_active_method_by_category_then_label(): void
    {
        $this->activeFpx();
        PaymentMethod::query()->create([
            'channel_code' => 'duitnow_qr', 'label' => 'DuitNow QR', 'category' => 'wallet_qr',
            'gateway' => 'chip', 'is_active' => true, 'percentage_rate' => 0.0, 'flat_fee_sen' => 0,
        ]);
        $this->bindGateway();
        $this->makeLinkedReseller();

        app(ResellerBotService::class)->handle(self::GROUP_ID, '.topupbaki 50', 'msg-1');

        $this->assertSame('fpx', WalletTopupAttempt::query()->firstOrFail()->channel_code);
    }

    public function test_topupbaki_is_unavailable_when_no_payment_method_is_active(): void
    {
        $this->bindGateway();
        $reseller = $this->makeLinkedReseller();

        app(ResellerBotService::class)->handle(self::GROUP_ID, '.topupbaki 50', 'msg-1');

        $this->assertSame(0, WalletTopupAttempt::query()->count());
        $this->assertDatabaseHas('reseller_bot_command_logs', [
            'reseller_id' => $reseller->id,
            'failure_reason' => 'topup_no_active_payment_method',
        ]);
    }

    public function test_topupbaki_is_rate_limited_at_5_per_minute(): void
    {
        $this->activeFpx();
        $this->bindGateway();
        $reseller = $this->makeLinkedReseller();
        RateLimiter::clear("reseller-bot-topupbaki:{$reseller->id}");

        // 6 calls, decision 4's limit is 5/min. Each successful call would
        // otherwise reuse the same pending attempt (decision 3), so the
        // rate-limit assertion keys off the command log instead: the 6th
        // must be blocked before it reaches the handler.
        for ($i = 0; $i < 6; $i++) {
            app(ResellerBotService::class)->handle(self::GROUP_ID, '.topupbaki 5', "msg-{$i}");
        }

        // 5 got through to the handler (each logged 'topup_below_minimum'),
        // the 6th was rate-limited before the handler ran.
        $this->assertSame(5, ResellerBotCommandLog::query()
            ->where('reseller_id', $reseller->id)
            ->where('failure_reason', 'topup_below_minimum')
            ->count());
    }

    // --- E10 hardening: `.baki`/`.trackorder`/`.listharga` reject a deactivated reseller ---

    public function test_baki_is_rejected_for_a_deactivated_reseller(): void
    {
        $reseller = $this->makeLinkedReseller();
        $reseller->update(['is_active' => false]);

        app(ResellerBotService::class)->handle(self::GROUP_ID, '.baki', 'msg-1');

        $this->assertDatabaseHas('reseller_bot_command_logs', [
            'reseller_id' => $reseller->id,
            'failure_reason' => 'reseller_inactive',
        ]);
    }

    public function test_trackorder_is_rejected_for_a_deactivated_reseller(): void
    {
        $reseller = $this->makeLinkedReseller();
        $reseller->update(['is_active' => false]);

        app(ResellerBotService::class)->handle(self::GROUP_ID, '.trackorder PG-TEST', 'msg-1');

        $this->assertDatabaseHas('reseller_bot_command_logs', [
            'reseller_id' => $reseller->id,
            'failure_reason' => 'reseller_inactive',
        ]);
    }

    public function test_listharga_is_rejected_for_a_deactivated_reseller(): void
    {
        $reseller = $this->makeLinkedReseller();
        $reseller->update(['is_active' => false]);

        app(ResellerBotService::class)->handle(self::GROUP_ID, '.listharga', 'msg-1');

        $this->assertDatabaseHas('reseller_bot_command_logs', [
            'reseller_id' => $reseller->id,
            'failure_reason' => 'reseller_inactive',
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
