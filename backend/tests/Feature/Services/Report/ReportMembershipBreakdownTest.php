<?php

namespace Tests\Feature\Services\Report;

use App\Models\Order;
use App\Services\Ledger\LedgerService;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\PaymentStatus;
use App\Services\Report\ReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-027 continued addendum decision 15 / Phase 6.5 (grilled
 * 2026-08-29, Q10) — locks in the member/standard split, the margin-
 * forgone definition (Σ normal_selling_price − selling_price over member
 * orders), and the membership_fee revenue line (Σ ledger type).
 */
class ReportMembershipBreakdownTest extends TestCase
{
    use RefreshDatabase;

    private function order(array $overrides = []): Order
    {
        return Order::query()->create(array_merge([
            'order_number' => 'KRS-'.uniqid(),
            'customer_email' => 'buyer@example.com',
            'player_id' => '123456',
            'cost_price' => 1000,
            'standard_selling_price' => 1150,
            'selling_price' => 1150,
            'transaction_fee' => 100,
            'final_amount' => 1250,
            'platform_profit' => 150,
            'reseller_profit' => 0,
            'payment_status' => PaymentStatus::Paid->value,
            'paid_at' => now(),
            'delivery_status' => DeliveryStatus::Delivered->value,
            'is_test' => false,
        ], $overrides));
    }

    public function test_splits_member_and_standard_sales_and_computes_margin_forgone(): void
    {
        // Member order: paid member price 1030 vs normal retail 1150.
        $this->order([
            'pricing_basis' => 'member',
            'selling_price' => 1030,
            'normal_selling_price' => 1150,
            'final_amount' => 1130, // 1030 + 100 fee
        ]);

        // Standard order.
        $this->order([
            'pricing_basis' => 'standard',
            'normal_selling_price' => null,
            'final_amount' => 1250,
        ]);

        (new LedgerService)->credit('platform', null, 890, 'membership_fee', 'membership', 1);

        $row = (new ReportService)->membershipBreakdown(null, null, null);

        $this->assertSame(1130, $row['member_sales']);
        $this->assertSame(1, $row['member_orders_count']);
        $this->assertSame(1250, $row['standard_sales']);
        $this->assertSame(1, $row['standard_orders_count']);
        $this->assertSame(120, $row['margin_forgone']); // 1150 - 1030
        $this->assertSame(890, $row['membership_fee_revenue']);
    }

    public function test_excludes_non_paid_and_test_orders_from_the_split(): void
    {
        $this->order(['pricing_basis' => 'member', 'selling_price' => 1030, 'normal_selling_price' => 1150]);
        $this->order(['pricing_basis' => 'member', 'payment_status' => PaymentStatus::Pending->value, 'paid_at' => null, 'selling_price' => 1030, 'normal_selling_price' => 1150, 'final_amount' => 5000]);
        $this->order(['pricing_basis' => 'member', 'is_test' => true, 'selling_price' => 1030, 'normal_selling_price' => 1150, 'final_amount' => 9999]);

        $row = (new ReportService)->membershipBreakdown(null, null, null);

        $this->assertSame(1, $row['member_orders_count']);
        $this->assertSame(0, $row['standard_orders_count']);
        $this->assertSame(120, $row['margin_forgone']);
    }
}
