<?php

namespace Tests\Feature\Services\Currency;

use App\Models\Supplier;
use App\Models\SupplierLedgerEntry;
use App\Models\SupplierTransfer;
use App\Services\Accounting\FundedCostOfSales;
use App\Services\Accounting\MovingAveragePool;
use App\Services\Accounting\SupplierLedgerEntryType;
use App\Services\Currency\LandedCostRate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-134: every supplier unit is priced and costed at what its deposit
 * actually cost — the moving pool's average after its last deposit or
 * adjustment, transfer fees included — not at the market mid-rate.
 */
class LandedCostRateTest extends TestCase
{
    use RefreshDatabase;

    private function supplier(string $currency = 'IDR', array $attributes = []): Supplier
    {
        return Supplier::query()->create(array_merge([
            'name' => 'S'.uniqid(), 'slug' => 's-'.uniqid(), 'api_config' => [], 'currency' => $currency,
        ], $attributes));
    }

    private function transfer(Supplier $supplier, int $myrSen, int $feeSen, string $units, string $on = '2026-10-10', ?string $supplierFee = null): SupplierTransfer
    {
        $transfer = SupplierTransfer::query()->create([
            'transferred_on' => $on, 'supplier_id' => $supplier->id, 'source_channel' => 'bank',
            'amount_myr_sent' => $myrSen, 'fee_myr' => $feeSen, 'currency' => $supplier->currency,
            'amount_foreign_received' => $units, 'supplier_fee' => $supplierFee,
        ]);
        $this->row($supplier, (string) ((float) $units - (float) ($supplierFee ?? 0)), 'supplier_transfer', $transfer->id, $on.' 08:00:00', SupplierLedgerEntryType::Topup);

        return $transfer;
    }

    private function row(Supplier $supplier, string $amount, string $type, int $id, string $at, SupplierLedgerEntryType $kind = SupplierLedgerEntryType::OrderDrawdown): SupplierLedgerEntry
    {
        return SupplierLedgerEntry::query()->forceCreate([
            'supplier_id' => $supplier->id, 'type' => $kind->value, 'amount' => $amount,
            'currency' => $supplier->currency, 'reference_type' => $type, 'reference_id' => $id, 'created_at' => $at,
        ]);
    }

    private function landed(): LandedCostRate
    {
        return app(LandedCostRate::class);
    }

    /** Maybank's 29 Oct transfer: RM4,998.11 + RM14.66 for Rp21,000,482 is 0.023870 sen a rupiah, not the market's 0.0224. */
    public function test_the_rate_is_what_the_deposit_cost_with_its_fee(): void
    {
        $supplier = $this->supplier();
        $this->transfer($supplier, 499811, 1466, '21000482');

        $this->assertEqualsWithDelta((499811 + 1466) / 21000482, $this->landed()->averageSen($supplier), 1e-12);
    }

    public function test_the_suppliers_own_deposit_fee_reduces_the_units_received(): void
    {
        $supplier = $this->supplier();
        $this->transfer($supplier, 19219, 670, '832672', supplierFee: '15000');

        $this->assertEqualsWithDelta((19219 + 670) / 817672, $this->landed()->averageSen($supplier), 1e-12);
    }

    public function test_a_foreign_supplier_never_funded_has_no_rate_and_cannot_be_priced(): void
    {
        $supplier = $this->supplier();

        $this->assertNull($this->landed()->averageSen($supplier));
        $this->assertFalse($this->landed()->isFunded($supplier));
        $this->assertNull($this->landed()->toCatalogSen($supplier, 4120.0));
        $this->assertNull($this->landed()->toCostSen($supplier, 4120.0));
    }

    public function test_an_myr_prepaid_supplier_with_no_transfer_is_at_face_value(): void
    {
        $supplier = $this->supplier('MYR');

        $this->assertSame(100.0, $this->landed()->averageSen($supplier));
        $this->assertSame(1234, $this->landed()->toCatalogSen($supplier, 12.34));
        $this->assertSame(1234, $this->landed()->toCostSen($supplier, 12.34));
    }

    /** ADR-134 decision 3: one rule for every prepaid supplier — an MYR deposit's fee is unit cost too. */
    public function test_an_myr_prepaid_suppliers_fee_is_unit_cost(): void
    {
        $supplier = $this->supplier('MYR');
        $this->transfer($supplier, 100000, 500, '1000.00');

        $this->assertEqualsWithDelta(100.5, $this->landed()->averageSen($supplier), 1e-9);
        $this->assertSame(1007, $this->landed()->toCatalogSen($supplier, 10.01));   // 1006.005: catalog rounds up
        $this->assertSame(1006, $this->landed()->toCostSen($supplier, 10.01));      // an order rounds like the pool
    }

    public function test_a_pay_after_supplier_stays_at_face_value_whatever_its_fees(): void
    {
        $supplier = $this->supplier('MYR', ['driver' => Supplier::DRIVER_MANUAL, 'funding_mode' => Supplier::FUNDING_PAY_AFTER]);
        $this->transfer($supplier, 100000, 500, '1000.00');

        $this->assertSame(100.0, $this->landed()->averageSen($supplier));
    }

    /** Catalog cost rounds up (ADR-033's cushion); an order's cost rounds half-up, like the pool. */
    public function test_catalog_cost_rounds_up_and_order_cost_rounds_like_the_pool(): void
    {
        $supplier = $this->supplier();
        $this->transfer($supplier, 23900, 0, '1000000');   // 0.0239 sen a rupiah

        $this->assertSame(99, $this->landed()->toCatalogSen($supplier, 4120.0));   // 98.468
        $this->assertSame(98, $this->landed()->toCostSen($supplier, 4120.0));
    }

    /** The invariant that keeps per-order profit and COGS one number: an order's landed cost is the cost the pool later gives its drawdown. */
    public function test_an_orders_landed_cost_is_exactly_what_the_pool_charges_its_drawdown(): void
    {
        $supplier = $this->supplier();
        $this->transfer($supplier, 499811, 1466, '21000482', '2026-10-01');
        $prices = [4120.0, 81602.0, 768000.0, 15791.0, 386373.0, 4029.0, 154102.0, 200050.0];

        $charged = [];
        foreach ($prices as $i => $price) {
            $charged[] = $this->landed()->toCostSen($supplier, $price);
            $rows[] = $this->row($supplier, '-'.$price, 'order', 9000 + $i, '2026-10-02 1'.$i.':00:00');
        }

        $pool = MovingAveragePool::replay($supplier->id, new FundedCostOfSales);
        $this->assertSame($charged, array_map(fn ($row) => -$pool->costs[$row->id], $rows));
    }

    /** Decision 8: a new, corrected or voided transfer is seen with no explicit cache invalidation. */
    public function test_the_cached_rate_follows_every_funding_change(): void
    {
        $supplier = $this->supplier();
        $first = $this->transfer($supplier, 20000, 0, '1000000');
        $this->assertEqualsWithDelta(0.02, $this->landed()->averageSen($supplier), 1e-12);

        $this->transfer($supplier, 30000, 0, '1000000', '2026-10-11');
        $this->assertEqualsWithDelta(0.025, $this->landed()->averageSen($supplier), 1e-12);

        $first->update(['amount_myr_sent' => 40000]);
        $this->assertEqualsWithDelta(0.035, $this->landed()->averageSen($supplier), 1e-12);

        $first->update(['voided_at' => now()]);
        $this->assertEqualsWithDelta(0.03, $this->landed()->averageSen($supplier), 1e-12);
    }

    /** Decision 2: a drawdown doesn't move the rate; its rounding stays in the pool until the next deposit. */
    public function test_drawdowns_do_not_move_the_rate(): void
    {
        $supplier = $this->supplier();
        $this->transfer($supplier, 10000, 0, '3');   // 3333.33 sen a unit
        $before = $this->landed()->averageSen($supplier);
        $this->row($supplier, '-1', 'order', 1, '2026-10-12 10:00:00');

        $this->assertSame($before, $this->landed()->averageSen($supplier));
        $this->assertSame($before, MovingAveragePool::replay($supplier->id, new FundedCostOfSales)->average);
        $this->assertSame(10000 - 3333, MovingAveragePool::replay($supplier->id, new FundedCostOfSales)->sen);
    }
}
