<?php

namespace Tests\Feature\Services\Reseller\Bot;

use App\Models\Game;
use App\Models\Order;
use App\Models\Package;
use App\Models\Reseller;
use App\Models\ResellerBotCommandLog;
use App\Models\ResellerTier;
use App\Models\ResellerWhatsAppGroup;
use App\Models\ResellerWhatsAppPendingLink;
use App\Models\Supplier;
use App\Services\Ledger\LedgerOwnerType;
use App\Services\Ledger\LedgerService;
use App\Services\Reseller\Bot\ResellerBotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
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
}
