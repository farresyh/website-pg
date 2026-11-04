<?php

namespace Tests\Support;

use App\Models\Campaign;
use App\Models\Game;
use App\Models\Order;
use App\Models\PromoCode;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\PaymentStatus;
use Illuminate\Support\Str;

/** ADR-118 V1–V20: campaigns, codes and earlier orders for the promo tests. */
trait PromoCodeFixtures
{
    private function promoCode(string $code = 'KOL5', array $overrides = [], ?int $affiliateId = null): PromoCode
    {
        $campaign = Campaign::query()->create([
            'affiliate_id' => $affiliateId ?? $this->primaryAffiliate()->id,
            'name' => 'KOL '.$code,
        ]);

        return PromoCode::query()->create(array_merge([
            'campaign_id' => $campaign->id,
            'code' => $code,
            'discount_percent' => 5,
            'budget_sen' => 100_000,
            'is_active' => true,
        ], $overrides));
    }

    /** An order already on the books, by default delivered on the primary brand. */
    private function earlierOrder(Game $game, array $overrides = []): Order
    {
        return Order::query()->create(array_merge([
            'placed_via' => 'storefront',
            'order_number' => 'PG-'.Str::upper(Str::random(10)),
            'affiliate_id' => $this->primaryAffiliate()->id,
            'game_id' => $game->id,
            'customer_email' => 'someone@example.com',
            'customer_name' => 'Someone',
            'customer_phone' => '0199999999',
            'player_id' => '999999999',
            'cost_price' => 421,
            'standard_selling_price' => 500,
            'selling_price' => 500,
            'transaction_fee' => 0,
            'final_amount' => 500,
            'platform_profit' => 79,
            'affiliate_profit' => 0,
            'payment_status' => PaymentStatus::Paid->value,
            'delivery_status' => DeliveryStatus::Delivered->value,
        ], $overrides));
    }
}
