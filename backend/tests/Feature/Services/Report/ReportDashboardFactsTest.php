<?php

namespace Tests\Feature\Services\Report;

use App\Models\Order;
use App\Models\Reseller;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\PaymentStatus;
use App\Services\Report\ReportService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-104 2026-10-15 grill outcome: Busy hours and Highlights, the two
 * Dashboard facts that live in ReportService beside the Sales definition
 * (paid, by paid_at, KL time, is_test excluded, net of wallet refunds).
 */
class ReportDashboardFactsTest extends TestCase
{
    use RefreshDatabase;

    private ReportService $reports;

    protected function setUp(): void
    {
        parent::setUp();
        $this->reports = new ReportService;
        $this->travelTo(CarbonImmutable::parse('2026-10-15 18:40', 'Asia/Kuala_Lumpur'));
    }

    /** A KL wall-clock time, stored as UTC the way the app stores it. */
    private function kl(string $at): CarbonImmutable
    {
        return CarbonImmutable::parse($at, 'Asia/Kuala_Lumpur')->utc();
    }

    /** [from, toExclusive) for KL calendar dates, inclusive. */
    private function range(string $from, string $to): array
    {
        return $this->reports->dateRangeFromDates($from, $to);
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
            'paid_at' => $this->kl('2026-10-15 09:00'),
            'delivery_status' => DeliveryStatus::Delivered->value,
            'is_test' => false,
        ], $overrides));
    }

    // --- busyHours() ------------------------------------------------------

    public function test_busy_hours_counts_paid_orders_by_kl_hour_of_payment(): void
    {
        $this->order(['paid_at' => $this->kl('2026-10-14 21:10')]);
        $this->order(['paid_at' => $this->kl('2026-10-15 21:50')]);
        $this->order(['paid_at' => $this->kl('2026-10-15 00:05')]);

        $hours = $this->reports->busyHours(...[...$this->range('2026-10-09', '2026-10-15'), null]);

        $this->assertCount(24, $hours);
        $this->assertSame(['hour' => 21, 'orders_count' => 2], $hours[21]);
        $this->assertSame(1, $hours[0]['orders_count']);
        $this->assertSame(3, array_sum(array_column($hours, 'orders_count')));
    }

    public function test_busy_hours_ignores_unpaid_test_and_out_of_range_orders(): void
    {
        $this->order(['paid_at' => $this->kl('2026-10-15 10:00'), 'payment_status' => PaymentStatus::Pending->value]);
        $this->order(['paid_at' => $this->kl('2026-10-15 10:00'), 'is_test' => true]);
        $this->order(['paid_at' => $this->kl('2026-10-01 10:00')]);

        $hours = $this->reports->busyHours(...[...$this->range('2026-10-09', '2026-10-15'), null]);

        $this->assertSame(0, array_sum(array_column($hours, 'orders_count')));
    }

    // --- highlights() -----------------------------------------------------

    public function test_best_day_is_the_highest_sales_day_in_range(): void
    {
        $this->order(['final_amount' => 1000, 'paid_at' => $this->kl('2026-10-10 12:00')]);
        $this->order(['final_amount' => 3000, 'paid_at' => $this->kl('2026-10-11 12:00')]);
        $this->order(['final_amount' => 2500, 'paid_at' => $this->kl('2026-10-11 23:30')]);

        $best = $this->reports->highlights(...[...$this->range('2026-10-09', '2026-10-15'), null])['best_day'];

        $this->assertSame(['date' => '2026-10-11', 'sales' => 5500, 'orders_count' => 2, 'is_all_time_record' => true], $best);
    }

    public function test_best_day_is_not_a_record_when_an_earlier_day_sold_more(): void
    {
        $this->order(['final_amount' => 9000, 'paid_at' => $this->kl('2026-09-01 12:00')]);
        $this->order(['final_amount' => 3000, 'paid_at' => $this->kl('2026-10-11 12:00')]);

        $best = $this->reports->highlights(...[...$this->range('2026-10-09', '2026-10-15'), null])['best_day'];

        $this->assertSame('2026-10-11', $best['date']);
        $this->assertFalse($best['is_all_time_record']);
    }

    public function test_best_day_is_left_out_for_a_single_day(): void
    {
        $this->order();

        $this->assertNull($this->reports->highlights(...[...$this->range('2026-10-15', '2026-10-15'), null])['best_day']);
    }

    public function test_top_reseller_and_biggest_order(): void
    {
        $small = Reseller::query()->create(['business_name' => 'Small Shop', 'is_active' => true]);
        $big = Reseller::query()->create(['business_name' => 'Kedai Gamer KL', 'is_active' => true]);
        $this->order(['wallet_reseller_id' => $small->id, 'final_amount' => 2000]);
        $this->order(['wallet_reseller_id' => $big->id, 'final_amount' => 1500]);
        $this->order(['wallet_reseller_id' => $big->id, 'final_amount' => 1500]);
        $biggest = $this->order(['final_amount' => 28900]);

        $facts = $this->reports->highlights(...[...$this->range('2026-10-09', '2026-10-15'), null]);

        $this->assertSame(['reseller_id' => $big->id, 'reseller_name' => 'Kedai Gamer KL', 'sales' => 3000, 'orders_count' => 2], $facts['top_reseller']);
        $this->assertSame($biggest->order_number, $facts['biggest_order']['order_number']);
        $this->assertSame(28900, $facts['biggest_order']['amount']);
    }

    public function test_every_fact_is_null_without_paid_orders(): void
    {
        $this->assertSame(
            ['best_day' => null, 'top_reseller' => null, 'biggest_order' => null],
            $this->reports->highlights(...[...$this->range('2026-10-09', '2026-10-15'), null]),
        );
    }
}
