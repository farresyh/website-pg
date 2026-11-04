<?php

namespace Tests\Feature\Campaign;

use App\Models\Affiliate;
use App\Models\AffiliateDomain;
use App\Models\PromoCodeClick;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\PromoCodeFixtures;
use Tests\TestCase;

/**
 * ADR-118 V16: a `?promo=CODE` visit is logged as one click for the
 * scorecard. The endpoint is public, so it answers the same way whatever
 * was sent and records only a real code on its own brand.
 */
class PromoCodeClickTest extends TestCase
{
    use PromoCodeFixtures;
    use RefreshDatabase;

    public function test_a_click_on_a_code_is_recorded_case_insensitively(): void
    {
        $code = $this->promoCode('KOL5');

        $this->postJson('/api/promo-clicks', ['code' => ' kol5 '])->assertNoContent();

        $this->assertSame(1, PromoCodeClick::query()->where('promo_code_id', $code->id)->count());
    }

    public function test_an_unknown_code_or_another_brands_code_records_nothing_and_answers_the_same(): void
    {
        $this->promoCode('KOL5');
        $brand = Affiliate::query()->create(['business_name' => 'Acme', 'email' => 'a@acme.test', 'markup_pct' => 10, 'max_markup_pct' => 30, 'status' => 'active']);
        AffiliateDomain::query()->create(['affiliate_id' => $brand->id, 'hostname' => 'shop.acme.test', 'status' => 'active']);

        $this->postJson('/api/promo-clicks', ['code' => 'NOPE'])->assertNoContent();
        $this->postJson('/api/promo-clicks', ['code' => 'KOL5'], ['X-Storefront-Host' => 'shop.acme.test'])->assertNoContent();

        $this->assertSame(0, PromoCodeClick::query()->count());
    }

    public function test_the_code_is_validated_structurally(): void
    {
        $this->postJson('/api/promo-clicks', [])->assertJsonValidationErrors('code');
        $this->postJson('/api/promo-clicks', ['code' => str_repeat('A', 33)])->assertJsonValidationErrors('code');
    }
}
