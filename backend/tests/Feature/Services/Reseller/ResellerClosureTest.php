<?php

namespace Tests\Feature\Services\Reseller;

use App\Models\AccountingPeriodClose;
use App\Models\AdminUser;
use App\Models\AffiliateUser;
use App\Models\LedgerEntry;
use App\Models\Reseller;
use App\Models\ResellerApiKey;
use App\Models\ResellerWebhook;
use App\Models\WalletTopupAttempt;
use App\Models\WhatsAppGroup;
use App\Services\Ledger\LedgerOwnerType;
use App\Services\Ledger\LedgerService;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\PaymentStatus;
use App\Services\Reseller\ResellerWalletService;
use App\Services\Reseller\WalletTopupAttemptStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AdminOrderControllerFixtures;
use Tests\TestCase;

/**
 * ADR-131 decisions 12-16: "Close account" is the only way to remove a
 * Reseller. The refund is sent first, then the debit is written for the
 * whole balance; open claims block it; keys, logins, groups and the
 * webhook go in the same transaction; a closed account is never credited.
 */
class ResellerClosureTest extends TestCase
{
    use AdminOrderControllerFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = AdminUser::factory()->superAdmin()->create();
        $this->actingAsSteppedUp($this->admin);
    }

    private AdminUser $admin;

    private function funded(int $sen = 150_000): Reseller
    {
        $reseller = $this->walletReseller();
        if ($sen > 0) {
            app(LedgerService::class)->credit(LedgerOwnerType::ResellerWallet, $reseller->id, $sen, 'wallet_topup');
        }

        return $reseller;
    }

    private function today(): string
    {
        return now('Asia/Kuala_Lumpur')->toDateString();
    }

    /** @param  array<string, mixed>  $data */
    private function close(Reseller $reseller, array $data = []): TestResponse
    {
        return $this->postJson("/api/resellers/{$reseller->id}/close", $data + ['transferred_on' => $this->today(), 'bank_reference' => 'MBB-REF-12345']);
    }

    private function balance(Reseller $reseller): int
    {
        return app(ResellerWalletService::class)->balance($reseller);
    }

    private function walletOrder(Reseller $reseller, DeliveryStatus $status): \App\Models\Order
    {
        return $this->order([
            'wallet_reseller_id' => $reseller->id,
            'payment_status' => PaymentStatus::Paid->value,
            'delivery_status' => $status->value,
            'final_amount' => 945,
        ]);
    }

    public function test_closing_refunds_the_whole_balance_as_one_debit_and_removes_the_account(): void
    {
        $reseller = $this->funded(150_000);

        $this->close($reseller)->assertOk()->assertJsonPath('refunded_sen', 150_000);

        $entry = LedgerEntry::query()->where('type', 'wallet_closure_refund')->sole();
        $this->assertSame(-150_000, $entry->amount);
        $this->assertSame($this->admin->id, $entry->created_by);
        $this->assertSame('reseller', $entry->reference_type);
        $this->assertStringContainsString('MBB-REF-12345', $entry->reason);
        $this->assertStringContainsString($this->today(), $entry->reason);
        $this->assertSame(0, $this->balance($reseller));
        $this->assertSoftDeleted('resellers', ['id' => $reseller->id]);
        $this->assertFalse((bool) Reseller::withTrashed()->find($reseller->id)->is_active);
    }

    public function test_a_zero_balance_account_closes_without_writing_a_ledger_row(): void
    {
        $reseller = $this->funded(0);

        $this->close($reseller)->assertOk()->assertJsonPath('refunded_sen', 0);

        $this->assertSame(0, LedgerEntry::query()->where('type', 'wallet_closure_refund')->count());
        $this->assertSoftDeleted('resellers', ['id' => $reseller->id]);
    }

    public function test_closing_revokes_keys_logins_groups_and_the_webhook_in_the_same_step(): void
    {
        $reseller = $this->funded(1_000);
        $key = ResellerApiKey::query()->create(['reseller_id' => $reseller->id, 'name' => 'k', 'key_hash' => hash('sha256', 'x')]);
        $user = AffiliateUser::query()->create([
            'owner_type' => 'reseller', 'owner_id' => $reseller->id, 'name' => 'Staff', 'email' => 'staff@close.test',
            'password' => Hash::make('secret-password'), 'is_active' => true,
        ]);
        $user->createToken('affiliate');
        $group = WhatsAppGroup::query()->create(['whatsapp_group_id' => '1203@g.us', 'owner_type' => 'reseller', 'owner_id' => $reseller->id, 'is_active' => true]);
        $hook = ResellerWebhook::query()->create(['reseller_id' => $reseller->id, 'url' => 'https://example.test/hook', 'secret' => 'shh', 'is_active' => true]);

        $this->close($reseller)->assertOk();

        $this->assertNotNull($key->fresh()->revoked_at);
        $this->assertFalse((bool) $user->fresh()->is_active);
        $this->assertSame(0, $user->tokens()->count());
        $this->assertFalse((bool) $group->fresh()->is_active);
        $this->assertFalse((bool) $hook->fresh()->is_active);
    }

    /** @return array<string, array{0: DeliveryStatus}> */
    public static function unfinishedStatuses(): array
    {
        return [
            'not started' => [DeliveryStatus::NotStarted],
            'processing' => [DeliveryStatus::Processing],
            'pending' => [DeliveryStatus::Pending],
            'needs review' => [DeliveryStatus::NeedsReview],
            'failed, not refunded' => [DeliveryStatus::Failed],
            'partially delivered, not refunded' => [DeliveryStatus::PartiallyDelivered],
        ];
    }

    #[DataProvider('unfinishedStatuses')]
    public function test_an_unfinished_or_unrefunded_wallet_order_blocks_the_closure(DeliveryStatus $status): void
    {
        $reseller = $this->funded(150_000);
        $this->walletOrder($reseller, $status);

        $this->close($reseller)->assertUnprocessable()->assertJsonValidationErrors('reseller');

        $this->assertSame(150_000, $this->balance($reseller));
        $this->assertNotSoftDeleted('resellers', ['id' => $reseller->id]);
        $this->assertSame(0, LedgerEntry::query()->where('type', 'wallet_closure_refund')->count());
    }

    public function test_delivered_and_already_refunded_orders_do_not_block(): void
    {
        $reseller = $this->funded(150_000);
        $this->walletOrder($reseller, DeliveryStatus::Delivered);
        $failed = $this->walletOrder($reseller, DeliveryStatus::Failed);
        app(LedgerService::class)->credit(LedgerOwnerType::ResellerWallet, $reseller->id, 945, 'wallet_refund', 'order', $failed->id);

        $this->close($reseller)->assertOk()->assertJsonPath('refunded_sen', 150_945);
    }

    public function test_another_resellers_unfinished_order_does_not_block(): void
    {
        $reseller = $this->funded(1_000);
        $other = $this->funded(1_000);
        $this->walletOrder($other, DeliveryStatus::Pending);

        $this->close($reseller)->assertOk();
    }

    #[DataProvider('unexpiredAndExpiredWindows')]
    public function test_a_pending_top_up_blocks_whatever_its_expiry(string $expiry): void
    {
        $reseller = $this->funded(150_000);
        WalletTopupAttempt::query()->create([
            'reseller_id' => $reseller->id, 'reference' => 'WT-PENDING001', 'amount_sen' => 5_000, 'total_charged_sen' => 5_100,
            'channel_code' => 'fpx', 'status' => WalletTopupAttemptStatus::Pending->value, 'expires_at' => now()->add($expiry),
        ]);

        $this->close($reseller)->assertUnprocessable()->assertJsonValidationErrors('reseller');
        $this->assertSame(150_000, $this->balance($reseller));
    }

    /** @return array<string, array{0: string}> */
    public static function unexpiredAndExpiredWindows(): array
    {
        return ['still inside its 30 minutes' => ['+10 minutes'], 'past its expiry (webhook may still be late)' => ['-10 minutes']];
    }

    public function test_a_failed_expired_or_paid_top_up_does_not_block(): void
    {
        $reseller = $this->funded(0);
        foreach ([WalletTopupAttemptStatus::Failed, WalletTopupAttemptStatus::Expired] as $i => $status) {
            WalletTopupAttempt::query()->create([
                'reseller_id' => $reseller->id, 'reference' => 'WT-DONE00'.$i, 'amount_sen' => 5_000, 'total_charged_sen' => 5_100,
                'channel_code' => 'fpx', 'status' => $status->value, 'expires_at' => now()->subHour(),
            ]);
        }

        $this->close($reseller)->assertOk();
    }

    public function test_closing_twice_is_refused_and_never_a_second_debit(): void
    {
        $reseller = $this->funded(150_000);
        $this->close($reseller)->assertOk();

        // The closed account no longer resolves by route (soft-deleted), so a repeat is a 404; the
        // service's own 422 covers a repeat that races past the route.
        $this->close($reseller)->assertNotFound();

        $this->assertSame(1, LedgerEntry::query()->where('type', 'wallet_closure_refund')->count());
    }

    public function test_the_transfer_date_must_be_a_day_this_month_and_not_in_the_future(): void
    {
        $reseller = $this->funded(150_000);

        $this->close($reseller, ['transferred_on' => now('Asia/Kuala_Lumpur')->addDay()->toDateString()])->assertUnprocessable()->assertJsonValidationErrors('transferred_on');
        $this->close($reseller, ['transferred_on' => now('Asia/Kuala_Lumpur')->startOfMonth()->subDay()->toDateString()])->assertUnprocessable()->assertJsonValidationErrors('transferred_on');
        $this->close($reseller, ['transferred_on' => 'yesterday'])->assertUnprocessable()->assertJsonValidationErrors('transferred_on');

        $this->assertSame(150_000, $this->balance($reseller));
    }

    public function test_a_day_earlier_this_month_is_accepted_and_recorded(): void
    {
        $this->travelTo(now('Asia/Kuala_Lumpur')->startOfMonth()->addDays(10)->setTime(12, 0));
        $reseller = $this->funded(1_000);
        $day = now('Asia/Kuala_Lumpur')->subDays(3)->toDateString();

        $this->close($reseller, ['transferred_on' => $day])->assertOk();

        $this->assertStringContainsString($day, LedgerEntry::query()->where('type', 'wallet_closure_refund')->sole()->reason);
    }

    public function test_a_closed_accounting_month_refuses_the_closure(): void
    {
        $reseller = $this->funded(150_000);
        AccountingPeriodClose::query()->create([
            'period_month' => now('Asia/Kuala_Lumpur')->startOfMonth()->toDateString(),
            'operating_profit_sen' => 0, 'prior_adjustment_sen' => 0, 'allocated_sen' => 0,
            'profit_lines' => [], 'cash_balances' => [], 'equation' => [], 'gap_sen' => 0,
            'closed_by' => $this->admin->id,
        ]);

        $this->close($reseller)->assertUnprocessable()->assertJsonValidationErrors('transferred_on');
        $this->assertSame(150_000, $this->balance($reseller));
    }

    public function test_the_bank_reference_is_required(): void
    {
        $reseller = $this->funded(1_000);

        $this->close($reseller, ['bank_reference' => ''])->assertUnprocessable()->assertJsonValidationErrors('bank_reference');
    }

    public function test_the_preflight_lists_the_blockers_and_the_balance_without_changing_anything(): void
    {
        $reseller = $this->funded(150_000);
        $this->walletOrder($reseller, DeliveryStatus::Failed);

        $this->getJson("/api/resellers/{$reseller->id}/close-check")
            ->assertOk()
            ->assertJsonPath('balance_sen', 150_000)
            ->assertJsonPath('can_close', false)
            ->assertJsonCount(1, 'blockers');

        $this->assertNotSoftDeleted('resellers', ['id' => $reseller->id]);
    }

    public function test_the_old_delete_route_is_gone_so_there_is_one_way_to_close(): void
    {
        $reseller = $this->funded(0);

        $this->deleteJson("/api/resellers/{$reseller->id}")->assertStatus(405);

        $this->assertNotSoftDeleted('resellers', ['id' => $reseller->id]);
    }

    public function test_a_refund_to_the_wallet_of_a_closed_reseller_is_refused_at_the_one_settlement_seam(): void
    {
        $reseller = $this->funded(0);
        $order = $this->walletOrder($reseller, DeliveryStatus::Failed);
        // Closed some other way than the action (a soft delete), the guard must still hold.
        $reseller->delete();

        $this->postJson("/api/orders/{$order->id}/refund-to-wallet")->assertUnprocessable();

        $this->assertSame(0, LedgerEntry::query()->where('type', 'wallet_refund')->count());
    }

    public function test_a_paid_top_up_that_lands_after_the_closure_is_credited_and_logged_critical(): void
    {
        $reseller = $this->funded(0);
        $attempt = WalletTopupAttempt::query()->create([
            'reseller_id' => $reseller->id, 'reference' => 'WT-LATE00001', 'amount_sen' => 5_000, 'total_charged_sen' => 5_100,
            'channel_code' => 'fpx', 'status' => WalletTopupAttemptStatus::Pending->value, 'expires_at' => now()->addMinutes(30),
        ]);
        $reseller->delete();
        Log::spy();

        app(ResellerWalletService::class)->completeTopup($attempt);

        $this->assertSame(WalletTopupAttemptStatus::Paid, $attempt->fresh()->status);
        $this->assertSame(5_000, $this->balance($reseller));
        Log::shouldHaveReceived('critical')->once();
    }
}
