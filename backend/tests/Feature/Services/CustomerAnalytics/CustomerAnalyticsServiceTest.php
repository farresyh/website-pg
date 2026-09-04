<?php

namespace Tests\Feature\Services\CustomerAnalytics;

use App\Models\Affiliate;
use App\Models\Game;
use App\Models\Order;
use App\Models\Package;
use App\Models\PlatformSettings;
use App\Models\Supplier;
use App\Services\CustomerAnalytics\CustomerAnalyticsService;
use App\Services\CustomerAnalytics\CustomerSegment;
use App\Services\Ledger\LedgerService;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\PaymentStatus;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Locks in ADR-049's grilled/pinned decisions — these are the exact
 * behaviors a regression here would silently produce wrong segment
 * tags or wrong spend figures for.
 */
class CustomerAnalyticsServiceTest extends TestCase
{
    use RefreshDatabase;

    private CustomerAnalyticsService $analytics;

    protected function setUp(): void
    {
        parent::setUp();
        $this->analytics = new CustomerAnalyticsService;
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
            'payment_status' => PaymentStatus::Paid->value,
            'paid_at' => now(),
            'delivery_status' => DeliveryStatus::Delivered->value,
            'is_test' => false,
        ], $overrides));
    }

    public function test_customers_only_counts_paid_non_test_orders(): void
    {
        $this->order(['customer_email' => 'a@example.com']);
        $this->order(['customer_email' => 'b@example.com', 'payment_status' => PaymentStatus::Pending->value, 'paid_at' => null]);
        $this->order(['customer_email' => 'c@example.com', 'is_test' => true]);

        $rows = $this->analytics->customers(null, null, null, null);

        $this->assertCount(1, $rows);
        $this->assertSame('a@example.com', $rows[0]['customer_email']);
    }

    public function test_vip_segment_uses_configurable_threshold(): void
    {
        PlatformSettings::current()->update(['vip_spend_threshold_sen' => 500000]);

        $this->order(['customer_email' => 'vip@example.com', 'final_amount' => 500000, 'paid_at' => CarbonImmutable::now()->subDays(45)]);
        // 2 orders, both 45 days ago: not VIP, not Frequent (< 20), not
        // Dormant (< 60 days), not New (first order > 30 days ago), not
        // One-time (2 orders) — falls through to no segment.
        $this->order(['customer_email' => 'plain@example.com', 'final_amount' => 1000, 'order_number' => 'KRS-plain-1', 'paid_at' => CarbonImmutable::now()->subDays(45)]);
        $this->order(['customer_email' => 'plain@example.com', 'final_amount' => 1000, 'order_number' => 'KRS-plain-2', 'paid_at' => CarbonImmutable::now()->subDays(44)]);

        $rows = collect($this->analytics->customers(null, null, null, null))->keyBy('customer_email');

        $this->assertSame(CustomerSegment::Vip->value, $rows['vip@example.com']['segment']);
        $this->assertNull($rows['plain@example.com']['segment']);
    }

    public function test_frequent_segment_needs_twenty_or_more_orders(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->order(['customer_email' => 'frequent@example.com', 'order_number' => 'KRS-freq-'.$i, 'final_amount' => 100]);
        }
        for ($i = 0; $i < 19; $i++) {
            $this->order(['customer_email' => 'not-frequent@example.com', 'order_number' => 'KRS-notfreq-'.$i, 'final_amount' => 100]);
        }

        $rows = collect($this->analytics->customers(null, null, null, null))->keyBy('customer_email');

        $this->assertSame(CustomerSegment::Frequent->value, $rows['frequent@example.com']['segment']);
        $this->assertNotSame(CustomerSegment::Frequent->value, $rows['not-frequent@example.com']['segment']);
    }

    public function test_dormant_segment_needs_last_order_over_sixty_days_ago(): void
    {
        $this->order(['customer_email' => 'dormant@example.com', 'paid_at' => CarbonImmutable::now()->subDays(61)]);
        $this->order(['customer_email' => 'active@example.com', 'paid_at' => CarbonImmutable::now()->subDays(59)]);

        $rows = collect($this->analytics->customers(null, null, null, null))->keyBy('customer_email');

        $this->assertSame(CustomerSegment::Dormant->value, $rows['dormant@example.com']['segment']);
        $this->assertNotSame(CustomerSegment::Dormant->value, $rows['active@example.com']['segment']);
    }

    public function test_new_segment_needs_first_order_under_thirty_days_ago(): void
    {
        $this->order(['customer_email' => 'new@example.com', 'paid_at' => CarbonImmutable::now()->subDays(29)]);
        // 2 orders, first 31 days ago (not New) and last 30 days ago (not
        // Dormant either) — isolates the New boundary from One-time/null.
        $this->order(['customer_email' => 'old@example.com', 'order_number' => 'KRS-old-1', 'paid_at' => CarbonImmutable::now()->subDays(31)]);
        $this->order(['customer_email' => 'old@example.com', 'order_number' => 'KRS-old-2', 'paid_at' => CarbonImmutable::now()->subDays(30)]);

        $rows = collect($this->analytics->customers(null, null, null, null))->keyBy('customer_email');

        $this->assertSame(CustomerSegment::NewCustomer->value, $rows['new@example.com']['segment']);
        $this->assertNull($rows['old@example.com']['segment']);
    }

    public function test_one_time_segment_is_exactly_one_order(): void
    {
        $this->order(['customer_email' => 'onetime@example.com', 'paid_at' => CarbonImmutable::now()->subDays(45)]);
        $this->order(['customer_email' => 'twice@example.com', 'order_number' => 'KRS-twice-1', 'paid_at' => CarbonImmutable::now()->subDays(45)]);
        $this->order(['customer_email' => 'twice@example.com', 'order_number' => 'KRS-twice-2', 'paid_at' => CarbonImmutable::now()->subDays(44)]);

        $rows = collect($this->analytics->customers(null, null, null, null))->keyBy('customer_email');

        $this->assertSame(CustomerSegment::OneTime->value, $rows['onetime@example.com']['segment']);
        $this->assertNull($rows['twice@example.com']['segment']);
    }

    public function test_segment_precedence_vip_beats_dormant(): void
    {
        PlatformSettings::current()->update(['vip_spend_threshold_sen' => 500000]);

        // Spent enough to be VIP, but last order was 61 days ago (also Dormant).
        $this->order(['customer_email' => 'vip-dormant@example.com', 'final_amount' => 500000, 'paid_at' => CarbonImmutable::now()->subDays(61)]);

        $rows = collect($this->analytics->customers(null, null, null, null))->keyBy('customer_email');

        $this->assertSame(CustomerSegment::Vip->value, $rows['vip-dormant@example.com']['segment']);
    }

    public function test_date_range_narrows_displayed_figures_but_not_segment(): void
    {
        // Lifetime VIP via an old, big order — outside the filtered range.
        PlatformSettings::current()->update(['vip_spend_threshold_sen' => 500000]);
        $this->order(['customer_email' => 'vip@example.com', 'order_number' => 'KRS-old', 'final_amount' => 500000, 'paid_at' => CarbonImmutable::now()->subDays(90)]);
        // A small order inside the filtered range.
        $this->order(['customer_email' => 'vip@example.com', 'order_number' => 'KRS-recent', 'final_amount' => 1000, 'paid_at' => CarbonImmutable::now()->subDays(1)]);

        $from = CarbonImmutable::now()->subDays(7);
        $rows = collect($this->analytics->customers($from, null, null, null))->keyBy('customer_email');

        // Segment still reflects the full lifetime spend (VIP)...
        $this->assertSame(CustomerSegment::Vip->value, $rows['vip@example.com']['segment']);
        // ...but the displayed figures are scoped to the filtered range only.
        $this->assertSame(1, $rows['vip@example.com']['orders_count']);
        $this->assertSame(1000, $rows['vip@example.com']['total_spent']);
    }

    public function test_customer_with_no_orders_in_filtered_range_is_excluded(): void
    {
        $this->order(['customer_email' => 'old-only@example.com', 'paid_at' => CarbonImmutable::now()->subDays(90)]);

        $from = CarbonImmutable::now()->subDays(7);
        $rows = collect($this->analytics->customers($from, null, null, null));

        $this->assertCount(0, $rows);
    }

    public function test_segment_filter_only_returns_matching_customers(): void
    {
        PlatformSettings::current()->update(['vip_spend_threshold_sen' => 500000]);
        $this->order(['customer_email' => 'vip@example.com', 'final_amount' => 500000]);
        $this->order(['customer_email' => 'other@example.com', 'final_amount' => 100]);

        $rows = $this->analytics->customers(null, null, null, CustomerSegment::Vip);

        $this->assertCount(1, $rows);
        $this->assertSame('vip@example.com', $rows[0]['customer_email']);
    }

    public function test_affiliate_id_scopes_customers_to_that_affiliate(): void
    {
        $affiliateA = Affiliate::query()->create(['business_name' => 'Affiliate A', 'markup_pct' => 5]);
        $affiliateB = Affiliate::query()->create(['business_name' => 'Affiliate B', 'markup_pct' => 5]);

        $this->order(['customer_email' => 'shared@example.com', 'affiliate_id' => $affiliateA->id, 'final_amount' => 1000]);
        $this->order(['customer_email' => 'shared@example.com', 'affiliate_id' => $affiliateB->id, 'order_number' => 'KRS-r2', 'final_amount' => 2000]);

        $rows = collect($this->analytics->customers(null, null, $affiliateA->id, null))->keyBy('customer_email');

        $this->assertCount(1, $rows);
        $this->assertSame(1000, $rows['shared@example.com']['total_spent']);
    }

    public function test_stats_reports_total_customers_avg_order_value_and_top_spender(): void
    {
        $this->order(['customer_email' => 'big@example.com', 'final_amount' => 5000]);
        $this->order(['customer_email' => 'small@example.com', 'final_amount' => 1000]);

        $stats = $this->analytics->stats(null, null, null);

        $this->assertSame(2, $stats['total_customers']);
        $this->assertSame(3000, $stats['avg_order_value']);
        $this->assertSame('big@example.com', $stats['top_spender']['customer_email']);
        $this->assertSame(5000, $stats['top_spender']['total_spent']);
    }

    public function test_stats_repeat_rate_is_based_on_lifetime_order_count(): void
    {
        $this->order(['customer_email' => 'repeat@example.com', 'order_number' => 'KRS-1', 'final_amount' => 1000]);
        $this->order(['customer_email' => 'repeat@example.com', 'order_number' => 'KRS-2', 'final_amount' => 1000]);
        $this->order(['customer_email' => 'single@example.com', 'final_amount' => 1000]);

        $stats = $this->analytics->stats(null, null, null);

        // 1 of 2 customers (50%) has more than one lifetime order.
        $this->assertSame(50.0, $stats['repeat_rate_pct']);
    }

    public function test_customer_detail_returns_null_for_unknown_email(): void
    {
        $this->assertNull($this->analytics->customerDetail('nobody@example.com'));
    }

    public function test_customer_detail_reports_basic_identity_and_stats(): void
    {
        $this->order([
            'customer_email' => 'detail@example.com',
            'customer_name' => 'Detail Person',
            'customer_phone' => '0123456789',
            'final_amount' => 1000,
            'paid_at' => CarbonImmutable::now()->subDays(10),
        ]);
        $this->order([
            'customer_email' => 'detail@example.com',
            'order_number' => 'KRS-detail-2',
            'final_amount' => 2000,
            'paid_at' => CarbonImmutable::now()->subDays(5),
        ]);

        $detail = $this->analytics->customerDetail('detail@example.com');

        $this->assertSame('detail@example.com', $detail['customer_email']);
        $this->assertSame('Detail Person', $detail['customer_name']);
        $this->assertSame('0123456789', $detail['customer_phone']);
        $this->assertSame(2, $detail['stats']['total_orders']);
        $this->assertSame(3000, $detail['stats']['total_spent']);
        $this->assertSame(1500, $detail['stats']['avg_order_value']);
    }

    /**
     * ADR-050 decision 2 — the Profit Analysis panel's 5 lines only
     * count delivered orders, even though stats()/total_orders above
     * stays Paid-scoped and would include the undelivered one too.
     */
    public function test_profit_analysis_only_counts_delivered_orders(): void
    {
        $delivered = $this->order([
            'customer_email' => 'mixed@example.com',
            'final_amount' => 1100,
            'cost_price' => 900,
            'transaction_fee' => 100,
            'platform_profit' => 100,
            'affiliate_profit' => 20,
            'delivery_status' => DeliveryStatus::Delivered->value,
        ]);
        (new LedgerService)->credit('platform', null, 100, 'order_profit', 'order', $delivered->id);
        (new LedgerService)->credit('affiliate', null, 20, 'order_profit', 'order', $delivered->id);

        // Paid but never delivered — counts toward stats()/total_spent,
        // must NOT count toward any Profit Analysis line.
        $this->order([
            'customer_email' => 'mixed@example.com',
            'order_number' => 'KRS-undelivered',
            'final_amount' => 5000,
            'cost_price' => 4000,
            'transaction_fee' => 500,
            'delivery_status' => DeliveryStatus::Pending->value,
        ]);

        $detail = $this->analytics->customerDetail('mixed@example.com');

        $this->assertSame(2, $detail['stats']['total_orders']);
        $this->assertSame(6100, $detail['stats']['total_spent']);

        $this->assertSame(1100, $detail['profit_analysis']['total_revenue']);
        $this->assertSame(900, $detail['profit_analysis']['supplier_cost']);
        $this->assertSame(100, $detail['profit_analysis']['transaction_fees']);
        $this->assertSame(20, $detail['profit_analysis']['affiliate_commission']);
        $this->assertSame(100, $detail['profit_analysis']['system_profit']);
    }

    public function test_order_history_shows_dash_for_undelivered_profit(): void
    {
        $delivered = $this->order([
            'customer_email' => 'history@example.com',
            'order_number' => 'KRS-delivered',
            'final_amount' => 1000,
            'platform_profit' => 80,
            'affiliate_profit' => 10,
            'delivery_status' => DeliveryStatus::Delivered->value,
        ]);
        (new LedgerService)->credit('platform', null, 80, 'order_profit', 'order', $delivered->id);
        (new LedgerService)->credit('affiliate', null, 10, 'order_profit', 'order', $delivered->id);

        $this->order([
            'customer_email' => 'history@example.com',
            'order_number' => 'KRS-pending',
            'final_amount' => 2000,
            'delivery_status' => DeliveryStatus::Pending->value,
        ]);

        $detail = $this->analytics->customerDetail('history@example.com');
        $rows = collect($detail['order_history'])->keyBy('order_number');

        $this->assertSame(80, $rows['KRS-delivered']['system_profit']);
        $this->assertSame(10, $rows['KRS-delivered']['affiliate_profit']);
        // Admin's Order History → /admin/orders?order={id} deep link
        // needs the real numeric id, not just order_number.
        $this->assertSame($delivered->id, $rows['KRS-delivered']['id']);
        $this->assertNull($rows['KRS-pending']['system_profit']);
        $this->assertNull($rows['KRS-pending']['affiliate_profit']);
    }

    public function test_top_packages_and_affiliates_rank_by_spend(): void
    {
        $affiliateA = Affiliate::query()->create(['business_name' => 'Affiliate A', 'markup_pct' => 5]);
        $affiliateB = Affiliate::query()->create(['business_name' => 'Affiliate B', 'markup_pct' => 5]);
        $supplier = Supplier::query()->create(['name' => 'Gamevion', 'slug' => 'gamevion', 'api_config' => [], 'currency' => 'MYR']);
        $game = Game::query()->create(['name' => 'Mobile Legends', 'slug' => 'mobile-legends']);
        $packageA = Package::query()->create(['game_id' => $game->id, 'name' => '86 Diamonds', 'cost_price' => 400, 'standard_selling_price' => 450, 'supplier_id' => $supplier->id, 'supplier_package_ref' => 'A']);
        $packageB = Package::query()->create(['game_id' => $game->id, 'name' => '172 Diamonds', 'cost_price' => 800, 'standard_selling_price' => 850, 'supplier_id' => $supplier->id, 'supplier_package_ref' => 'B']);

        $this->order(['customer_email' => 'ranker@example.com', 'order_number' => 'KRS-a', 'package_id' => $packageA->id, 'affiliate_id' => $affiliateA->id, 'final_amount' => 500]);
        $this->order(['customer_email' => 'ranker@example.com', 'order_number' => 'KRS-b', 'package_id' => $packageB->id, 'affiliate_id' => $affiliateB->id, 'final_amount' => 2000]);

        $detail = $this->analytics->customerDetail('ranker@example.com');

        $this->assertSame('172 Diamonds', $detail['top_packages'][0]['name']);
        $this->assertSame(2000, $detail['top_packages'][0]['total_spent']);
        $this->assertSame('Affiliate B', $detail['top_affiliates'][0]['name']);
        $this->assertSame(2000, $detail['top_affiliates'][0]['total_spent']);
    }

    public function test_monthly_trend_buckets_by_paid_month(): void
    {
        $this->order(['customer_email' => 'trend@example.com', 'order_number' => 'KRS-1', 'final_amount' => 1000, 'paid_at' => CarbonImmutable::now()->subMonths(1)->startOfMonth()->addDays(5)]);
        $this->order(['customer_email' => 'trend@example.com', 'order_number' => 'KRS-2', 'final_amount' => 500, 'paid_at' => CarbonImmutable::now()->subMonths(1)->startOfMonth()->addDays(10)]);
        $this->order(['customer_email' => 'trend@example.com', 'order_number' => 'KRS-3', 'final_amount' => 2000, 'paid_at' => CarbonImmutable::now()]);

        $detail = $this->analytics->customerDetail('trend@example.com');
        $trend = collect($detail['monthly_trend'])->keyBy('month');

        $lastMonthKey = CarbonImmutable::now()->subMonths(1)->format('Y-m');
        $thisMonthKey = CarbonImmutable::now()->format('Y-m');

        $this->assertSame(1500, $trend[$lastMonthKey]['total_spent']);
        $this->assertSame(2000, $trend[$thisMonthKey]['total_spent']);
    }
}
