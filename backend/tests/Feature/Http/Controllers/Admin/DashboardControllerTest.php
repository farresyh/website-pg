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
        return Order::query()->create(array_merge([
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

    public function test_summary_requires_authentication(): void
    {
        $this->getJson('/api/dashboard/summary')->assertUnauthorized();
    }

    public function test_health_requires_authentication(): void
    {
        $this->getJson('/api/dashboard/health')->assertUnauthorized();
    }

    public function test_funnel_requires_authentication(): void
    {
        $this->getJson('/api/dashboard/funnel')->assertUnauthorized();
    }

    public function test_top_games_requires_authentication(): void
    {
        $this->getJson('/api/dashboard/top-games')->assertUnauthorized();
    }

    public function test_hourly_activity_requires_authentication(): void
    {
        $this->getJson('/api/dashboard/hourly-activity?date=2026-08-27')->assertUnauthorized();
    }

    public function test_summary_returns_kpi_cards_with_definitions(): void
    {
        $this->order(['final_amount' => 5000]);
        $this->actingAsAdmin();

        $response = $this->getJson('/api/dashboard/summary');

        $response->assertOk()
            ->assertJsonStructure([
                'sales_today' => ['value', 'comparison' => ['pct', 'direction'], 'definition'],
                'orders_today' => ['value', 'comparison', 'definition'],
                'profit_today' => ['value', 'comparison', 'definition'],
                'vouchers_issued_today' => ['value', 'amount_sen', 'comparison', 'definition'],
            ]);
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
        ]);
    }

    public function test_funnel_returns_cohort_shape(): void
    {
        $this->order();
        $this->actingAsAdmin();

        $response = $this->getJson('/api/dashboard/funnel');

        $response->assertOk()
            ->assertJsonStructure(['window_days', 'created' => ['value', 'definition'], 'payment_confirmed', 'delivered'])
            ->assertJson(['created' => ['value' => 1], 'payment_confirmed' => ['value' => 1], 'delivered' => ['value' => 1]]);
    }

    public function test_top_games_respects_limit_query_param(): void
    {
        $this->actingAsAdmin();

        $response = $this->getJson('/api/dashboard/top-games?limit=3');

        $response->assertOk()->assertJsonStructure(['window_days', 'games', 'definition']);
    }

    public function test_hourly_activity_requires_a_date_param(): void
    {
        $this->actingAsAdmin();

        $this->getJson('/api/dashboard/hourly-activity')->assertUnprocessable();
    }

    public function test_hourly_activity_returns_24_hour_buckets(): void
    {
        $this->actingAsAdmin();

        $response = $this->getJson('/api/dashboard/hourly-activity?date=2026-08-27');

        $response->assertOk()->assertJsonCount(24, 'hours');
    }
}
