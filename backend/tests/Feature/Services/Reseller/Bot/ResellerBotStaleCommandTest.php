<?php

namespace Tests\Feature\Services\Reseller\Bot;

use App\Models\Order;
use App\Models\PlatformSettings;
use App\Models\WalletTopupAttempt;
use App\Services\Ledger\LedgerOwnerType;
use App\Services\Ledger\LedgerService;
use App\Services\Reseller\Bot\ResellerBotService;
use App\Services\Reseller\WalletTopupAttemptStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ResellerBotFixtures;
use Tests\TestCase;

/**
 * ADR-075 2026-11-14 addendum, decision 2: a money command that reaches us
 * after the limit (an outage held it) moves no money. The durable order key,
 * not the clock, decides whether an `.order` already placed its order.
 */
class ResellerBotStaleCommandTest extends TestCase
{
    use RefreshDatabase;
    use ResellerBotFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->captureReplies();
    }

    private function send(string $text, string $messageId, ?int $sentAt): void
    {
        app(ResellerBotService::class)->handle(self::GROUP_ID, $text, $messageId, $sentAt);
    }

    private function balance(int $resellerId): int
    {
        return app(LedgerService::class)->balance(LedgerOwnerType::ResellerWallet, $resellerId);
    }

    public function test_a_stale_order_places_nothing_and_says_so(): void
    {
        $this->makePackage();
        $reseller = $this->makeLinkedReseller(walletBalance: 10000);

        $this->send('.order MLMY-14 51049607 2005', 'msg-1', now()->subMinutes(10)->getTimestamp());

        $this->assertSame(0, Order::query()->count());
        $this->assertSame(10000, $this->balance($reseller->id));
        $this->assertCount(1, $this->replies());
        $this->assertStringContainsString('tidak diproses', $this->replies()[0]);
        $this->assertDatabaseHas('reseller_bot_command_logs', ['reseller_id' => $reseller->id, 'failure_reason' => 'stale_command']);
    }

    /** A redelivered webhook of an order that did go through gets that order's status, never "not processed". */
    public function test_a_stale_redelivery_of_a_placed_order_reports_the_order(): void
    {
        $this->makePackage();
        $reseller = $this->makeLinkedReseller(walletBalance: 10000);
        $this->send('.order MLMY-14 51049607 2005', 'msg-1', now()->getTimestamp());
        $order = Order::query()->sole();
        $afterOrder = $this->balance($reseller->id);

        $this->travel(20)->minutes();
        $this->send('.order MLMY-14 51049607 2005', 'msg-1', now()->subMinutes(20)->getTimestamp());

        $this->assertSame(1, Order::query()->count());
        $this->assertSame($afterOrder, $this->balance($reseller->id));
        $last = $this->replies()[array_key_last($this->replies())];
        $this->assertStringContainsString($order->order_number, $last);
        $this->assertStringNotContainsString('tidak diproses', $last);
    }

    public function test_a_stale_read_command_is_dropped_silently(): void
    {
        $this->makeLinkedReseller();

        $this->send('.baki', 'msg-1', now()->subMinutes(10)->getTimestamp());

        $this->assertSame([], $this->replies());
    }

    public function test_a_stale_batalbaki_never_cancels_the_top_up_pending_now(): void
    {
        $reseller = $this->makeLinkedReseller();
        $attempt = WalletTopupAttempt::query()->create([
            'reseller_id' => $reseller->id,
            'reference' => 'WT-STALE00001',
            'amount_sen' => 10000,
            'total_charged_sen' => 10100,
            'channel_code' => 'fpx',
            'status' => WalletTopupAttemptStatus::Pending->value,
            'expires_at' => now()->addMinutes(30),
        ]);

        $this->send('.batalbaki', 'msg-1', now()->subMinutes(10)->getTimestamp());

        $this->assertSame(WalletTopupAttemptStatus::Pending, $attempt->fresh()->status);
        $this->assertStringContainsString('tidak diproses', $this->replies()[0]);
    }

    public function test_a_money_command_with_no_time_is_refused(): void
    {
        $this->makePackage();
        $this->makeLinkedReseller();

        $this->send('.order MLMY-14 51049607 2005', 'msg-1', null);

        $this->assertSame(0, Order::query()->count());
    }

    /** A phone clock running fast puts the time in the future: still run. */
    public function test_a_time_in_the_future_is_accepted(): void
    {
        $this->makePackage();
        $this->makeLinkedReseller();

        $this->send('.order MLMY-14 51049607 2005', 'msg-1', now()->addMinutes(5)->getTimestamp());

        $this->assertSame(1, Order::query()->count());
    }

    public function test_the_limit_is_the_panel_setting(): void
    {
        PlatformSettings::current()->update(['bot_command_max_age_minutes' => 15]);
        $this->makePackage();
        $this->makeLinkedReseller();

        $this->send('.order MLMY-14 51049607 2005', 'msg-1', now()->subMinutes(10)->getTimestamp());

        $this->assertSame(1, Order::query()->count());
    }

    /** Found in CI: a freshly created settings row read the limit back as null (0 minutes). */
    public function test_a_fresh_settings_row_has_the_three_minute_limit(): void
    {
        PlatformSettings::query()->delete();

        $this->assertSame(3, PlatformSettings::current()->bot_command_max_age_minutes);
    }

    public function test_a_command_a_few_seconds_old_runs_on_a_fresh_settings_row(): void
    {
        PlatformSettings::query()->delete();
        $this->makePackage();
        $this->makeLinkedReseller();

        $this->send('.order MLMY-14 51049607 2005', 'msg-1', now()->subSeconds(5)->getTimestamp());

        $this->assertSame(1, Order::query()->count());
    }
}
