<?php

namespace Tests\Feature\Services\Fulfillment;

use App\Models\Game;
use App\Models\Order;
use App\Models\OrderDeliveryLeg;
use App\Models\Package;
use App\Models\Supplier;
use App\Services\Fulfillment\SupplierDeliveryCheckService;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\PaymentStatus;
use App\Services\Supplier\SupplierAdapter;
use App\Services\Supplier\SupplierOrderRequest;
use App\Services\Supplier\SupplierResponse;
use App\Services\Supplier\SupplierStatusCheckRequest;
use App\Services\Supplier\ValidationNotSupportedException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * ADR-097 decision 14 — this service (ADR-096's scheduled reconcile
 * poll + admin manual "Check from Supplier") builds its own
 * SupplierStatusCheckRequest independently of OrderFulfillmentService,
 * so it needed the separator-override fix wired here too, not just at
 * createOrder()'s call site. No dedicated test file existed for this
 * service before this ADR — these are the first tests for it in
 * isolation (it was previously only exercised indirectly via
 * CheckSupplierDeliveryJobTest/OrderManualCheckTest).
 */
class SupplierDeliveryCheckServiceTest extends TestCase
{
    use RefreshDatabase;

    private function capturingAdapter(): SupplierAdapter
    {
        return new class implements SupplierAdapter
        {
            public ?SupplierStatusCheckRequest $captured = null;

            public function checkBalance(): SupplierResponse
            {
                throw new RuntimeException('not used in this test');
            }

            public function listProducts(): SupplierResponse
            {
                throw new RuntimeException('not used in this test');
            }

            public function createOrder(SupplierOrderRequest $request): SupplierResponse
            {
                throw new RuntimeException('not used in this test');
            }

            public function checkStatus(SupplierStatusCheckRequest $request): SupplierResponse
            {
                $this->captured = $request;

                return SupplierResponse::pending([]);
            }

            public function validatePlayer(string $playerId, ?string $serverId): SupplierResponse
            {
                throw new ValidationNotSupportedException('not used in this test');
            }
        };
    }

    public function test_check_order_forwards_the_games_customer_no_separator_override(): void
    {
        $supplier = Supplier::query()->create([
            'name' => 'Digiflazz Test', 'slug' => 'digiflazz-check-test', 'api_config' => [], 'currency' => 'IDR',
        ]);
        $game = Game::query()->create([
            'name' => 'MLBB', 'slug' => 'mlbb-check-'.uniqid(),
            'validation_rules' => ['customer_no_separator' => 'space'],
        ]);
        $order = Order::query()->create([
            'affiliate_id' => $this->primaryAffiliate()->id,
            'order_number' => 'KRS-DELIVCHECK-1',
            'reference_number' => 'REF-DELIVCHECK-1',
            'customer_email' => 'buyer@example.com',
            'player_id' => '51049607',
            'server_id' => '2005',
            'game_id' => $game->id,
            'supplier_id' => $supplier->id,
            'supplier_product_ref' => 'xld10',
            'cost_price' => 900, 'standard_selling_price' => 900, 'selling_price' => 1000,
            'transaction_fee' => 100, 'final_amount' => 1100, 'platform_profit' => 100, 'affiliate_profit' => 0,
            'payment_status' => PaymentStatus::Paid->value,
            'delivery_status' => DeliveryStatus::Pending->value,
        ]);

        $adapter = $this->capturingAdapter();
        $this->app->bind('supplier-adapter.digiflazz-check-test', fn () => $adapter);

        $this->app->make(SupplierDeliveryCheckService::class)->check($order);

        $this->assertSame('space', $adapter->captured?->customerNoSeparator);
    }

    /**
     * ADR-097 decision 14 — checkComboLegs() resolves the separator
     * from $order->game, same as attemptLeg() does on the createOrder
     * side (StoreComboPackageRequest's same-game-only combo guarantee
     * makes this safe).
     */
    public function test_check_combo_legs_forwards_the_orders_game_customer_no_separator_override(): void
    {
        $supplier = Supplier::query()->create([
            'name' => 'Digiflazz Test', 'slug' => 'digiflazz-check-combo-test', 'api_config' => [], 'currency' => 'IDR',
        ]);
        $game = Game::query()->create([
            'name' => 'MLBB', 'slug' => 'mlbb-check-combo-'.uniqid(),
            'validation_rules' => ['customer_no_separator' => 'pipe'],
        ]);
        $component = Package::query()->create([
            'game_id' => $game->id, 'name' => 'Component', 'denomination' => 100,
            'cost_price' => 500, 'standard_selling_price' => 600, 'markup_percent' => 20,
            'supplier_id' => $supplier->id, 'supplier_package_ref' => 'REF-'.uniqid(),
        ]);
        $combo = Package::query()->create([
            'game_id' => $game->id, 'name' => 'Combo', 'is_combo' => true,
            'denomination' => 0, 'cost_price' => 0, 'standard_selling_price' => 0, 'markup_percent' => 0,
        ]);
        $combo->components()->attach($component->id, ['quantity' => 1, 'sort_order' => 0]);

        $order = Order::query()->create([
            'affiliate_id' => $this->primaryAffiliate()->id,
            'order_number' => 'KRS-DELIVCHECK-COMBO-1',
            'reference_number' => 'REF-DELIVCHECK-COMBO-1',
            'customer_email' => 'buyer@example.com',
            'player_id' => '51049607',
            'server_id' => '2005',
            'game_id' => $game->id,
            'package_id' => $combo->id,
            'supplier_id' => null,
            'supplier_product_ref' => null,
            'cost_price' => 0, 'standard_selling_price' => 0, 'selling_price' => 1500,
            'transaction_fee' => 100, 'final_amount' => 1600, 'platform_profit' => 200, 'affiliate_profit' => 0,
            'payment_status' => PaymentStatus::Paid->value,
            'delivery_status' => DeliveryStatus::Pending->value,
        ]);

        OrderDeliveryLeg::query()->create([
            'order_id' => $order->id,
            'component_package_id' => $component->id,
            'supplier_id' => $supplier->id,
            'leg_number' => 1,
            'reference_number' => $order->reference_number.'-L1',
            'status' => DeliveryStatus::Pending->value,
        ]);

        $adapter = $this->capturingAdapter();
        $this->app->bind('supplier-adapter.digiflazz-check-combo-test', fn () => $adapter);

        $this->app->make(SupplierDeliveryCheckService::class)->check($order);

        $this->assertSame('pipe', $adapter->captured?->customerNoSeparator);
        // ADR-103 decision 5 — reads the leg's own stored reference_number,
        // never re-derives it.
        $this->assertSame($order->reference_number.'-L1', $adapter->captured?->supplierRef);
    }
}
