<?php

namespace Tests\Support;

use App\Models\Game;
use App\Models\Order;
use App\Models\OrderDeliveryLeg;
use App\Models\Package;
use App\Models\Supplier;
use App\Services\Accounting\SupplierFundingService;
use App\Services\Currency\CurrencyRateService;
use App\Services\Fulfillment\OrderFulfillmentService;
use App\Services\Ledger\LedgerService;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\OrderStatusService;
use App\Services\Order\PaymentStatus;
use App\Services\Order\ReferenceNumberService;
use App\Services\Supplier\SupplierAdapter;
use App\Services\Supplier\SupplierAdapterFactory;
use App\Services\Supplier\SupplierOrderRequest;
use App\Services\Supplier\SupplierResponse;
use App\Services\Supplier\SupplierStatusCheckRequest;
use App\Services\Supplier\ValidationNotSupportedException;
use App\Services\Voucher\VoucherService;
use RuntimeException;

/** Shared setup and helpers for the OrderFulfillmentServiceComboTest split (one file per feature). */
trait ComboFulfillmentFixtures
{
    private const DEFAULT_SUPPLIER_SLUG = 'test-supplier';

    private function service(SupplierAdapter $adapter): OrderFulfillmentService
    {
        $this->app->bind('supplier-adapter.'.self::DEFAULT_SUPPLIER_SLUG, fn () => $adapter);

        return new OrderFulfillmentService(
            new OrderStatusService,
            new ReferenceNumberService,
            $this->app->make(SupplierAdapterFactory::class),
            new LedgerService,
            new VoucherService(new LedgerService),
            new SupplierFundingService,
            new CurrencyRateService,
        );
    }

    private function supplier(): Supplier
    {
        return Supplier::query()->firstOrCreate(
            ['slug' => self::DEFAULT_SUPPLIER_SLUG],
            ['name' => 'Test Supplier', 'api_config' => [], 'currency' => 'MYR'],
        );
    }

    private function componentPackage(Supplier $supplier, int $gameId, array $overrides = []): Package
    {
        return Package::query()->create(array_merge([
            'game_id' => $gameId, 'name' => 'Component', 'denomination' => 100,
            'cost_price' => 500, 'standard_selling_price' => 600, 'markup_percent' => 20,
            'supplier_id' => $supplier->id, 'supplier_package_ref' => 'REF-'.uniqid(),
        ], $overrides));
    }

    private function comboPackage(int $gameId, array $components): Package
    {
        $combo = Package::query()->create([
            'game_id' => $gameId, 'name' => 'Combo', 'is_combo' => true,
            'denomination' => 0, 'cost_price' => 0, 'standard_selling_price' => 0, 'markup_percent' => 0,
        ]);

        foreach ($components as $index => ['package' => $component, 'quantity' => $quantity]) {
            $combo->components()->attach($component->id, ['quantity' => $quantity, 'sort_order' => $index]);
        }

        return $combo;
    }

    private function paidComboOrder(Package $combo, array $overrides = []): Order
    {
        return Order::query()->create(array_merge(['placed_via' => 'storefront',
            'affiliate_id' => $this->primaryAffiliate()->id,
            'order_number' => 'KRS-COMBO-1',
            'customer_email' => 'buyer@example.com',
            'game_id' => $combo->game_id,
            'package_id' => $combo->id,
            'player_id' => '123456',
            'server_id' => '1234',
            'supplier_id' => null,
            'supplier_product_ref' => null,
            'cost_price' => $combo->cost_price,
            'standard_selling_price' => $combo->standard_selling_price,
            'selling_price' => 1500,
            'transaction_fee' => 100,
            'final_amount' => 1600,
            'platform_profit' => 200,
            'affiliate_profit' => 0,
            'payment_status' => PaymentStatus::Paid->value,
            'delivery_status' => DeliveryStatus::NotStarted->value,
        ], $overrides));
    }

    /**
     * Consumes one queued SupplierResponse per createOrder() call, in
     * call order — deterministic since attemptLeg() walks legs in
     * ascending leg_number.
     */
    private function queuedAdapter(array $responses): SupplierAdapter
    {
        return new class($responses) implements SupplierAdapter
        {
            private int $index = 0;

            public function __construct(private readonly array $responses) {}

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
                $next = $this->responses[$this->index++] ?? throw new RuntimeException('no more queued responses');

                return $next instanceof \Throwable ? throw $next : $next;
            }

            public function checkStatus(SupplierStatusCheckRequest $request): SupplierResponse
            {
                throw new RuntimeException('not used in this test');
            }

            public function validatePlayer(string $playerId, ?string $serverId): SupplierResponse
            {
                throw new ValidationNotSupportedException('not used in this test');
            }
        };
    }

    /**
     * ADR-102 addendum (2026-09-29, PR-D): the same replay-safe rule at
     * leg granularity — an ambiguous Digiflazz leg (breaker open, or a
     * thrown timeout) is parked at Pending and auto-polled with its own
     * same reference; the order rolls up to Pending, not NeedsReview.
     *
     * @return array{0: Order, 1: OrderFulfillmentService}
     */
    private function digiflazzCombo(array $responses): array
    {
        $supplier = Supplier::query()->create(['name' => 'Digiflazz', 'slug' => 'digiflazz', 'api_config' => [], 'currency' => 'MYR']);
        $adapter = $this->queuedAdapter($responses); // one instance, so responses advance across legs
        $this->app->bind('supplier-adapter.digiflazz', fn () => $adapter);
        $gameId = Game::query()->create(['name' => 'MLBB', 'slug' => 'mlbb-'.uniqid()])->id;
        $combo = $this->comboPackage($gameId, [
            ['package' => $this->componentPackage($supplier, $gameId), 'quantity' => 1],
            ['package' => $this->componentPackage($supplier, $gameId), 'quantity' => 1],
        ]);

        return [$this->paidComboOrder($combo), $this->service($this->queuedAdapter([]))];
    }

    /**
     * @param  list<DeliveryStatus>  $legStatuses
     */
    private function needsReviewComboWithLegs(array $legStatuses): Order
    {
        $supplier = $this->supplier();
        $gameId = Game::query()->create(['name' => 'MLBB', 'slug' => 'mlbb-'.uniqid()])->id;
        $packages = array_map(fn () => $this->componentPackage($supplier, $gameId), $legStatuses);
        $combo = $this->comboPackage($gameId, array_map(fn (Package $p) => ['package' => $p, 'quantity' => 1], $packages));
        $order = $this->paidComboOrder($combo, ['delivery_status' => DeliveryStatus::NeedsReview->value]);

        foreach ($legStatuses as $i => $status) {
            OrderDeliveryLeg::query()->create([
                'order_id' => $order->id, 'component_package_id' => $packages[$i]->id, 'supplier_id' => $supplier->id,
                'leg_number' => $i + 1, 'status' => $status->value, 'selling_price_sen' => $packages[$i]->standard_selling_price,
            ]);
        }

        return $order->fresh();
    }

    private function assertComboReconciliationHoldsForBasis(string $pricingBasis, int $affiliateProfit): void
    {
        config(['services.real_cost_reconciliation.enabled' => true]);
        $supplier = $this->supplier();
        $gameId = Game::query()->create(['name' => 'MLBB', 'slug' => 'mlbb-'.uniqid()])->id;
        $a = $this->componentPackage($supplier, $gameId, ['cost_price' => 500]);
        $b = $this->componentPackage($supplier, $gameId, ['cost_price' => 500]);
        $combo = $this->comboPackage($gameId, [
            ['package' => $a, 'quantity' => 1],
            ['package' => $b, 'quantity' => 1],
        ]);
        $order = $this->paidComboOrder($combo, [
            'pricing_basis' => $pricingBasis,
            'selling_price' => 1500,
            'affiliate_profit' => $affiliateProfit,
            'platform_profit' => 100,
        ]);
        OrderDeliveryLeg::query()->create([
            'order_id' => $order->id, 'component_package_id' => $a->id, 'supplier_id' => $supplier->id,
            'leg_number' => 1, 'status' => DeliveryStatus::Delivered->value, 'supplier_reference' => 'SREF-1',
            'selling_price_sen' => $a->standard_selling_price, 'real_cost_price_sen' => 700,
        ]);
        OrderDeliveryLeg::query()->create([
            'order_id' => $order->id, 'component_package_id' => $b->id, 'supplier_id' => $supplier->id,
            'leg_number' => 2, 'status' => DeliveryStatus::Delivered->value, 'supplier_reference' => 'SREF-2',
            'selling_price_sen' => $b->standard_selling_price, 'real_cost_price_sen' => 500,
        ]);

        $result = $this->service($this->queuedAdapter([]))->fulfill($order);

        // costTotal = 700 + 500 = 1200 (both real, no fallback needed).
        $this->assertSame($affiliateProfit, $result->affiliate_profit);
        $this->assertSame(1500 - 1200 - $affiliateProfit, $result->platform_profit);
    }
}
