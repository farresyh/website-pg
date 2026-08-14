<?php

namespace Tests\Feature\Services\Fulfillment;

use App\Models\Game;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\OrderResendAttempt;
use App\Models\Package;
use App\Models\PlayerValidation;
use App\Models\Supplier;
use App\Services\Fulfillment\OrderFulfillmentService;
use App\Services\Fulfillment\OrderResendService;
use App\Services\Ledger\LedgerService;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\OrderStatusService;
use App\Services\Order\PaymentStatus;
use App\Services\Order\ReferenceNumberService;
use App\Services\Pricing\PricingService;
use App\Services\Supplier\SupplierAdapter;
use App\Services\Supplier\SupplierOrderRequest;
use App\Services\Supplier\SupplierResponse;
use App\Services\Supplier\ValidationNotSupportedException;
use App\Services\Voucher\VoucherService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

/**
 * ADR-017: same-game package swap + live price reconciliation on top
 * of OrderFulfillmentService's own untouched fulfill() contract.
 */
class OrderResendServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(SupplierAdapter $adapter): OrderResendService
    {
        return new OrderResendService(
            new OrderFulfillmentService(
                new OrderStatusService(),
                new ReferenceNumberService(),
                $adapter,
                new LedgerService(),
                new VoucherService(),
            ),
            new PricingService(),
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

            public function checkStatus(string $supplierRef): SupplierResponse
            {
                throw new RuntimeException('not used in this test');
            }

            public function validatePlayer(string $playerId, ?string $serverId): SupplierResponse
            {
                throw new ValidationNotSupportedException('not used in this test');
            }
        };
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
            'reseller_cost_price' => 900,
            'markup_percent' => 0,
            'is_active' => true,
            'supplier_id' => $supplier->id,
            'supplier_package_ref' => 'A',
        ], $overrides));
    }

    private function failedOrder(Game $game, Package $package, Supplier $supplier, array $overrides = []): Order
    {
        return Order::query()->create(array_merge([
            'order_number' => 'KRS-RESEND-1',
            'customer_email' => 'buyer@example.com',
            'player_id' => '123456',
            'game_id' => $game->id,
            'package_id' => $package->id,
            'supplier_id' => $supplier->id,
            'supplier_product_ref' => $package->supplier_package_ref,
            'cost_price' => 900,
            'reseller_cost_price' => 900,
            'reseller_markup_pct' => 0,
            'selling_price' => 1000,
            'transaction_fee' => 100,
            'final_amount' => 1100,
            'platform_profit' => 100,
            'reseller_profit' => 0,
            'payment_status' => PaymentStatus::Paid->value,
            'delivery_status' => DeliveryStatus::Failed->value,
        ], $overrides));
    }

    public function test_rejects_a_package_from_a_different_game(): void
    {
        $supplier = $this->supplier();
        $game = $this->game();
        $otherGame = Game::query()->create(['name' => 'MLBB', 'slug' => 'mlbb']);
        $package = $this->package($game, $supplier);
        $otherGamePackage = $this->package($otherGame, $supplier, ['name' => '5 Diamonds', 'supplier_package_ref' => 'B']);
        $order = $this->failedOrder($game, $package, $supplier);

        $this->expectException(ValidationException::class);

        $this->service($this->fakeSupplierAdapter(true))->resend($order, $otherGamePackage, null, 'Admin');
    }

    public function test_rejects_an_inactive_package(): void
    {
        $supplier = $this->supplier();
        $game = $this->game();
        $package = $this->package($game, $supplier);
        $inactivePackage = $this->package($game, $supplier, ['name' => '210 Diamonds', 'supplier_package_ref' => 'C', 'is_active' => false]);
        $order = $this->failedOrder($game, $package, $supplier);

        $this->expectException(ValidationException::class);

        $this->service($this->fakeSupplierAdapter(true))->resend($order, $inactivePackage, null, 'Admin');
    }

    public function test_rejects_an_order_that_is_not_currently_failed(): void
    {
        $supplier = $this->supplier();
        $game = $this->game();
        $package = $this->package($game, $supplier);
        $order = $this->failedOrder($game, $package, $supplier, ['delivery_status' => DeliveryStatus::Delivered->value]);

        $this->expectException(ValidationException::class);

        $this->service($this->fakeSupplierAdapter(true))->resend($order, $package, null, 'Admin');
    }

    /**
     * ADR-026 decision 4b: this job-level re-check (assertResendable())
     * is separate from OrderController::resend()'s own pre-check — both
     * must independently allow needs_review, or the controller could
     * accept the request while the actual queued job silently rejects
     * it.
     */
    public function test_allows_resend_from_a_needs_review_order(): void
    {
        $supplier = $this->supplier();
        $game = $this->game();
        $package = $this->package($game, $supplier);
        $order = $this->failedOrder($game, $package, $supplier, ['delivery_status' => DeliveryStatus::NeedsReview->value]);

        $result = $this->service($this->fakeSupplierAdapter(true, ['supplier_ref' => 'GV-1']))
            ->resend($order, $package, null, 'Admin');

        $this->assertSame(DeliveryStatus::Delivered, $result->delivery_status);
    }

    /**
     * Decision #2: cost_price/reseller_cost_price/selling_price/
     * final_amount/transaction_fee are the historical charged record —
     * never rewritten by a resend, same-package or not.
     */
    public function test_never_touches_the_immutable_checkout_snapshot_fields(): void
    {
        $supplier = $this->supplier();
        $game = $this->game();
        $original = $this->package($game, $supplier);
        $swap = $this->package($game, $supplier, ['name' => '210 Diamonds', 'supplier_package_ref' => 'D', 'cost_price' => 1900, 'reseller_cost_price' => 1900]);
        $order = $this->failedOrder($game, $original, $supplier);

        $result = $this->service($this->fakeSupplierAdapter(true, ['supplier_ref' => 'GV-1']))
            ->resend($order, $swap, null, 'Admin');

        $this->assertSame(900, $result->cost_price);
        $this->assertSame(900, $result->reseller_cost_price);
        $this->assertSame(1000, $result->selling_price);
        $this->assertSame(1100, $result->final_amount);
        $this->assertSame(100, $result->transaction_fee);
    }

    /**
     * Decision #1: package_id/supplier_product_ref DO update — that's
     * the whole point of a package swap.
     */
    public function test_swaps_the_package_and_supplier_product_ref_on_success(): void
    {
        $supplier = $this->supplier();
        $game = $this->game();
        $original = $this->package($game, $supplier);
        $swap = $this->package($game, $supplier, ['name' => '210 Diamonds', 'supplier_package_ref' => 'D', 'cost_price' => 1900, 'reseller_cost_price' => 1900]);
        $order = $this->failedOrder($game, $original, $supplier);

        $result = $this->service($this->fakeSupplierAdapter(true, ['supplier_ref' => 'GV-1']))
            ->resend($order, $swap, null, 'Admin');

        $this->assertSame($swap->id, $result->package_id);
        $this->assertSame('D', $result->supplier_product_ref);
        $this->assertSame(DeliveryStatus::Delivered, $result->delivery_status);
    }

    /**
     * Decision #3: price_diff_sen = this attempt's live cost_price
     * minus the order's original snapshotted cost_price — recorded in
     * both directions, success or failure (decision #3's "always
     * absorb, always record").
     */
    public function test_records_a_resend_attempt_with_the_live_price_reconciliation(): void
    {
        $supplier = $this->supplier();
        $game = $this->game();
        $original = $this->package($game, $supplier);
        $swap = $this->package($game, $supplier, ['name' => '210 Diamonds', 'supplier_package_ref' => 'D', 'cost_price' => 1200, 'reseller_cost_price' => 1200]);
        $order = $this->failedOrder($game, $original, $supplier);

        $this->service($this->fakeSupplierAdapter(true, ['supplier_ref' => 'GV-1']))
            ->resend($order, $swap, 'Customer requested a bigger pack', 'Admin User');

        $attempt = OrderResendAttempt::query()->sole();
        $this->assertSame($order->id, $attempt->order_id);
        $this->assertSame($swap->id, $attempt->package_id);
        $this->assertSame(1200, $attempt->cost_price_sen);
        $this->assertSame(1200, $attempt->reseller_cost_price_sen);
        $this->assertSame(300, $attempt->price_diff_sen); // 1200 live - 900 original snapshot
        $this->assertSame('success', $attempt->outcome);
        $this->assertSame('Customer requested a bigger pack', $attempt->note);
        $this->assertSame('Admin User', $attempt->triggered_by);
    }

    public function test_records_a_failed_attempt_without_throwing(): void
    {
        $supplier = $this->supplier();
        $game = $this->game();
        $package = $this->package($game, $supplier);
        $order = $this->failedOrder($game, $package, $supplier);

        $result = $this->service($this->fakeSupplierAdapter(false))->resend($order, $package, null, 'Admin');

        $this->assertSame(DeliveryStatus::Failed, $result->delivery_status);
        $attempt = OrderResendAttempt::query()->sole();
        $this->assertSame('failed', $attempt->outcome);
    }

    /**
     * Decision #5: platform_profit/reseller_profit are recomputed from
     * this attempt's live package economics and only actually credited
     * to the ledger when this attempt is the one that succeeds —
     * mirrors OrderFulfillmentService::creditProfit()'s existing
     * "fires exactly once" guarantee, unchanged.
     */
    public function test_credits_the_ledger_with_the_recomputed_profit_for_the_swapped_package(): void
    {
        $supplier = $this->supplier();
        $game = $this->game();
        $original = $this->package($game, $supplier, ['cost_price' => 900, 'reseller_cost_price' => 900]);
        // Live cost is now higher than what the customer's snapshot assumed.
        $swap = $this->package($game, $supplier, ['name' => '210 Diamonds', 'supplier_package_ref' => 'D', 'cost_price' => 1300, 'reseller_cost_price' => 1300]);
        $order = $this->failedOrder($game, $original, $supplier, ['reseller_markup_pct' => 0]);

        $this->service($this->fakeSupplierAdapter(true, ['supplier_ref' => 'GV-1']))
            ->resend($order, $swap, null, 'Admin');

        // PricingService: platformProfit = resellerCostPrice - costPrice = 1300 - 1300 = 0 (markup 0%, reseller=platform owner).
        $this->assertSame(0, (int) LedgerEntry::query()->where('owner_type', 'platform')->sum('amount'));
        $this->assertSame(0, $order->fresh()->platform_profit);
    }

    public function test_does_not_credit_the_ledger_when_the_resend_fails(): void
    {
        $supplier = $this->supplier();
        $game = $this->game();
        $package = $this->package($game, $supplier);
        $order = $this->failedOrder($game, $package, $supplier);

        $this->service($this->fakeSupplierAdapter(false))->resend($order, $package, null, 'Admin');

        $this->assertSame(0, LedgerEntry::query()->count());
    }

    /**
     * Decision #6: reuses the same player_validations gate
     * CheckoutController enforces — a game with validation enabled but
     * no recent valid row blocks the resend entirely.
     */
    public function test_blocks_resend_when_game_requires_player_validation_and_none_is_on_file(): void
    {
        $supplier = $this->supplier();
        $game = $this->game();
        $game->update([
            'player_validator_enabled' => true,
            'player_validator_profile_id' => \App\Models\PlayerValidatorProfile::query()->create([
                'name' => 'MLBB Validator', 'key' => 'mlbb',
            ])->id,
        ]);
        $package = $this->package($game, $supplier);
        $order = $this->failedOrder($game, $package, $supplier);

        $this->expectException(ValidationException::class);

        $this->service($this->fakeSupplierAdapter(true))->resend($order, $package, null, 'Admin');
    }

    public function test_allows_resend_when_game_has_no_validator_profile_assigned(): void
    {
        $supplier = $this->supplier();
        $game = $this->game();
        $game->update(['player_validator_enabled' => true, 'player_validator_profile_id' => null]);
        $package = $this->package($game, $supplier);
        $order = $this->failedOrder($game, $package, $supplier);

        // Without a profile id, the gate never engages (matches
        // CheckoutController's own null-check) — resend proceeds.
        $result = $this->service($this->fakeSupplierAdapter(true, ['supplier_ref' => 'GV-1']))
            ->resend($order, $package, null, 'Admin');

        $this->assertSame(DeliveryStatus::Delivered, $result->delivery_status);
    }

    public function test_allows_resend_when_player_validation_is_on_file_and_recent(): void
    {
        $supplier = $this->supplier();
        $game = $this->game();
        $game->update([
            'player_validator_enabled' => true,
            'player_validator_profile_id' => \App\Models\PlayerValidatorProfile::query()->create([
                'name' => 'MLBB Validator', 'key' => 'mlbb',
            ])->id,
        ]);
        $package = $this->package($game, $supplier);
        $order = $this->failedOrder($game, $package, $supplier);
        PlayerValidation::query()->create([
            'game_id' => $game->id,
            'player_id' => $order->player_id,
            'server_id' => $order->server_id,
            'status' => 'valid',
            'validated_at' => now(),
        ]);

        $result = $this->service($this->fakeSupplierAdapter(true, ['supplier_ref' => 'GV-1']))
            ->resend($order, $package, null, 'Admin');

        $this->assertSame(DeliveryStatus::Delivered, $result->delivery_status);
    }
}
