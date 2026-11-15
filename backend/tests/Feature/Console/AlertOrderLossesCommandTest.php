<?php

namespace Tests\Feature\Console;

use App\Models\AdminUser;
use App\Models\Order;
use App\Models\OrderResendAttempt;
use App\Services\Order\DeliveryStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** ADR-111 2026-11-15 addendum: the daily order-loss digest (PRD §16 item 169's trigger). */
class AlertOrderLossesCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.plunk.api_key' => 'plunk-key', 'services.plunk.base_url' => 'http://plunk.test']);
        Http::fake(['plunk.test/*' => Http::response(['success' => true])]);
        AdminUser::factory()->create(['email' => 'boss@example.test', 'is_active' => true]);

        // 08:00 Malaysia on 16 Nov: "yesterday" is 15 Nov Malaysia = 14 Nov 16:00 → 15 Nov 16:00 UTC.
        $this->travelTo(Carbon::parse('2026-11-16 00:00:00', 'UTC'));
    }

    /** A delivered order at a loss of $lossSen, delivered at a UTC time. */
    private function loss(int $lossSen, string $deliveredAtUtc, array $overrides = []): Order
    {
        return Order::factory()->delivered()->create(array_merge([
            'cost_price' => 1000,
            'real_cost_price_sen' => 1000 + $lossSen,
            'selling_price' => 1000,
            'platform_profit' => -$lossSen,
            'delivered_at' => Carbon::parse($deliveredAtUtc, 'UTC'),
        ], $overrides));
    }

    private function sentBody(): string
    {
        $bodies = [];
        Http::assertSent(function (Request $request) use (&$bodies) {
            $bodies[] = (string) ($request['body'] ?? '');

            return true;
        });

        return implode("\n", $bodies);
    }

    public function test_yesterdays_losses_are_emailed_once_with_the_month_total(): void
    {
        $this->loss(117, '2026-11-15 10:00:00', ['order_number' => 'PG-YESTERDAY']);
        $this->loss(13, '2026-11-03 10:00:00', ['order_number' => 'PG-EARLIER']);                 // this month, not yesterday
        $this->loss(500, '2026-11-15 17:00:00', ['order_number' => 'PG-TODAY']);                  // 16 Nov Malaysia
        $this->loss(400, '2026-11-15 10:00:00', ['order_number' => 'PG-TEST', 'is_test' => true]);
        $this->loss(300, '2026-11-15 10:00:00', ['order_number' => 'PG-PARTIAL', 'delivery_status' => DeliveryStatus::PartiallyDelivered]);
        Order::factory()->delivered()->create(['order_number' => 'PG-PROFIT', 'delivered_at' => Carbon::parse('2026-11-15 10:00:00', 'UTC')]);

        $this->artisan('app:alert-order-losses')->assertSuccessful();

        Http::assertSentCount(1);
        $body = $this->sentBody();
        $this->assertStringContainsString('PG-YESTERDAY', $body);
        $this->assertStringContainsString('RM1.17', $body);
        $this->assertStringContainsString('RM1.30', $body);                                       // month to date: 117 + 13
        foreach (['PG-EARLIER', 'PG-TODAY', 'PG-TEST', 'PG-PARTIAL', 'PG-PROFIT'] as $absent) {
            $this->assertStringNotContainsString($absent, $body);
        }
        $this->assertStringNotContainsString('max_price', $body);
    }

    public function test_no_loss_yesterday_sends_nothing(): void
    {
        $this->loss(13, '2026-11-03 10:00:00');
        Order::factory()->delivered()->create(['delivered_at' => Carbon::parse('2026-11-15 10:00:00', 'UTC')]);

        $this->artisan('app:alert-order-losses')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_the_trigger_is_named_when_one_order_loses_more_than_rm5(): void
    {
        $this->loss(501, '2026-11-15 10:00:00');

        $this->artisan('app:alert-order-losses')->assertSuccessful();

        $this->assertStringContainsString('max_price', $this->sentBody());
    }

    public function test_the_trigger_is_named_when_the_month_passes_rm20(): void
    {
        $this->loss(1950, '2026-11-02 10:00:00');
        $this->loss(100, '2026-11-15 10:00:00');

        $this->artisan('app:alert-order-losses')->assertSuccessful();

        $this->assertStringContainsString('max_price', $this->sentBody());
    }

    public function test_a_resent_order_is_labelled(): void
    {
        $order = $this->loss(40, '2026-11-15 10:00:00');
        OrderResendAttempt::query()->create(['order_id' => $order->id, 'attempt_type' => 'resend', 'cost_price_sen' => 1000, 'standard_selling_price_sen' => 1100, 'price_diff_sen' => 40, 'outcome' => 'success']);

        $this->artisan('app:alert-order-losses')->assertSuccessful();

        $this->assertStringContainsString('resend', strtolower($this->sentBody()));
    }

    public function test_on_the_first_of_the_month_it_reports_the_previous_month(): void
    {
        $this->travelTo(Carbon::parse('2026-12-01 00:00:00', 'UTC'));
        $this->loss(50, '2026-11-30 10:00:00', ['order_number' => 'PG-LASTDAY']);
        $this->loss(20, '2026-11-10 10:00:00');

        $this->artisan('app:alert-order-losses')->assertSuccessful();

        $body = $this->sentBody();
        $this->assertStringContainsString('PG-LASTDAY', $body);
        $this->assertStringContainsString('RM0.70', $body);
    }
}
