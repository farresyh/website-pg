<?php

namespace Tests\Feature\Services\Report;

use App\Models\Affiliate;
use App\Models\Game;
use App\Models\Order;
use App\Services\Ledger\LedgerService;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\PaymentStatus;
use App\Services\Report\ReportService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Locks in the definitions grilled/pinned 2026-08-26 (see
 * ReportService's own doc comment) — these are the exact behaviors a
 * regression here would silently produce wrong business numbers for.
 */
class ReportServiceTest extends TestCase
{
    use RefreshDatabase;

    private ReportService $reports;

    protected function setUp(): void
    {
        parent::setUp();
        $this->reports = new ReportService;
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

    public function test_summary_only_counts_paid_orders(): void
    {
        $this->order(); // Paid
        $this->order(['payment_status' => PaymentStatus::Pending->value, 'paid_at' => null, 'final_amount' => 5000]);

        $summary = $this->reports->summary(null, null, null);

        $this->assertSame(1, $summary['orders_count']);
        $this->assertSame(1100, $summary['total_sales']);
    }

    public function test_summary_excludes_test_orders(): void
    {
        $this->order();
        $this->order(['is_test' => true, 'final_amount' => 99999]);

        $summary = $this->reports->summary(null, null, null);

        $this->assertSame(1, $summary['orders_count']);
        $this->assertSame(1100, $summary['total_sales']);
    }

    public function test_profit_is_ledger_sourced_not_the_cached_column(): void
    {
        $delivered = $this->order(['platform_profit' => 100, 'affiliate_profit' => 20]);
        (new LedgerService)->credit('platform', null, $delivered->platform_profit, 'order_profit', 'order', $delivered->id);
        (new LedgerService)->credit('affiliate', null, $delivered->affiliate_profit, 'order_profit', 'order', $delivered->id);

        // Paid, but delivery failed and never became a ledger-recognized
        // profit — Order.platform_profit is still nonzero (stamped at
        // checkout), but must NOT count here.
        $this->order([
            'platform_profit' => 500,
            'affiliate_profit' => 80,
            'delivery_status' => DeliveryStatus::Failed->value,
        ]);

        $summary = $this->reports->summary(null, null, null);

        $this->assertSame(100, $summary['platform_profit']);
        $this->assertSame(20, $summary['affiliate_profit']);
        $this->assertSame(2, $summary['orders_count']); // both still count as "orders"/"sales"
        $this->assertSame(2200, $summary['total_sales']);
    }

    public function test_margin_pct_uses_platform_profit_over_sales(): void
    {
        $order = $this->order(['final_amount' => 1000, 'platform_profit' => 100]);
        (new LedgerService)->credit('platform', null, 100, 'order_profit', 'order', $order->id);

        $summary = $this->reports->summary(null, null, null);

        $this->assertSame(10.0, $summary['margin_pct']);
    }

    public function test_affiliate_filter_scopes_sales_and_keeps_profits_separate(): void
    {
        $affiliateA = Affiliate::query()->create(['business_name' => 'Affiliate A', 'markup_pct' => 5]);
        $affiliateB = Affiliate::query()->create(['business_name' => 'Affiliate B', 'markup_pct' => 5]);

        $orderA = $this->order(['affiliate_id' => $affiliateA->id, 'final_amount' => 1000, 'platform_profit' => 50, 'affiliate_profit' => 30]);
        (new LedgerService)->credit('platform', null, 50, 'order_profit', 'order', $orderA->id);
        (new LedgerService)->credit('affiliate', $affiliateA->id, 30, 'order_profit', 'order', $orderA->id);

        $orderB = $this->order(['affiliate_id' => $affiliateB->id, 'final_amount' => 2000, 'platform_profit' => 90, 'affiliate_profit' => 60]);
        (new LedgerService)->credit('platform', null, 90, 'order_profit', 'order', $orderB->id);
        (new LedgerService)->credit('affiliate', $affiliateB->id, 60, 'order_profit', 'order', $orderB->id);

        $summaryAll = $this->reports->summary(null, null, null);
        $summaryA = $this->reports->summary(null, null, $affiliateA->id);

        $this->assertSame(3000, $summaryAll['total_sales']);
        $this->assertSame(140, $summaryAll['platform_profit']);
        $this->assertSame(90, $summaryAll['affiliate_profit']);

        $this->assertSame(1000, $summaryA['total_sales']);
        $this->assertSame(50, $summaryA['platform_profit']);
        $this->assertSame(30, $summaryA['affiliate_profit']);
    }

    public function test_latest_order_is_most_recent_paid_by_paid_at(): void
    {
        $this->order(['order_number' => 'OLDER', 'paid_at' => now()->subDay()]);
        $this->order(['order_number' => 'NEWER', 'paid_at' => now()]);
        $this->order(['order_number' => 'PENDING-NEWEST', 'payment_status' => PaymentStatus::Pending->value, 'paid_at' => null]);

        $summary = $this->reports->summary(null, null, null);

        $this->assertSame('NEWER', $summary['latest_order']['order_number']);
    }

    public function test_daily_trend_attributes_todays_late_night_order_to_today_kl(): void
    {
        $todayKl = CarbonImmutable::now(ReportService::TIMEZONE)->startOfDay();
        $lateNight = $todayKl->addHours(23)->addMinutes(30);

        $this->order(['final_amount' => 1500, 'paid_at' => $lateNight->setTimezone('UTC')]);

        $days = collect($this->reports->dailyTrend(7, null));
        $todayRow = $days->firstWhere('date', $todayKl->toDateString());

        $this->assertSame(1500, $todayRow['sales']);
    }

    public function test_export_rows_include_recognized_profit_and_affiliate_name(): void
    {
        $affiliate = Affiliate::query()->create(['business_name' => 'Affiliate A', 'markup_pct' => 5]);
        $order = $this->order(['affiliate_id' => $affiliate->id, 'final_amount' => 1000, 'platform_profit' => 50]);
        (new LedgerService)->credit('platform', null, 50, 'order_profit', 'order', $order->id);

        $rows = $this->reports->exportRows(null, null, null)->all();

        $this->assertCount(1, $rows);
        $this->assertSame('Affiliate A', $rows[0]['affiliate_name']);
        $this->assertSame(50, $rows[0]['platform_profit']);
    }

    public function test_date_range_for_year_month_resolves_kl_month_boundaries(): void
    {
        [$from, $to] = $this->reports->dateRangeForYearMonth(2026, 8);

        $this->assertSame('2026-07-31T16:00:00+00:00', $from->toIso8601String());
        $this->assertSame('2026-08-31T16:00:00+00:00', $to->toIso8601String());
    }

    public function test_summary_computes_avg_order_value(): void
    {
        $this->order(['final_amount' => 1000]);
        $this->order(['final_amount' => 2000]);

        $summary = $this->reports->summary(null, null, null);

        $this->assertSame(1500, $summary['avg_order_value']);
    }

    public function test_game_breakdown_groups_by_game_and_is_ledger_sourced(): void
    {
        $gameA = Game::query()->create(['name' => 'Mobile Legends', 'slug' => 'mobile-legends']);
        $gameB = Game::query()->create(['name' => 'PUBG Mobile', 'slug' => 'pubg-mobile']);

        $a1 = $this->order(['game_id' => $gameA->id, 'final_amount' => 1000, 'platform_profit' => 100]);
        (new LedgerService)->credit('platform', null, 100, 'order_profit', 'order', $a1->id);
        $this->order(['game_id' => $gameA->id, 'final_amount' => 500]); // delivered but no ledger credit set up below

        $b1 = $this->order(['game_id' => $gameB->id, 'final_amount' => 3000, 'platform_profit' => 300]);
        (new LedgerService)->credit('platform', null, 300, 'order_profit', 'order', $b1->id);

        $rows = $this->reports->gameBreakdown(null, null, null);

        $this->assertSame('PUBG Mobile', $rows[0]['game_name']); // highest sales first
        $this->assertSame(3000, $rows[0]['sales']);
        $this->assertSame(300, $rows[0]['platform_profit']);
        $this->assertSame('Mobile Legends', $rows[1]['game_name']);
        $this->assertSame(1500, $rows[1]['sales']);
        $this->assertSame(2, $rows[1]['orders_count']);
        $this->assertSame(100, $rows[1]['platform_profit']); // second order's profit never credited
    }

    public function test_top_games_respects_limit(): void
    {
        $gameA = Game::query()->create(['name' => 'A', 'slug' => 'a']);
        $gameB = Game::query()->create(['name' => 'B', 'slug' => 'b']);
        $gameC = Game::query()->create(['name' => 'C', 'slug' => 'c']);
        $this->order(['game_id' => $gameA->id, 'final_amount' => 300]);
        $this->order(['game_id' => $gameB->id, 'final_amount' => 200]);
        $this->order(['game_id' => $gameC->id, 'final_amount' => 100]);

        $rows = $this->reports->gameBreakdown(null, null, null, limit: 2);

        $this->assertCount(2, $rows);
        $this->assertSame('A', $rows[0]['game_name']);
    }

    public function test_payment_method_breakdown_computes_share_of_sales(): void
    {
        $this->order(['payment_method' => 'fpx', 'final_amount' => 3000]);
        $this->order(['payment_method' => 'card', 'final_amount' => 1000]);

        $rows = $this->reports->paymentMethodBreakdown(null, null, null);

        $this->assertSame('fpx', $rows[0]['payment_method']);
        $this->assertSame(75.0, $rows[0]['pct_of_sales']);
        $this->assertSame('card', $rows[1]['payment_method']);
        $this->assertSame(25.0, $rows[1]['pct_of_sales']);
    }

    public function test_affiliate_breakdown_groups_by_affiliate(): void
    {
        $affiliateA = Affiliate::query()->create(['business_name' => 'Affiliate A', 'markup_pct' => 5]);
        $orderA = $this->order(['affiliate_id' => $affiliateA->id, 'final_amount' => 1000, 'platform_profit' => 40, 'affiliate_profit' => 20]);
        (new LedgerService)->credit('platform', null, 40, 'order_profit', 'order', $orderA->id);
        (new LedgerService)->credit('affiliate', $affiliateA->id, 20, 'order_profit', 'order', $orderA->id);

        $rows = $this->reports->affiliateBreakdown(null, null, null);

        $this->assertSame('Affiliate A', $rows[0]['affiliate_name']);
        $this->assertSame(1000, $rows[0]['sales']);
        $this->assertSame(40, $rows[0]['platform_profit']);
        $this->assertSame(20, $rows[0]['affiliate_profit']);
    }

    public function test_order_status_funnel_counts_by_delivery_status(): void
    {
        $this->order(['delivery_status' => DeliveryStatus::Delivered->value]);
        $this->order(['delivery_status' => DeliveryStatus::Delivered->value]);
        $this->order(['delivery_status' => DeliveryStatus::Failed->value]);

        $funnel = $this->reports->orderStatusFunnel(null, null, null);

        $this->assertSame(3, $funnel['total']);
        $this->assertSame(2, $funnel['by_status']['delivered']);
        $this->assertSame(1, $funnel['by_status']['failed']);
        $this->assertSame(0, $funnel['by_status']['pending']);
        $this->assertSame(66.67, $funnel['success_rate_pct']);
    }

    public function test_daily_breakdown_never_zero_fills_and_sorts_newest_first(): void
    {
        $this->order(['final_amount' => 1000, 'paid_at' => now()->subDays(5)]);
        $this->order(['final_amount' => 2000, 'transaction_fee' => 50, 'paid_at' => now()]);

        $rows = $this->reports->dailyBreakdown(null, null, null);

        $this->assertCount(2, $rows); // no zero-filled gap days in between
        $this->assertSame(2000, $rows[0]['sales']); // newest first
        $this->assertSame(1, $rows[0]['orders_count']);
        $this->assertSame(50, $rows[0]['transaction_fees']);
        $this->assertSame(2000, $rows[0]['avg_order_value']);
    }

    /**
     * ADR-086 PR-1 — the grouped-SQL rewrite's #1 correctness risk: every
     * delivered order carries TWO `order_profit` ledger rows (platform +
     * affiliate split). A naive JOIN-then-GROUP-BY that also sums
     * `final_amount` in the same row set double-counts sales, since the
     * order row is matched twice. These lock in that sales is summed once
     * per order regardless of how many ledger rows it has, across every
     * grouped breakdown dimension.
     */
    public function test_game_breakdown_sales_not_doubled_by_dual_ledger_rows(): void
    {
        $game = Game::query()->create(['name' => 'Mobile Legends', 'slug' => 'mobile-legends']);
        $affiliate = Affiliate::query()->create(['business_name' => 'Affiliate A', 'markup_pct' => 5]);

        $order = $this->order([
            'game_id' => $game->id,
            'affiliate_id' => $affiliate->id,
            'final_amount' => 1100,
            'platform_profit' => 100,
            'affiliate_profit' => 20,
        ]);
        (new LedgerService)->credit('platform', null, 100, 'order_profit', 'order', $order->id);
        (new LedgerService)->credit('affiliate', $affiliate->id, 20, 'order_profit', 'order', $order->id);

        $rows = $this->reports->gameBreakdown(null, null, null);

        $this->assertCount(1, $rows);
        $this->assertSame(1100, $rows[0]['sales']); // not 2200
        $this->assertSame(1, $rows[0]['orders_count']);
        $this->assertSame(100, $rows[0]['platform_profit']);
        $this->assertSame(20, $rows[0]['affiliate_profit']);
    }

    public function test_affiliate_breakdown_sales_not_doubled_by_dual_ledger_rows(): void
    {
        $affiliate = Affiliate::query()->create(['business_name' => 'Affiliate A', 'markup_pct' => 5]);

        $order = $this->order([
            'affiliate_id' => $affiliate->id,
            'final_amount' => 1100,
            'platform_profit' => 100,
            'affiliate_profit' => 20,
        ]);
        (new LedgerService)->credit('platform', null, 100, 'order_profit', 'order', $order->id);
        (new LedgerService)->credit('affiliate', $affiliate->id, 20, 'order_profit', 'order', $order->id);

        $rows = $this->reports->affiliateBreakdown(null, null, null);

        $this->assertCount(1, $rows);
        $this->assertSame(1100, $rows[0]['sales']);
        $this->assertSame(1, $rows[0]['orders_count']);
        $this->assertSame(100, $rows[0]['platform_profit']);
        $this->assertSame(20, $rows[0]['affiliate_profit']);
    }

    public function test_daily_breakdown_sales_not_doubled_by_dual_ledger_rows(): void
    {
        $affiliate = Affiliate::query()->create(['business_name' => 'Affiliate A', 'markup_pct' => 5]);

        $order = $this->order([
            'affiliate_id' => $affiliate->id,
            'final_amount' => 1100,
            'platform_profit' => 100,
            'affiliate_profit' => 20,
        ]);
        (new LedgerService)->credit('platform', null, 100, 'order_profit', 'order', $order->id);
        (new LedgerService)->credit('affiliate', $affiliate->id, 20, 'order_profit', 'order', $order->id);

        $rows = $this->reports->dailyBreakdown(null, null, null);

        $this->assertCount(1, $rows);
        $this->assertSame(1100, $rows[0]['sales']);
        $this->assertSame(1, $rows[0]['orders_count']);
        $this->assertSame(100, $rows[0]['platform_profit']);
        $this->assertSame(20, $rows[0]['affiliate_profit']);
    }

    public function test_daily_trend_sales_not_doubled_by_dual_ledger_rows(): void
    {
        $affiliate = Affiliate::query()->create(['business_name' => 'Affiliate A', 'markup_pct' => 5]);

        $order = $this->order([
            'affiliate_id' => $affiliate->id,
            'final_amount' => 1100,
            'platform_profit' => 100,
            'affiliate_profit' => 20,
        ]);
        (new LedgerService)->credit('platform', null, 100, 'order_profit', 'order', $order->id);
        (new LedgerService)->credit('affiliate', $affiliate->id, 20, 'order_profit', 'order', $order->id);

        $todayKl = CarbonImmutable::now(ReportService::TIMEZONE)->startOfDay()->toDateString();
        $rows = collect($this->reports->dailyTrend(7, null));
        $today = $rows->firstWhere('date', $todayKl);

        $this->assertSame(1100, $today['sales']);
        $this->assertSame(100, $today['platform_profit']);
        $this->assertSame(20, $today['affiliate_profit']);
    }
}
