<?php

namespace Tests\Feature\Services\Dashboard;

use App\Models\Game;
use App\Models\Order;
use App\Models\Supplier;
use App\Models\Voucher;
use App\Services\CircuitBreaker\CircuitBreaker;
use App\Services\Dashboard\DashboardService;
use App\Services\Ledger\LedgerService;
use App\Services\OpenWa\OpenWaSessionStatus;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\PaymentStatus;
use App\Services\Report\ReportService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Locks in ADR-045's 27 grilled/pinned decisions — a regression here
 * would silently show the founder a wrong number on the one screen
 * meant to be a quick, trustworthy health/business snapshot.
 */
class DashboardServiceTest extends TestCase
{
    use RefreshDatabase;

    private DashboardService $dashboard;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dashboard = new DashboardService(new ReportService, new OpenWaSessionStatus);
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

    // --- summary() / DASH-1 ---------------------------------------------

    public function test_summary_sales_orders_match_report_service_definitions(): void
    {
        $order = $this->order(['final_amount' => 5000]);
        (new LedgerService)->credit('platform', null, $order->platform_profit, 'order_profit', 'order', $order->id);

        $summary = $this->dashboard->summary();

        $this->assertSame(5000, $summary['sales_today']['value']);
        $this->assertSame(1, $summary['orders_today']['value']);
        $this->assertSame(100, $summary['profit_today']['value']);
    }

    public function test_summary_excludes_orders_paid_yesterday(): void
    {
        $this->order(['final_amount' => 5000, 'paid_at' => now()->subDay()]);

        $summary = $this->dashboard->summary();

        $this->assertSame(0, $summary['sales_today']['value']);
    }

    public function test_summary_comparison_is_percentage_change_vs_yesterday(): void
    {
        $this->order(['final_amount' => 1000]);
        $this->order(['final_amount' => 1000, 'paid_at' => now()->subDay()]);
        $this->order(['final_amount' => 1000, 'paid_at' => now()->subDay()]);

        $summary = $this->dashboard->summary();

        // Today: 1 order, Yesterday: 2 orders -> -50%
        $this->assertSame(-50.0, -1 * $summary['orders_today']['comparison']['pct'] * ($summary['orders_today']['comparison']['direction'] === 'down' ? 1 : -1));
        $this->assertSame('down', $summary['orders_today']['comparison']['direction']);
        $this->assertSame(50.0, $summary['orders_today']['comparison']['pct']);
    }

    public function test_summary_comparison_is_new_when_yesterday_was_zero(): void
    {
        $this->order(['final_amount' => 1000]);

        $summary = $this->dashboard->summary();

        $this->assertNull($summary['sales_today']['comparison']['pct']);
        $this->assertSame('new', $summary['sales_today']['comparison']['direction']);
    }

    public function test_summary_comparison_is_flat_when_both_days_are_zero(): void
    {
        $summary = $this->dashboard->summary();

        $this->assertSame('flat', $summary['orders_today']['comparison']['direction']);
    }

    public function test_vouchers_issued_today_counts_only_path_b_order_triggered_vouchers(): void
    {
        $order = $this->order();

        Voucher::query()->create([
            'order_id' => $order->id,
            'code' => 'PATHB-'.uniqid(),
            'customer_email' => 'buyer@example.com',
            'amount' => 500,
            'remaining' => 500,
            'status' => 'active',
            'reason' => 'Order delivery failed',
        ]);

        Voucher::query()->create([
            'order_id' => null,
            'code' => 'PATHA-'.uniqid(),
            'customer_email' => 'promo@example.com',
            'amount' => 2000,
            'remaining' => 2000,
            'status' => 'active',
            'reason' => 'Goodwill promo',
        ]);

        $summary = $this->dashboard->summary();

        $this->assertSame(1, $summary['vouchers_issued_today']['value']);
        $this->assertSame(500, $summary['vouchers_issued_today']['amount_sen']);
    }

    public function test_vouchers_issued_today_excludes_vouchers_on_test_orders(): void
    {
        $testOrder = $this->order(['is_test' => true]);

        Voucher::query()->create([
            'order_id' => $testOrder->id,
            'code' => 'TESTVCH-'.uniqid(),
            'customer_email' => 'buyer@example.com',
            'amount' => 500,
            'remaining' => 500,
            'status' => 'active',
            'reason' => 'Sandbox test voucher',
        ]);

        $summary = $this->dashboard->summary();

        $this->assertSame(0, $summary['vouchers_issued_today']['value']);
    }

    // --- health() / DASH-2 -----------------------------------------------

    public function test_health_reads_circuit_state_derived_not_live(): void
    {
        $slug = 'test-supplier-'.uniqid();
        Supplier::query()->create(['name' => 'Test Supplier', 'slug' => $slug, 'api_config' => [], 'currency' => 'MYR', 'balance' => 15000]);

        $breaker = new CircuitBreaker($slug, failureThreshold: 3, cooldownSeconds: 60);
        $breaker->recordFailure();
        $breaker->recordFailure();
        $breaker->recordFailure();

        $health = $this->dashboard->health();

        $supplierRow = collect($health['suppliers'])->firstWhere('slug', $slug);
        $this->assertSame('open', $supplierRow['circuit_state']);
        $this->assertSame(15000.0, $supplierRow['balance']);
        $this->assertFalse($supplierRow['low_balance']);
    }

    public function test_health_flags_a_supplier_whose_balance_is_below_its_threshold(): void
    {
        Supplier::query()->create([
            'name' => 'Low One', 'slug' => 'low-'.uniqid(), 'currency' => 'IDR', 'balance' => 500,
            'api_config' => ['low_balance_threshold' => '1000'],
        ]);
        Supplier::query()->create([
            'name' => 'Fine One', 'slug' => 'fine-'.uniqid(), 'currency' => 'IDR', 'balance' => 5000,
            'api_config' => ['low_balance_threshold' => '1000'],
        ]);
        Supplier::query()->create([
            'name' => 'No Threshold', 'slug' => 'nothr-'.uniqid(), 'currency' => 'IDR', 'balance' => 1,
            'api_config' => [],
        ]);

        $rows = collect($this->dashboard->health()['suppliers'])->keyBy('name');

        $this->assertTrue($rows['Low One']['low_balance']);
        $this->assertFalse($rows['Fine One']['low_balance']);
        $this->assertFalse($rows['No Threshold']['low_balance'], 'no threshold means never flagged');

        // ADR-069 stress-test Q8 — health() reads the threshold out of
        // the encrypted api_config but must never surface it: the
        // supplier row is a fixed whitelist of keys.
        $this->assertSame(
            ['id', 'name', 'slug', 'balance', 'low_balance', 'circuit_state'],
            array_keys($rows['Low One']),
        );
    }

    public function test_stuck_orders_combines_needs_review_and_stale_processing_and_stale_pending(): void
    {
        config(['services.delivery_reconciliation.stale_after_minutes' => 15]);
        config(['services.delivery_reconciliation.pending_stale_minutes' => 10]);

        $this->order(['delivery_status' => DeliveryStatus::NeedsReview->value]);

        $staleProcessing = $this->order(['delivery_status' => DeliveryStatus::Processing->value]);
        DB::table('orders')->where('id', $staleProcessing->id)->update(['updated_at' => now()->subMinutes(20)]);

        $stalePending = $this->order(['delivery_status' => DeliveryStatus::Pending->value]);
        DB::table('orders')->where('id', $stalePending->id)->update(['updated_at' => now()->subMinutes(12)]);

        // Fresh processing/pending orders — must NOT count.
        $this->order(['delivery_status' => DeliveryStatus::Processing->value]);
        $this->order(['delivery_status' => DeliveryStatus::Pending->value]);

        $health = $this->dashboard->health();

        $this->assertSame(3, $health['stuck_orders']['value']);
    }

    public function test_stuck_orders_excludes_test_orders(): void
    {
        $this->order(['delivery_status' => DeliveryStatus::NeedsReview->value, 'is_test' => true]);

        $health = $this->dashboard->health();

        $this->assertSame(0, $health['stuck_orders']['value']);
    }

    public function test_pending_payments_counts_orders_with_pending_payment_status(): void
    {
        $this->order(['payment_status' => PaymentStatus::Pending->value, 'paid_at' => null]);
        $this->order(); // Paid — must not count

        $health = $this->dashboard->health();

        $this->assertSame(1, $health['pending_payments']['value']);
    }

    public function test_queue_depth_scoped_to_orders_queue_only(): void
    {
        DB::table('jobs')->insert([
            'queue' => 'orders',
            'payload' => '{}',
            'attempts' => 0,
            'available_at' => now()->timestamp,
            'created_at' => now()->timestamp,
        ]);
        DB::table('jobs')->insert([
            'queue' => 'price-sync',
            'payload' => '{}',
            'attempts' => 0,
            'available_at' => now()->timestamp,
            'created_at' => now()->timestamp,
        ]);

        $health = $this->dashboard->health();

        $this->assertSame(1, $health['queue']['pending']);
    }

    // --- funnel() / DASH-3 ------------------------------------------------

    public function test_funnel_is_a_cohort_by_created_at_not_three_independent_windows(): void
    {
        // Created today, paid today, delivered.
        $this->order();

        // Created today, still pending payment (never paid) — counts in
        // Created, not in Payment Confirmed or Delivered.
        $this->order(['payment_status' => PaymentStatus::Pending->value, 'paid_at' => null, 'delivery_status' => DeliveryStatus::NotStarted->value]);

        // Created OUTSIDE the 7-day window — must not count at all,
        // even though it's Paid/Delivered.
        $outside = $this->order();
        DB::table('orders')->where('id', $outside->id)->update(['created_at' => now()->subDays(10)]);

        $funnel = $this->dashboard->funnel();

        $this->assertSame(2, $funnel['created']['value']);
        $this->assertSame(1, $funnel['payment_confirmed']['value']);
        $this->assertSame(1, $funnel['delivered']['value']);
    }

    public function test_funnel_delivered_uses_current_status_not_a_delivered_at_window(): void
    {
        // Created 6 days ago, delivered just now (delivered_at is recent,
        // but the order itself is still inside the 7-day creation window).
        $order = $this->order();
        DB::table('orders')->where('id', $order->id)->update(['created_at' => now()->subDays(6)]);

        $funnel = $this->dashboard->funnel();

        $this->assertSame(1, $funnel['delivered']['value']);
    }

    public function test_funnel_excludes_test_orders(): void
    {
        $this->order(['is_test' => true]);

        $funnel = $this->dashboard->funnel();

        $this->assertSame(0, $funnel['created']['value']);
    }

    // --- topGames() / DASH-4 -----------------------------------------------

    public function test_top_games_reuses_report_service_game_breakdown(): void
    {
        $order = $this->order(['final_amount' => 3000]);

        $topGames = $this->dashboard->topGames(5);

        $this->assertCount(1, $topGames['games']);
        $this->assertSame($order->game_id, $topGames['games'][0]['game_id']);
        $this->assertSame(3000, $topGames['games'][0]['sales']);
    }

    public function test_top_games_comparison_is_new_when_game_had_no_sales_last_week(): void
    {
        $this->order(['final_amount' => 3000]);

        $topGames = $this->dashboard->topGames(5);

        $this->assertSame('new', $topGames['games'][0]['comparison']['direction']);
    }

    public function test_top_games_comparison_vs_prior_seven_day_window(): void
    {
        $game = Game::query()->create(['name' => 'Test Game', 'slug' => 'test-game-'.uniqid()]);

        $this->order(['final_amount' => 2000, 'game_id' => $game->id]);
        $lastWeek = $this->order(['final_amount' => 1000, 'game_id' => $game->id]);
        DB::table('orders')->where('id', $lastWeek->id)->update([
            'paid_at' => CarbonImmutable::now()->subDays(10),
            'created_at' => CarbonImmutable::now()->subDays(10),
        ]);

        $topGames = $this->dashboard->topGames(5);
        $row = collect($topGames['games'])->firstWhere('game_id', $game->id);

        $this->assertSame('up', $row['comparison']['direction']);
        $this->assertSame(100.0, $row['comparison']['pct']);
    }

    // --- hourlyActivity() / DASH-5 ------------------------------------------

    public function test_hourly_activity_groups_by_hour_of_day_in_kl_timezone(): void
    {
        $today = CarbonImmutable::now(DashboardService::TIMEZONE)->startOfDay();

        $order = $this->order();
        DB::table('orders')->where('id', $order->id)->update([
            'created_at' => $today->addHours(14)->setTimezone('UTC'),
        ]);

        $activity = $this->dashboard->hourlyActivity($today->toDateString());

        $this->assertCount(24, $activity['hours']);
        $this->assertSame(1, $activity['hours'][14]['count']);
        $this->assertSame(0, $activity['hours'][0]['count']);
    }

    public function test_hourly_activity_counts_created_orders_regardless_of_payment_status(): void
    {
        $today = CarbonImmutable::now(DashboardService::TIMEZONE)->startOfDay();

        $order = $this->order(['payment_status' => PaymentStatus::Failed->value, 'paid_at' => null]);
        DB::table('orders')->where('id', $order->id)->update([
            'created_at' => $today->addHours(9)->setTimezone('UTC'),
        ]);

        $activity = $this->dashboard->hourlyActivity($today->toDateString());

        $this->assertSame(1, $activity['hours'][9]['count']);
    }

    public function test_hourly_activity_excludes_test_orders(): void
    {
        $today = CarbonImmutable::now(DashboardService::TIMEZONE)->startOfDay();

        $order = $this->order(['is_test' => true]);
        DB::table('orders')->where('id', $order->id)->update([
            'created_at' => $today->addHours(9)->setTimezone('UTC'),
        ]);

        $activity = $this->dashboard->hourlyActivity($today->toDateString());

        $this->assertSame(0, $activity['hours'][9]['count']);
    }
}
