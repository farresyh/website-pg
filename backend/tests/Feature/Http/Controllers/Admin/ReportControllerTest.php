<?php

namespace Tests\Feature\Http\Controllers\Admin;

use App\Models\AdminUser;
use App\Models\Order;
use App\Models\Reseller;
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

    /** ADR-104 R12–R14 — Compare period: a second call to the same summary, plus deltas. */
    public function test_summary_compares_with_the_previous_period_when_asked(): void
    {
        $this->order(['paid_at' => '2026-10-05 04:00:00', 'final_amount' => 1500]);
        $this->order(['paid_at' => '2026-09-28 04:00:00', 'final_amount' => 1000]);
        $this->actingAsAdmin();

        $response = $this->getJson('/api/reports/summary?from=2026-10-02&to=2026-10-08&compare=previous');

        $response->assertOk()->assertJson([
            'total_sales' => 1500,
            'compare' => [
                'previous_range' => ['from' => '2026-09-25', 'to' => '2026-10-01'],
                'previous' => ['total_sales' => 1000, 'orders_count' => 1],
                'changes' => [
                    'total_sales' => ['pct' => 50, 'direction' => 'up'],
                    'orders_count' => ['pct' => 0, 'direction' => 'flat'],
                    'margin_pct' => ['points' => 0, 'direction' => 'flat'],
                ],
            ],
        ]);
    }

    public function test_summary_month_to_date_compares_with_the_same_days_of_last_month(): void
    {
        $this->actingAsAdmin();

        $this->getJson('/api/reports/summary?from=2026-10-01&to=2026-10-08&compare=month_to_date')
            ->assertOk()
            ->assertJsonPath('compare.previous_range', ['from' => '2026-09-01', 'to' => '2026-09-08']);
    }

    /** R12 — All time has no previous period. */
    public function test_summary_has_no_comparison_for_all_time(): void
    {
        $this->actingAsAdmin();

        $this->getJson('/api/reports/summary?compare=previous')->assertOk()->assertJsonPath('compare', null);
    }

    public function test_summary_rejects_an_unknown_compare_mode(): void
    {
        $this->actingAsAdmin();

        $this->getJson('/api/reports/summary?from=2026-10-01&to=2026-10-08&compare=yoy')->assertUnprocessable();
    }

    /** ADR-104 R13 Phase 1 — Compare reaches every tab's KPI cards through the same seam as the summary. */
    public function test_membership_breakdown_compares_with_the_previous_period_when_asked(): void
    {
        $this->order(['paid_at' => '2026-10-05 04:00:00', 'final_amount' => 1500]);
        $this->order(['paid_at' => '2026-09-28 04:00:00', 'final_amount' => 1000]);
        $this->actingAsAdmin();

        $this->getJson('/api/reports/membership-breakdown?from=2026-10-02&to=2026-10-08&compare=previous')
            ->assertOk()
            ->assertJsonPath('standard_sales', 1500)
            ->assertJsonPath('compare.previous_range', ['from' => '2026-09-25', 'to' => '2026-10-01'])
            ->assertJsonPath('compare.previous.standard_sales', 1000)
            ->assertJsonPath('compare.changes.standard_sales', ['pct' => 50, 'direction' => 'up'])
            ->assertJsonPath('compare.changes.member_sales', ['pct' => null, 'direction' => 'flat']);
    }

    public function test_channel_breakdown_compares_each_channel_with_the_previous_period(): void
    {
        $this->order(['paid_at' => '2026-10-05 04:00:00', 'final_amount' => 1500]);
        $this->order(['paid_at' => '2026-09-28 04:00:00', 'final_amount' => 1000]);
        $this->actingAsAdmin();

        $this->getJson('/api/reports/breakdown/channels?from=2026-10-02&to=2026-10-08&compare=previous')
            ->assertOk()
            ->assertJsonPath('compare.changes.own_brand', ['pct' => 50, 'direction' => 'up'])
            ->assertJsonPath('compare.changes.reseller_wallet', ['pct' => null, 'direction' => 'flat']);
    }

    public function test_breakdown_compare_is_null_for_all_time_and_absent_when_not_asked(): void
    {
        $this->actingAsAdmin();

        $this->getJson('/api/reports/membership-breakdown?compare=previous')->assertOk()->assertJsonPath('compare', null);
        $this->getJson('/api/reports/breakdown/channels?compare=previous')->assertOk()->assertJsonPath('compare', null);
        $this->getJson('/api/reports/membership-breakdown?from=2026-10-01&to=2026-10-08')->assertOk()->assertJsonMissingPath('compare');
        $this->getJson('/api/reports/breakdown/channels?from=2026-10-01&to=2026-10-08&compare=yoy')->assertUnprocessable();
    }

    /** ADR-104 R17 — "All time" spans the first paid order to today; the old 30-day fallback is gone. */
    public function test_trend_for_all_time_spans_the_first_paid_order_to_today(): void
    {
        $this->order(['paid_at' => now()->subDays(44)]);
        $this->actingAsAdmin();

        $response = $this->getJson('/api/reports/trend');

        $response->assertOk();
        $this->assertCount(45, $response->json('days'));
    }

    public function test_accounting_bridge_endpoint_and_hidden_under_an_affiliate_filter(): void
    {
        $this->order();
        $this->actingAsAdmin();

        $this->getJson('/api/reports/accounting-bridge')->assertOk()
            ->assertJsonPath('bridge.recognised_revenue', 1000)
            ->assertJsonPath('bridge.unexplained_difference', 0);
        $this->getJson('/api/reports/accounting-bridge?affiliate_id='.$this->primaryAffiliate()->id)->assertOk()
            ->assertJsonPath('bridge', null);
    }

    public function test_failed_compensated_endpoint_returns_the_section_and_store_credit(): void
    {
        $this->order(['delivery_status' => DeliveryStatus::Failed->value]);
        $this->actingAsAdmin();

        $this->getJson('/api/reports/failed-compensated')->assertOk()
            ->assertJsonPath('failed_count', 1)
            ->assertJsonPath('outstanding_store_credit', 0);
    }

    public function test_channel_and_delivery_by_game_endpoints_return_rows(): void
    {
        $this->order();
        $this->actingAsAdmin();

        $this->getJson('/api/reports/breakdown/channels')->assertOk()->assertJsonCount(3, 'channels');
        $this->getJson('/api/reports/breakdown/delivery-by-game')->assertOk()->assertJsonCount(1, 'games');
    }

    public function test_trend_follows_the_same_from_to_filter_as_every_other_tab(): void
    {
        $this->actingAsAdmin();

        $response = $this->getJson('/api/reports/trend?from=2026-08-01&to=2026-08-05');

        $response->assertOk();
        $this->assertCount(5, $response->json('days'));
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

    public function test_affiliate_breakdown_returns_rows(): void
    {
        $this->order();
        $this->actingAsAdmin();

        $this->getJson('/api/reports/breakdown/affiliates')->assertOk()->assertJsonStructure(['affiliates']);
    }

    public function test_reseller_breakdown_returns_rows(): void
    {
        $reseller = Reseller::query()->create(['business_name' => 'Acme Reseller', 'is_active' => true]);
        $this->order(['wallet_reseller_id' => $reseller->id]);
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
