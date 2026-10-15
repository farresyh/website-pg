<?php

namespace Tests\Feature\Http\Controllers\Admin;

use App\Models\AdminUser;
use App\Models\Order;
use App\Models\PlatformSettings;
use App\Models\Reseller;
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
        $customers = $response->json('data');

        $this->assertCount(1, $customers);
        $this->assertSame('vip@example.com', $customers[0]['customer_email']);
        $this->assertSame('VIP', $customers[0]['segment_label']);
    }

    /** ADR-049 addendum — reseller_id filters exactly like affiliate_id does. */
    public function test_customers_filters_by_reseller_id(): void
    {
        $this->actingAsAdmin();
        $reseller = Reseller::query()->create(['business_name' => 'Wholesale Partner']);
        $this->order(['customer_email' => 'wholesale@example.com', 'wallet_reseller_id' => $reseller->id]);
        $this->order(['customer_email' => 'retail@example.com']);

        $response = $this->getJson('/api/customer-analytics/customers?reseller_id='.$reseller->id)->assertOk();
        $customers = $response->json('data');

        $this->assertCount(1, $customers);
        $this->assertSame('wholesale@example.com', $customers[0]['customer_email']);
        $this->assertSame('Reseller: Wholesale Partner', $customers[0]['source_label']);
    }

    /** ADR-049 2026-10-15 addendum decision 4 — Laravel's paginator shape, as on Orders. */
    public function test_customers_is_paged_and_filtered_by_date_and_search(): void
    {
        $this->actingAsAdmin();
        $this->order(['customer_email' => 'ali@example.com', 'order_number' => 'KRS-a', 'paid_at' => '2026-09-10 03:00:00']);
        $this->order(['customer_email' => 'mali@example.com', 'order_number' => 'KRS-m', 'paid_at' => '2026-09-10 03:00:00']);
        $this->order(['customer_email' => 'ali@example.com', 'order_number' => 'KRS-old', 'paid_at' => '2026-08-01 03:00:00']);

        $this->getJson('/api/customer-analytics/customers?from=2026-09-01&to=2026-09-30&search=ali&per_page=25&page=1&sort=orders_count')
            ->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonPath('per_page', 25)
            ->assertJsonPath('current_page', 1)
            ->assertJsonPath('data.0.customer_email', 'ali@example.com')
            ->assertJsonPath('data.0.orders_count', 1);
    }

    /** Decision 10 — a bad filter is rejected, not silently dropped. */
    public function test_bad_filters_are_a_422(): void
    {
        $this->actingAsAdmin();

        foreach (['segment=gold', 'sort=email', 'per_page=1000', 'from=2026-13-01', 'from=2026-09-10&to=2026-09-01', 'search=a'] as $query) {
            $this->getJson('/api/customer-analytics/customers?'.$query)->assertUnprocessable();
        }
        $this->getJson('/api/customer-analytics/summary?segment=gold')->assertUnprocessable();
        $this->getJson('/api/customer-analytics/export?sort=email')->assertUnprocessable();
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
                'stats', 'profit_analysis', 'monthly_trend', 'top_packages', 'top_sources',
                'order_history' => ['data', 'total', 'current_page', 'last_page', 'per_page'],
            ])
            ->assertJson(['customer_email' => 'buyer@example.com']);
    }
}
