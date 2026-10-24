<?php

namespace Tests\Support;

use App\Models\Order;
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
use Illuminate\Http\Client\ConnectionException;
use RuntimeException;

/** Shared setup and helpers for the OrderFulfillmentServiceTest split (one file per feature). */
trait OrderFulfillmentFixtures
{
    /**
     * ADR-031: binds $adapter under this test's own default supplier
     * slug and resolves OrderFulfillmentService through the real
     * SupplierAdapterFactory — every other test in this file exercises
     * a single supplier and doesn't care about routing itself (that's
     * test_fulfill_routes_to_the_adapter_bound_for_the_orders_own_supplier's
     * job), so this stays a one-adapter-in, one-service-out helper.
     */
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

    private const DEFAULT_SUPPLIER_SLUG = 'test-supplier';

    private function paidOrder(array $overrides = []): Order
    {
        $supplierId = $overrides['supplier_id']
            ?? Supplier::query()->firstOrCreate(
                ['slug' => self::DEFAULT_SUPPLIER_SLUG],
                ['name' => 'Test Supplier', 'api_config' => [], 'currency' => 'MYR'],
            )->id;

        return Order::query()->create(array_merge(['placed_via' => 'storefront',
            'affiliate_id' => $this->primaryAffiliate()->id,
            'order_number' => 'KRS-TEST-1',
            'customer_email' => 'buyer@example.com',
            'player_id' => '123456',
            'server_id' => '1234',
            'supplier_id' => $supplierId,
            'supplier_product_ref' => 'FFP5',
            'cost_price' => 900,
            'standard_selling_price' => 900,
            'selling_price' => 1000,
            'transaction_fee' => 100,
            'final_amount' => 1100,
            'platform_profit' => 100,
            'affiliate_profit' => 0,
            'payment_status' => PaymentStatus::Paid->value,
            'delivery_status' => DeliveryStatus::NotStarted->value,
        ], $overrides));
    }

    private function fakeSupplierAdapter(
        bool $success,
        ?array $data = null,
        ?string $errorCode = null,
        ?string $errorMessage = null,
        // ADR-098, split by ADR-102 decision 5 — both default false so
        // every pre-existing plain-Failed test keeps its exact prior
        // behavior.
        bool $resendUnsafeWithSameReference = false,
        bool $outcomeConfirmedFailed = false,
    ): SupplierAdapter {
        return new class($success, $data, $errorCode, $errorMessage, $resendUnsafeWithSameReference, $outcomeConfirmedFailed) implements SupplierAdapter
        {
            public function __construct(
                private readonly bool $success,
                private readonly ?array $data,
                private readonly ?string $errorCode,
                private readonly ?string $errorMessage,
                private readonly bool $resendUnsafeWithSameReference,
                private readonly bool $outcomeConfirmedFailed,
            ) {}

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
                return $this->success
                    ? SupplierResponse::success($this->data)
                    : SupplierResponse::failure(
                        $this->errorCode,
                        $this->errorMessage,
                        resendUnsafeWithSameReference: $this->resendUnsafeWithSameReference,
                        outcomeConfirmedFailed: $this->outcomeConfirmedFailed,
                    );
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

    /** ADR-032: an async supplier accepted the order but hasn't confirmed the final outcome yet. */
    private function fakePendingSupplierAdapter(array $data = []): SupplierAdapter
    {
        return new class($data) implements SupplierAdapter
        {
            public function __construct(private readonly array $data) {}

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
                return SupplierResponse::pending($this->data);
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
     * 2026-09-28 audit finding M-1 — a supplier call that throws instead
     * of returning a SupplierResponse (a real ConnectionException on
     * timeout, but any Throwable is treated the same way).
     */
    private function throwingSupplierAdapter(\Throwable $exception): SupplierAdapter
    {
        return new class($exception) implements SupplierAdapter
        {
            public function __construct(private readonly \Throwable $exception) {}

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
                throw $this->exception;
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
     * ADR-102 addendum (2026-09-29, PR-D): for a supplier whose same-ref
     * re-submit replays the stored outcome (Digiflazz), an ambiguous
     * outcome goes to Pending and gets auto-polled with the SAME ref —
     * not to manual NeedsReview.
     */
    private function digiflazzOrder(SupplierAdapter $adapter): array
    {
        $supplier = Supplier::query()->create(['name' => 'Digiflazz', 'slug' => 'digiflazz', 'api_config' => [], 'currency' => 'MYR']);
        $this->app->bind('supplier-adapter.digiflazz', fn () => $adapter);

        return [$this->paidOrder(['supplier_id' => $supplier->id]), $this->service($this->fakeSupplierAdapter(true))];
    }

    private function assertReconciliationHoldsForBasis(string $pricingBasis, int $affiliateProfit): void
    {
        $order = $this->paidOrder([
            'pricing_basis' => $pricingBasis,
            'selling_price' => 1000,
            'affiliate_profit' => $affiliateProfit,
            'platform_profit' => 100,
        ]);

        $result = $this->service($this->fakeSupplierAdapter(true, ['supplier_ref' => 'REF-1', 'price' => 8.5]))
            ->fulfill($order);

        $this->assertSame($affiliateProfit, $result->affiliate_profit);
        $this->assertSame(1000 - 850 - $affiliateProfit, $result->platform_profit);
    }
}
