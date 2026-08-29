<?php

namespace Tests\Feature\Http\Controllers\Admin;

use App\Models\AdminUser;
use App\Models\Order;
use App\Models\PlatformSettings;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\PaymentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CustomerAnalyticsControllerTest extends TestCase
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
        $this->getJson('/api/customer-analytics/summary')->assertUnauthorized();
    }

    public function test_customers_requires_authentication(): void
    {
        $this->getJson('/api/customer-analytics/customers')->assertUnauthorized();
    }

    public function test_summary_returns_pinned_shape(): void
    {
        $this->actingAsAdmin();
        $this->order();

        $this->getJson('/api/customer-analytics/summary')
            ->assertOk()
            ->assertJsonStructure(['total_customers', 'avg_order_value', 'repeat_rate_pct', 'top_spender']);
    }

    public function test_customers_returns_segment_and_filters_by_it(): void
    {
        $this->actingAsAdmin();
        PlatformSettings::current()->update(['vip_spend_threshold_sen' => 500000]);
        $this->order(['customer_email' => 'vip@example.com', 'final_amount' => 500000]);
        $this->order(['customer_email' => 'other@example.com', 'final_amount' => 100]);

        $response = $this->getJson('/api/customer-analytics/customers?segment=vip')->assertOk();
        $customers = $response->json('customers');

        $this->assertCount(1, $customers);
        $this->assertSame('vip@example.com', $customers[0]['customer_email']);
        $this->assertSame('VIP', $customers[0]['segment_label']);
    }

    public function test_export_streams_csv(): void
    {
        $this->actingAsAdmin();
        $this->order(['customer_email' => 'buyer@example.com']);

        $response = $this->get('/api/customer-analytics/export');

        $response->assertOk();
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
    }

    public function test_show_requires_authentication(): void
    {
        $this->getJson('/api/customer-analytics/customers/buyer@example.com')->assertUnauthorized();
    }

    public function test_show_returns_404_for_unknown_customer(): void
    {
        $this->actingAsAdmin();

        $this->getJson('/api/customer-analytics/customers/nobody@example.com')->assertNotFound();
    }

    public function test_show_returns_pinned_shape_for_a_url_encoded_email(): void
    {
        $this->actingAsAdmin();
        $this->order(['customer_email' => 'buyer@example.com']);

        $this->getJson('/api/customer-analytics/customers/'.urlencode('buyer@example.com'))
            ->assertOk()
            ->assertJsonStructure([
                'customer_email', 'customer_name', 'customer_phone', 'segment', 'segment_label',
                'stats', 'profit_analysis', 'monthly_trend', 'top_packages', 'top_resellers', 'order_history',
            ])
            ->assertJson(['customer_email' => 'buyer@example.com']);
    }
}
