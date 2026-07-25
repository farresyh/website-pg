<?php

namespace Tests\Feature\Http\Controllers\Admin;

use App\Jobs\FulfillOrderJob;
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
}
