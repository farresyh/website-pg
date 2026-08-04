<?php

namespace Tests\Feature\Http\Controllers\Admin;

use App\Jobs\FulfillOrderJob;
use App\Jobs\ResendOrderDeliveryJob;
use App\Models\AdminUser;
use App\Models\Game;
use App\Models\Order;
use App\Models\Package;
use App\Models\Supplier;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\PaymentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrderControllerTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): void
    {
        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'admin']));
    }

    private function order(array $overrides = []): Order
    {
        return Order::query()->create(array_merge([
            'order_number' => 'KRS-'.uniqid(),
            'customer_email' => 'buyer@example.com',
            'player_id' => '123456',
            'cost_price' => 900,
            'reseller_cost_price' => 900,
            'selling_price' => 1000,
            'transaction_fee' => 100,
            'final_amount' => 1100,
            'platform_profit' => 100,
            'reseller_profit' => 0,
            'payment_status' => PaymentStatus::Pending->value,
            'delivery_status' => DeliveryStatus::NotStarted->value,
        ], $overrides));
    }

    public function test_index_requires_authentication(): void
    {
        $this->getJson('/api/orders')->assertUnauthorized();
    }

    public function test_index_lists_orders_with_game_and_package_names(): void
    {
        $supplier = Supplier::query()->create(['name' => 'Gamevion', 'slug' => 'gamevion', 'api_config' => [], 'currency' => 'MYR']);
        $game = Game::query()->create(['name' => 'Free Fire Global', 'slug' => 'free-fire-global']);
        $package = Package::query()->create([
            'game_id' => $game->id, 'name' => '100 Diamonds', 'cost_price' => 421, 'reseller_cost_price' => 500,
            'supplier_id' => $supplier->id, 'supplier_package_ref' => 'A',
        ]);
        $this->order(['game_id' => $game->id, 'package_id' => $package->id]);
        $this->actingAsAdmin();

        $response = $this->getJson('/api/orders');

        $response->assertOk();
        $this->assertSame('Free Fire Global', $response->json('data.0.game.name'));
        $this->assertSame('100 Diamonds', $response->json('data.0.package.name'));
    }

    public function test_index_can_filter_by_need_action(): void
    {
        $this->order(['order_number' => 'KRS-NEEDS-ACTION', 'payment_status' => PaymentStatus::Paid->value, 'delivery_status' => DeliveryStatus::Failed->value]);
        $this->order(['order_number' => 'KRS-DELIVERED', 'payment_status' => PaymentStatus::Paid->value, 'delivery_status' => DeliveryStatus::Delivered->value]);
        $this->actingAsAdmin();

        $response = $this->getJson('/api/orders?status=need_action');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('KRS-NEEDS-ACTION', $response->json('data.0.order_number'));
    }

    public function test_index_can_filter_by_processing(): void
    {
        $this->order(['order_number' => 'KRS-PROCESSING', 'payment_status' => PaymentStatus::Paid->value, 'delivery_status' => DeliveryStatus::Processing->value]);
        $this->order(['order_number' => 'KRS-PENDING', 'payment_status' => PaymentStatus::Pending->value, 'delivery_status' => DeliveryStatus::NotStarted->value]);
        $this->actingAsAdmin();

        $response = $this->getJson('/api/orders?status=processing');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('KRS-PROCESSING', $response->json('data.0.order_number'));
    }

    public function test_index_can_filter_by_completed(): void
    {
        $this->order(['order_number' => 'KRS-DONE', 'payment_status' => PaymentStatus::Paid->value, 'delivery_status' => DeliveryStatus::Delivered->value]);
        $this->order(['order_number' => 'KRS-NOT-DONE', 'payment_status' => PaymentStatus::Paid->value, 'delivery_status' => DeliveryStatus::Processing->value]);
        $this->actingAsAdmin();

        $response = $this->getJson('/api/orders?status=completed');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('KRS-DONE', $response->json('data.0.order_number'));
    }

    /**
     * ADR-021 (PAY-3) — surfaces an order whose Xendit webhook never
     * arrived. Matches the same 30-minute window
     * ReconcilePendingPaymentsCommand itself acts on.
     */
    public function test_index_can_filter_by_awaiting_payment(): void
    {
        $stuck = $this->order(['order_number' => 'KRS-STUCK-PENDING']);
        $stuck->forceFill(['created_at' => now()->subMinutes(45)])->save();
        $this->order(['order_number' => 'KRS-FRESH-PENDING']); // just checked out, webhook hasn't had time to arrive yet
        $this->order(['order_number' => 'KRS-ALREADY-PAID', 'payment_status' => PaymentStatus::Paid->value])
            ->forceFill(['created_at' => now()->subMinutes(45)])->save();

        $this->actingAsAdmin();

        $response = $this->getJson('/api/orders?status=awaiting_payment');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('KRS-STUCK-PENDING', $response->json('data.0.order_number'));
    }

    public function test_index_can_filter_by_today(): void
    {
        $today = $this->order(['order_number' => 'KRS-TODAY']);
        $yesterday = $this->order(['order_number' => 'KRS-YESTERDAY']);
        $yesterday->forceFill(['created_at' => now()->subDay()])->save();
        $this->actingAsAdmin();

        $response = $this->getJson('/api/orders?status=today');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('KRS-TODAY', $response->json('data.0.order_number'));
    }

    public function test_index_can_search_by_order_number(): void
    {
        $this->order(['order_number' => 'KRS-FINDME', 'customer_email' => 'a@example.com']);
        $this->order(['order_number' => 'KRS-OTHER', 'customer_email' => 'b@example.com']);
        $this->actingAsAdmin();

        $response = $this->getJson('/api/orders?search=FINDME');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }

    public function test_index_can_search_by_customer_email(): void
    {
        $this->order(['order_number' => 'KRS-A', 'customer_email' => 'findme@example.com']);
        $this->order(['order_number' => 'KRS-B', 'customer_email' => 'other@example.com']);
        $this->actingAsAdmin();

        $response = $this->getJson('/api/orders?search=findme@example.com');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }

    public function test_show_returns_full_order_detail(): void
    {
        $supplier = Supplier::query()->create(['name' => 'Gamevion', 'slug' => 'gamevion', 'api_config' => [], 'currency' => 'MYR']);
        $game = Game::query()->create(['name' => 'Free Fire Global', 'slug' => 'free-fire-global']);
        $package = Package::query()->create([
            'game_id' => $game->id, 'name' => '100 Diamonds', 'cost_price' => 421, 'reseller_cost_price' => 500,
            'supplier_id' => $supplier->id, 'supplier_package_ref' => 'A',
        ]);
        $order = $this->order([
            'game_id' => $game->id, 'package_id' => $package->id, 'supplier_id' => $supplier->id,
            'supplier_response' => ['supplier_ref' => 'GV-123'],
        ]);
        $this->actingAsAdmin();

        $response = $this->getJson("/api/orders/{$order->id}");

        $response->assertOk();
        $response->assertJsonPath('order_number', $order->order_number);
        $response->assertJsonPath('game.name', 'Free Fire Global');
        $response->assertJsonPath('package.name', '100 Diamonds');
        $response->assertJsonPath('supplier.name', 'Gamevion');
        $response->assertJsonPath('supplier_response.supplier_ref', 'GV-123');
    }

    public function test_show_returns_404_for_a_nonexistent_order(): void
    {
        $this->actingAsAdmin();

        $response = $this->getJson('/api/orders/999999');

        $response->assertNotFound();
    }

    /**
     * ORD-7 / ADR-014: the manual retry action queues FulfillOrderJob
     * rather than running it inline, same as the webhook path.
     */
    public function test_retry_delivery_queues_a_fulfillment_job_for_a_failed_order(): void
    {
        Queue::fake();
        $this->actingAsAdmin();
        $order = $this->order([
            'payment_status' => PaymentStatus::Paid->value,
            'delivery_status' => DeliveryStatus::Failed->value,
        ]);

        $response = $this->postJson("/api/orders/{$order->id}/retry-delivery");

        $response->assertOk();
        Queue::assertPushed(FulfillOrderJob::class, fn (FulfillOrderJob $job) => $job->order->id === $order->id);
    }

    public function test_retry_delivery_rejects_an_order_that_is_not_failed(): void
    {
        Queue::fake();
        $this->actingAsAdmin();
        $order = $this->order([
            'payment_status' => PaymentStatus::Paid->value,
            'delivery_status' => DeliveryStatus::Delivered->value,
        ]);

        $response = $this->postJson("/api/orders/{$order->id}/retry-delivery");

        $response->assertUnprocessable();
        Queue::assertNothingPushed();
    }

    public function test_retry_delivery_requires_authentication(): void
    {
        $order = $this->order(['delivery_status' => DeliveryStatus::Failed->value]);

        $this->postJson("/api/orders/{$order->id}/retry-delivery")->assertUnauthorized();
    }

    /**
     * ADR-017: same async-dispatch discipline as retryDelivery — the
     * admin request never blocks on a live Gamevion call.
     */
    public function test_resend_queues_a_resend_job_for_a_failed_order_with_a_same_game_package(): void
    {
        Queue::fake();
        $this->actingAsAdmin();
        $supplier = Supplier::query()->create(['name' => 'Gamevion', 'slug' => 'gamevion', 'api_config' => [], 'currency' => 'MYR']);
        $game = Game::query()->create(['name' => 'Free Fire Global', 'slug' => 'free-fire-global']);
        $package = Package::query()->create([
            'game_id' => $game->id, 'name' => '210 Diamonds', 'cost_price' => 1900, 'reseller_cost_price' => 1900,
            'supplier_id' => $supplier->id, 'supplier_package_ref' => 'B', 'is_active' => true,
        ]);
        $order = $this->order([
            'game_id' => $game->id,
            'payment_status' => PaymentStatus::Paid->value,
            'delivery_status' => DeliveryStatus::Failed->value,
        ]);

        $response = $this->postJson("/api/orders/{$order->id}/resend", ['package_id' => $package->id, 'note' => 'Bigger pack']);

        $response->assertOk();
        Queue::assertPushed(ResendOrderDeliveryJob::class, fn (ResendOrderDeliveryJob $job) => $job->order->id === $order->id
            && $job->packageId === $package->id
            && $job->note === 'Bigger pack');
    }

    public function test_resend_rejects_an_order_that_is_not_failed(): void
    {
        Queue::fake();
        $this->actingAsAdmin();
        $supplier = Supplier::query()->create(['name' => 'Gamevion', 'slug' => 'gamevion', 'api_config' => [], 'currency' => 'MYR']);
        $game = Game::query()->create(['name' => 'Free Fire Global', 'slug' => 'free-fire-global']);
        $package = Package::query()->create(['game_id' => $game->id, 'name' => '100 Diamonds', 'cost_price' => 900, 'reseller_cost_price' => 900, 'supplier_id' => $supplier->id, 'supplier_package_ref' => 'A']);
        $order = $this->order([
            'game_id' => $game->id,
            'payment_status' => PaymentStatus::Paid->value,
            'delivery_status' => DeliveryStatus::Delivered->value,
        ]);

        $response = $this->postJson("/api/orders/{$order->id}/resend", ['package_id' => $package->id]);

        $response->assertUnprocessable();
        Queue::assertNothingPushed();
    }

    /**
     * Decision #1: same-game swap only — cross-game rejected before
     * ever reaching the queue.
     */
    public function test_resend_rejects_a_package_from_a_different_game(): void
    {
        Queue::fake();
        $this->actingAsAdmin();
        $supplier = Supplier::query()->create(['name' => 'Gamevion', 'slug' => 'gamevion', 'api_config' => [], 'currency' => 'MYR']);
        $game = Game::query()->create(['name' => 'Free Fire Global', 'slug' => 'free-fire-global']);
        $otherGame = Game::query()->create(['name' => 'MLBB', 'slug' => 'mlbb']);
        $otherGamePackage = Package::query()->create(['game_id' => $otherGame->id, 'name' => '5 Diamonds', 'cost_price' => 500, 'reseller_cost_price' => 500, 'supplier_id' => $supplier->id, 'supplier_package_ref' => 'B']);
        $order = $this->order([
            'game_id' => $game->id,
            'payment_status' => PaymentStatus::Paid->value,
            'delivery_status' => DeliveryStatus::Failed->value,
        ]);

        $response = $this->postJson("/api/orders/{$order->id}/resend", ['package_id' => $otherGamePackage->id]);

        $response->assertUnprocessable();
        Queue::assertNothingPushed();
    }

    public function test_resend_requires_authentication(): void
    {
        $supplier = Supplier::query()->create(['name' => 'Gamevion', 'slug' => 'gamevion', 'api_config' => [], 'currency' => 'MYR']);
        $game = Game::query()->create(['name' => 'Free Fire Global', 'slug' => 'free-fire-global']);
        $package = Package::query()->create(['game_id' => $game->id, 'name' => '100 Diamonds', 'cost_price' => 900, 'reseller_cost_price' => 900, 'supplier_id' => $supplier->id, 'supplier_package_ref' => 'A']);
        $order = $this->order(['game_id' => $game->id, 'delivery_status' => DeliveryStatus::Failed->value]);

        $this->postJson("/api/orders/{$order->id}/resend", ['package_id' => $package->id])->assertUnauthorized();
    }

    /**
     * Decision #4: "Delivery Logs" — show() surfaces every resend
     * attempt, most recent first.
     */
    public function test_show_includes_resend_attempt_history(): void
    {
        $this->actingAsAdmin();
        $supplier = Supplier::query()->create(['name' => 'Gamevion', 'slug' => 'gamevion', 'api_config' => [], 'currency' => 'MYR']);
        $game = Game::query()->create(['name' => 'Free Fire Global', 'slug' => 'free-fire-global']);
        $package = Package::query()->create(['game_id' => $game->id, 'name' => '100 Diamonds', 'cost_price' => 900, 'reseller_cost_price' => 900, 'supplier_id' => $supplier->id, 'supplier_package_ref' => 'A']);
        $order = $this->order(['game_id' => $game->id, 'package_id' => $package->id]);
        \App\Models\OrderResendAttempt::query()->create([
            'order_id' => $order->id,
            'package_id' => $package->id,
            'cost_price_sen' => 950,
            'reseller_cost_price_sen' => 950,
            'price_diff_sen' => 50,
            'outcome' => 'success',
            'triggered_by' => 'Admin User',
        ]);

        $response = $this->getJson("/api/orders/{$order->id}");

        $response->assertOk();
        $this->assertCount(1, $response->json('resend_attempts'));
        $this->assertSame(50, $response->json('resend_attempts.0.price_diff_sen'));
        $this->assertSame('Admin User', $response->json('resend_attempts.0.triggered_by'));
    }

    /**
     * The Admin Panel's "Issue Voucher" button (Orders page) needs to
     * know whether one already exists for this order, without a
     * separate lookup — show() eager-loads the voucher relation.
     */
    public function test_show_includes_the_issued_voucher_when_one_exists(): void
    {
        $this->actingAsAdmin();
        $order = $this->order(['delivery_status' => DeliveryStatus::Failed->value, 'payment_status' => PaymentStatus::Paid->value]);
        \App\Models\Voucher::query()->create([
            'order_id' => $order->id,
            'code' => 'VC-TESTCODE',
            'customer_email' => $order->customer_email,
            'amount' => 1000,
            'remaining' => 1000,
            'status' => 'active',
            'reason' => 'Delivery failed - refund voucher',
            'created_by' => AdminUser::factory()->create()->id,
        ]);

        $response = $this->getJson("/api/orders/{$order->id}");

        $response->assertOk();
        $response->assertJsonPath('voucher.code', 'VC-TESTCODE');
    }

    public function test_show_voucher_is_null_when_none_issued(): void
    {
        $this->actingAsAdmin();
        $order = $this->order();

        $response = $this->getJson("/api/orders/{$order->id}");

        $response->assertOk();
        $response->assertJsonPath('voucher', null);
    }

    /**
     * ADR-018 decision #2: permanent, unconditional exclusion — a
     * sandbox order must never appear on this real, money-critical
     * screen, regardless of what status filter is applied.
     */
    public function test_index_never_includes_sandbox_orders(): void
    {
        $this->order(['order_number' => 'KRS-SANDBOX-1', 'is_test' => true]);
        $this->order(['order_number' => 'KRS-REAL-1', 'is_test' => false]);
        $this->actingAsAdmin();

        $response = $this->getJson('/api/orders');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('KRS-REAL-1', $response->json('data.0.order_number'));
    }

    public function test_show_404s_for_a_sandbox_order(): void
    {
        $order = $this->order(['is_test' => true]);
        $this->actingAsAdmin();

        $this->getJson("/api/orders/{$order->id}")->assertNotFound();
    }

    public function test_retry_delivery_404s_for_a_sandbox_order(): void
    {
        $order = $this->order(['is_test' => true, 'payment_status' => PaymentStatus::Paid->value, 'delivery_status' => DeliveryStatus::Failed->value]);
        $this->actingAsAdmin();

        $this->postJson("/api/orders/{$order->id}/retry-delivery")->assertNotFound();
    }

    public function test_resend_404s_for_a_sandbox_order(): void
    {
        $supplier = Supplier::query()->create(['name' => 'Gamevion', 'slug' => 'gamevion', 'api_config' => [], 'currency' => 'MYR']);
        $game = Game::query()->create(['name' => 'Free Fire Global', 'slug' => 'free-fire-global']);
        $package = Package::query()->create(['game_id' => $game->id, 'name' => '100 Diamonds', 'cost_price' => 900, 'reseller_cost_price' => 900, 'supplier_id' => $supplier->id, 'supplier_package_ref' => 'A']);
        $order = $this->order([
            'is_test' => true,
            'game_id' => $game->id,
            'payment_status' => PaymentStatus::Paid->value,
            'delivery_status' => DeliveryStatus::Failed->value,
        ]);
        $this->actingAsAdmin();

        $this->postJson("/api/orders/{$order->id}/resend", ['package_id' => $package->id])->assertNotFound();
    }
}
