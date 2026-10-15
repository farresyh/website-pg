<?php

namespace Tests\Concurrency;

use App\Models\Order;
use App\Services\CustomerAnalytics\CustomerAnalyticsService;
use App\Services\CustomerAnalytics\CustomerFilters;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\PaymentStatus;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\TestCase;

/**
 * ADR-049 2026-10-15 addendum, decision 12: Customer Analytics leaves
 * email letter case to the database. sqlite compares case-sensitively, so
 * only real MySQL (prod's utf8mb4_unicode_ci) can prove that two spellings
 * of one email are one customer, end to end. Also covers the MySQL side of
 * the LIKE escape and the window-function name lookup.
 */
class CustomerAnalyticsMysqlTest extends TestCase
{
    use DatabaseMigrations;

    private int $seq = 0;

    private function order(string $email, array $overrides = []): Order
    {
        return Order::query()->create(array_merge([
            'placed_via' => 'storefront',
            'affiliate_id' => $this->primaryAffiliate()->id,
            'order_number' => 'MY-'.(++$this->seq),
            'customer_email' => $email,
            'player_id' => '123456',
            'cost_price' => 900,
            'standard_selling_price' => 900,
            'selling_price' => 1000,
            'transaction_fee' => 100,
            'final_amount' => 1000,
            'platform_profit' => 100,
            'affiliate_profit' => 0,
            'payment_status' => PaymentStatus::Paid->value,
            'paid_at' => now()->subDays(45),
            'delivery_status' => DeliveryStatus::Delivered->value,
            'is_test' => false,
        ], $overrides));
    }

    public function test_letter_case_variants_are_one_customer(): void
    {
        $this->order('Ali@Example.com', ['customer_name' => 'Old Name', 'paid_at' => now()->subDays(10)]);
        $this->order('ali@example.com', ['customer_name' => 'Ali Latest', 'paid_at' => now()->subDays(2)]);
        $this->order('a_b@example.com');

        $analytics = new CustomerAnalyticsService;
        $rows = $analytics->customers(new CustomerFilters(search: 'ALI'))->items();

        $this->assertCount(1, $rows);
        $this->assertSame(2, $rows[0]['orders_count']);
        $this->assertSame(2000, $rows[0]['total_spent']);
        $this->assertSame('Ali Latest', $rows[0]['customer_name']);
        $stats = $analytics->stats(new CustomerFilters);
        $this->assertSame(2, $stats['total_customers']);
        $this->assertSame(50.0, $stats['repeat_rate_pct']);

        $detail = $analytics->customerDetail('ALI@EXAMPLE.COM');
        $this->assertSame(2, $detail['stats']['total_orders']);
        $this->assertSame('Ali Latest', $detail['customer_name']);

        $this->assertSame(['a_b@example.com'], array_column($analytics->customers(new CustomerFilters(search: 'a_b'))->items(), 'customer_email'));
    }
}
