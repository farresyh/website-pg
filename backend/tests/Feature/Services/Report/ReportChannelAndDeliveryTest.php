<?php

namespace Tests\Feature\Services\Report;

use App\Models\Affiliate;
use App\Models\Game;
use App\Models\Order;
use App\Models\Reseller;
use App\Services\Ledger\LedgerService;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\PlacedVia;
use App\Services\Report\ReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** ADR-104 2026-10-08 addendum R15, R16 (API vs Bot) and Delivery by game. */
class ReportChannelAndDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private function order(array $overrides = []): Order
    {
        return Order::factory()->create(array_merge(['paid_at' => now(), 'delivery_status' => DeliveryStatus::Delivered], $overrides));
    }

    private function external(): Affiliate
    {
        return Affiliate::query()->create(['business_name' => 'Ohahastore', 'markup_pct' => 5, 'status' => 'active', 'is_owned' => false]);
    }

    private function reseller(): Reseller
    {
        return Reseller::query()->create(['business_name' => 'Naeem', 'is_active' => true]);
    }

    /** R15 — three exclusive buckets, reseller wallet checked first (its orders also carry the primary affiliate). */
    public function test_sales_by_channel_uses_three_exclusive_buckets(): void
    {
        $this->order();                                                               // own brand: 1100
        $this->order(['wallet_reseller_id' => $this->reseller()->id, 'final_amount' => 900, 'placed_via' => PlacedVia::ResellerBot]);
        $theirs = $this->order(['affiliate_id' => $this->external()->id, 'final_amount' => 500]);
        (new LedgerService)->credit('affiliate', $theirs->affiliate_id, 40, 'order_profit', 'order', $theirs->id);

        $rows = collect((new ReportService)->channelBreakdown(null, null, null)['channels'])->keyBy('channel');

        $this->assertSame(['own_brand', 'reseller_wallet', 'external_affiliate'], $rows->keys()->all());
        $this->assertSame(1100, $rows['own_brand']['sales']);
        $this->assertSame(900, $rows['reseller_wallet']['sales']);
        $this->assertSame(500, $rows['external_affiliate']['sales']);
        $this->assertSame(40, $rows['external_affiliate']['affiliate_profit']);
        $this->assertSame(1, $rows['reseller_wallet']['orders_count']);
    }

    public function test_every_channel_is_listed_even_with_no_sales(): void
    {
        $this->order();

        $rows = collect((new ReportService)->channelBreakdown(null, null, null)['channels'])->keyBy('channel');

        $this->assertSame(0, $rows['reseller_wallet']['sales']);
        $this->assertSame(0, $rows['external_affiliate']['orders_count']);
    }

    /** R16 — the reseller-wallet split by door. */
    public function test_reseller_wallet_sales_split_into_api_and_bot(): void
    {
        $reseller = $this->reseller();
        $this->order(['wallet_reseller_id' => $reseller->id, 'final_amount' => 900, 'placed_via' => PlacedVia::ResellerBot]);
        $this->order(['wallet_reseller_id' => $reseller->id, 'final_amount' => 800, 'placed_via' => PlacedVia::ResellerBot]);
        $this->order(['wallet_reseller_id' => $reseller->id, 'final_amount' => 300, 'placed_via' => PlacedVia::ResellerApi]);
        $this->order(); // storefront, not in the split

        $split = collect((new ReportService)->channelBreakdown(null, null, null)['reseller_wallet_by_placed_via'])->keyBy('placed_via');

        $this->assertSame(['reseller_api', 'reseller_bot'], $split->keys()->sort()->values()->all());
        $this->assertSame(1700, $split['reseller_bot']['sales']);
        $this->assertSame(2, $split['reseller_bot']['orders_count']);
        $this->assertSame(300, $split['reseller_api']['sales']);
    }

    public function test_delivery_by_game_counts_outcomes_and_success_rate(): void
    {
        $mlbb = Game::query()->create(['name' => 'MLBB', 'slug' => 'mlbb']);
        $pubg = Game::query()->create(['name' => 'PUBG', 'slug' => 'pubg']);
        $this->order(['game_id' => $mlbb->id]);
        $this->order(['game_id' => $mlbb->id]);
        $this->order(['game_id' => $mlbb->id]);
        $this->order(['game_id' => $mlbb->id, 'delivery_status' => DeliveryStatus::Failed]);
        $this->order(['game_id' => $pubg->id, 'delivery_status' => DeliveryStatus::Processing]);
        $this->order(['game_id' => $pubg->id, 'delivery_status' => DeliveryStatus::PartiallyDelivered]);

        $rows = collect((new ReportService)->deliveryByGame(null, null, null))->keyBy('game_name');

        $this->assertSame(['total' => 4, 'delivered' => 3, 'failed' => 1, 'partially_delivered' => 0, 'in_progress' => 0, 'success_rate_pct' => 75.0],
            collect($rows['MLBB'])->only(['total', 'delivered', 'failed', 'partially_delivered', 'in_progress', 'success_rate_pct'])->all());
        $this->assertSame(1, $rows['PUBG']['in_progress']);
        $this->assertSame(1, $rows['PUBG']['partially_delivered']);
        $this->assertSame('MLBB', $rows->keys()->first()); // most orders first
    }

    /** R17 — All time spans the first paid order to today. */
    public function test_trend_range_for_all_time_starts_at_the_first_paid_order(): void
    {
        $this->order(['paid_at' => now()->subDays(40)]);

        [$from, $toExclusive] = (new ReportService)->trendRange(null, null, null);

        $this->assertSame(now(ReportService::TIMEZONE)->subDays(40)->toDateString(), $from->setTimezone(ReportService::TIMEZONE)->toDateString());
        $this->assertSame(now(ReportService::TIMEZONE)->addDay()->toDateString(), $toExclusive->setTimezone(ReportService::TIMEZONE)->toDateString());
    }

    public function test_trend_range_with_no_orders_is_just_today(): void
    {
        [$from, $toExclusive] = (new ReportService)->trendRange(null, null, null);

        $this->assertSame(1, (int) $from->diffInDays($toExclusive));
    }
}
