<?php

namespace Tests\Feature\Campaign;

use App\Models\AdminUser;
use App\Models\Campaign;
use App\Models\Game;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\PlatformSettings;
use App\Models\PromoCode;
use App\Models\PromoCodeClick;
use App\Models\PromoCodeRedemption;
use App\Services\Campaign\CampaignFeeSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\Support\PromoCodeFixtures;
use Tests\TestCase;

/**
 * ADR-118 V14: is this KOL worth paying again? Profit is the ledger's (as
 * Reports), fees are the accrued schedule (never payment dates), and a
 * new customer counts as qualified only with a first order of at least
 * the panel minimum (RM10) or a repeat purchase.
 *
 * November 2026, campaign KOL 1 (RM500 a month), code KOL5:
 * - customer 1: redeemed RM19.00 (paid RM20.00), came back on the 20th (RM31.00);
 * - customer 2: redeemed RM5.00 (paid RM6.00), never came back: not qualified;
 * - customer 3: redeemed RM15.00 (paid RM16.00), never came back.
 */
class CampaignScorecardTest extends TestCase
{
    use PromoCodeFixtures;
    use RefreshDatabase;

    private Campaign $campaign;

    private PromoCode $code;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-25 04:00:00');
        $admin = AdminUser::factory()->superAdmin()->create();
        Sanctum::actingAs($admin);
        $this->primaryAffiliate();

        $schedule = app(CampaignFeeSchedule::class);
        $this->campaign = $schedule->createCampaign('KOL 1', 50_000, '2026-11', null, $admin->id);
        $schedule->createCampaign('KOL 2', 0, '2026-11', null, $admin->id);
        $this->code = PromoCode::query()->create(['campaign_id' => $this->campaign->id, 'code' => 'KOL5', 'discount_percent' => 5, 'budget_sen' => 100_000, 'is_active' => true]);

        $game = Game::query()->create(['name' => 'MLBB', 'slug' => 'mlbb', 'is_active' => true]);
        $this->redeemed($game, 'one@example.com', '0111111111', '11', 1900, 100, 2000, 150, '2026-11-05 03:00:00');
        $this->attributedOnly($game, 'one@example.com', '0111111111', '11', 3000, 3100, 250, '2026-11-20 03:00:00');
        $this->redeemed($game, 'two@example.com', '0122222222', '22', 500, 26, 600, 40, '2026-11-06 03:00:00');
        $this->redeemed($game, 'three@example.com', '0133333333', '33', 1500, 78, 1600, 120, '2026-11-07 03:00:00');

        foreach (['2026-11-02', '2026-11-03', '2026-11-04', '2026-10-20'] as $day) {
            PromoCodeClick::query()->create(['promo_code_id' => $this->code->id])->forceFill(['created_at' => "{$day} 05:00:00"])->save();
        }
    }

    public function test_the_november_scorecard(): void
    {
        $row = $this->row($this->getJson('/api/campaigns?from=2026-11-01&to=2026-11-30')->assertOk()->json('campaigns'), 'KOL 1')['range'];

        $this->assertSame(3, $row['clicks']);
        $this->assertSame(3, $row['redemptions']);
        $this->assertSame(3, $row['new_customers']);
        $this->assertSame(2, $row['qualified_new_customers']);
        $this->assertSame(1500, $row['median_first_order_sen']);
        $this->assertSame(1, $row['returned_customers']);
        $this->assertSame(1, $row['repeat_30d_customers']);
        $this->assertSame(['sales_sen' => 4200, 'profit_sen' => 310, 'orders' => 3], $row['redeemed']);
        $this->assertSame(['sales_sen' => 3100, 'profit_sen' => 250, 'orders' => 1], $row['attributed_only']);
        $this->assertSame(204, $row['discount_sen']);
        $this->assertSame(50_000, $row['fees_accrued_sen']);
        $this->assertSame(25_000, $row['cac_sen']);
        $this->assertSame(310 + 250 - 50_000, $row['net_sen']);
        $this->assertSame([['code' => 'KOL5', 'redemptions' => 3, 'new_customers' => 3, 'sales_sen' => 7300]], array_map(
            fn ($c) => array_intersect_key($c, array_flip(['code', 'redemptions', 'new_customers', 'sales_sen'])),
            $row['codes'],
        ));
    }

    public function test_a_range_before_the_campaign_shows_only_its_clicks_and_no_cac(): void
    {
        $row = $this->row($this->getJson('/api/campaigns?from=2026-10-01&to=2026-10-31')->json('campaigns'), 'KOL 1')['range'];

        $this->assertSame(1, $row['clicks']);
        $this->assertSame(0, $row['new_customers']);
        $this->assertSame(0, $row['fees_accrued_sen']);
        $this->assertNull($row['cac_sen']);
        $this->assertNull($row['median_first_order_sen']);
    }

    public function test_cumulative_fees_follow_the_schedule_not_the_payments_and_a_campaign_without_activity_still_shows(): void
    {
        Carbon::setTestNow('2027-01-10 04:00:00');
        $campaigns = $this->getJson('/api/campaigns')->json('campaigns');

        $one = $this->row($campaigns, 'KOL 1')['cumulative'];
        $this->assertSame(150_000, $one['fees_accrued_sen']); // Nov, Dec, Jan
        $this->assertSame(75_000, $one['cac_sen']);
        $this->assertSame(560 - 150_000, $one['net_sen']);

        $two = $this->row($campaigns, 'KOL 2')['cumulative'];
        $this->assertSame(0, $two['new_customers']);
        $this->assertNull($two['cac_sen']);
    }

    public function test_the_verdict_needs_a_target_and_a_trial_and_waits_for_the_trial_to_end(): void
    {
        $this->assertNull($this->row($this->getJson('/api/campaigns')->json('campaigns'), 'KOL 1')['verdict']);

        PlatformSettings::current()->update(['campaign_target_cac_sen' => 60_000, 'campaign_trial_months' => 2]);
        $this->assertSame(['status' => 'in_trial', 'month' => 1, 'of' => 2], $this->row($this->getJson('/api/campaigns')->json('campaigns'), 'KOL 1')['verdict']);

        // January: the trial (Nov, Dec) is over; RM1,500 accrued ÷ 2 qualified = RM750 > RM600.
        Carbon::setTestNow('2027-01-10 04:00:00');
        $this->assertSame('above_target', $this->row($this->getJson('/api/campaigns')->json('campaigns'), 'KOL 1')['verdict']['status']);

        PlatformSettings::current()->update(['campaign_target_cac_sen' => 80_000]);
        $this->assertSame('worth_keeping', $this->row($this->getJson('/api/campaigns')->json('campaigns'), 'KOL 1')['verdict']['status']);
    }

    private function row(array $campaigns, string $name): array
    {
        return collect($campaigns)->firstWhere('name', $name);
    }

    private function redeemed(Game $game, string $email, string $phone, string $playerId, int $selling, int $promo, int $final, int $profit, string $paidAt): Order
    {
        $order = $this->order($game, $email, $phone, $playerId, $selling, $final, $profit, $paidAt, ['promo_code_id' => $this->code->id, 'promo_discount_sen' => $promo]);
        PromoCodeRedemption::query()->create(['promo_code_id' => $this->code->id, 'order_id' => $order->id, 'amount_sen' => $promo, 'status' => PromoCodeRedemption::STATUS_COMMITTED]);

        return $order;
    }

    private function attributedOnly(Game $game, string $email, string $phone, string $playerId, int $selling, int $final, int $profit, string $paidAt): Order
    {
        return $this->order($game, $email, $phone, $playerId, $selling, $final, $profit, $paidAt, []);
    }

    private function order(Game $game, string $email, string $phone, string $playerId, int $selling, int $final, int $profit, string $paidAt, array $extra): Order
    {
        $order = $this->earlierOrder($game, array_merge([
            'customer_email' => $email,
            'customer_phone' => $phone,
            'player_id' => $playerId,
            'selling_price' => $selling,
            'final_amount' => $final,
            'transaction_fee' => $final - $selling,
            'platform_profit' => $profit,
            'paid_at' => $paidAt,
            'recognised_at' => $paidAt,
            'attributed_promo_code_id' => $this->code->id,
        ], $extra));

        LedgerEntry::query()->create(['owner_type' => 'platform', 'owner_id' => null, 'type' => 'order_profit', 'amount' => $profit, 'reference_type' => 'order', 'reference_id' => $order->id]);

        return $order;
    }
}
