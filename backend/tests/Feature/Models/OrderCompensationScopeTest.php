<?php

namespace Tests\Feature\Models;

use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\Voucher;
use App\Models\VoucherRedemption;
use App\Services\Ledger\LedgerOwnerType;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\PaymentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AdminOrderControllerFixtures;
use Tests\TestCase;

/**
 * ADR-124 2026-10-18 addendum, decision 5: `Order::scopeCompensated()` is
 * the query mirror of `isAlreadyCompensated()`. One order per way of being
 * (or nearly being) compensated; the scope and the method must agree on
 * every one, so the two can't drift.
 */
class OrderCompensationScopeTest extends TestCase
{
    use AdminOrderControllerFixtures;
    use RefreshDatabase;

    public function test_the_compensated_scope_agrees_with_is_already_compensated(): void
    {
        $failed = ['payment_status' => PaymentStatus::Paid->value, 'delivery_status' => DeliveryStatus::Failed->value];

        $plain = $this->order($failed);

        $voucherIssued = $this->order($failed);
        Voucher::query()->create(['order_id' => $voucherIssued->id, 'affiliate_id' => $voucherIssued->affiliate_id, 'code' => 'VC-ISSUED', 'customer_email' => 'a@example.com', 'amount' => 1000, 'remaining' => 1000, 'status' => 'active', 'reason' => 'failed']);

        $paidWith = Voucher::query()->create(['affiliate_id' => $plain->affiliate_id, 'code' => 'VC-PAIDWITH', 'customer_email' => 'a@example.com', 'amount' => 500, 'remaining' => 0, 'status' => 'active', 'reason' => 'admin']);
        $restored = $this->order($failed);
        VoucherRedemption::query()->create(['voucher_id' => $paidWith->id, 'order_id' => $restored->id, 'amount' => 500, 'status' => 'restored']);
        $reserved = $this->order($failed);
        VoucherRedemption::query()->create(['voucher_id' => $paidWith->id, 'order_id' => $reserved->id, 'amount' => 500, 'status' => 'reserved']);

        $reseller = $this->walletReseller();
        $refunded = $this->order($failed + ['wallet_reseller_id' => $reseller->id]);
        $this->walletRefund($reseller->id, $refunded->id);
        // A refund booked to a different wallet does not compensate this order.
        $other = $this->walletReseller();
        $wrongWallet = $this->order($failed + ['wallet_reseller_id' => $reseller->id]);
        $this->walletRefund($other->id, $wrongWallet->id);

        $all = collect([$plain, $voucherIssued, $restored, $reserved, $refunded, $wrongWallet]);
        $byMethod = $all->filter(fn (Order $o) => $o->fresh()->isAlreadyCompensated())->pluck('id')->sort()->values()->all();
        $byScope = Order::query()->compensated()->pluck('id')->sort()->values()->all();

        $this->assertSame([$voucherIssued->id, $restored->id, $refunded->id], $byMethod);
        $this->assertSame($byMethod, $byScope);
    }

    public function test_closed_is_delivered_or_compensated(): void
    {
        $delivered = $this->order(['payment_status' => PaymentStatus::Paid->value, 'delivery_status' => DeliveryStatus::Delivered->value]);
        $open = $this->order(['payment_status' => PaymentStatus::Paid->value, 'delivery_status' => DeliveryStatus::Failed->value]);
        $compensated = $this->order(['payment_status' => PaymentStatus::Paid->value, 'delivery_status' => DeliveryStatus::Failed->value]);
        Voucher::query()->create(['order_id' => $compensated->id, 'affiliate_id' => $compensated->affiliate_id, 'code' => 'VC-CLOSED', 'customer_email' => 'a@example.com', 'amount' => 1000, 'remaining' => 1000, 'status' => 'active', 'reason' => 'failed']);

        $this->assertEqualsCanonicalizing([$delivered->id, $compensated->id], Order::query()->closed()->pluck('id')->all());
        $this->assertNotContains($open->id, Order::query()->closed()->pluck('id')->all());
    }

    private function walletRefund(int $resellerId, int $orderId): void
    {
        LedgerEntry::query()->create([
            'owner_type' => LedgerOwnerType::ResellerWallet->value, 'owner_id' => $resellerId,
            'type' => 'wallet_refund', 'amount' => 1100, 'reference_type' => 'order', 'reference_id' => $orderId,
        ]);
    }
}
