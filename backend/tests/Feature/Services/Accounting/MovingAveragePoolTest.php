<?php

namespace Tests\Feature\Services\Accounting;

use App\Models\Order;
use App\Models\Supplier;
use App\Models\SupplierLedgerEntry;
use App\Models\SupplierTransfer;
use App\Services\Accounting\CashPositionService;
use App\Services\Accounting\FundedCostOfSales;
use App\Services\Accounting\MonthlyAccountingSummaryService;
use App\Services\Accounting\MovingAveragePool;
use App\Services\Accounting\SupplierLedgerEntryType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * ADR-083 2026-10-25 addendum (§16 item 132): from 1 October 2026 a
 * foreign-currency supplier is costed at a moving weighted average.
 * Units are priced in whole ringgit here (100 sen a unit in September,
 * 120 in October) so each figure can be checked by hand.
 */
class MovingAveragePoolTest extends TestCase
{
    use RefreshDatabase;

    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->supplier = Supplier::query()->create(['name' => 'Digiflazz', 'slug' => 'digiflazz', 'api_config' => [], 'currency' => 'IDR']);
        $this->transfer(10000, '100', '2026-09-05');                // RM100 for 100 units
        $this->row('-50', 'order', 9001, '2026-09-15 10:00:00');     // September: 50 used
        $this->transfer(12000, '100', '2026-10-05');                // RM120 for 100 units
    }

    private function transfer(int $myrSen, string $units, string $on): SupplierTransfer
    {
        $transfer = SupplierTransfer::query()->create([
            'transferred_on' => $on, 'supplier_id' => $this->supplier->id, 'source_channel' => 'wise',
            'amount_myr_sent' => $myrSen, 'fee_myr' => 0, 'currency' => 'IDR', 'amount_foreign_received' => $units,
        ]);
        $this->row($units, 'supplier_transfer', $transfer->id, $on.' 08:00:00', SupplierLedgerEntryType::Topup);

        return $transfer;
    }

    private function row(string $amount, string $type, int $id, string $at, SupplierLedgerEntryType $kind = SupplierLedgerEntryType::OrderDrawdown): SupplierLedgerEntry
    {
        return SupplierLedgerEntry::query()->forceCreate([
            'supplier_id' => $this->supplier->id, 'type' => $kind->value, 'amount' => $amount,
            'currency' => 'IDR', 'reference_type' => $type, 'reference_id' => $id, 'created_at' => $at,
        ]);
    }

    private function pool(?string $untilKl = null): MovingAveragePool
    {
        return MovingAveragePool::replay($this->supplier->id, new FundedCostOfSales, $untilKl === null ? null : Carbon::parse($untilKl, 'Asia/Kuala_Lumpur')->utc());
    }

    /** Opening 50 units at RM1 (September's own rate), +100 at RM1.20: 150 units for RM170; every ringgit sent ends as a cost or in the pool. */
    public function test_each_drawdown_takes_the_pools_average_and_the_ringgit_sent_is_conserved(): void
    {
        $a = $this->row('-100', 'order', 2, '2026-10-10 10:00:00');
        $b = $this->row('-50', 'order', 3, '2026-10-20 10:00:00');

        $pool = $this->pool();

        $this->assertSame(-11333, $pool->costs[$a->id]);   // 17000 × 100 ÷ 150
        $this->assertSame(-5667, $pool->costs[$b->id]);    // the rest: the pool is empty
        $this->assertSame(0, $pool->sen);
        $this->assertSame(10000 + 12000, 5000 + 11333 + 5667 + $pool->sen);
    }

    public function test_an_empty_pool_takes_the_last_known_average(): void
    {
        $this->row('-150', 'order', 2, '2026-10-10 10:00:00');
        $late = $this->row('-10', 'order', 3, '2026-10-12 10:00:00');

        $this->assertSame(-1133, $this->pool()->costs[$late->id]);   // 10 × 113.33 sen
    }

    /** The opening is September's close value, and the pool at a month end sees only what happened before it. */
    public function test_the_pool_opens_at_septembers_value_and_stops_at_the_month_end(): void
    {
        $this->row('-100', 'order', 2, '2026-11-02 10:00:00');

        $this->assertSame(5000, $this->pool('2026-10-01')->sen);
        $this->assertSame(17000, $this->pool('2026-11-01')->sen);
        $this->assertSame(17000 - 11333, $this->pool()->sen);
    }

    /** Prod's two 15-Sep voids are adjustments, not VOID_REVERSAL: the transfer and every row on it are out, or the pool is understated. */
    public function test_a_voided_transfer_and_every_row_on_it_are_left_out(): void
    {
        $voided = $this->transfer(50000, '500', '2026-10-06');
        $this->row('-500', 'supplier_transfer', $voided->id, '2026-10-06 09:00:00', SupplierLedgerEntryType::ManualAdjustment);
        $voided->update(['voided_at' => now()]);

        $this->assertSame(17000, $this->pool()->sen);
    }

    public function test_a_balance_correction_moves_units_at_the_average_not_the_average_itself(): void
    {
        $adjustment = $this->row('-15', 'supplier_transfer', 2, '2026-10-08 10:00:00', SupplierLedgerEntryType::ManualAdjustment);
        $drawdown = $this->row('-100', 'order', 2, '2026-10-10 10:00:00');

        $pool = $this->pool();

        $this->assertSame(-1700, $pool->costs[$adjustment->id]);  // 15 × 113.33
        $this->assertSame(-11333, $pool->costs[$drawdown->id]);   // still 113.33 a unit
    }

    /** October's COGS is the pool's cost, not the cumulative average's (RM220 ÷ 200 = RM1.10 a unit: RM110). */
    public function test_october_cogs_and_the_month_end_balance_read_the_pool(): void
    {
        $order = Order::factory()->delivered()->create(['paid_at' => '2026-10-10 10:00:00', 'cost_price' => 11000, 'supplier_id' => $this->supplier->id]);
        $this->row('-100', 'order', $order->id, '2026-10-10 10:00:00');

        $october = (new MonthlyAccountingSummaryService)->forPeriod(2026, 10);

        $this->assertSame(11333, $october['cogs_sen']);
        $this->assertSame(17000 - 11333, app(CashPositionService::class)->asOf(2026, 10)['assets']['supplier_prepaid_sen']);
        $this->assertSame(5000, app(CashPositionService::class)->asOf(2026, 9)['assets']['supplier_prepaid_sen']);
    }
}
