<?php

namespace Tests\Feature\Services\CustomerAnalytics;

use App\Models\Affiliate;
use App\Models\Order;
use App\Models\PlatformSettings;
use App\Models\Reseller;
use App\Services\CustomerAnalytics\CustomerAnalyticsService;
use App\Services\CustomerAnalytics\CustomerFilters;
use App\Services\CustomerAnalytics\CustomerSegment;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\PaymentStatus;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** ADR-049 2026-10-15 addendum (item 103) — paging, sort, search, Source and the scale guard. */
class CustomerAnalyticsListingTest extends TestCase
{
    use RefreshDatabase;

    private CustomerAnalyticsService $analytics;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->analytics = new CustomerAnalyticsService;
    }

    private function order(string $email, int $amount = 1000, array $overrides = []): Order
    {
        return Order::query()->create(array_merge([
            'placed_via' => 'storefront',
            'affiliate_id' => $this->primaryAffiliate()->id,
            'order_number' => 'LST-'.(++$this->seq),
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
            'paid_at' => now()->subDays(45),
            'delivery_status' => DeliveryStatus::Delivered->value,
            'is_test' => false,
        ], $overrides));
    }

    /** @return list<string> */
    private function emails(CustomerFilters $filters, string $sort = 'total_spent', int $page = 1, int $perPage = 25): array
    {
        return array_column($this->analytics->customers($filters, $sort, $page, $perPage)->items(), 'customer_email');
    }

    public function test_pages_the_list_and_reports_the_total(): void
    {
        foreach (range(1, 5) as $i) {
            $this->order("c{$i}@example.com", $i * 1000);
        }

        $page = $this->analytics->customers(new CustomerFilters, page: 2, perPage: 2);

        $this->assertSame(5, $page->total());
        $this->assertSame(3, $page->lastPage());
        $this->assertSame(['c3@example.com', 'c2@example.com'], array_column($page->items(), 'customer_email'));
    }

    public function test_sorts_by_orders_or_last_order_with_email_as_tie_break(): void
    {
        // b and c must share one paid_at for the email tie-break to decide;
        // unfrozen, a slow run put c a second later (CI, 2026-10-16).
        $this->freezeTime();
        $this->order('b@example.com', 5000, ['paid_at' => now()->subDays(1)]);
        $this->order('a@example.com', 1000, ['paid_at' => now()->subDays(9)]);
        $this->order('a@example.com', 1000, ['paid_at' => now()->subDays(8)]);
        $this->order('c@example.com', 1000, ['paid_at' => now()->subDays(1)]);

        $this->assertSame(['a@example.com', 'b@example.com', 'c@example.com'], $this->emails(new CustomerFilters, 'orders_count'));
        $this->assertSame(['b@example.com', 'c@example.com', 'a@example.com'], $this->emails(new CustomerFilters, 'last_order_at'));
    }

    public function test_search_matches_part_of_the_email_ignoring_case_and_wildcards(): void
    {
        $this->order('ali@example.com');
        $this->order('mali@example.com');
        $this->order('bob@example.com');
        $this->order('a_b@example.com');

        $this->assertSame(['ali@example.com', 'mali@example.com'], $this->emails(new CustomerFilters(search: 'ALI'), 'last_order_at'));
        // "_" is a literal underscore, not LIKE's any-character.
        $this->assertSame(['a_b@example.com'], $this->emails(new CustomerFilters(search: 'a_b')));
    }

    public function test_stat_cards_ignore_search_and_segment(): void
    {
        $this->order('ali@example.com', 2000);
        $this->order('bob@example.com', 4000);

        $stats = $this->analytics->stats(new CustomerFilters(segment: CustomerSegment::Vip, search: 'ali'));

        $this->assertSame(2, $stats['total_customers']);
        $this->assertSame('bob@example.com', $stats['top_spender']['customer_email']);
    }

    /** Addendum decision 3 — repeat counts every paid order, whatever the Affiliate filter. */
    public function test_repeat_rate_under_an_affiliate_filter_counts_all_orders(): void
    {
        $affiliate = Affiliate::query()->create(['business_name' => 'Affiliate B', 'markup_pct' => 5]);
        $this->order('shared@example.com', 1000, ['affiliate_id' => $affiliate->id]);
        $this->order('shared@example.com');

        $stats = $this->analytics->stats(new CustomerFilters(affiliateId: $affiliate->id));

        $this->assertSame(1, $stats['total_customers']);
        $this->assertSame(100.0, $stats['repeat_rate_pct']);
    }

    public function test_source_names_every_reseller_and_retail(): void
    {
        $a = Reseller::query()->create(['business_name' => 'Alpha']);
        $b = Reseller::query()->create(['business_name' => 'Beta']);
        $this->order('shared@example.com', 1000, ['wallet_reseller_id' => $b->id]);
        $this->order('shared@example.com', 1000, ['wallet_reseller_id' => $a->id]);
        $this->order('shared@example.com');
        $this->order('two@example.com', 1000, ['wallet_reseller_id' => $a->id]);
        $this->order('two@example.com', 1000, ['wallet_reseller_id' => $b->id]);

        $rows = collect($this->analytics->customers(new CustomerFilters)->items())->keyBy('customer_email');

        $this->assertSame('Reseller: Alpha, Beta + Retail', $rows['shared@example.com']['source_label']);
        $this->assertSame('Reseller: Alpha, Beta', $rows['two@example.com']['source_label']);
    }

    public function test_name_comes_from_the_latest_paid_order_only(): void
    {
        $this->order('buyer@example.com', 1000, ['customer_name' => 'Paid Name', 'paid_at' => now()->subDays(5)]);
        $this->order('buyer@example.com', 1000, ['customer_name' => 'Unpaid Name', 'payment_status' => PaymentStatus::Pending->value, 'paid_at' => null]);
        $this->order('buyer@example.com', 1000, ['customer_name' => null, 'paid_at' => now()->subDay()]);

        $this->assertSame('Paid Name', $this->analytics->customers(new CustomerFilters)->items()[0]['customer_name']);
        $this->assertSame('Paid Name', $this->analytics->customerDetail('buyer@example.com')['customer_name']);
    }

    public function test_export_returns_every_matching_row_unpaged(): void
    {
        foreach (range(1, 30) as $i) {
            $this->order("c{$i}@example.com", $i * 100);
        }

        $rows = iterator_to_array($this->analytics->exportRows(new CustomerFilters(search: 'c1')), false);

        // c1, c10..c19
        $this->assertCount(11, $rows);
        $this->assertSame('c19@example.com', $rows[0]['customer_email']);
    }

    public function test_detail_order_history_is_paged(): void
    {
        foreach (range(1, 27) as $i) {
            $this->order('many@example.com', 1000, ['paid_at' => now()->subMinutes($i)]);
        }

        $first = $this->analytics->customerDetail('many@example.com')['order_history'];
        $second = $this->analytics->customerDetail('many@example.com', 2)['order_history'];

        $this->assertSame(27, $first['total']);
        $this->assertCount(25, $first['data']);
        $this->assertSame('LST-1', $first['data'][0]['order_number']);
        $this->assertSame(['LST-26', 'LST-27'], array_column($second['data'], 'order_number'));
        $this->assertSame(27, $this->analytics->customerDetail('many@example.com')['stats']['total_orders']);
    }

    public function test_dormant_and_new_boundaries_are_strict(): void
    {
        CarbonImmutable::setTestNow('2026-10-15 04:00:00');
        $this->order('dormant@example.com', 1000, ['paid_at' => CarbonImmutable::now()->subDays(60)->subSecond()]);
        $this->order('dormant@example.com', 1000, ['paid_at' => CarbonImmutable::now()->subDays(90)]);
        $this->order('not-dormant@example.com', 1000, ['paid_at' => CarbonImmutable::now()->subDays(60)]);
        $this->order('not-dormant@example.com', 1000, ['paid_at' => CarbonImmutable::now()->subDays(90)]);
        $this->order('new@example.com', 1000, ['paid_at' => CarbonImmutable::now()->subDays(30)->addSecond()]);
        $this->order('not-new@example.com', 1000, ['paid_at' => CarbonImmutable::now()->subDays(30)]);

        $rows = collect($this->analytics->customers(new CustomerFilters)->items())->keyBy('customer_email');
        CarbonImmutable::setTestNow();

        $this->assertSame('dormant', $rows['dormant@example.com']['segment']);
        $this->assertNull($rows['not-dormant@example.com']['segment']);
        $this->assertSame('new', $rows['new@example.com']['segment']);
        $this->assertSame('one_time', $rows['not-new@example.com']['segment']);
    }

    /**
     * The scale guard: the list and stat cards run a fixed number of
     * queries however many customers or orders exist. The detail page adds
     * exactly one per realised order, from Order::cashCompensationSen()
     * (see profitAnalysis()); batching that is a separate change.
     */
    public function test_query_count_does_not_grow_with_customers(): void
    {
        $count = function (callable $call): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $call();
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };
        $list = function () {
            $this->analytics->stats(new CustomerFilters(from: CarbonImmutable::now()->subYear()));
            $this->analytics->customers(new CustomerFilters);
            iterator_to_array($this->analytics->exportRows(new CustomerFilters));
        };
        $detail = fn () => $this->analytics->customerDetail('c1@example.com');

        PlatformSettings::current();
        foreach (range(1, 3) as $i) {
            $this->order("c{$i}@example.com");
        }
        $smallList = $count($list);
        $smallDetail = $count($detail);

        foreach (range(4, 40) as $i) {
            $this->order("c{$i}@example.com");
            $this->order('c1@example.com');
        }

        $this->assertSame($smallList, $count($list));
        $this->assertSame($smallDetail + 37, $count($detail));
    }
}
