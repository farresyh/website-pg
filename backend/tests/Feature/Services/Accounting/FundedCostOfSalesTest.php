<?php

namespace Tests\Feature\Services\Accounting;

use App\Models\AdminUser;
use App\Models\Game;
use App\Models\Order;
use App\Models\OrderDeliveryLeg;
use App\Models\Package;
use App\Models\Supplier;
use App\Models\SupplierLedgerEntry;
use App\Models\SupplierTransfer;
use App\Services\Accounting\MonthlyAccountingSummaryService;
use App\Services\Accounting\SupplierLedgerEntryType;
use App\Services\Fulfillment\OrderSettlementService;
use App\Services\Order\DeliveryStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\PartialComboOrders;
use Tests\TestCase;

/**
 * ADR-083 2026-10-17 addendum (§16 item 115): COGS at funded cost, the
 * bridge to the per-order cost, and drawdowns not yet in COGS.
 */
class FundedCostOfSalesTest extends TestCase
{
    use PartialComboOrders;
    use RefreshDatabase;

    private function summary(int $year = 2026, int $month = 9): array
    {
        return (new MonthlyAccountingSummaryService)->forPeriod($year, $month);
    }

    /** An IDR supplier whose deposit cost $myrSen for $units IDR. */
    private function idrSupplier(int $myrSen = 100000, string $units = '100000.0000', string $on = '2026-09-05', string $slug = 'digiflazz'): Supplier
    {
        $supplier = Supplier::query()->create(['name' => ucfirst($slug), 'slug' => $slug, 'api_config' => [], 'currency' => 'IDR']);
        $this->transfer($supplier, $myrSen, $units, $on);

        return $supplier;
    }

    private function transfer(Supplier $supplier, int $myrSen, string $units, string $on): void
    {
        SupplierTransfer::query()->create([
            'transferred_on' => $on, 'supplier_id' => $supplier->id, 'source_channel' => 'wise',
            'amount_myr_sent' => $myrSen, 'fee_myr' => 0, 'currency' => $supplier->currency, 'amount_foreign_received' => $units,
        ]);
    }

    private function drawdown(Supplier $supplier, string $units, string $type, int $id, string $at = '2026-09-15 10:00:00'): void
    {
        SupplierLedgerEntry::query()->forceCreate([
            'supplier_id' => $supplier->id, 'type' => SupplierLedgerEntryType::OrderDrawdown->value, 'amount' => '-'.$units,
            'currency' => $supplier->currency, 'reference_type' => $type, 'reference_id' => $id, 'created_at' => $at,
        ]);
    }

    private function delivered(Supplier $supplier, int $costPrice, int $sellingPrice = 0, string $paidAt = '2026-09-15 10:00:00'): Order
    {
        return Order::factory()->delivered()->create([
            'paid_at' => $paidAt, 'cost_price' => $costPrice, 'selling_price' => $sellingPrice, 'supplier_id' => $supplier->id,
        ]);
    }

    private function assertBridgeHolds(array $lines): void
    {
        $this->assertSame($lines['cogs_sen'], $lines['cogs_at_order_cost_sen'] + $lines['cogs_funding_difference_sen']);
    }

    /** Two deposits at 10 and 20 MYR/unit blend to 15; 50 units drawn cost RM750, not the RM700 estimate. */
    public function test_cogs_is_the_drawdown_at_the_deposits_blended_rate(): void
    {
        $supplier = $this->idrSupplier(100000, '100.0000');
        $this->transfer($supplier, 200000, '100.0000', '2026-09-05');
        $order = $this->delivered($supplier, 70000);
        $this->drawdown($supplier, '50', 'order', $order->id);

        $lines = $this->summary();

        $this->assertSame(75000, $lines['cogs_sen']);
        $this->assertSame(70000, $lines['cogs_at_order_cost_sen']);
        $this->assertSame(5000, $lines['cogs_funding_difference_sen']);
        $this->assertSame(0, $lines['cogs_fallback_order_count']);
        $this->assertArrayNotHasKey('supplier_prepaid_fx_variance_sen', $lines);
    }

    /** K1: three orders of 1/3 sen each round to 0 one by one, but cost 1 sen together. */
    public function test_cost_is_rounded_once_per_supplier_not_per_order(): void
    {
        $supplier = $this->idrSupplier(1000, '3000.0000'); // RM10 for 3,000 units: 1/3 sen a unit
        foreach (range(1, 3) as $i) {
            $this->drawdown($supplier, '1', 'order', $this->delivered($supplier, 1)->id);
        }

        $this->assertSame(1, $this->summary()['cogs_sen']);
    }

    public function test_an_myr_supplier_drawdown_is_its_face_value_without_any_transfer(): void
    {
        $supplier = Supplier::query()->create(['name' => 'Gamevion', 'slug' => 'gamevion', 'api_config' => [], 'currency' => 'MYR']);
        $this->drawdown($supplier, '12.34', 'order', $this->delivered($supplier, 1200)->id);

        $lines = $this->summary();

        $this->assertSame(1234, $lines['cogs_sen']);
        $this->assertSame(0, $lines['cogs_fallback_order_count']);
    }

    /** The old true-up skipped a supplier with no rate, so its cost dropped out of profit. */
    public function test_a_supplier_with_no_rate_is_costed_at_the_orders_own_cost_never_zero(): void
    {
        $supplier = Supplier::query()->create(['name' => 'New IDR', 'slug' => 'new-idr', 'api_config' => [], 'currency' => 'IDR']);
        $this->drawdown($supplier, '50000', 'order', $this->delivered($supplier, 1500, 2000)->id);

        $lines = $this->summary();

        $this->assertSame(1500, $lines['cogs_sen']);
        $this->assertSame(1, $lines['cogs_fallback_order_count']);
        $this->assertSame(2000 - 1500, $lines['operating_profit_sen']);
    }

    public function test_an_order_with_no_drawdown_is_costed_at_its_own_cost_and_counted(): void
    {
        $supplier = $this->idrSupplier();
        $this->delivered($supplier, 800, 1000);

        $lines = $this->summary();

        $this->assertSame(800, $lines['cogs_sen']);
        $this->assertSame(1, $lines['cogs_fallback_order_count']);
        $this->assertSame(200, $lines['operating_profit_sen']);
    }

    /** One combo leg drawn from the deposit, the other with no drawdown: funded + that leg's own cost. */
    public function test_a_combo_is_costed_leg_by_leg(): void
    {
        $supplier = $this->idrSupplier(); // 1 sen a unit
        $gameId = Game::query()->create(['name' => 'MLBB', 'slug' => 'mlbb'])->id;
        $pkg = fn (string $name, int $cost) => Package::query()->create(['game_id' => $gameId, 'name' => $name, 'denomination' => 1, 'cost_price' => $cost, 'standard_selling_price' => $cost + 100, 'supplier_id' => $supplier->id, 'supplier_package_ref' => $name]);
        $a = $pkg('A', 300);
        $b = $pkg('B', 400);
        $order = Order::factory()->delivered()->create(['paid_at' => '2026-09-15 10:00:00', 'cost_price' => 700, 'supplier_id' => null, 'package_id' => $a->id]);
        $legA = OrderDeliveryLeg::query()->create(['order_id' => $order->id, 'component_package_id' => $a->id, 'supplier_id' => $supplier->id, 'leg_number' => 1, 'status' => DeliveryStatus::Delivered->value, 'selling_price_sen' => 400]);
        OrderDeliveryLeg::query()->create(['order_id' => $order->id, 'component_package_id' => $b->id, 'supplier_id' => $supplier->id, 'leg_number' => 2, 'status' => DeliveryStatus::Delivered->value, 'selling_price_sen' => 500, 'real_cost_price_sen' => 410]);
        $this->drawdown($supplier, '320', 'order_delivery_leg', $legA->id);

        $lines = $this->summary();

        $this->assertSame(320 + 410, $lines['cogs_sen']);
        $this->assertSame(1, $lines['cogs_fallback_order_count']);
        $this->assertBridgeHolds($lines);
    }

    /** ADR-094 decision 41: only the delivered leg; the fixture's MYR legs have no drawdown, so it is a fallback. */
    public function test_a_settled_partial_counts_its_delivered_leg(): void
    {
        $this->settledPartialComboOrder();

        $october = $this->summary(2026, 10);

        $this->assertSame(2400, $october['cogs_sen']);
        $this->assertSame(1, $october['cogs_fallback_order_count']);
        $this->assertBridgeHolds($october);
    }

    /** K2/K4: an unsettled partial's leg drawdown waits outside COGS, visibly, and enters COGS on settlement. */
    public function test_a_drawdown_whose_order_is_not_recognised_shows_outside_cogs(): void
    {
        $order = $this->partialComboOrder();
        $supplier = Supplier::query()->where('slug', 'partial-fixture')->firstOrFail();
        $leg = $order->deliveryLegs()->where('status', DeliveryStatus::Delivered->value)->firstOrFail();
        $this->drawdown($supplier, '24.00', 'order_delivery_leg', $leg->id, '2026-10-15 04:00:00');

        $october = $this->summary(2026, 10);
        $this->assertSame(0, $october['cogs_sen']);
        $this->assertSame(2400, $october['drawdowns_not_in_cogs_sen']);

        app(OrderSettlementService::class)->settle($order, AdminUser::factory()->create(['role' => 'admin'])->id);

        $october = $this->summary(2026, 10);
        $this->assertSame(2400, $october['cogs_sen']);
        $this->assertSame(0, $october['drawdowns_not_in_cogs_sen']);
        $this->assertSame(0, $october['cogs_fallback_order_count']);
    }

    /** Cost follows the order's revenue month; the drawdown's own month shows it outside COGS. */
    public function test_a_drawdown_recorded_the_month_after_payment_is_costed_with_the_revenue(): void
    {
        $supplier = $this->idrSupplier();
        $order = $this->delivered($supplier, 900, 1000, '2026-09-30 15:59:00'); // 30 Sep 23:59 KL
        $this->drawdown($supplier, '900', 'order', $order->id, '2026-09-30 16:01:00'); // 1 Oct KL

        $this->assertSame(900, $this->summary(2026, 9)['cogs_sen']);
        $this->assertSame(0, $this->summary(2026, 9)['drawdowns_not_in_cogs_sen']);
        $this->assertSame(900, $this->summary(2026, 10)['drawdowns_not_in_cogs_sen']);
    }

    /** Decision 11 (Q24) still holds: a later transfer never moves a closed month. */
    public function test_the_rate_is_as_of_the_months_last_day(): void
    {
        $supplier = $this->idrSupplier(); // 1 sen a unit
        $this->transfer($supplier, 900000, '100000.0000', '2026-10-03'); // 9 sen a unit, October
        $this->drawdown($supplier, '90000', 'order', $this->delivered($supplier, 9000)->id);

        $this->assertSame(90000, $this->summary()['cogs_sen']);
    }

    /**
     * The invariant behind the change: where every drawdown's order is
     * recognised in its own month and every supplier has a rate, operating
     * profit equals the old formula's (revenue − drawdowns at funded cost).
     */
    public function test_operating_profit_matches_the_old_formula_when_every_drawdown_is_recognised(): void
    {
        $digiflazz = $this->idrSupplier(23300, '100000.0000'); // RM233 per million IDR
        $other = $this->idrSupplier(23500, '100000.0000', '2026-09-06', 'second');
        $drawn = ['digiflazz' => 0, 'second' => 0];
        foreach ([[$digiflazz, 1515, '6500'], [$digiflazz, 2890, '12345'], [$other, 777, '3333'], [$other, 4321, '18000']] as [$supplier, $estimate, $units]) {
            $this->drawdown($supplier, $units, 'order', $this->delivered($supplier, $estimate, $estimate + 300)->id);
            $drawn[$supplier->slug] += (int) $units;
        }
        $oldCost = (int) round($drawn['digiflazz'] * 0.233) + (int) round($drawn['second'] * 0.235);

        $lines = $this->summary();

        $this->assertSame(4 * 300 + (1515 + 2890 + 777 + 4321) - $oldCost, $lines['operating_profit_sen']);
        $this->assertSame($oldCost, $lines['cogs_sen']);
        $this->assertBridgeHolds($lines);
    }
}
