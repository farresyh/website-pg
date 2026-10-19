<?php

namespace Tests\Feature\Services\Reseller\Bot;

use App\Jobs\Reseller\SendResellerBotReplyJob;
use App\Models\Game;
use App\Models\Order;
use App\Models\Package;
use App\Models\Reseller;
use App\Models\ResellerBotCommandLog;
use App\Models\ResellerTier;
use App\Models\Supplier;
use App\Models\WhatsAppGroup;
use App\Models\WhatsAppPendingLink;
use App\Services\Ledger\LedgerOwnerType;
use App\Services\Ledger\LedgerService;
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
 * This file: group linking, balance, lists, track order and deactivated resellers.
 */
class ResellerBotServiceTest extends TestCase
{
    use RefreshDatabase;
    use ResellerBotFixtures;

    public function test_a_message_from_an_unlinked_group_creates_a_pending_link(): void
    {
        app(ResellerBotService::class)->handle('unlinked@g.us', 'hi there', 'msg-1');

        $this->assertDatabaseHas('whatsapp_pending_links', ['whatsapp_group_id' => 'unlinked@g.us']);
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
        $this->assertDatabaseHas('whatsapp_pending_links', ['whatsapp_group_id' => 'unlinked@g.us']);
    }

    // --- Item 37 (2026-09-26 audit): `.list` must not leak cost price
    // (0% markup) for a reseller with no tier assigned. ---

    public function test_list_command_rejects_a_reseller_with_no_tier_assigned(): void
    {
        $this->makePackage();
        $this->primaryAffiliate();
        $reseller = Reseller::query()->create(['business_name' => 'No Tier Co', 'reseller_tier_id' => null, 'is_active' => true]);
        WhatsAppGroup::query()->create(['owner_type' => 'reseller', 'owner_id' => $reseller->id, 'whatsapp_group_id' => self::GROUP_ID, 'is_active' => true]);

        app(ResellerBotService::class)->handle(self::GROUP_ID, '.list MLMY', 'msg-1');

        $this->assertDatabaseHas('reseller_bot_command_logs', [
            'reseller_id' => $reseller->id,
            'failure_reason' => 'no_tier_assigned',
        ]);
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

        $this->assertSame(0, WhatsAppPendingLink::query()->count());
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
        WhatsAppGroup::query()->create(['owner_type' => 'reseller', 'owner_id' => $otherReseller->id, 'whatsapp_group_id' => 'g2@g.us', 'is_active' => true]);

        app(ResellerBotService::class)->handle('g2@g.us', ".trackorder {$order->order_number}", 'msg-2');

        $this->assertDatabaseHas('reseller_bot_command_logs', [
            'reseller_id' => $otherReseller->id,
            'failure_reason' => 'order_not_found_or_not_owned',
        ]);
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
}
