<?php

namespace Tests\Feature\Services\Fulfillment;

use App\Models\Game;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\OrderResendAttempt;
use App\Models\Package;
use App\Models\PlayerValidation;
use App\Models\PlayerValidatorProfile;
use App\Models\Supplier;
use App\Models\Voucher;
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
use App\Services\Supplier\SupplierOutcome;
use App\Services\Supplier\SupplierResponse;
use App\Services\Supplier\SupplierStatusCheckRequest;
use App\Services\Supplier\ValidationNotSupportedException;
use App\Services\Voucher\VoucherService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * ADR-017: same-game package swap + live price reconciliation on top
 * of OrderFulfillmentService's own untouched fulfill() contract.
 */
class OrderResendServiceTest extends TestCase
{
    use RefreshDatabase;

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

    /**
     * ADR-094 decision 10: this package-swap tool assumes exactly one
     * supplier_product_ref to copy onto the Order — nonsensical for a
     * combo. Rejected on either side of the swap; the ordinary "Resend
     * Delivery" retry (no swap) stays the correct tool for a combo
     * order instead.
     */
    public function test_rejects_swapping_a_combo_order_to_a_different_package(): void
    {
        $supplier = $this->supplier();
        $game = $this->game();
        $comboOrderPackage = Package::query()->create([
            'game_id' => $game->id, 'name' => 'Combo', 'is_combo' => true, 'is_active' => true,
            'denomination' => 100, 'cost_price' => 900, 'standard_selling_price' => 900, 'markup_percent' => 0,
        ]);
        $targetPackage = $this->package($game, $supplier);
        $order = $this->failedOrder($game, $comboOrderPackage, $supplier, ['supplier_id' => null, 'supplier_product_ref' => null]);

        $this->expectException(ValidationException::class);

        $this->service($this->fakeSupplierAdapter(true))->resend($order, $targetPackage, null, 'Admin');
    }

    public function test_rejects_swapping_a_plain_order_to_a_combo_package(): void
    {
        $supplier = $this->supplier();
        $game = $this->game();
        $package = $this->package($game, $supplier);
        $comboTarget = Package::query()->create([
            'game_id' => $game->id, 'name' => 'Combo', 'is_combo' => true, 'is_active' => true,
            'denomination' => 100, 'cost_price' => 900, 'standard_selling_price' => 900, 'markup_percent' => 0,
        ]);
        $order = $this->failedOrder($game, $package, $supplier);

        $this->expectException(ValidationException::class);

        $this->service($this->fakeSupplierAdapter(true))->resend($order, $comboTarget, null, 'Admin');
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
     * ADR-102 decision 1: before this ADR, `assertResendable()` never
     * checked compensation at all — only `OrderController::resend()`
     * did, at click time. This proves the actual attempt-time re-check
     * closes the TOCTOU gap that let a voucher get issued for an order
     * between the click and the job running.
     */
    public function test_rejects_an_order_with_an_already_issued_voucher(): void
    {
        $supplier = $this->supplier();
        $game = $this->game();
        $package = $this->package($game, $supplier);
        $order = $this->failedOrder($game, $package, $supplier);
        Voucher::query()->create([
            'order_id' => $order->id,
            'affiliate_id' => $order->affiliate_id,
            'code' => 'KRS-RESEND-GUARD',
            'customer_email' => 'buyer@example.com',
            'amount' => 500,
            'remaining' => 500,
            'status' => 'active',
            'reason' => 'test',
        ]);

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
     * Decision #2: cost_price/standard_selling_price/selling_price/
     * final_amount/transaction_fee are the historical charged record —
     * never rewritten by a resend, same-package or not.
     */
    /**
     * ADR-017 decision 2, revised by ADR-105 2026-10-06 decision 13:
     * what the customer was charged never moves; cost_price follows the
     * package actually delivered.
     */
    public function test_never_touches_what_the_customer_was_charged_and_moves_cost_price_to_the_delivered_package(): void
    {
        $supplier = $this->supplier();
        $game = $this->game();
        $original = $this->package($game, $supplier);
        // ADR-105: kept under the order's own standard_selling_price
        // (900, the failedOrder() default) so this resend doesn't trip
        // decision 4's below-cost guard — this test isn't about that.
        $swap = $this->package($game, $supplier, ['name' => '210 Diamonds', 'supplier_package_ref' => 'D', 'cost_price' => 850, 'standard_selling_price' => 850]);
        $order = $this->failedOrder($game, $original, $supplier);

        $result = $this->service($this->fakeSupplierAdapter(true, ['supplier_ref' => 'GV-1']))
            ->resend($order, $swap, null, 'Admin');

        $this->assertSame(850, $result->cost_price);
        $this->assertSame(900, $result->standard_selling_price);
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
        // ADR-105: kept under the order's own standard_selling_price
        // (900) so this resend doesn't trip decision 4's below-cost
        // guard — this test isn't about that.
        $swap = $this->package($game, $supplier, ['name' => '210 Diamonds', 'supplier_package_ref' => 'D', 'cost_price' => 850, 'standard_selling_price' => 850]);
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
        $swap = $this->package($game, $supplier, ['name' => '210 Diamonds', 'supplier_package_ref' => 'D', 'cost_price' => 1200, 'standard_selling_price' => 1200]);
        // ADR-105: standard_selling_price (and selling_price, since the
        // negative-profit guard now reconciles against selling_price —
        // decision 8) raised above the swap's live cost (1200) so this
        // absorbed-cost-increase scenario doesn't also trip decision 4's
        // below-cost guard — this test is about the attempt row, not
        // that guard.
        $order = $this->failedOrder($game, $original, $supplier, ['standard_selling_price' => 1300, 'selling_price' => 1300]);

        $this->service($this->fakeSupplierAdapter(true, ['supplier_ref' => 'GV-1']))
            ->resend($order, $swap, 'Customer requested a bigger pack', 'Admin User');

        $attempt = OrderResendAttempt::query()->sole();
        $this->assertSame($order->id, $attempt->order_id);
        $this->assertSame($swap->id, $attempt->package_id);
        $this->assertSame(1200, $attempt->cost_price_sen);
        $this->assertSame(1200, $attempt->standard_selling_price_sen);
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
     * 2026-09-15 bugfix (Bug A, part 1): a genuinely still-Pending async
     * result (Digiflazz rc=03) is no longer coerced into a hard
     * 'failed' the moment the resend call returns.
     */
    public function test_records_a_pending_outcome_for_an_async_supplier_resend(): void
    {
        $supplier = $this->supplier();
        $game = $this->game();
        $package = $this->package($game, $supplier);
        $order = $this->failedOrder($game, $package, $supplier);

        $result = $this->service($this->pendingSupplierAdapter(['status' => 'Pending']))->resend($order, $package, null, 'Admin');

        $this->assertSame(DeliveryStatus::Pending, $result->delivery_status);
        $attempt = OrderResendAttempt::query()->sole();
        $this->assertSame('pending', $attempt->outcome);
    }

    /**
     * 2026-09-15 bugfix (Bug A, part 2): the historical attempt row
     * self-corrects the moment the real async outcome resolves — via
     * finalizePendingDelivery(), the one shared path a webhook, the
     * scheduled reconcile poll, and ADR-096's manual "Check from
     * Supplier" button all funnel through.
     */
    public function test_a_pending_resend_attempt_self_corrects_to_success_once_the_order_finalizes(): void
    {
        $supplier = $this->supplier();
        $game = $this->game();
        $package = $this->package($game, $supplier);
        $order = $this->failedOrder($game, $package, $supplier);

        $this->service($this->pendingSupplierAdapter(['status' => 'Pending']))->resend($order, $package, null, 'Admin');
        $attempt = OrderResendAttempt::query()->sole();
        $this->assertSame('pending', $attempt->outcome);

        $this->fulfillmentService()->finalizePendingDelivery(
            $order->fresh(),
            SupplierOutcome::Success,
            'GV-RESOLVED-LATER',
            ['status' => 'Sukses'],
        );

        $attempt->refresh();
        $this->assertSame('success', $attempt->outcome);
        $this->assertSame(['status' => 'Sukses'], $attempt->supplier_response);
    }

    /** Same self-correction, the Failure branch. */
    public function test_a_pending_resend_attempt_self_corrects_to_failed_once_the_order_finalizes(): void
    {
        $supplier = $this->supplier();
        $game = $this->game();
        $package = $this->package($game, $supplier);
        $order = $this->failedOrder($game, $package, $supplier);

        $this->service($this->pendingSupplierAdapter(['status' => 'Pending']))->resend($order, $package, null, 'Admin');
        $attempt = OrderResendAttempt::query()->sole();

        $this->fulfillmentService()->finalizePendingDelivery(
            $order->fresh(),
            SupplierOutcome::Failure,
            null,
            ['status' => 'Gagal'],
        );

        $attempt->refresh();
        $this->assertSame('failed', $attempt->outcome);
        $this->assertSame(['status' => 'Gagal'], $attempt->supplier_response);
    }

    /**
     * A resend of a supplier that never went Pending at all (the
     * ordinary Gamevion sync case) must never accidentally "steal" an
     * unrelated older order's pending attempt — the lookup is scoped by
     * order_id.
     */
    public function test_finalizing_a_different_orders_pending_delivery_does_not_touch_this_orders_attempt(): void
    {
        $supplier = $this->supplier();
        $game = $this->game();
        $package = $this->package($game, $supplier);
        $order = $this->failedOrder($game, $package, $supplier);

        $this->service($this->pendingSupplierAdapter(['status' => 'Pending']))->resend($order, $package, null, 'Admin');
        $attempt = OrderResendAttempt::query()->sole();

        $otherOrder = $this->failedOrder($game, $package, $supplier, ['order_number' => 'KRS-RESEND-OTHER', 'delivery_status' => DeliveryStatus::Pending->value]);

        $this->fulfillmentService()->finalizePendingDelivery($otherOrder, SupplierOutcome::Success, 'GV-OTHER', ['status' => 'Sukses']);

        $attempt->refresh();
        $this->assertSame('pending', $attempt->outcome);
    }

    /**
     * Decision #5: platform_profit/affiliate_profit are recomputed from
     * this attempt's live package economics and only actually credited
     * to the ledger when this attempt is the one that succeeds —
     * mirrors OrderFulfillmentService::creditProfit()'s existing
     * "fires exactly once" guarantee, unchanged.
     */
    public function test_credits_the_ledger_with_the_recomputed_profit_for_the_swapped_package(): void
    {
        $supplier = $this->supplier();
        $game = $this->game();
        $original = $this->package($game, $supplier, ['cost_price' => 900, 'standard_selling_price' => 1000]);
        // Live cost is now higher than what the original package cost —
        // still safely under the order's own frozen standard_selling_price
        // (1000), so the platform absorbs it out of the existing margin
        // rather than tripping decision 4's below-cost guard.
        $swap = $this->package($game, $supplier, ['name' => '210 Diamonds', 'supplier_package_ref' => 'D', 'cost_price' => 950, 'standard_selling_price' => 1045]);
        $order = $this->failedOrder($game, $original, $supplier, ['standard_selling_price' => 1000, 'affiliate_markup_pct' => 0]);

        $this->service($this->fakeSupplierAdapter(true, ['supplier_ref' => 'GV-1']))
            ->resend($order, $swap, null, 'Admin');

        // ADR-105 decision 1: platformProfit = order's OWN frozen
        // standard_selling_price (1000) - live cost (950) = 50 — not the
        // swap package's own live standard_selling_price (1045), which
        // would give a different, un-reconciled 95.
        $this->assertSame(50, (int) LedgerEntry::query()->where('owner_type', 'platform')->sum('amount'));
        $this->assertSame(50, $order->fresh()->platform_profit);
    }

    /**
     * ADR-105's own regression case — reproduces the real bug found live
     * on order PG-KWKBUHNDMKQT (order id 15, 2026-09-17): a resend swaps
     * to a package whose own `standard_selling_price` has ALSO
     * independently drifted (a routine price-sync, unrelated to this
     * resend) between the order's checkout and this resend attempt.
     * Pre-fix, profit was computed against that drifted live standard
     * price (392→402 in the real incident) and came out HIGHER despite
     * the platform absorbing more cost. Post-fix, it correctly reflects
     * the order's own frozen price minus the live cost.
     */
    public function test_platform_profit_reconciles_against_the_orders_own_frozen_price_not_the_swapped_packages_drifted_live_price(): void
    {
        $supplier = $this->supplier();
        $game = $this->game();
        // Mirrors the real order: cost 356, standard/selling 392 at
        // checkout (a ~10% markup).
        $original = $this->package($game, $supplier, ['cost_price' => 356, 'standard_selling_price' => 392]);
        // Mirrors the real swap target: live cost rose to 365 (the real
        // +9 sen absorbed) AND its own standard_selling_price
        // independently drifted 398 -> 402 that same morning, via an
        // unrelated price-sync — the exact drift that decoupled profit
        // from reality pre-fix.
        $swap = $this->package($game, $supplier, [
            'name' => 'PUBGG 60 UC', 'supplier_package_ref' => 'PUBGG_60_PG1',
            'cost_price' => 365, 'standard_selling_price' => 402,
        ]);
        $order = $this->failedOrder($game, $original, $supplier, [
            'cost_price' => 356,
            'standard_selling_price' => 392,
            'selling_price' => 392,
            'affiliate_markup_pct' => 0,
        ]);

        $this->service($this->fakeSupplierAdapter(true, ['supplier_ref' => 'MG-1']))
            ->resend($order, $swap, null, 'Admin');

        // Pre-fix (the real bug): 402 (swap's live standard) - 365 (live
        // cost) = 37 — profit rose despite absorbing +9 sen of cost.
        // Post-fix: 392 (order's own frozen standard) - 365 (live cost)
        // = 27 — a real 9-sen decrease, matching the absorbed amount 1:1.
        $this->assertSame(27, $order->fresh()->platform_profit);
        $this->assertSame(27, (int) LedgerEntry::query()->where('owner_type', 'platform')->sum('amount'));

        $attempt = OrderResendAttempt::query()->sole();
        $this->assertSame(9, $attempt->price_diff_sen);
    }

    /**
     * ADR-105 decision 4: a resend whose live cost now exceeds what the
     * customer already paid (frozen) is a genuine loss — blocked without
     * an explicit override reason, distinguishing an admin's deliberate
     * "deliver anyway, eat the loss" decision from silent data
     * corruption.
     */
    public function test_resend_that_would_sell_below_the_orders_frozen_price_requires_an_override_reason(): void
    {
        $supplier = $this->supplier();
        $game = $this->game();
        $original = $this->package($game, $supplier, ['cost_price' => 900, 'standard_selling_price' => 1000]);
        // Live cost (1100) now exceeds the order's frozen standard_selling_price (1000).
        $swap = $this->package($game, $supplier, ['name' => '210 Diamonds', 'supplier_package_ref' => 'D', 'cost_price' => 1100, 'standard_selling_price' => 1210]);
        $order = $this->failedOrder($game, $original, $supplier, ['standard_selling_price' => 1000]);

        $this->expectException(ValidationException::class);

        $this->service($this->fakeSupplierAdapter(true))->resend($order, $swap, null, 'Admin');
    }

    /** Same scenario as above, but with an override reason — proceeds and records the real (negative) loss. */
    public function test_resend_that_would_sell_below_the_orders_frozen_price_proceeds_with_an_override_reason(): void
    {
        $supplier = $this->supplier();
        $game = $this->game();
        $original = $this->package($game, $supplier, ['cost_price' => 900, 'standard_selling_price' => 1000]);
        $swap = $this->package($game, $supplier, ['name' => '210 Diamonds', 'supplier_package_ref' => 'D', 'cost_price' => 1100, 'standard_selling_price' => 1210]);
        $order = $this->failedOrder($game, $original, $supplier, ['standard_selling_price' => 1000, 'affiliate_markup_pct' => 0]);

        $result = $this->service($this->fakeSupplierAdapter(true, ['supplier_ref' => 'GV-1']))
            ->resend($order, $swap, null, 'Admin', overrideReason: 'Customer already paid, deliver anyway per founder instruction');

        // 1000 (frozen) - 1100 (live cost) = -100, a real loss, stored as-is.
        $this->assertSame(-100, $result->platform_profit);
        $this->assertSame(-100, (int) LedgerEntry::query()->where('owner_type', 'platform')->sum('amount'));
    }

    /**
     * ADR-106 addendum (2026-09-21), grill Q7 — `override_reason` used
     * to only ever reach `Log::warning()`, never the durable
     * `order_resend_attempts.note` column, even though it's the exact
     * reason an admin decided to accept a loss and force the resend.
     * Retrofitted for consistency with the new leg/retry attempt rows,
     * which persist it the same way. Only falls back to it when no
     * separate `note` was given — a real `note` always wins.
     */
    public function test_override_reason_falls_back_into_the_attempt_rows_note_when_no_note_given(): void
    {
        $supplier = $this->supplier();
        $game = $this->game();
        $original = $this->package($game, $supplier, ['cost_price' => 900, 'standard_selling_price' => 1000]);
        $swap = $this->package($game, $supplier, ['name' => '210 Diamonds', 'supplier_package_ref' => 'D', 'cost_price' => 1100, 'standard_selling_price' => 1210]);
        $order = $this->failedOrder($game, $original, $supplier, ['standard_selling_price' => 1000, 'affiliate_markup_pct' => 0]);

        $this->service($this->fakeSupplierAdapter(true, ['supplier_ref' => 'GV-1']))
            ->resend($order, $swap, null, 'Admin', overrideReason: 'Customer already paid, deliver anyway per founder instruction');

        $attempt = OrderResendAttempt::query()->where('order_id', $order->id)->sole();
        $this->assertSame('Customer already paid, deliver anyway per founder instruction', $attempt->note);
    }

    /** A real `note` is never clobbered by an override_reason given alongside it. */
    public function test_a_real_note_is_not_overridden_by_an_override_reason(): void
    {
        $supplier = $this->supplier();
        $game = $this->game();
        $original = $this->package($game, $supplier, ['cost_price' => 900, 'standard_selling_price' => 1000]);
        $swap = $this->package($game, $supplier, ['name' => '210 Diamonds', 'supplier_package_ref' => 'D', 'cost_price' => 1100, 'standard_selling_price' => 1210]);
        $order = $this->failedOrder($game, $original, $supplier, ['standard_selling_price' => 1000, 'affiliate_markup_pct' => 0]);

        $this->service($this->fakeSupplierAdapter(true, ['supplier_ref' => 'GV-1']))
            ->resend($order, $swap, 'Customer requested a bigger pack', 'Admin', overrideReason: 'Deliver anyway, absorb the loss');

        $attempt = OrderResendAttempt::query()->where('order_id', $order->id)->sole();
        $this->assertSame('Customer requested a bigger pack', $attempt->note);
    }

    /**
     * ADR-105 2026-10-06 addendum, decisions 9 + 11 (reverses decision 3):
     * a member order resent to a dearer package records the residual
     * against what the customer actually paid, not a re-priced member
     * price for the new package. Paid 1000, swap cost 1200 → −200 (the
     * old member formula recorded +36 here), so it needs an override.
     */
    public function test_member_resend_to_a_dearer_package_is_a_loss_that_needs_an_override_reason(): void
    {
        $supplier = $this->supplier();
        $game = $this->game();
        $original = $this->package($game, $supplier, ['cost_price' => 900, 'standard_selling_price' => 900, 'markup_percent' => 15]);
        $swap = $this->package($game, $supplier, [
            'name' => '210 Diamonds', 'supplier_package_ref' => 'D',
            'cost_price' => 1200, 'markup_percent' => 20, 'standard_selling_price' => 1440,
        ]);
        $order = $this->failedOrder($game, $original, $supplier, [
            'pricing_basis' => 'member',
            'member_discount_percent' => 80.00,
            'markup_percent' => 15.00,
            'affiliate_profit' => 0,
        ]);

        try {
            $this->service($this->fakeSupplierAdapter(true))->resend($order, $swap, null, 'Admin');
            $this->fail('expected a ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('override_reason', $e->errors());
        }

        $result = $this->service($this->fakeSupplierAdapter(true, ['supplier_ref' => 'GV-1']))
            ->resend($order->fresh(), $swap, null, 'Admin', overrideReason: 'Deliver anyway');

        $this->assertSame(-200, $result->platform_profit);
        $this->assertSame(0, $result->affiliate_profit);
    }

    /**
     * Decision 9: a member order's affiliate_profit stays what checkout
     * froze (0, ADR-027) even with a nonzero affiliate_markup_pct on the
     * row — resend never reads a markup.
     */
    public function test_member_resend_keeps_affiliate_profit_and_records_the_residual(): void
    {
        $supplier = $this->supplier();
        $game = $this->game();
        $original = $this->package($game, $supplier, ['cost_price' => 900, 'standard_selling_price' => 900, 'markup_percent' => 15]);
        $swap = $this->package($game, $supplier, [
            'name' => '210 Diamonds', 'supplier_package_ref' => 'D',
            'cost_price' => 950, 'markup_percent' => 20, 'standard_selling_price' => 1140,
        ]);
        $order = $this->failedOrder($game, $original, $supplier, [
            'pricing_basis' => 'member',
            'member_discount_percent' => 80.00,
            'markup_percent' => 15.00,
            'affiliate_markup_pct' => 10.00,
            'affiliate_profit' => 0,
        ]);

        $this->service($this->fakeSupplierAdapter(true, ['supplier_ref' => 'GV-1']))
            ->resend($order, $swap, null, 'Admin');

        $this->assertSame(50, $order->fresh()->platform_profit); // 1000 − 950 − 0
        $this->assertSame(0, $order->fresh()->affiliate_profit);
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

    /**
     * @param  array<string, mixed>  $orderOverrides
     */
    #[DataProvider('basisAndSwapCost')]
    public function test_every_basis_keeps_cost_plus_shares_equal_to_what_the_customer_paid(array $orderOverrides, int $swapCost): void
    {
        $supplier = $this->supplier();
        $game = $this->game();
        $original = $this->package($game, $supplier, ['cost_price' => 900]);
        $target = $swapCost === 900
            ? $original
            : $this->package($game, $supplier, ['name' => 'Swap', 'supplier_package_ref' => 'S', 'cost_price' => $swapCost, 'standard_selling_price' => 2000, 'markup_percent' => 50]);
        $order = $this->failedOrder($game, $original, $supplier, $orderOverrides);
        $affiliateBefore = $order->affiliate_profit;

        $result = $this->service($this->fakeSupplierAdapter(true, ['supplier_ref' => 'GV-1']))
            ->resend($order, $target, null, 'Admin', overrideReason: 'test');

        $this->assertSame($swapCost, $result->cost_price);
        $this->assertSame($affiliateBefore, $result->affiliate_profit);
        $this->assertSame($result->selling_price, $result->cost_price + $result->affiliate_profit + $result->platform_profit);
        $this->assertSame(
            $result->platform_profit,
            (int) LedgerEntry::query()->where('owner_type', 'platform')->sum('amount'),
        );
    }

    /**
     * ADR-060 PR-4b: a reseller-wallet order's resend recomputes
     * affiliateProfit (always 0 here) at the order's own frozen
     * `wholesale_markup_pct` (the tier rate), not the standard retail
     * chain — the latent bug PR-4b fixed. ADR-105 decision 8: unlike
     * PR-4b's own original assertion, platformProfit itself is now the
     * money-conservation residual (order's frozen selling_price - live
     * cost - affiliateProfit) rather than wholesaleBase - liveCost
     * directly — `selling_price` is set here to what the order's real
     * original checkout would have produced (900 cost * 1.20 tier =
     * 1080, matching wholesaleBase at THAT cost), so this resend's
     * absorbed-cost story is realistic, not an arbitrary leftover
     * default.
     */
    public function test_resend_of_a_reseller_wallet_order_recomputes_at_the_frozen_tier_rate(): void
    {
        $supplier = $this->supplier();
        $game = $this->game();
        $original = $this->package($game, $supplier, ['cost_price' => 900, 'standard_selling_price' => 900]);
        $swap = $this->package($game, $supplier, [
            'name' => '210 Diamonds', 'supplier_package_ref' => 'D',
            'cost_price' => 1000, 'standard_selling_price' => 1500,
        ]);
        $order = $this->failedOrder($game, $original, $supplier, [
            'pricing_basis' => 'reseller-wallet',
            'wholesale_markup_pct' => 20.00,
            'affiliate_markup_pct' => 0,
            'selling_price' => 1080,
        ]);

        $this->service($this->fakeSupplierAdapter(true, ['supplier_ref' => 'GV-1']))
            ->resend($order, $swap, null, 'Admin');

        // affiliateProfit = wholesaleBase(1000*1.20=1200)*0% = 0 — the
        // frozen tier rate, NOT the standard chain (1500-1000=500) the
        // pre-PR-4b `else` branch produced. platformProfit = 1080
        // (frozen) - 1000 (live cost) - 0 = 80.
        $this->assertSame(80, $order->fresh()->platform_profit);
        $this->assertSame(0, $order->fresh()->affiliate_profit);
    }

    /**
     * ADR-060 PR-4b: an affiliate-basis order (PR-4c wires these) resends
     * at frozen `wholesale_markup_pct` + frozen `affiliate_markup_pct`
     * for affiliateProfit. ADR-105 decision 8: `selling_price` set to
     * what original checkout (cost 900) would have actually produced —
     * 900*1.20=1080 wholesale, +10% affiliate markup = 1188 — so
     * platformProfit's residual formula has a realistic frozen total to
     * reconcile against.
     */
    public function test_resend_of_an_affiliate_basis_order_recomputes_at_frozen_wholesale_and_affiliate_markup(): void
    {
        $supplier = $this->supplier();
        $game = $this->game();
        $original = $this->package($game, $supplier, ['cost_price' => 900, 'standard_selling_price' => 900]);
        $swap = $this->package($game, $supplier, [
            'name' => '210 Diamonds', 'supplier_package_ref' => 'D',
            'cost_price' => 1000, 'standard_selling_price' => 1500,
        ]);
        $order = $this->failedOrder($game, $original, $supplier, [
            'pricing_basis' => 'affiliate',
            'wholesale_markup_pct' => 20.00,
            'affiliate_markup_pct' => 10.00,
            'selling_price' => 1188,
            'affiliate_profit' => 108,
        ]);

        $this->service($this->fakeSupplierAdapter(true, ['supplier_ref' => 'GV-1']))
            ->resend($order, $swap, null, 'Admin');

        // ADR-105 2026-10-06 decision 10: affiliateProfit stays the
        // checkout value (1080 × 10% = 108), it no longer scales with the
        // swap's cost. platformProfit = 1188 − 1000 − 108 = 80.
        $this->assertSame(80, $order->fresh()->platform_profit);
        $this->assertSame(108, $order->fresh()->affiliate_profit);
    }

    /**
     * ADR-105 decision 8 (addendum) — the phantom-profit scenario found
     * while explaining decision 2 to the founder: pre-addendum, a
     * tier-affiliate resend's platformProfit was computed independently
     * of what was actually collected (wholesaleBase - liveCost), so a
     * big enough live-cost jump could make platformProfit +
     * affiliateProfit together exceed the order's own frozen
     * selling_price — crediting both platform and affiliate more than
     * the order ever actually collected, with nothing catching it
     * (wholesaleBase >= liveCost always holds for a non-negative tier%,
     * so the old code's only guard never tripped).
     */
    public function test_platform_profit_never_exceeds_what_the_order_actually_collected_on_a_tier_affiliate_resend(): void
    {
        $supplier = $this->supplier();
        $game = $this->game();
        // Mirrors a real tier-affiliate order: cost 356, tier 20%,
        // affiliate markup 5% -> wholesaleBase 427, affiliateProfit 21,
        // frozen selling_price (what the affiliate's own customer paid) 448.
        $original = $this->package($game, $supplier, ['cost_price' => 356, 'standard_selling_price' => 900]);
        // Live cost rises to 400 on resend.
        $swap = $this->package($game, $supplier, [
            'name' => 'PUBGG 60 UC', 'supplier_package_ref' => 'PUBGG_60_PG1',
            'cost_price' => 400, 'standard_selling_price' => 900,
        ]);
        $order = $this->failedOrder($game, $original, $supplier, [
            'pricing_basis' => 'affiliate',
            'wholesale_markup_pct' => 20.00,
            'affiliate_markup_pct' => 5.00,
            'selling_price' => 448,
            'affiliate_profit' => 21,
        ]);

        $this->service($this->fakeSupplierAdapter(true, ['supplier_ref' => 'MG-1']))
            ->resend($order, $swap, null, 'Admin');

        // Decision 10: affiliateProfit stays the checkout 21.
        // platformProfit = 448 − 400 − 21 = 27 — NOT 80 (480 − 400), which
        // would have made 400 + 24 + 80 = 504 exceed the 448 collected.
        $order->refresh();
        $this->assertSame(21, $order->affiliate_profit);
        $this->assertSame(27, $order->platform_profit);
        // The conservation identity itself: 400 + 21 + 27 = 448.
        $this->assertSame(448, 400 + $order->platform_profit + $order->affiliate_profit);
    }

    /**
     * ADR-105 decision 8 (addendum): the negative-profit override gate
     * (decision 4) extends to the tier-affiliate/ResellerWallet basis
     * too, now that platformProfit is a residual against the order's
     * own frozen total rather than an always-non-negative
     * wholesaleBase-liveCost figure.
     */
    public function test_tier_affiliate_resend_that_would_result_in_a_platform_loss_requires_an_override_reason(): void
    {
        $supplier = $this->supplier();
        $game = $this->game();
        $original = $this->package($game, $supplier, ['cost_price' => 356, 'standard_selling_price' => 900]);
        // Live cost rises far enough that, after the affiliate's frozen
        // 21, nothing is left for the platform: 448 − 430 − 21 = −3.
        $swap = $this->package($game, $supplier, ['name' => 'PUBGG 60 UC', 'supplier_package_ref' => 'PUBGG_60_PG1', 'cost_price' => 430, 'standard_selling_price' => 900]);
        $order = $this->failedOrder($game, $original, $supplier, [
            'pricing_basis' => 'affiliate',
            'wholesale_markup_pct' => 20.00,
            'affiliate_markup_pct' => 5.00,
            'selling_price' => 448,
            'affiliate_profit' => 21,
        ]);

        $this->expectException(ValidationException::class);

        $this->service($this->fakeSupplierAdapter(true))->resend($order, $swap, null, 'Admin');
    }

    /** Same scenario, with an override reason — proceeds, affiliate's cut untouched, platform absorbs the negative. */
    public function test_tier_affiliate_resend_that_would_result_in_a_platform_loss_proceeds_with_an_override_reason(): void
    {
        $supplier = $this->supplier();
        $game = $this->game();
        $original = $this->package($game, $supplier, ['cost_price' => 356, 'standard_selling_price' => 900]);
        $swap = $this->package($game, $supplier, ['name' => 'PUBGG 60 UC', 'supplier_package_ref' => 'PUBGG_60_PG1', 'cost_price' => 430, 'standard_selling_price' => 900]);
        $order = $this->failedOrder($game, $original, $supplier, [
            'pricing_basis' => 'affiliate',
            'wholesale_markup_pct' => 20.00,
            'affiliate_markup_pct' => 5.00,
            'selling_price' => 448,
            'affiliate_profit' => 21,
        ]);

        $result = $this->service($this->fakeSupplierAdapter(true, ['supplier_ref' => 'MG-1']))
            ->resend($order, $swap, null, 'Admin', overrideReason: 'Founder-approved: deliver anyway, absorb the loss');

        $this->assertSame(21, $result->affiliate_profit);
        $this->assertSame(-3, $result->platform_profit);
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
            'player_validator_profile_id' => PlayerValidatorProfile::query()->create([
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
            'player_validator_profile_id' => PlayerValidatorProfile::query()->create([
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

    /**
     * ADR-102 decision 10 — the optional Player ID/Server ID correction
     * closes Context point 7: today, a wrong-ID failure can only be
     * resolved via Issue Voucher + a brand new customer-placed order.
     * The corrected value is what actually gets persisted and sent to
     * the supplier.
     */
    public function test_applies_a_player_id_correction_before_resending(): void
    {
        $supplier = $this->supplier();
        $game = $this->game();
        $game->update(['validation_rules' => ['extra_field' => 'server_id']]);
        $package = $this->package($game, $supplier);
        $order = $this->failedOrder($game, $package, $supplier, ['player_id' => '11111']);

        $result = $this->service($this->fakeSupplierAdapter(true, ['supplier_ref' => 'GV-1']))
            ->resend($order, $package, null, 'Admin', playerId: '22222', serverId: '2002');

        $this->assertSame('22222', $result->player_id);
        $this->assertSame('2002', $result->server_id);
        $this->assertSame(DeliveryStatus::Delivered, $result->delivery_status);
    }

    /**
     * The player-ID validation gate (decision #6, unchanged) must
     * re-check against the CORRECTED id, not the order's original one
     * — a validation row that only covers the typo'd original id must
     * not let a resend through for a still-unvalidated corrected id.
     */
    public function test_blocks_resend_when_validation_is_only_on_file_for_the_original_not_the_corrected_player_id(): void
    {
        $supplier = $this->supplier();
        $game = $this->game();
        $game->update([
            'player_validator_enabled' => true,
            'player_validator_profile_id' => PlayerValidatorProfile::query()->create([
                'name' => 'MLBB Validator', 'key' => 'mlbb',
            ])->id,
        ]);
        $package = $this->package($game, $supplier);
        $order = $this->failedOrder($game, $package, $supplier, ['player_id' => 'typo-id']);
        PlayerValidation::query()->create([
            'game_id' => $game->id,
            'player_id' => 'typo-id',
            'server_id' => $order->server_id,
            'status' => 'valid',
            'validated_at' => now(),
        ]);

        $this->expectException(ValidationException::class);

        $this->service($this->fakeSupplierAdapter(true))
            ->resend($order, $package, null, 'Admin', playerId: 'corrected-id');
    }

    /** An empty-string player_id is "no correction given", not a literal blank value to submit — the original is kept. */
    public function test_an_empty_player_id_correction_is_ignored(): void
    {
        $supplier = $this->supplier();
        $game = $this->game();
        $package = $this->package($game, $supplier);
        $order = $this->failedOrder($game, $package, $supplier, ['player_id' => 'original-id']);

        $result = $this->service($this->fakeSupplierAdapter(true, ['supplier_ref' => 'GV-1']))
            ->resend($order, $package, null, 'Admin', playerId: '');

        $this->assertSame('original-id', $result->player_id);
    }

    /** An empty-string server_id, unlike player_id, IS a real correction — clears it (some games have none). */
    public function test_an_empty_server_id_correction_clears_it(): void
    {
        $supplier = $this->supplier();
        $game = $this->game();
        $package = $this->package($game, $supplier);
        $order = $this->failedOrder($game, $package, $supplier, ['server_id' => 'wrong-server']);

        $result = $this->service($this->fakeSupplierAdapter(true, ['supplier_ref' => 'GV-1']))
            ->resend($order, $package, null, 'Admin', serverId: '');

        $this->assertNull($result->server_id);
    }

    /**
     * ADR-111 decision 5: resend() needs no code change of its own —
     * it sets affiliate_profit correctly (per its own per-basis formula)
     * BEFORE calling fulfill(), and fulfill()'s own Success-branch
     * correction (decision 3) then unconditionally re-derives
     * platform_profit from the now-known REAL cost, using the
     * affiliate_profit resend() already set. The real cost here (800
     * sen) differs from both the swap package's live catalog cost_price
     * (950) and resend()'s own pre-fulfill estimate (50) — the final
     * stored figure must reflect the real cost, not either estimate.
     */
    public function test_fulfill_overwrites_resends_own_estimate_with_the_real_cost_when_flag_enabled(): void
    {
        config(['services.real_cost_reconciliation.enabled' => true]);
        $supplier = $this->supplier();
        $game = $this->game();
        $original = $this->package($game, $supplier, ['cost_price' => 900, 'standard_selling_price' => 1000]);
        $swap = $this->package($game, $supplier, ['name' => '210 Diamonds', 'supplier_package_ref' => 'D', 'cost_price' => 950, 'standard_selling_price' => 1045]);
        $order = $this->failedOrder($game, $original, $supplier, ['standard_selling_price' => 1000, 'affiliate_markup_pct' => 0]);

        $this->service($this->fakeSupplierAdapter(true, ['supplier_ref' => 'GV-1', 'price' => 8.0]))
            ->resend($order, $swap, null, 'Admin');

        // 1000 (frozen selling_price) - 800 (real cost, not the swap
        // package's live 950 catalog cost) - 0 (affiliate_profit,
        // resend()'s own, untouched) = 200 — not resend()'s own
        // pre-fulfill estimate of 50 (1000 - 950 - 0).
        $fresh = $order->fresh();
        $this->assertSame(800, $fresh->real_cost_price_sen);
        $this->assertSame(0, $fresh->affiliate_profit);
        $this->assertSame(200, $fresh->platform_profit);
        $this->assertSame(200, (int) LedgerEntry::query()->where('owner_type', 'platform')->sum('amount'));
    }

    /** ADR-097 2026-10-05 addendum, decision 31 — a corrected ID meets the same per-game contract. */
    public function test_rejects_a_malformed_player_id_correction(): void
    {
        $supplier = $this->supplier();
        $game = $this->game();
        $package = $this->package($game, $supplier);
        $order = $this->failedOrder($game, $package, $supplier, ['player_id' => '11111']);

        try {
            $this->service($this->fakeSupplierAdapter(true))->resend($order, $package, null, 'Admin', playerId: '22222 (2001)');
            $this->fail('expected a ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('player_id', $e->errors());
        }

        $this->assertSame('11111', $order->fresh()->player_id);
    }

    /** ADR-105 2026-10-06 decision 12 — preflight() reports the impact and writes nothing. */
    public function test_preflight_reports_the_impact_without_writing_anything(): void
    {
        $supplier = $this->supplier();
        $game = $this->game();
        $original = $this->package($game, $supplier);
        $swap = $this->package($game, $supplier, ['name' => '210 Diamonds', 'supplier_package_ref' => 'D', 'cost_price' => 1100]);
        $order = $this->failedOrder($game, $original, $supplier);

        $impact = $this->service($this->fakeSupplierAdapter(true))->preflight($order, $swap);

        $this->assertNull($impact->blockedReason);
        $this->assertSame(0, $impact->affiliateProfitSen);
        $this->assertSame(-100, $impact->platformProfitSen); // 1000 − 1100 − 0
        $this->assertSame(200, $impact->costDiffSen);
        $this->assertTrue($impact->overrideRequired());
        $this->assertSame(900, $order->fresh()->cost_price);
        $this->assertSame($original->id, $order->fresh()->package_id);
        $this->assertSame(0, OrderResendAttempt::query()->count());
    }

    /** Decision 12 — a hard guard becomes a blocked reason, not an exception, so the preview can show it. */
    public function test_preflight_reports_a_blocked_reason_for_a_package_from_another_game(): void
    {
        $supplier = $this->supplier();
        $game = $this->game();
        $otherGame = Game::query()->create(['name' => 'MLBB', 'slug' => 'mlbb']);
        $package = $this->package($game, $supplier);
        $foreign = $this->package($otherGame, $supplier, ['supplier_package_ref' => 'X']);
        $order = $this->failedOrder($game, $package, $supplier);

        $impact = $this->service($this->fakeSupplierAdapter(true))->preflight($order, $foreign);

        $this->assertSame('package_id', $impact->blockedField);
        $this->assertSame('The selected package must belong to the same game as this order.', $impact->blockedReason);
    }

    /** Decision 16 — a refused resend leaves an auditable row with its NOT NULL cost columns filled. */
    public function test_record_rejection_writes_a_rejected_attempt_row(): void
    {
        $supplier = $this->supplier();
        $game = $this->game();
        $original = $this->package($game, $supplier);
        $swap = $this->package($game, $supplier, ['name' => '210 Diamonds', 'supplier_package_ref' => 'D', 'cost_price' => 1100, 'standard_selling_price' => 1300]);
        $order = $this->failedOrder($game, $original, $supplier);

        $this->service($this->fakeSupplierAdapter(true))->recordRejection($order, $swap, 'Live cost now exceeds what was collected.', 'Admin');

        $attempt = OrderResendAttempt::query()->sole();
        $this->assertSame('rejected', $attempt->outcome);
        $this->assertSame('resend', $attempt->attempt_type);
        $this->assertSame($swap->id, $attempt->package_id);
        $this->assertSame(1100, $attempt->cost_price_sen);
        $this->assertSame(1300, $attempt->standard_selling_price_sen);
        $this->assertSame(200, $attempt->price_diff_sen);
        $this->assertSame('Live cost now exceeds what was collected.', $attempt->note);
        $this->assertSame('Admin', $attempt->triggered_by);
    }

    /** Decision 13 — price_diff_sen is relative to the cost assigned before this attempt. */
    public function test_a_second_resend_diffs_against_the_previous_assignment(): void
    {
        $supplier = $this->supplier();
        $game = $this->game();
        $original = $this->package($game, $supplier);
        $first = $this->package($game, $supplier, ['name' => 'First', 'supplier_package_ref' => 'F', 'cost_price' => 920]);
        $second = $this->package($game, $supplier, ['name' => 'Second', 'supplier_package_ref' => 'G', 'cost_price' => 950]);
        $order = $this->failedOrder($game, $original, $supplier);

        $this->service($this->fakeSupplierAdapter(false))->resend($order, $first, null, 'Admin');
        $this->service($this->fakeSupplierAdapter(true, ['supplier_ref' => 'GV-2']))->resend($order->fresh(), $second, null, 'Admin');

        $diffs = OrderResendAttempt::query()->orderBy('id')->pluck('price_diff_sen')->all();
        $this->assertSame([20, 30], $diffs);
        $this->assertSame(950, $order->fresh()->cost_price);
    }

    /**
     * ADR-105 2026-10-06 decision 14, build-time correction — the window
     * the lock cannot close: B's locked write lands after A commits but
     * before A's fulfill() phase 1. A then delivers B's package; A's row
     * must name what was actually sent, and B's later attempt is refused
     * with a `rejected` row. Exactly one delivery, ledger = order row.
     */
    public function test_a_write_landing_between_as_commit_and_as_fulfill_is_recorded_as_what_was_sent(): void
    {
        $supplier = $this->supplier();
        $game = $this->game();
        $original = $this->package($game, $supplier);
        $packageA = $this->package($game, $supplier, ['name' => 'A', 'supplier_package_ref' => 'PA', 'cost_price' => 920]);
        $packageB = $this->package($game, $supplier, ['name' => 'B', 'supplier_package_ref' => 'PB', 'cost_price' => 950, 'standard_selling_price' => 990]);
        $order = $this->failedOrder($game, $original, $supplier);
        $service = $this->service($this->fakeSupplierAdapter(true, ['supplier_ref' => 'GV-1']));

        // B's locked write, landing in the window right after A commits.
        $fired = false;
        Order::updated(function (Order $updated) use (&$fired, $service, $packageB) {
            if ($fired || $updated->package_id === $packageB->id) {
                return;
            }
            $fired = true;
            DB::afterCommit(function () use ($service, $updated, $packageB) {
                $impact = $service->preflight($updated->fresh(), $packageB);
                DB::table('orders')->where('id', $updated->id)->update([
                    'package_id' => $packageB->id,
                    'supplier_product_ref' => $packageB->supplier_package_ref,
                    'cost_price' => $impact->costPriceSen,
                    'platform_profit' => $impact->platformProfitSen,
                ]);
            });
        });

        $result = $service->resend($order, $packageA, null, 'Admin A');

        $this->assertSame($packageB->id, $result->package_id);
        $this->assertSame('PB', $result->supplier_product_ref);
        $this->assertSame(DeliveryStatus::Delivered, $result->delivery_status);
        $rowA = OrderResendAttempt::query()->sole();
        $this->assertSame($packageB->id, $rowA->package_id);
        $this->assertSame(950, $rowA->cost_price_sen);
        $this->assertSame(990, $rowA->standard_selling_price_sen);
        $this->assertSame(50, $result->platform_profit); // 1000 − 950 − 0
        $this->assertSame(50, (int) LedgerEntry::query()->where('owner_type', 'platform')->sum('amount'));

        // B's own job: fulfill() would throw on the Delivered order; its
        // retry re-runs preflight under the lock and is refused.
        try {
            $service->resend($order->fresh(), $packageB, null, 'Admin B');
            $this->fail('expected a ValidationException');
        } catch (ValidationException $e) {
            $service->recordRejection($order->fresh(), $packageB, $e->getMessage(), 'Admin B');
        }

        $this->assertSame(['success', 'rejected'], OrderResendAttempt::query()->orderBy('id')->pluck('outcome')->all());
        $this->assertSame(50, (int) LedgerEntry::query()->where('owner_type', 'platform')->sum('amount'));
    }
}
