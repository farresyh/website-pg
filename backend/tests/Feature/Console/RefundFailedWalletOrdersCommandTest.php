<?php

namespace Tests\Feature\Console;

use App\Models\AdminUser;
use App\Models\Order;
use App\Models\Reseller;
use App\Services\Fulfillment\OrderSettlementService;
use App\Services\Ledger\LedgerOwnerType;
use App\Services\Ledger\LedgerService;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\PaymentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\AdminOrderControllerFixtures;
use Tests\TestCase;

/** ADR-139 decisions 26–29 (ADR-073 2026-11-08 addendum): the automatic refund sweep. */
class RefundFailedWalletOrdersCommandTest extends TestCase
{
    use AdminOrderControllerFixtures;
    use RefreshDatabase;

    private function failedWalletOrder(Reseller $reseller, array $overrides = []): Order
    {
        return $this->order(array_merge([
            'wallet_reseller_id' => $reseller->id,
            'payment_status' => PaymentStatus::Paid->value,
            'delivery_status' => DeliveryStatus::Failed->value,
            'final_amount' => 945,
        ], $overrides));
    }

    private function balance(Reseller $reseller): int
    {
        return app(LedgerService::class)->balance(LedgerOwnerType::ResellerWallet, $reseller->id);
    }

    public function test_a_failed_wallet_order_is_refunded_once(): void
    {
        $reseller = $this->walletReseller();
        $order = $this->failedWalletOrder($reseller);

        $this->artisan('app:refund-failed-wallet-orders')->assertSuccessful();
        $this->artisan('app:refund-failed-wallet-orders')->assertSuccessful();

        $this->assertSame(945, $this->balance($reseller));
        $this->assertDatabaseCount('ledger_entries', 1);
        $this->assertTrue($order->fresh()->isAlreadyCompensated());
    }

    public function test_it_leaves_alone_what_it_must_not_refund(): void
    {
        $reseller = $this->walletReseller();
        // Uncertain outcomes are never refunded (ADR-102); a partial combo stays an admin decision.
        foreach ([DeliveryStatus::NeedsReview, DeliveryStatus::Pending, DeliveryStatus::Processing, DeliveryStatus::Delivered, DeliveryStatus::PartiallyDelivered] as $status) {
            $this->failedWalletOrder($reseller, ['delivery_status' => $status->value]);
        }
        $this->failedWalletOrder($reseller, ['is_test' => true]);
        $this->failedWalletOrder($reseller, ['payment_status' => PaymentStatus::Pending->value]);
        // A retail order is compensated by voucher, by an admin.
        $this->order(['payment_status' => PaymentStatus::Paid->value, 'delivery_status' => DeliveryStatus::Failed->value]);

        $this->artisan('app:refund-failed-wallet-orders')->assertSuccessful();

        $this->assertSame(0, $this->balance($reseller));
        $this->assertDatabaseCount('ledger_entries', 0);
        $this->assertDatabaseCount('vouchers', 0);
    }

    /** ADR-139 decision 29: a late Success on a Failed order means the credit may have arrived; a person decides. */
    public function test_it_skips_an_order_with_a_late_success_from_the_supplier(): void
    {
        $reseller = $this->walletReseller();
        $this->failedWalletOrder($reseller, ['supplier_response' => ['late_supplier_result' => ['status' => 'Sukses']]]);

        $this->artisan('app:refund-failed-wallet-orders')->assertSuccessful();

        $this->assertDatabaseCount('ledger_entries', 0);
    }

    public function test_it_skips_a_closed_reseller_instead_of_failing_every_minute(): void
    {
        $reseller = $this->walletReseller();
        $this->failedWalletOrder($reseller);
        $reseller->delete();

        $this->artisan('app:refund-failed-wallet-orders')->assertSuccessful();

        $this->assertDatabaseCount('ledger_entries', 0);
    }

    public function test_one_failing_order_does_not_block_the_others_and_alerts_on_the_third_run(): void
    {
        Http::fake();
        AdminUser::factory()->create(['email' => 'founder@example.com', 'is_active' => true]);
        $reseller = $this->walletReseller();
        $stuck = $this->failedWalletOrder($reseller);
        $fine = $this->failedWalletOrder($reseller, ['final_amount' => 500]);

        // A real failure inside settle(): the database refuses this order's refund row (sqlite, the fast suite).
        DB::statement("CREATE TRIGGER refuse_refund BEFORE INSERT ON ledger_entries WHEN NEW.reference_id = {$stuck->id} AND NEW.type = 'wallet_refund' BEGIN SELECT RAISE(ABORT, 'simulated failure'); END");

        $this->artisan('app:refund-failed-wallet-orders')->assertSuccessful();
        $this->assertSame(500, $this->balance($reseller));
        $this->artisan('app:refund-failed-wallet-orders')->assertSuccessful();
        Http::assertNothingSent();

        $this->artisan('app:refund-failed-wallet-orders')->assertSuccessful();
        Http::assertSent(fn ($request) => str_contains((string) $request->body(), "#{$stuck->id}"));

        $this->artisan('app:refund-failed-wallet-orders')->assertSuccessful();
        Http::assertSentCount(1);
    }

    public function test_a_system_actor_is_refused_for_a_retail_order(): void
    {
        $order = $this->order(['payment_status' => PaymentStatus::Paid->value, 'delivery_status' => DeliveryStatus::Failed->value]);

        $this->expectException(\InvalidArgumentException::class);
        app(OrderSettlementService::class)->settle($order, null);
    }
}
