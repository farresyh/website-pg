<?php

namespace Tests\Support;

use App\Models\Game;
use App\Models\Order;
use App\Models\Package;
use App\Models\Supplier;
use App\Services\Accounting\SupplierFundingService;
use App\Services\Currency\CurrencyRateService;
use App\Services\Fulfillment\OrderFulfillmentService;
use App\Services\Fulfillment\OrderResendService;
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

/** Shared setup and helpers for the OrderResendServiceTest split (one file per feature). */
trait OrderResendFixtures
{
    /**
     * ADR-031: binds $adapter under 'gamevion' — every test in this
     * file creates its order/original/swap packages against the same
     * supplier() fixture (slug 'gamevion'), so this default matches
     * whichever package the order ends up routed to after a swap.
     */
    private function service(SupplierAdapter $adapter, string $supplierSlug = 'gamevion'): OrderResendService
    {
        $this->app->bind("supplier-adapter.{$supplierSlug}", fn () => $adapter);

        return new OrderResendService(
            new OrderFulfillmentService(
                new OrderStatusService,
                new ReferenceNumberService,
                $this->app->make(SupplierAdapterFactory::class),
                new LedgerService,
                new VoucherService(new LedgerService),
                new SupplierFundingService,
                new CurrencyRateService,
            ),
        );
    }

    private function fakeSupplierAdapter(bool $success, array $data = []): SupplierAdapter
    {
        return new class($success, $data) implements SupplierAdapter
        {
            public function __construct(private readonly bool $success, private readonly array $data) {}

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
                    : SupplierResponse::failure('500', 'Server error');
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
     * 2026-09-15 bugfix — an async supplier's own resend re-submit
     * (Digiflazz rc=03/Pending) is a third, distinct outcome from plain
     * success/failure.
     */
    private function pendingSupplierAdapter(array $data = []): SupplierAdapter
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

    private function fulfillmentService(): OrderFulfillmentService
    {
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
        return Supplier::query()->create(['name' => 'Gamevion', 'slug' => 'gamevion', 'api_config' => [], 'currency' => 'MYR']);
    }

    private function game(): Game
    {
        return Game::query()->create(['name' => 'Free Fire Global', 'slug' => 'free-fire-global']);
    }

    private function package(Game $game, Supplier $supplier, array $overrides = []): Package
    {
        return Package::query()->create(array_merge([
            'game_id' => $game->id,
            'name' => '100 Diamonds',
            'cost_price' => 900,
            'standard_selling_price' => 900,
            'markup_percent' => 0,
            'is_active' => true,
            'supplier_id' => $supplier->id,
            'supplier_package_ref' => 'A',
        ], $overrides));
    }

    private function failedOrder(Game $game, Package $package, Supplier $supplier, array $overrides = []): Order
    {
        return Order::query()->create(array_merge(['placed_via' => 'storefront',
            'affiliate_id' => $this->primaryAffiliate()->id,
            'order_number' => 'KRS-RESEND-1',
            'customer_email' => 'buyer@example.com',
            'player_id' => '123456',
            'game_id' => $game->id,
            'package_id' => $package->id,
            'supplier_id' => $supplier->id,
            'supplier_product_ref' => $package->supplier_package_ref,
            'cost_price' => 900,
            'standard_selling_price' => 900,
            'affiliate_markup_pct' => 0,
            'selling_price' => 1000,
            'transaction_fee' => 100,
            'final_amount' => 1100,
            'platform_profit' => 100,
            'affiliate_profit' => 0,
            'payment_status' => PaymentStatus::Paid->value,
            'delivery_status' => DeliveryStatus::Failed->value,
        ], $overrides));
    }

    /**
     * Decisions 9, 10, 13 — the invariant on every basis, for a resend
     * to the same, a cheaper and a dearer package: cost_price follows
     * the delivered package, affiliate_profit never moves, and
     * cost + affiliate + platform = selling_price.
     *
     * @return array<string, array{array<string, mixed>, int}>
     */
    public static function basisAndSwapCost(): array
    {
        $bases = [
            'standard' => ['pricing_basis' => 'standard', 'selling_price' => 1000, 'affiliate_profit' => 0],
            'lapsed affiliate' => ['pricing_basis' => 'affiliate', 'affiliate_markup_pct' => 5.00, 'selling_price' => 945, 'affiliate_profit' => 45],
            'tier affiliate' => ['pricing_basis' => 'affiliate', 'wholesale_markup_pct' => 10.00, 'affiliate_markup_pct' => 5.00, 'selling_price' => 1040, 'affiliate_profit' => 50],
            'reseller wallet' => ['pricing_basis' => 'reseller-wallet', 'wholesale_markup_pct' => 10.00, 'selling_price' => 990, 'affiliate_profit' => 0],
            'member' => ['pricing_basis' => 'member', 'member_discount_percent' => 80.00, 'markup_percent' => 15.00, 'selling_price' => 927, 'affiliate_profit' => 0],
        ];
        $cases = [];
        foreach ($bases as $name => $order) {
            foreach (['same' => 900, 'cheaper' => 850, 'dearer' => 920] as $swapName => $cost) {
                $cases["{$name}, {$swapName} package"] = [$order, $cost];
            }
        }

        return $cases;
    }
}
