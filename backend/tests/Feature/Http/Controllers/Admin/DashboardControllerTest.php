<?php

namespace Tests\Feature\Http\Controllers\Admin;

use App\Models\AdminUser;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DashboardControllerTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): void
    {
        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'admin']));
    }

    private function order(array $overrides = []): Order
    {
        return Order::query()->create(array_merge(['placed_via' => 'storefront',
            'affiliate_id' => $this->primaryAffiliate()->id,
            'order_number' => 'KRS-'.uniqid(),
            'customer_email' => 'buyer@example.com',
            'player_id' => '123456',
            'cost_price' => 900,
            'standard_selling_price' => 900,
            'selling_price' => 1000,
            'transaction_fee' => 100,
            'final_amount' => 1100,
            'platform_profit' => 100,
            'affiliate_profit' => 20,
            'payment_status' => 'paid',
            'paid_at' => now(),
            'delivery_status' => 'delivered',
            'is_test' => false,
        ], $overrides));
    }

    public function test_health_requires_authentication(): void
    {
        $this->getJson('/api/dashboard/health')->assertUnauthorized();
    }

    public function test_health_returns_system_health_with_definitions(): void
    {
        $this->actingAsAdmin();

        $response = $this->getJson('/api/dashboard/health');

        $response->assertOk()->assertJsonStructure([
            'suppliers',
            'suppliers_definition',
            'stuck_orders' => ['value', 'definition'],
            'pending_payments' => ['value', 'definition'],
            'queue' => ['pending', 'failed', 'definition'],
            'scheduled_tasks' => ['tasks' => [['name', 'tier', 'stale', 'last_ok_at', 'last_failed_at', 'last_error', 'last_runtime_seconds']], 'definition'],
        ]);
    }
}
