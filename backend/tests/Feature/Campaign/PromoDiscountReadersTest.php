<?php

namespace Tests\Feature\Campaign;

use App\Models\Game;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\PromoCodeFixtures;
use Tests\TestCase;

/**
 * ADR-118 V18: every surface that shows a voucher discount also shows the
 * KOL promo, so selling price, discount and total add up for the reader.
 */
class PromoDiscountReadersTest extends TestCase
{
    use PromoCodeFixtures;
    use RefreshDatabase;

    public function test_track_order_and_the_report_view_carry_the_promo_discount(): void
    {
        $code = $this->promoCode();
        $game = Game::query()->create(['name' => 'Mobile Legends', 'slug' => 'mobile-legends', 'is_active' => true]);
        $order = $this->earlierOrder($game, ['selling_price' => 950, 'final_amount' => 950, 'promo_code_id' => $code->id, 'promo_discount_sen' => 50, 'paid_at' => now()]);

        $this->getJson("/api/track-order/{$order->order_number}")
            ->assertOk()
            ->assertJsonPath('promo_discount', 50)
            ->assertJsonPath('selling_price', 950);

        $this->assertSame(50, (int) DB::table('llm_report_orders')->where('order_id', $order->id)->value('promo_discount_sen'));
    }
}
