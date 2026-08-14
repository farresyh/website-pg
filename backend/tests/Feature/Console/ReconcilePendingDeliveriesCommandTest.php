<?php

namespace Tests\Feature\Console;

use App\Jobs\FulfillOrderJob;
use App\Models\Order;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\PaymentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * ADR-026 (ORD-10) — ReconcilePendingDeliveriesCommand covers two
 * distinct ambiguous-failure shapes (Gap X: stuck processing with no
 * supplier_ref; Gap Y: a stale duplicate_reference recorded before
 * needs_review existed). See the command's own docblock for the full
 * story.
 */
class ReconcilePendingDeliveriesCommandTest extends TestCase
{
    use RefreshDatabase;

    private function order(array $overrides = []): Order
    {
        return Order::query()->create(array_merge([
            'order_number' => 'KRS-'.uniqid(),
            'customer_email' => 'buyer@example.com',
            'player_id' => '123456',
            'supplier_product_ref' => 'FFP5',
            'cost_price' => 900,
            'reseller_cost_price' => 900,
            'selling_price' => 1000,
            'transaction_fee' => 100,
            'final_amount' => 1100,
            'platform_profit' => 100,
            'reseller_profit' => 0,
            'payment_status' => PaymentStatus::Paid->value,
            'delivery_status' => DeliveryStatus::Processing->value,
        ], $overrides));
    }

    private function stale(Order $order): Order
    {
        $order->forceFill(['updated_at' => now()->subMinutes(20)])->save();

        return $order->fresh();
    }

    public function test_redispatches_a_stuck_processing_order_past_the_threshold(): void
    {
        Bus::fake();
        $order = $this->stale($this->order());

        $this->artisan('app:reconcile-pending-deliveries')->assertExitCode(0);

        Bus::assertDispatched(FulfillOrderJob::class, fn ($job) => $job->order->id === $order->id);
    }

    public function test_does_not_redispatch_a_processing_order_still_within_the_threshold(): void
    {
        Bus::fake();
        $this->order(); // updated_at is "now" — not yet stale

        $this->artisan('app:reconcile-pending-deliveries')->assertExitCode(0);

        Bus::assertNotDispatched(FulfillOrderJob::class);
    }

    /**
     * A supplier_ref means the order isn't Gap X at all — it reached
     * Delivered/Failed normally at some point, or a previous
     * reconciliation pass already redispatched it; a stuck-processing
     * order without one is what makes it genuinely ambiguous.
     */
    public function test_does_not_redispatch_a_stuck_processing_order_that_already_has_a_supplier_ref(): void
    {
        Bus::fake();
        $this->stale($this->order(['supplier_ref' => 'GV-ALREADY-HAS-ONE']));

        $this->artisan('app:reconcile-pending-deliveries')->assertExitCode(0);

        Bus::assertNotDispatched(FulfillOrderJob::class);
    }

    public function test_flags_a_stale_duplicate_reference_order_for_manual_review(): void
    {
        Bus::fake();
        $order = $this->stale($this->order([
            'delivery_status' => DeliveryStatus::Failed->value,
            'supplier_response' => ['error_code' => 'duplicate_reference', 'error_message' => 'dup'],
        ]));

        $this->artisan('app:reconcile-pending-deliveries')->assertExitCode(0);

        $this->assertSame(DeliveryStatus::NeedsReview, $order->fresh()->delivery_status);
    }

    public function test_does_not_flag_a_recent_duplicate_reference_order_still_within_the_threshold(): void
    {
        $order = $this->order([
            'delivery_status' => DeliveryStatus::Failed->value,
            'supplier_response' => ['error_code' => 'duplicate_reference', 'error_message' => 'dup'],
        ]);

        $this->artisan('app:reconcile-pending-deliveries')->assertExitCode(0);

        $this->assertSame(DeliveryStatus::Failed, $order->fresh()->delivery_status);
    }

    /**
     * A genuine business-level failure (wrong product code, insufficient
     * supplier balance, etc.) must never be swept into needs_review —
     * only duplicate_reference is evidence of an ambiguous outcome.
     */
    public function test_does_not_flag_a_stale_order_with_a_different_error_code(): void
    {
        $order = $this->stale($this->order([
            'delivery_status' => DeliveryStatus::Failed->value,
            'supplier_response' => ['error_code' => 'insufficient_balance', 'error_message' => 'no funds'],
        ]));

        $this->artisan('app:reconcile-pending-deliveries')->assertExitCode(0);

        $this->assertSame(DeliveryStatus::Failed, $order->fresh()->delivery_status);
    }

    public function test_does_not_touch_a_delivered_order(): void
    {
        Bus::fake();
        $this->stale($this->order(['delivery_status' => DeliveryStatus::Delivered->value, 'supplier_ref' => 'GV-1']));

        $this->artisan('app:reconcile-pending-deliveries')->assertExitCode(0);

        Bus::assertNotDispatched(FulfillOrderJob::class);
    }
}
