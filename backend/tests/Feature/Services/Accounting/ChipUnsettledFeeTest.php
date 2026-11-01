<?php

namespace Tests\Feature\Services\Accounting;

use App\Models\ChipSettledTransaction;
use App\Models\Order;
use App\Models\PaymentSettlement;
use App\Services\Accounting\CashPositionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The month close's "CHIP paid, not settled yet" asset nets the processing
 * fee each customer paid, which is what the books assume CHIP takes until
 * the settlement file lands (the difference is that month's payment
 * processing gain/loss). Found on the October 2026 close: 13 payments of
 * 31 Oct, mostly DuitNow at ~1%, carried RM8.58 of customer fees while the
 * asset deducted a flat 13 × RM1.
 */
class ChipUnsettledFeeTest extends TestCase
{
    use RefreshDatabase;

    private function chipOrder(int $finalAmount, array $attributes = []): Order
    {
        return Order::factory()->delivered()->create([
            'payment_gateway' => 'chip', 'payment_ref' => 'chip-'.uniqid(), 'selling_price' => $finalAmount, 'transaction_fee' => 0,
            'final_amount' => $finalAmount, 'paid_at' => '2026-10-31 10:00:00', 'delivered_at' => '2026-10-31 10:00:00', ...$attributes,
        ]);
    }

    private function settled(Order $order, int $feeSen, string $on): void
    {
        $batch = PaymentSettlement::query()->create([
            'date_from' => $on, 'date_to' => $on, 'matched_gross_sen' => 0, 'matched_fee_sen' => 0, 'matched_net_sen' => 0,
            'file_gross_sen' => 0, 'file_fee_sen' => 0, 'file_net_sen' => 0, 'status' => 'matched', 'original_filename' => 'chip.xlsx',
        ]);
        ChipSettledTransaction::query()->create([
            'transaction_id' => $order->payment_ref, 'matched_type' => 'order', 'matched_id' => $order->id,
            'amount_sen' => $order->final_amount, 'fee_sen' => $feeSen, 'net_amount_sen' => $order->final_amount - $feeSen,
            'acquirer' => 'dnqr', 'settled_on' => $on, 'payment_settlement_id' => $batch->id,
        ]);
    }

    public function test_each_unsettled_payment_nets_the_fee_its_customer_paid(): void
    {
        $duitNow = $this->chipOrder(68419, ['transaction_fee' => 684, 'gateway_fee_sen' => 685]);
        $this->settled($duitNow, 685, '2026-11-01');          // settled after the month end: its 1-sen difference is November's gain/loss
        $this->chipOrder(4208, ['transaction_fee' => 42]);
        $this->chipOrder(1100, ['transaction_fee' => 100]);  // FPX's flat RM1
        $before = $this->chipOrder(5000, ['transaction_fee' => 50]);
        $this->settled($before, 50, '2026-10-31');            // settled inside the month: not an asset at all

        $position = app(CashPositionService::class)->asOf(2026, 10);

        $this->assertSame(3, $position['chip_unsettled_count']);
        $this->assertSame((68419 - 684) + (4208 - 42) + (1100 - 100), $position['assets']['chip_unsettled_net_sen']);
    }
}
