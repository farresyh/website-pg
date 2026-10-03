<?php

namespace Tests\Support;

use App\Models\AdminUser;
use App\Models\Game;
use App\Models\Order;
use App\Models\OrderDeliveryLeg;
use App\Models\Package;
use App\Models\Supplier;
use App\Services\Fulfillment\OrderSettlementService;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\PaymentStatus;

/**
 * ADR-094 2026-10-04 addendum — a combo order with leg 1 (priced 3000,
 * cost 2400) delivered and leg 2 (priced 2000) failed, so u = 0.4. For
 * the readers that must count a partial delivery correctly.
 */
trait PartialComboOrders
{
    protected function partialComboOrder(array $overrides = []): Order
    {
        $supplier = Supplier::query()->firstOrCreate(['slug' => 'partial-fixture'], ['name' => 'Fixture Supplier', 'api_config' => [], 'currency' => 'MYR']);
        $gameId = Game::query()->create(['name' => 'MLBB', 'slug' => 'mlbb-'.uniqid()])->id;
        $legA = Package::query()->create(['game_id' => $gameId, 'name' => 'A', 'denomination' => 1, 'cost_price' => 2400, 'standard_selling_price' => 3000, 'supplier_id' => $supplier->id, 'supplier_package_ref' => 'A-'.uniqid()]);
        $legB = Package::query()->create(['game_id' => $gameId, 'name' => 'B', 'denomination' => 2, 'cost_price' => 1700, 'standard_selling_price' => 2000, 'supplier_id' => $supplier->id, 'supplier_package_ref' => 'B-'.uniqid()]);
        $combo = Package::query()->create(['game_id' => $gameId, 'name' => 'Combo', 'is_combo' => true, 'denomination' => 3, 'cost_price' => 4100, 'standard_selling_price' => 5000]);

        $order = Order::query()->create(array_merge([
            'affiliate_id' => $this->primaryAffiliate()->id,
            'order_number' => 'PG-PARTIAL-'.uniqid(),
            'customer_email' => 'buyer@example.com',
            'game_id' => $gameId,
            'package_id' => $combo->id,
            'player_id' => '123456',
            'cost_price' => 4100,
            'standard_selling_price' => 5000,
            'selling_price' => 5000,
            'transaction_fee' => 100,
            'final_amount' => 5100,
            'platform_profit' => 900,
            'affiliate_profit' => 0,
            'payment_status' => PaymentStatus::Paid->value,
            'paid_at' => '2026-10-15 04:00:00',
            'delivery_status' => DeliveryStatus::PartiallyDelivered->value,
        ], $overrides));

        OrderDeliveryLeg::query()->create(['order_id' => $order->id, 'component_package_id' => $legA->id, 'supplier_id' => $supplier->id, 'leg_number' => 1, 'status' => DeliveryStatus::Delivered->value, 'selling_price_sen' => 3000]);
        OrderDeliveryLeg::query()->create(['order_id' => $order->id, 'component_package_id' => $legB->id, 'supplier_id' => $supplier->id, 'leg_number' => 2, 'status' => DeliveryStatus::Failed->value, 'selling_price_sen' => 2000]);

        return $order->fresh();
    }

    /** Settled: voucher 2000 (5000 cash × 0.4), platform profit (5000 − 2000) − 2400 = 600. */
    protected function settledPartialComboOrder(array $overrides = []): Order
    {
        $order = $this->partialComboOrder($overrides);
        app(OrderSettlementService::class)->settle($order, AdminUser::factory()->create(['role' => 'admin'])->id);

        return $order->fresh();
    }
}
