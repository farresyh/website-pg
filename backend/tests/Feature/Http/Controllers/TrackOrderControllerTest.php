<?php

namespace Tests\Feature\Http\Controllers;

use App\Models\Game;
use App\Models\Order;
use App\Models\Package;
use App\Models\Supplier;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\PaymentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TrackOrderControllerTest extends TestCase
{
    use RefreshDatabase;

    private function order(array $overrides = []): Order
    {
        return Order::query()->create(array_merge([
            'order_number' => 'KRS-'.uniqid(),
            'customer_email' => 'buyer@example.com',
            'player_id' => '123456',
            'server_id' => '2005',
            'cost_price' => 900,
            'reseller_cost_price' => 900,
            'selling_price' => 1000,
            'transaction_fee' => 100,
            'final_amount' => 1100,
            'platform_profit' => 100,
            'reseller_profit' => 0,
            'payment_status' => PaymentStatus::Paid->value,
            'delivery_status' => DeliveryStatus::Delivered->value,
        ], $overrides));
    }

    public function test_returns_the_customer_safe_fields_for_a_known_order_number(): void
    {
        $supplier = Supplier::query()->create(['name' => 'Gamevion', 'slug' => 'gamevion', 'api_config' => [], 'currency' => 'MYR']);
        $game = Game::query()->create(['name' => 'Mobile Legends (Malaysia)', 'slug' => 'mobile-legends-malaysia']);
        $package = Package::query()->create([
            'game_id' => $game->id, 'name' => '86 Diamonds', 'cost_price' => 421, 'reseller_cost_price' => 500,
            'supplier_id' => $supplier->id, 'supplier_package_ref' => 'A',
        ]);
        $order = $this->order(['order_number' => 'KRS-TEST123', 'game_id' => $game->id, 'package_id' => $package->id]);

        $response = $this->getJson("/api/track-order/{$order->order_number}");

        $response->assertOk();
        $response->assertJson([
            'order_number' => 'KRS-TEST123',
            'game' => ['name' => 'Mobile Legends (Malaysia)', 'slug' => 'mobile-legends-malaysia'],
            'package_name' => '86 Diamonds',
            'player_id' => '123456',
            'server_id' => '2005',
            'final_amount' => 1100,
            'payment_status' => 'paid',
            'delivery_status' => 'delivered',
        ]);
    }

    public function test_never_exposes_internal_financial_or_supplier_fields(): void
    {
        $order = $this->order(['order_number' => 'KRS-PRIVACY']);

        $response = $this->getJson("/api/track-order/{$order->order_number}");

        $response->assertOk();
        $keys = array_keys($response->json());
        foreach (['cost_price', 'reseller_cost_price', 'platform_profit', 'reseller_profit', 'supplier_response', 'payment_ref', 'supplier_ref', 'customer_email', 'customer_phone'] as $forbidden) {
            $this->assertNotContains($forbidden, $keys);
        }
    }

    public function test_returns_404_for_an_unknown_order_number(): void
    {
        $response = $this->getJson('/api/track-order/KRS-DOES-NOT-EXIST');

        $response->assertNotFound();
        $response->assertJson(['message' => 'No order found with that order number.']);
    }

    public function test_does_not_require_authentication(): void
    {
        $order = $this->order();

        $this->getJson("/api/track-order/{$order->order_number}")->assertOk();
    }
}
