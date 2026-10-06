<?php

namespace Tests\Feature\Database;

use App\Models\Game;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\OrderResendAttempt;
use App\Models\Package;
use App\Models\Supplier;
use App\Services\Ledger\LedgerOwnerType;
use App\Services\Ledger\LedgerService;
use App\Services\Order\DeliveryStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ADR-105 2026-10-06 decision 18 (two-part rule, build-time correction):
 * a resent order's cost_price follows its last successful resend; its
 * platform_profit is rewritten only where real cost is null and the
 * result equals the order's platform ledger. The ledger is never touched.
 */
class ResentOrderCostPriceMigrationTest extends TestCase
{
    use RefreshDatabase;

    private Package $package;

    protected function setUp(): void
    {
        parent::setUp();

        $supplier = Supplier::query()->create(['name' => 'Digiflazz', 'slug' => 'digiflazz', 'api_config' => [], 'currency' => 'MYR']);
        $game = Game::query()->create(['name' => 'PUBG Mobile', 'slug' => 'pubg-mobile']);
        $this->package = Package::query()->create([
            'game_id' => $game->id, 'name' => '60 UC', 'cost_price' => 365, 'standard_selling_price' => 402,
            'supplier_id' => $supplier->id, 'supplier_package_ref' => 'PUBGG_60_PG1', 'is_active' => true,
        ]);
    }

    private function migrate(): void
    {
        (require database_path('migrations/2026_10_06_000000_correct_cost_price_on_resent_orders.php'))->up();
    }

    /** @param array<string, mixed> $overrides */
    private function resentOrder(array $overrides, int $resendCost, string $outcome = 'success'): Order
    {
        $order = Order::factory()->create(array_merge([
            'game_id' => $this->package->game_id,
            'package_id' => $this->package->id,
            'delivery_status' => DeliveryStatus::Delivered,
        ], $overrides));

        OrderResendAttempt::query()->create([
            'order_id' => $order->id,
            'attempt_type' => 'resend',
            'package_id' => $this->package->id,
            'cost_price_sen' => $resendCost,
            'standard_selling_price_sen' => 402,
            'price_diff_sen' => $resendCost - $order->cost_price,
            'outcome' => $outcome,
        ]);

        return $order;
    }

    private function platformLedger(Order $order, int ...$amounts): void
    {
        foreach ($amounts as $i => $amount) {
            // The first is the delivery credit; any later one is a manual
            // adjustment (order 15's −10), which carries its own key.
            app(LedgerService::class)->credit(
                LedgerOwnerType::Platform, null, $amount, 'order_profit', 'order', $order->id,
                reason: $i === 0 ? null : 'ADR-105 correction',
                idempotencyKey: $i === 0 ? null : "adjust-{$order->id}-{$i}",
            );
        }
    }

    /** Order 15's shape: profit column 37, ledger 37 − 10 adjustment = 27. */
    public function test_rewrites_cost_and_profit_when_real_cost_is_null_and_the_ledger_agrees(): void
    {
        $order = $this->resentOrder(['cost_price' => 356, 'selling_price' => 392, 'platform_profit' => 37], 365);
        $this->platformLedger($order, 37, -10);

        $this->migrate();

        $order->refresh();
        $this->assertSame(365, $order->cost_price);
        $this->assertSame(27, $order->platform_profit);
    }

    /** Order 19's shape: the profit was already right, only cost_price was stale. */
    public function test_rewrites_only_cost_when_the_profit_already_matches(): void
    {
        $order = $this->resentOrder(['cost_price' => 356, 'selling_price' => 367, 'platform_profit' => 2], 365);
        $this->platformLedger($order, 2);

        $this->migrate();

        $order->refresh();
        $this->assertSame(365, $order->cost_price);
        $this->assertSame(2, $order->platform_profit);
    }

    /** Real cost known: ADR-111 owns platform_profit, so only cost_price moves. */
    public function test_rewrites_only_cost_when_real_cost_is_known(): void
    {
        $order = $this->resentOrder(['cost_price' => 356, 'selling_price' => 392, 'platform_profit' => 40, 'real_cost_price_sen' => 352], 365);
        $this->platformLedger($order, 40);

        $this->migrate();

        $order->refresh();
        $this->assertSame(365, $order->cost_price);
        $this->assertSame(40, $order->platform_profit);
    }

    public function test_skips_an_order_whose_ledger_disagrees_with_the_residual(): void
    {
        $order = $this->resentOrder(['cost_price' => 356, 'selling_price' => 392, 'platform_profit' => 37], 365);
        $this->platformLedger($order, 37);

        $this->migrate();

        $order->refresh();
        $this->assertSame(356, $order->cost_price);
        $this->assertSame(37, $order->platform_profit);
    }

    public function test_leaves_orders_without_a_successful_resend_alone(): void
    {
        $failedOnly = $this->resentOrder(['cost_price' => 356, 'selling_price' => 392, 'platform_profit' => 36], 365, 'failed');
        $notDelivered = $this->resentOrder(['cost_price' => 356, 'selling_price' => 392, 'platform_profit' => 27, 'delivery_status' => DeliveryStatus::Failed], 365);

        $this->migrate();

        $this->assertSame(356, $failedOnly->fresh()->cost_price);
        $this->assertSame(356, $notDelivered->fresh()->cost_price);
    }

    public function test_a_second_run_changes_nothing(): void
    {
        $order = $this->resentOrder(['cost_price' => 356, 'selling_price' => 392, 'platform_profit' => 37], 365);
        $this->platformLedger($order, 37, -10);
        $this->migrate();
        $writes = 0;
        DB::listen(function ($query) use (&$writes) {
            if (str_starts_with(strtolower($query->sql), 'update')) {
                $writes++;
            }
        });

        $this->migrate();

        $this->assertSame(0, $writes);
        $this->assertSame(365, $order->fresh()->cost_price);
        $this->assertSame(27, $order->fresh()->platform_profit);
        $this->assertSame(27, (int) LedgerEntry::query()->where('reference_id', $order->id)->sum('amount'));
    }
}
