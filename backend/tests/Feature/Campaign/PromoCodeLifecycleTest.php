<?php

namespace Tests\Feature\Campaign;

use App\Models\AdminUser;
use App\Models\Game;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\OrderDeliveryLeg;
use App\Models\Package;
use App\Models\PromoCode;
use App\Models\PromoCodeRedemption;
use App\Models\Supplier;
use App\Services\Campaign\CampaignService;
use App\Services\Campaign\InvalidPromoCodeException;
use App\Services\Campaign\PromoCustomer;
use App\Services\Campaign\PromoRejection;
use App\Services\Fulfillment\OrderSettlementService;
use App\Services\Order\DeliveryStatus;
use App\Services\Pricing\PricingBasis;
use App\Services\Pricing\PricingResolution;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\OrderFulfillmentFixtures;
use Tests\Support\PromoCodeFixtures;
use Tests\TestCase;

/**
 * ADR-118 V9 + V13: a promo order through delivery, compensation and a
 * Paid-after-Failed delivery. R1 means none of the profit writers change;
 * these prove the redemption follows the order and the ledger credits the
 * profit after the promo.
 */
class PromoCodeLifecycleTest extends TestCase
{
    use OrderFulfillmentFixtures;
    use PromoCodeFixtures;
    use RefreshDatabase;

    public function test_a_delivered_promo_order_commits_its_code_and_credits_the_profit_after_the_promo(): void
    {
        $code = $this->promoCode();
        // Listed at 1000, 5% off: sold at 950 on a cost of 900.
        $order = $this->paidOrder(['selling_price' => 950, 'final_amount' => 1050, 'platform_profit' => 50, 'promo_code_id' => $code->id, 'promo_discount_sen' => 50]);
        $this->reserve($code, $order, 50);

        $this->service($this->fakeSupplierAdapter(true, ['supplier_ref' => 'SUP-1']))->fulfill($order);

        $this->assertSame('committed', PromoCodeRedemption::query()->where('order_id', $order->id)->value('status'));
        $this->assertSame(50, (int) LedgerEntry::query()->where('type', 'order_profit')->where('owner_type', 'platform')->sum('amount'));
    }

    public function test_a_paid_after_failed_delivery_re_reserves_its_code_and_flags_the_overrun_without_blocking(): void
    {
        $code = $this->promoCode('KOL5', ['budget_sen' => 50]);
        $order = $this->paidOrder(['selling_price' => 950, 'final_amount' => 1050, 'platform_profit' => 50, 'promo_code_id' => $code->id, 'promo_discount_sen' => 50]);
        // Its payment once failed, so its share went back and another order took the whole budget.
        PromoCodeRedemption::query()->create(['promo_code_id' => $code->id, 'order_id' => $order->id, 'amount_sen' => 50, 'restored_sen' => 50, 'status' => 'restored', 'restored_at' => now()]);
        $other = $this->paidOrder(['order_number' => 'KRS-TEST-2', 'customer_email' => 'other@example.com', 'promo_code_id' => $code->id]);
        $this->reserve($code, $other, 50);

        $this->service($this->fakeSupplierAdapter(true, ['supplier_ref' => 'SUP-1']))->fulfill($order);

        $redemption = PromoCodeRedemption::query()->where('order_id', $order->id)->firstOrFail();
        $this->assertSame('delivered', $order->fresh()->delivery_status->value);
        $this->assertSame('committed', $redemption->status);
        $this->assertSame(0, $redemption->restored_sen);
        $this->assertTrue($redemption->over_cap);
        $this->assertSame(100, app(CampaignService::class)->usedSen($code));
    }

    public function test_compensating_a_failed_promo_order_gives_back_the_code_and_what_was_paid(): void
    {
        $code = $this->promoCode();
        $order = $this->paidOrder(['selling_price' => 950, 'final_amount' => 1050, 'transaction_fee' => 100, 'platform_profit' => 50, 'promo_code_id' => $code->id, 'promo_discount_sen' => 50, 'delivery_status' => DeliveryStatus::Failed->value]);
        $this->reserve($code, $order, 50);

        $result = app(OrderSettlementService::class)->settle($order, AdminUser::factory()->create()->id);

        // The voucher is what the customer paid after the promo, never the listed price.
        $this->assertSame(950, $result->voucher->amount);
        $this->assertSame(0, app(CampaignService::class)->usedSen($code));
        $this->assertSame('restored', PromoCodeRedemption::query()->where('order_id', $order->id)->value('status'));
    }

    public function test_compensating_a_partial_promo_order_gives_back_only_the_undelivered_share(): void
    {
        $code = $this->promoCode();
        $supplier = Supplier::query()->create(['name' => 'Digiflazz', 'slug' => 'digiflazz', 'api_config' => [], 'currency' => 'MYR']);
        $game = Game::query()->create(['name' => 'MLBB', 'slug' => 'mlbb-partial']);
        $legA = Package::query()->create(['game_id' => $game->id, 'name' => 'A', 'denomination' => 100, 'cost_price' => 2500, 'standard_selling_price' => 3000, 'supplier_id' => $supplier->id, 'supplier_package_ref' => 'A']);
        $legB = Package::query()->create(['game_id' => $game->id, 'name' => 'B', 'denomination' => 100, 'cost_price' => 1700, 'standard_selling_price' => 2000, 'supplier_id' => $supplier->id, 'supplier_package_ref' => 'B']);
        // Listed 5000, 5% off: 4750. Leg B (2000 of 5000) failed: 40% of the promo goes back.
        $order = $this->paidOrder(['game_id' => $game->id, 'cost_price' => 4200, 'standard_selling_price' => 5000, 'selling_price' => 4750, 'final_amount' => 4850, 'platform_profit' => 550, 'promo_code_id' => $code->id, 'promo_discount_sen' => 250, 'delivery_status' => DeliveryStatus::PartiallyDelivered->value]);
        OrderDeliveryLeg::query()->create(['order_id' => $order->id, 'component_package_id' => $legA->id, 'supplier_id' => $supplier->id, 'leg_number' => 1, 'status' => 'delivered', 'selling_price_sen' => 3000, 'real_cost_price_sen' => 2500]);
        OrderDeliveryLeg::query()->create(['order_id' => $order->id, 'component_package_id' => $legB->id, 'supplier_id' => $supplier->id, 'leg_number' => 2, 'status' => 'failed', 'selling_price_sen' => 2000]);
        $this->reserve($code, $order, 250);

        app(OrderSettlementService::class)->settle($order, AdminUser::factory()->create()->id);

        $this->assertSame(100, PromoCodeRedemption::query()->where('order_id', $order->id)->value('restored_sen'));
        $this->assertSame(150, app(CampaignService::class)->usedSen($code));
    }

    public function test_a_joki_order_cannot_take_a_promo(): void
    {
        $this->promoCode();
        $game = Game::query()->create(['name' => 'Joki MLBB', 'slug' => 'joki-mlbb']);

        try {
            app(CampaignService::class)->quote('KOL5', $this->primaryAffiliate()->id,
                new PricingResolution(costPriceSen: 1000, standardSellingPriceSen: 2000, sellingPriceSen: 2000, platformProfitSen: 1000, affiliateProfitSen: 0, basis: PricingBasis::Fixed),
                PromoCustomer::at($game, 'buyer@example.com', '0123456789', '123', null));
            $this->fail('A Fixed (joki) order must not take a promo.');
        } catch (InvalidPromoCodeException $e) {
            $this->assertSame(PromoRejection::NotApplicable, $e->reason);
        }
    }

    private function reserve(PromoCode $code, Order $order, int $amount): void
    {
        PromoCodeRedemption::query()->create(['promo_code_id' => $code->id, 'order_id' => $order->id, 'amount_sen' => $amount, 'status' => 'reserved']);
    }
}
