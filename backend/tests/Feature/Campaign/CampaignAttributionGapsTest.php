<?php

namespace Tests\Feature\Campaign;

use App\Models\AdminUser;
use App\Models\Campaign;
use App\Models\Game;
use App\Models\Order;
use App\Models\PromoCode;
use App\Models\PromoCodeRedemption;
use App\Models\Reseller;
use App\Services\Campaign\CampaignFeeSchedule;
use App\Services\Campaign\CampaignService;
use App\Services\Campaign\PromoCustomer;
use App\Services\Order\DeliveryStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\Support\PromoCodeFixtures;
use Tests\TestCase;

/** ADR-118 2026-11-07 addendum, V21 to V25. */
class CampaignAttributionGapsTest extends TestCase
{
    use PromoCodeFixtures;
    use RefreshDatabase;

    private Game $game;

    private PromoCode $code;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-25 04:00:00');
        Sanctum::actingAs(AdminUser::factory()->superAdmin()->create());
        $this->game = Game::query()->create(['name' => 'MLBB', 'slug' => 'mlbb', 'is_active' => true]);
        $this->code = $this->promoCode();
    }

    private function first(string $status = PromoCodeRedemption::STATUS_RESERVED, array $redemption = [], array $order = []): Order
    {
        $first = $this->earlierOrder($this->game, array_merge([
            'customer_email' => 'buyer@example.com', 'customer_phone' => '0111111111', 'player_id' => '11',
            'promo_code_id' => $this->code->id, 'attributed_promo_code_id' => $this->code->id,
            'promo_discount_sen' => 100, 'paid_at' => now(),
        ], $order));
        PromoCodeRedemption::query()->create(array_merge([
            'promo_code_id' => $this->code->id, 'order_id' => $first->id, 'amount_sen' => 100, 'status' => $status,
        ], $redemption));

        return $first;
    }

    private function later(array $overrides = []): Order
    {
        return $this->earlierOrder($this->game, array_merge([
            'customer_email' => 'buyer@example.com', 'customer_phone' => '0111111111', 'player_id' => '11', 'paid_at' => now(),
        ], $overrides));
    }

    private function customer(): PromoCustomer
    {
        return PromoCustomer::at($this->game, 'buyer@example.com', '0111111111', '11', null);
    }

    public function test_an_order_placed_before_the_first_one_is_delivered_is_counted_once_it_is(): void
    {
        $first = $this->first();
        $second = $this->later();
        $stranger = $this->later(['customer_email' => 'x@example.com', 'customer_phone' => '0122222222', 'player_id' => '22']);

        app(CampaignService::class)->commit($first->id);

        $this->assertSame($this->code->id, $second->fresh()->attributed_promo_code_id);
        $this->assertNull($stranger->fresh()->attributed_promo_code_id);
    }

    public function test_a_stamp_with_a_value_is_never_rewritten(): void
    {
        $first = $this->first();
        $other = $this->promoCode('OTHER');
        $second = $this->later(['attributed_promo_code_id' => $other->id]);

        app(CampaignService::class)->commit($first->id);

        $this->assertSame($other->id, $second->fresh()->attributed_promo_code_id);
    }

    public function test_an_earlier_order_and_a_wallet_order_are_not_claimed(): void
    {
        $earlier = $this->later();
        $first = $this->first();
        $reseller = Reseller::query()->create(['business_name' => 'Acme', 'is_active' => true]);
        $wallet = $this->later(['wallet_reseller_id' => $reseller->id]);

        app(CampaignService::class)->commit($first->id);

        $this->assertNull($earlier->fresh()->attributed_promo_code_id);
        $this->assertNull($wallet->fresh()->attributed_promo_code_id);
    }

    public function test_a_failed_first_order_claims_nothing(): void
    {
        $first = $this->first();
        $second = $this->later();

        app(CampaignService::class)->restore($first->id);

        $this->assertNull($second->fresh()->attributed_promo_code_id);
        $this->assertNull(app(CampaignService::class)->attributionFor($this->primaryAffiliate()->id, $this->customer()));
    }

    public function test_a_partly_delivered_customer_stays_the_kols(): void
    {
        $first = $this->first(order: ['delivery_status' => DeliveryStatus::PartiallyDelivered->value]);
        $second = $this->later();

        app(CampaignService::class)->restore($first->id, 40);

        $this->assertSame($this->code->id, $second->fresh()->attributed_promo_code_id);
        $this->assertSame($this->code->id, app(CampaignService::class)->attributionFor($this->primaryAffiliate()->id, $this->customer()));

        $row = collect($this->getJson('/api/campaigns')->json('campaigns'))->firstWhere('id', $this->code->campaign_id);
        $this->assertSame(1, $row['cumulative']['redemptions']);
    }

    public function test_the_oldest_redemption_wins_when_two_match(): void
    {
        $this->first(PromoCodeRedemption::STATUS_COMMITTED, order: ['customer_phone' => '0133333333', 'player_id' => '33']);
        $other = $this->promoCode('OTHER');
        $second = $this->earlierOrder($this->game, [
            'customer_email' => 'else@example.com', 'customer_phone' => '0111111111', 'player_id' => '44',
            'promo_code_id' => $other->id, 'promo_discount_sen' => 100,
        ]);
        PromoCodeRedemption::query()->create(['promo_code_id' => $other->id, 'order_id' => $second->id, 'amount_sen' => 100, 'status' => PromoCodeRedemption::STATUS_COMMITTED]);

        $this->assertSame($this->code->id, app(CampaignService::class)->attributionFor($this->primaryAffiliate()->id, $this->customer()));
    }

    private function campaignWithFee(): Campaign
    {
        return app(CampaignFeeSchedule::class)->createCampaign('KOL Paid', 50_000, '2026-11', null, AdminUser::query()->value('id'));
    }

    public function test_ending_a_campaign_needs_its_last_fee_month_and_stops_the_fee(): void
    {
        $campaign = $this->campaignWithFee();
        $schedule = app(CampaignFeeSchedule::class);

        $this->patchJson("/api/campaigns/{$campaign->id}", ['ended' => true])->assertJsonValidationErrors('last_fee_month');
        $this->assertNull($campaign->fresh()->ended_at);

        $this->patchJson("/api/campaigns/{$campaign->id}", ['ended' => true, 'last_fee_month' => '2026-11'])->assertOk();

        $campaign = $campaign->fresh('fees');
        $this->assertNotNull($campaign->ended_at);
        $this->assertSame(50_000, $schedule->feeForMonth($campaign, '2026-11'));
        $this->assertSame(0, $schedule->feeForMonth($campaign, '2026-12'));
    }

    public function test_a_fee_that_already_has_a_last_month_needs_no_answer_and_keeps_it(): void
    {
        $campaign = $this->campaignWithFee();
        $campaign->fees()->first()->update(['to_month' => '2026-12-01']);

        $this->patchJson("/api/campaigns/{$campaign->id}", ['ended' => true])->assertOk();

        $this->assertSame('2026-12', $campaign->fresh('fees')->fees->first()->to_month->format('Y-m'));
    }

    public function test_the_order_payload_names_the_kol_an_order_counts_for(): void
    {
        $this->first(PromoCodeRedemption::STATUS_COMMITTED);
        $later = $this->later(['attributed_promo_code_id' => $this->code->id]);

        $this->getJson("/api/orders/{$later->id}")->assertOk()
            ->assertJsonPath('attributed_promo_code.code', 'KOL5')
            ->assertJsonPath('attributed_promo_code.campaign.name', 'KOL KOL5');
    }

    public function test_the_campaign_lists_its_customers_on_the_scorecards_orders(): void
    {
        $this->first(PromoCodeRedemption::STATUS_COMMITTED, order: ['selling_price' => 1900, 'final_amount' => 2000, 'transaction_fee' => 100]);
        $this->later(['attributed_promo_code_id' => $this->code->id, 'selling_price' => 3000, 'final_amount' => 3100, 'transaction_fee' => 100]);
        $this->later(['attributed_promo_code_id' => $this->code->id, 'selling_price' => 500, 'final_amount' => 500, 'is_test' => true]);

        $response = $this->getJson("/api/campaigns/{$this->code->campaign_id}")->assertOk();

        $customer = $response->json('customers.0');
        $this->assertCount(1, $response->json('customers'));
        $this->assertSame('buyer@example.com', $customer['email']);
        $this->assertSame('KOL5', $customer['code']);
        $this->assertSame(1, $customer['later_orders']);
        $this->assertSame(
            $response->json('cumulative.redeemed.sales_sen') + $response->json('cumulative.attributed_only.sales_sen'),
            $customer['first_order_sales_sen'] + $customer['later_sales_sen'],
        );
    }
}
