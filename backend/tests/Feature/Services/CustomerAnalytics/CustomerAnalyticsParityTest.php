<?php

namespace Tests\Feature\Services\CustomerAnalytics;

use App\Models\Affiliate;
use App\Models\Game;
use App\Models\Order;
use App\Models\Package;
use App\Models\PlatformSettings;
use App\Models\Reseller;
use App\Models\Supplier;
use App\Services\CustomerAnalytics\CustomerAnalyticsService;
use App\Services\CustomerAnalytics\CustomerFilters;
use App\Services\Ledger\LedgerService;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\PaymentStatus;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-049 2026-10-15 addendum (item 103) — the money, count and segment
 * figures the pre-rewrite service produced for one fixed data set, with
 * every segment boundary, a wallet refund, a mixed wallet+retail email and
 * the excluded order kinds. The expected values were captured from the old
 * PHP implementation before it was removed; the SQL seam must match them.
 */
class CustomerAnalyticsParityTest extends TestCase
{
    use RefreshDatabase;

    private const NOW = '2026-10-15 04:00:00';

    private CustomerAnalyticsService $analytics;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(self::NOW);
        CarbonImmutable::setTestNow(self::NOW);
        $this->analytics = new CustomerAnalyticsService;
        $this->seedFixture();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function order(string $email, int $amount, CarbonImmutable $paidAt, array $overrides = []): Order
    {
        return Order::query()->create(array_merge([
            'placed_via' => 'storefront',
            'affiliate_id' => $this->primaryAffiliate()->id,
            'order_number' => 'PAR-'.(++$this->seq),
            'customer_email' => $email,
            'customer_name' => 'Name '.$email,
            'player_id' => '123456',
            'cost_price' => 900,
            'standard_selling_price' => 900,
            'selling_price' => 1000,
            'transaction_fee' => 100,
            'final_amount' => $amount,
            'platform_profit' => 100,
            'affiliate_profit' => 0,
            'payment_status' => PaymentStatus::Paid->value,
            'paid_at' => $paidAt,
            'delivery_status' => DeliveryStatus::Delivered->value,
            'is_test' => false,
        ], $overrides));
    }

    private function seedFixture(): void
    {
        PlatformSettings::current()->update(['vip_spend_threshold_sen' => 500000]);
        $now = CarbonImmutable::now();
        $ledger = new LedgerService;

        $affiliate = Affiliate::query()->create(['business_name' => 'Affiliate B', 'markup_pct' => 5]);
        $resellerA = Reseller::query()->create(['business_name' => 'Reseller A']);
        $supplier = Supplier::query()->create(['name' => 'Gamevion', 'slug' => 'gamevion', 'api_config' => [], 'currency' => 'MYR']);
        $game = Game::query()->create(['name' => 'Mobile Legends', 'slug' => 'mobile-legends']);
        $small = Package::query()->create(['game_id' => $game->id, 'name' => '86 Diamonds', 'cost_price' => 400, 'standard_selling_price' => 450, 'supplier_id' => $supplier->id, 'supplier_package_ref' => 'A']);
        $big = Package::query()->create(['game_id' => $game->id, 'name' => '172 Diamonds', 'cost_price' => 800, 'standard_selling_price' => 850, 'supplier_id' => $supplier->id, 'supplier_package_ref' => 'B']);

        // VIP beats Dormant.
        $this->order('vip@example.com', 500000, $now->subDays(61));
        // Frequent: 20 orders, the last one 2 days ago.
        for ($i = 0; $i < 20; $i++) {
            $this->order('freq@example.com', 1000 + $i, $now->subDays(40 - $i));
        }
        // Dormant by one minute; and one minute short of it.
        $this->order('dormant@example.com', 3000, $now->subDays(70));
        $this->order('dormant@example.com', 3000, $now->subDays(60)->subMinute());
        $this->order('edge-active@example.com', 2000, $now->subDays(70));
        $this->order('edge-active@example.com', 2000, $now->subDays(60)->addMinute());
        // New by one minute; one minute past it (and only one order: One-time).
        $this->order('new@example.com', 1500, $now->subDays(30)->addMinute());
        $this->order('edge-old@example.com', 1500, $now->subDays(30)->subMinute());
        // No segment: two orders, neither new nor dormant.
        $this->order('plain@example.com', 1200, $now->subDays(45));
        $this->order('plain@example.com', 1300, $now->subDays(44));

        // Wallet reseller: one order fully refunded, one delivered.
        $refunded = $this->order('wallet@example.com', 34351, $now->subDays(10), ['wallet_reseller_id' => $resellerA->id, 'delivery_status' => DeliveryStatus::Failed->value, 'package_id' => $big->id]);
        $ledger->credit('reseller_wallet', $resellerA->id, 34351, 'wallet_refund', 'order', $refunded->id);
        $this->order('wallet@example.com', 9194, $now->subDays(5), ['wallet_reseller_id' => $resellerA->id, 'package_id' => $small->id]);

        // Mixed: two wallet orders plus one Affiliate storefront order.
        $m1 = $this->order('mixed@example.com', 4000, $now->subDays(20), ['wallet_reseller_id' => $resellerA->id, 'package_id' => $big->id]);
        $ledger->credit('platform', null, 100, 'order_profit', 'order', $m1->id);
        $this->order('mixed@example.com', 2500, $now->subDays(3), ['wallet_reseller_id' => $resellerA->id, 'package_id' => $small->id, 'delivery_status' => DeliveryStatus::Pending->value]);
        $m3 = $this->order('mixed@example.com', 6000, $now->subDays(1), ['affiliate_id' => $affiliate->id, 'package_id' => $big->id, 'affiliate_profit' => 20]);
        $ledger->credit('platform', null, 80, 'order_profit', 'order', $m3->id);
        $ledger->credit('affiliate', $affiliate->id, 20, 'order_profit', 'order', $m3->id);

        // Never counted: pending, failed payment, test.
        $this->order('pending@example.com', 9999, $now, ['payment_status' => PaymentStatus::Pending->value, 'paid_at' => null]);
        $this->order('vip@example.com', 9999, $now, ['payment_status' => PaymentStatus::Failed->value]);
        $this->order('test@example.com', 9999, $now, ['is_test' => true]);
    }

    private function filters(array $args = []): CustomerFilters
    {
        return new CustomerFilters(...$args);
    }

    /** @return array<string, array<string, mixed>> */
    private function rowsByEmail(CustomerFilters $filters): array
    {
        return collect($this->analytics->customers($filters, perPage: 100)->items())->keyBy('customer_email')->all();
    }

    public function test_list_matches_the_old_figures_and_segments(): void
    {
        $rows = $this->rowsByEmail($this->filters());

        $expected = [
            // email => [segment, orders, spent, last order (KL)]
            'vip@example.com' => ['vip', 1, 500000, '2026-08-15T12:00:00+08:00'],
            'freq@example.com' => ['frequent', 20, 20190, '2026-09-24T12:00:00+08:00'],
            'mixed@example.com' => ['new', 3, 12500, '2026-10-14T12:00:00+08:00'],
            'wallet@example.com' => ['new', 2, 9194, '2026-10-10T12:00:00+08:00'],
            'dormant@example.com' => ['dormant', 2, 6000, '2026-08-16T11:59:00+08:00'],
            'edge-active@example.com' => [null, 2, 4000, '2026-08-16T12:01:00+08:00'],
            'plain@example.com' => [null, 2, 2500, '2026-09-01T12:00:00+08:00'],
            'edge-old@example.com' => ['one_time', 1, 1500, '2026-09-15T11:59:00+08:00'],
            'new@example.com' => ['new', 1, 1500, '2026-09-15T12:01:00+08:00'],
        ];

        $this->assertSame(array_keys($expected), array_keys($rows), 'same customers, sorted by spend');
        foreach ($expected as $email => [$segment, $orders, $spent, $last]) {
            $this->assertSame($segment, $rows[$email]['segment'], $email);
            $this->assertSame($orders, $rows[$email]['orders_count'], $email);
            $this->assertSame($spent, $rows[$email]['total_spent'], $email);
            $this->assertSame($last, $rows[$email]['last_order_at'], $email);
            $this->assertSame('Name '.$email, $rows[$email]['customer_name'], $email);
        }

        $this->assertSame('Retail', $rows['vip@example.com']['source_label']);
        $this->assertSame('Reseller: Reseller A', $rows['wallet@example.com']['source_label']);
        // Changed on purpose (addendum decision 6): the old MAX() labelled all 3 orders "Reseller A".
        $this->assertSame('Reseller: Reseller A + Retail', $rows['mixed@example.com']['source_label']);
    }

    public function test_date_range_narrows_figures_but_not_segment(): void
    {
        $rows = $this->rowsByEmail($this->filters(['from' => CarbonImmutable::now()->subDays(25)]));

        $this->assertSame(['mixed@example.com', 'wallet@example.com', 'freq@example.com'], array_keys($rows));
        $this->assertSame([3, 12500], [$rows['mixed@example.com']['orders_count'], $rows['mixed@example.com']['total_spent']]);
        $this->assertSame([2, 9194], [$rows['wallet@example.com']['orders_count'], $rows['wallet@example.com']['total_spent']]);
        $this->assertSame([5, 5085], [$rows['freq@example.com']['orders_count'], $rows['freq@example.com']['total_spent']]);
        $this->assertSame('frequent', $rows['freq@example.com']['segment']);
    }

    public function test_affiliate_filter_narrows_figures_but_source_and_segment_stay_lifetime(): void
    {
        $affiliateId = Affiliate::query()->where('business_name', 'Affiliate B')->value('id');
        $rows = $this->rowsByEmail($this->filters(['affiliateId' => $affiliateId]));

        $this->assertSame(['mixed@example.com'], array_keys($rows));
        $this->assertSame([1, 6000], [$rows['mixed@example.com']['orders_count'], $rows['mixed@example.com']['total_spent']]);
        $this->assertSame('new', $rows['mixed@example.com']['segment']);
        // Changed on purpose (addendum decisions 3, 6): the old code showed "Retail" here.
        $this->assertSame('Reseller: Reseller A + Retail', $rows['mixed@example.com']['source_label']);
    }

    public function test_stats_match_the_old_figures(): void
    {
        $this->assertSame([
            'total_customers' => 9,
            'avg_order_value' => 16394,
            'repeat_rate_pct' => 66.67,
            'top_spender' => ['customer_email' => 'vip@example.com', 'customer_name' => 'Name vip@example.com', 'total_spent' => 500000],
        ], $this->analytics->stats($this->filters()));

        $this->assertSame([
            'total_customers' => 3,
            'avg_order_value' => 2678,
            'repeat_rate_pct' => 100.0,
            'top_spender' => ['customer_email' => 'mixed@example.com', 'customer_name' => 'Name mixed@example.com', 'total_spent' => 12500],
        ], $this->analytics->stats($this->filters(['from' => CarbonImmutable::now()->subDays(25)])));
    }

    public function test_detail_matches_the_old_figures(): void
    {
        $mixed = $this->analytics->customerDetail('mixed@example.com');

        $this->assertSame('new', $mixed['segment']);
        $this->assertSame('Name mixed@example.com', $mixed['customer_name']);
        $this->assertSame(['total_orders' => 3, 'total_spent' => 12500, 'avg_order_value' => 4167, 'customer_since' => '2026-09-25'], $mixed['stats']);
        $this->assertSame(['total_revenue' => 10000, 'supplier_cost' => 1800, 'affiliate_commission' => 20, 'transaction_fees' => 200, 'system_profit' => 180], $mixed['profit_analysis']);
        $this->assertSame([['month' => '2026-09', 'label' => 'Sep', 'total_spent' => 4000], ['month' => '2026-10', 'label' => 'Oct', 'total_spent' => 8500]], $mixed['monthly_trend']);
        $this->assertSame([['172 Diamonds', 2, 10000, 80.0], ['86 Diamonds', 1, 2500, 20.0]], array_map(fn ($r) => [$r['name'], $r['orders_count'], $r['total_spent'], (float) $r['pct_of_spend']], $mixed['top_packages']));
        $this->assertSame([['Reseller: Reseller A', 2, 6500, 52.0], ['Affiliate B', 1, 6000, 48.0]], array_map(fn ($r) => [$r['name'], $r['orders_count'], $r['total_spent'], (float) $r['pct_of_spend']], $mixed['top_sources']));
        $this->assertSame(3, $mixed['order_history']['total']);
        $this->assertSame(
            [['PAR-34', 6000, 'Affiliate B', 20, 80], ['PAR-33', 2500, 'Reseller: Reseller A', null, null], ['PAR-32', 4000, 'Reseller: Reseller A', 0, 100]],
            array_map(fn ($h) => [$h['order_number'], $h['final_amount'], $h['source_name'], $h['affiliate_profit'], $h['system_profit']], $mixed['order_history']['data']),
        );

        $wallet = $this->analytics->customerDetail('wallet@example.com');

        $this->assertSame(['total_orders' => 2, 'total_spent' => 9194, 'avg_order_value' => 4597, 'customer_since' => '2026-10-05'], $wallet['stats']);
        $this->assertSame(['total_revenue' => 9194, 'supplier_cost' => 900, 'affiliate_commission' => 0, 'transaction_fees' => 100, 'system_profit' => 0], $wallet['profit_analysis']);
        $this->assertSame([['86 Diamonds', 1, 9194, 100.0], ['172 Diamonds', 1, 0, 0.0]], array_map(fn ($r) => [$r['name'], $r['orders_count'], $r['total_spent'], (float) $r['pct_of_spend']], $wallet['top_packages']));
    }
}
