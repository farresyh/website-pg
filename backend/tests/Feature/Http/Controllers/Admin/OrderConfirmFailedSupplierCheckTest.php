<?php

namespace Tests\Feature\Http\Controllers\Admin;

use App\Jobs\ResendOrderDeliveryJob;
use App\Models\AdminUser;
use App\Models\Game;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\OrderDeliveryLeg;
use App\Models\Package;
use App\Models\Supplier;
use App\Services\Fulfillment\OrderFulfillmentService;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\PaymentStatus;
use App\Services\Supplier\SupplierAdapter;
use App\Services\Supplier\SupplierOrderRequest;
use App\Services\Supplier\SupplierResponse;
use App\Services\Supplier\SupplierStatusCheckRequest;
use App\Services\Supplier\ValidationNotSupportedException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

/**
 * ADR-102 2026-10-05 addendum — Confirm Failed on a replay-safe supplier
 * (Digiflazz) asks the supplier first and applies its answer, with no
 * override (decisions 4-5); Check supplier also works on NeedsReview
 * (decision 6); no package swap from NeedsReview (decision 8).
 */
class OrderConfirmFailedSupplierCheckTest extends TestCase
{
    use RefreshDatabase;

    /** @var \ArrayObject<int, SupplierStatusCheckRequest> */
    private \ArrayObject $checks;

    protected function setUp(): void
    {
        parent::setUp();
        $this->checks = new \ArrayObject;
        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'admin', 'name' => 'Founder']));
    }

    /** @param  SupplierResponse|RuntimeException|array<string, SupplierResponse>  $answer  per supplier ref, or one for all */
    private function digiflazzAnswers(SupplierResponse|RuntimeException|array $answer): void
    {
        $this->app->bind('supplier-adapter.digiflazz', fn () => new class($answer, $this->checks) implements SupplierAdapter
        {
            public function __construct(private readonly mixed $answer, private readonly \ArrayObject $checks) {}

            public function checkBalance(): SupplierResponse
            {
                throw new RuntimeException('not used');
            }

            public function listProducts(): SupplierResponse
            {
                throw new RuntimeException('not used');
            }

            public function createOrder(SupplierOrderRequest $request): SupplierResponse
            {
                throw new RuntimeException('not used');
            }

            public function checkStatus(SupplierStatusCheckRequest $request): SupplierResponse
            {
                $this->checks[] = $request;
                $answer = is_array($this->answer) ? $this->answer[$request->supplierRef] : $this->answer;

                if ($answer instanceof RuntimeException) {
                    throw $answer;
                }

                return $answer;
            }

            public function validatePlayer(string $playerId, ?string $serverId): SupplierResponse
            {
                throw new ValidationNotSupportedException('not used');
            }
        });
    }

    private function digiflazz(): Supplier
    {
        return Supplier::query()->firstOrCreate(['slug' => 'digiflazz'], ['name' => 'Digiflazz', 'currency' => 'IDR', 'api_config' => []]);
    }

    private function needsReviewOrder(array $overrides = []): Order
    {
        return Order::query()->create(array_merge(['placed_via' => 'storefront',
            'affiliate_id' => $this->primaryAffiliate()->id,
            'order_number' => 'KRS-'.uniqid(),
            'reference_number' => 'REF-'.uniqid(),
            'customer_email' => 'buyer@example.com',
            'player_id' => '123456',
            'supplier_id' => $this->digiflazz()->id,
            'supplier_product_ref' => 'mlbb5',
            'cost_price' => 900,
            'standard_selling_price' => 900,
            'selling_price' => 1000,
            'transaction_fee' => 100,
            'final_amount' => 1100,
            'platform_profit' => 100,
            'affiliate_profit' => 0,
            'payment_status' => PaymentStatus::Paid->value,
            'delivery_status' => DeliveryStatus::NeedsReview->value,
        ], $overrides));
    }

    private function gagal(): SupplierResponse
    {
        return SupplierResponse::failure('02', 'Transaksi Gagal', resendUnsafeWithSameReference: true, outcomeConfirmedFailed: true);
    }

    private function confirm(Order $order): TestResponse
    {
        return $this->postJson("/api/orders/{$order->id}/confirm-failed", ['note' => 'Customer says nothing arrived']);
    }

    public function test_confirm_failed_delivers_the_order_when_the_supplier_says_sukses(): void
    {
        $this->digiflazzAnswers(SupplierResponse::success(['supplier_ref' => 'SN-LATE', 'status' => 'Sukses', 'price' => 4500]));
        $order = $this->needsReviewOrder();

        $this->confirm($order)->assertOk()->assertJsonPath('delivery_status', 'delivered');

        $order->refresh();
        $this->assertSame('SN-LATE', $order->supplier_ref);
        $this->assertSame('Founder', $order->supplier_response['confirmed_failed_by']);
        $this->assertSame(1, LedgerEntry::query()->where('reference_id', $order->id)->where('type', 'order_profit')->where('owner_type', 'platform')->count());
    }

    public function test_confirm_failed_fails_the_order_when_the_supplier_confirms_gagal(): void
    {
        $this->digiflazzAnswers($this->gagal());
        $order = $this->needsReviewOrder();

        $this->confirm($order)->assertOk()->assertJsonPath('delivery_status', 'failed');

        $order->refresh();
        $this->assertSame('02', $order->supplier_response['error_code']);
        $this->assertSame('Customer says nothing arrived', $order->supplier_response['note']);
        $this->assertCount(1, $this->checks);
    }

    public function test_confirm_failed_is_refused_while_the_supplier_still_says_pending(): void
    {
        $this->digiflazzAnswers(SupplierResponse::pending(['status' => 'Pending']));
        $order = $this->needsReviewOrder();

        $this->confirm($order)->assertUnprocessable()->assertJsonValidationErrors('delivery_status');

        $this->assertSame(DeliveryStatus::NeedsReview, $order->fresh()->delivery_status);
    }

    public function test_confirm_failed_is_refused_on_an_ambiguous_supplier_failure(): void
    {
        $this->digiflazzAnswers(SupplierResponse::failure('CIRCUIT_OPEN', 'breaker open', isServerError: true, resendUnsafeWithSameReference: true));
        $order = $this->needsReviewOrder();

        $this->confirm($order)->assertUnprocessable();

        $this->assertSame(DeliveryStatus::NeedsReview, $order->fresh()->delivery_status);
    }

    public function test_confirm_failed_is_refused_when_the_supplier_is_unreachable(): void
    {
        $this->digiflazzAnswers(new RuntimeException('connection timed out'));
        $order = $this->needsReviewOrder();

        $this->confirm($order)->assertUnprocessable();

        $this->assertSame(DeliveryStatus::NeedsReview, $order->fresh()->delivery_status);
    }

    /** Decision 4 exception: no reference means the supplier never received it — a "check" would be a first purchase. */
    public function test_confirm_failed_without_a_reference_confirms_without_asking_the_supplier(): void
    {
        $this->digiflazzAnswers(new RuntimeException('must not be called'));
        $order = $this->needsReviewOrder(['reference_number' => null]);

        $this->confirm($order)->assertOk()->assertJsonPath('delivery_status', 'failed');

        $this->assertCount(0, $this->checks);
    }

    /** Decision 4 exception: Digiflazz treats a ref_id over 90 days old as a new transaction. */
    public function test_confirm_failed_past_the_reconcile_age_confirms_without_asking_the_supplier(): void
    {
        $this->digiflazzAnswers(new RuntimeException('must not be called'));
        $order = $this->needsReviewOrder();
        Order::query()->whereKey($order->id)->update(['created_at' => now()->subDays(91)]);

        $this->confirm($order)->assertOk()->assertJsonPath('delivery_status', 'failed');

        $this->assertCount(0, $this->checks);
    }

    /** A supplier that does not replay (Gamevion) keeps today's admin-only confirmation. */
    public function test_confirm_failed_on_a_non_replay_supplier_does_not_ask_the_supplier(): void
    {
        $gamevion = Supplier::query()->create(['name' => 'Gamevion', 'slug' => 'gamevion', 'currency' => 'MYR', 'api_config' => []]);
        $order = $this->needsReviewOrder(['supplier_id' => $gamevion->id]);

        $this->confirm($order)->assertOk()->assertJsonPath('delivery_status', 'failed');
    }

    /** Decision 7: every NeedsReview leg is asked; a delivered + a Gagal leg lands on PartiallyDelivered. */
    public function test_confirm_failed_on_a_combo_asks_every_needs_review_leg(): void
    {
        [$order, $leg1, $leg2] = $this->comboWithNeedsReviewLegs();
        $this->digiflazzAnswers([
            $leg1->reference_number => SupplierResponse::success(['supplier_ref' => 'SN-L1', 'status' => 'Sukses']),
            $leg2->reference_number => $this->gagal(),
        ]);

        $this->confirm($order)->assertOk()->assertJsonPath('delivery_status', 'partially_delivered');

        $this->assertSame(DeliveryStatus::Delivered, $leg1->fresh()->status);
        $this->assertSame(DeliveryStatus::Failed, $leg2->fresh()->status);
        $this->assertCount(2, $this->checks);
    }

    public function test_confirm_failed_on_a_combo_is_refused_while_a_leg_is_still_pending_at_the_supplier(): void
    {
        [$order, $leg1, $leg2] = $this->comboWithNeedsReviewLegs();
        $this->digiflazzAnswers([
            $leg1->reference_number => $this->gagal(),
            $leg2->reference_number => SupplierResponse::pending(['status' => 'Pending']),
        ]);

        $this->confirm($order)->assertUnprocessable();

        $this->assertSame(DeliveryStatus::NeedsReview, $leg2->fresh()->status);
        $this->assertSame(DeliveryStatus::NeedsReview, $order->fresh()->delivery_status);
    }

    /** A leg never sent (no reference) is not asked, and is confirmed failed with the rest. */
    public function test_confirm_failed_on_a_combo_skips_a_leg_that_never_reached_the_supplier(): void
    {
        [$order, $leg1, $leg2] = $this->comboWithNeedsReviewLegs();
        $leg2->update(['reference_number' => null]);
        $this->digiflazzAnswers([$leg1->reference_number => $this->gagal()]);

        $this->confirm($order)->assertOk()->assertJsonPath('delivery_status', 'failed');

        $this->assertSame(DeliveryStatus::Failed, $leg2->fresh()->status);
        $this->assertCount(1, $this->checks);
    }

    /** Decision 6: Check supplier also works on NeedsReview; a confirmed Gagal moves it to Failed. */
    public function test_check_supplier_on_a_needs_review_order_applies_a_confirmed_gagal(): void
    {
        $this->digiflazzAnswers($this->gagal());
        $order = $this->needsReviewOrder();

        $this->postJson("/api/orders/{$order->id}/check-supplier")->assertOk()->assertJsonPath('delivery_status', 'failed');
    }

    public function test_check_supplier_on_a_needs_review_order_applies_a_late_sukses(): void
    {
        $this->digiflazzAnswers(SupplierResponse::success(['supplier_ref' => 'SN-CHECK', 'status' => 'Sukses']));
        $order = $this->needsReviewOrder();

        $this->postJson("/api/orders/{$order->id}/check-supplier")->assertOk()->assertJsonPath('delivery_status', 'delivered');
    }

    public function test_check_supplier_refuses_a_needs_review_order_that_never_reached_the_supplier(): void
    {
        $this->digiflazzAnswers(new RuntimeException('must not be called'));
        $order = $this->needsReviewOrder(['reference_number' => null]);

        $this->postJson("/api/orders/{$order->id}/check-supplier")->assertUnprocessable();

        $this->assertCount(0, $this->checks);
    }

    public function test_check_supplier_refuses_a_needs_review_order_on_a_non_replay_supplier(): void
    {
        $gamevion = Supplier::query()->create(['name' => 'Gamevion', 'slug' => 'gamevion', 'currency' => 'MYR', 'api_config' => []]);
        $order = $this->needsReviewOrder(['supplier_id' => $gamevion->id]);

        $this->postJson("/api/orders/{$order->id}/check-supplier")->assertUnprocessable();
    }

    /** Decision 8: a NeedsReview resend reuses the ref_id, so a different package would be ambiguous. */
    public function test_resend_refuses_a_package_swap_from_needs_review_on_a_replay_supplier(): void
    {
        Queue::fake();
        [$package, $other] = $this->twoPackages();
        $order = $this->needsReviewOrder(['game_id' => $package->game_id, 'package_id' => $package->id]);

        $this->postJson("/api/orders/{$order->id}/resend", ['package_id' => $other->id])
            ->assertUnprocessable()->assertJsonValidationErrors('package_id');

        Queue::assertNothingPushed();
    }

    public function test_resend_still_allows_the_same_package_from_needs_review(): void
    {
        Queue::fake();
        [$package] = $this->twoPackages();
        $order = $this->needsReviewOrder(['game_id' => $package->game_id, 'package_id' => $package->id]);

        $this->postJson("/api/orders/{$order->id}/resend", ['package_id' => $package->id])->assertOk();

        Queue::assertPushed(ResendOrderDeliveryJob::class);
    }

    public function test_resend_still_allows_a_package_swap_from_failed(): void
    {
        Queue::fake();
        [$package, $other] = $this->twoPackages();
        $order = $this->needsReviewOrder([
            'game_id' => $package->game_id,
            'package_id' => $package->id,
            'delivery_status' => DeliveryStatus::Failed->value,
        ]);

        $this->postJson("/api/orders/{$order->id}/resend", ['package_id' => $other->id])->assertOk();

        Queue::assertPushed(ResendOrderDeliveryJob::class);
    }

    /**
     * ADR-102 2026-10-05 addendum (rc correction) — found in the local
     * browser check: through the REAL adapter, Digiflazz answering rc 45
     * (IP not recognised, "Terbentuk Transaksi = Tidak") rejected the
     * request itself. Confirm Failed must refuse, not fail the order.
     */
    public function test_confirm_failed_refuses_when_digiflazz_rejects_the_request_itself(): void
    {
        $this->realDigiflazzAnswers(['status' => 'Gagal', 'rc' => '45', 'message' => 'IP Anda tidak kami kenali']);
        $order = $this->needsReviewOrder();

        $this->confirm($order)->assertUnprocessable();

        $this->assertSame(DeliveryStatus::NeedsReview, $order->fresh()->delivery_status);
    }

    public function test_confirm_failed_through_the_real_adapter_still_fails_on_a_formed_gagal(): void
    {
        $this->realDigiflazzAnswers(['status' => 'Gagal', 'rc' => '02', 'message' => 'Transaksi Gagal']);
        $order = $this->needsReviewOrder();

        $this->confirm($order)->assertOk()->assertJsonPath('delivery_status', 'failed');
    }

    /** A Retry from NeedsReview re-submits the stored reference: a request rejection parks it Pending, never Failed. */
    public function test_a_needs_review_retry_rejected_by_digiflazz_is_parked_pending_not_failed(): void
    {
        Queue::fake();
        $this->realDigiflazzAnswers(['status' => 'Gagal', 'rc' => '45', 'message' => 'IP Anda tidak kami kenali']);
        $order = $this->needsReviewOrder();

        app(OrderFulfillmentService::class)->fulfill($order);

        $this->assertSame(DeliveryStatus::Pending, $order->fresh()->delivery_status);
    }

    /** @param  array<string, string>  $data  Digiflazz's `data` envelope */
    private function realDigiflazzAnswers(array $data): void
    {
        Supplier::query()->updateOrCreate(['slug' => 'digiflazz'], [
            'name' => 'Digiflazz',
            'currency' => 'IDR',
            'api_config' => [
                'base_url' => 'https://api.digiflazz.com',
                'username' => 'test-username',
                'api_key' => 'test-api-key',
                'testing' => false,
                'customer_no_separator' => '|',
            ],
        ]);
        Http::fake(['api.digiflazz.com/*' => Http::response(['data' => $data], 200)]);
    }

    /** @return array{0: Package, 1: Package} */
    private function twoPackages(): array
    {
        $game = Game::query()->create(['name' => 'MLBB', 'slug' => 'mlbb-'.uniqid()]);
        $make = fn (string $ref, int $cost) => Package::query()->create([
            'game_id' => $game->id, 'name' => $ref, 'cost_price' => $cost, 'standard_selling_price' => $cost,
            'supplier_id' => $this->digiflazz()->id, 'supplier_package_ref' => $ref, 'is_active' => true,
        ]);

        return [$make('mlbb5', 900), $make('mlbb10', 800)];
    }

    /** @return array{0: Order, 1: OrderDeliveryLeg, 2: OrderDeliveryLeg} */
    private function comboWithNeedsReviewLegs(): array
    {
        $game = Game::query()->create(['name' => 'MLBB', 'slug' => 'mlbb-'.uniqid()]);
        $component = Package::query()->create([
            'game_id' => $game->id, 'name' => 'Component', 'denomination' => 100,
            'cost_price' => 450, 'standard_selling_price' => 500,
            'supplier_id' => $this->digiflazz()->id, 'supplier_package_ref' => 'dgf-leg',
        ]);
        $combo = Package::query()->create([
            'game_id' => $game->id, 'name' => 'Combo', 'is_combo' => true,
            'denomination' => 200, 'cost_price' => 900, 'standard_selling_price' => 1000,
        ]);
        $combo->components()->attach($component->id, ['quantity' => 2, 'sort_order' => 0]);
        $order = $this->needsReviewOrder([
            'game_id' => $game->id,
            'package_id' => $combo->id,
            'supplier_id' => null,
            'supplier_product_ref' => null,
        ]);
        $leg = fn (int $n) => OrderDeliveryLeg::query()->create([
            'order_id' => $order->id, 'component_package_id' => $component->id, 'supplier_id' => $component->supplier_id,
            'leg_number' => $n, 'reference_number' => $order->reference_number.'-L'.$n,
            'status' => DeliveryStatus::NeedsReview->value,
        ]);

        return [$order, $leg(1), $leg(2)];
    }
}
