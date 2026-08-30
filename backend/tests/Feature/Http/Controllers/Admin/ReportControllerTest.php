<?php

namespace Tests\Feature\Http\Controllers\Admin;

use App\Models\AdminUser;
use App\Models\Order;
use App\Services\Ledger\LedgerService;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\PaymentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReportControllerTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): void
    {
        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'admin']));
    }

    private function order(array $overrides = []): Order
    {
        return Order::query()->create(array_merge([
            'reseller_id' => $this->primaryReseller()->id,
            'order_number' => 'KRS-'.uniqid(),
            'customer_email' => 'buyer@example.com',
            'player_id' => '123456',
            'cost_price' => 900,
            'standard_selling_price' => 900,
            'selling_price' => 1000,
            'transaction_fee' => 100,
            'final_amount' => 1100,
            'platform_profit' => 100,
            'reseller_profit' => 20,
            'payment_status' => PaymentStatus::Paid->value,
            'paid_at' => now(),
            'delivery_status' => DeliveryStatus::Delivered->value,
            'is_test' => false,
        ], $overrides));
    }

    public function test_summary_requires_authentication(): void
    {
        $this->getJson('/api/reports/summary')->assertUnauthorized();
    }

    public function test_summary_returns_pinned_shape(): void
    {
        $order = $this->order();
        (new LedgerService)->credit('platform', null, $order->platform_profit, 'order_profit', 'order', $order->id);
        $this->actingAsAdmin();

        $response = $this->getJson('/api/reports/summary');

        $response->assertOk()->assertJson([
            'total_sales' => 1100,
            'orders_count' => 1,
            'platform_profit' => 100,
            'margin_pct' => round(100 / 1100 * 100, 2),
        ]);
    }

    public function test_trend_defaults_to_seven_days(): void
    {
        $this->actingAsAdmin();

        $response = $this->getJson('/api/reports/trend');

        $response->assertOk();
        $this->assertCount(7, $response->json('days'));
    }

    public function test_trend_rejects_unsupported_day_count(): void
    {
        $this->actingAsAdmin();

        $response = $this->getJson('/api/reports/trend?days=99');

        $this->assertCount(7, $response->json('days'));
    }

    public function test_export_csv_streams_order_rows(): void
    {
        $this->order();
        $this->actingAsAdmin();

        $response = $this->get('/api/reports/export?format=csv');

        $response->assertOk();
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
    }

    public function test_daily_breakdown_requires_authentication(): void
    {
        $this->getJson('/api/reports/daily-breakdown')->assertUnauthorized();
    }

    public function test_daily_breakdown_returns_rows(): void
    {
        $this->order();
        $this->actingAsAdmin();

        $response = $this->getJson('/api/reports/daily-breakdown');

        $response->assertOk();
        $this->assertCount(1, $response->json('days'));
    }

    public function test_top_games_limits_results(): void
    {
        $this->order();
        $this->actingAsAdmin();

        $response = $this->getJson('/api/reports/top-games?limit=5');

        $response->assertOk()->assertJsonStructure(['games']);
    }

    public function test_game_breakdown_returns_rows(): void
    {
        $this->order();
        $this->actingAsAdmin();

        $this->getJson('/api/reports/breakdown/games')->assertOk()->assertJsonStructure(['games']);
    }

    public function test_payment_method_breakdown_returns_rows(): void
    {
        $this->order(['payment_method' => 'fpx']);
        $this->actingAsAdmin();

        $this->getJson('/api/reports/breakdown/payment-methods')->assertOk()->assertJsonStructure(['payment_methods']);
    }

    public function test_reseller_breakdown_returns_rows(): void
    {
        $this->order();
        $this->actingAsAdmin();

        $this->getJson('/api/reports/breakdown/resellers')->assertOk()->assertJsonStructure(['resellers']);
    }

    public function test_order_status_funnel_returns_shape(): void
    {
        $this->order();
        $this->actingAsAdmin();

        $response = $this->getJson('/api/reports/order-status-funnel');

        $response->assertOk()->assertJsonStructure(['total', 'by_status', 'success_rate_pct']);
    }
}
