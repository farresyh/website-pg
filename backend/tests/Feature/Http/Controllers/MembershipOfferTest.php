<?php

namespace Tests\Feature\Http\Controllers;

use App\Models\Game;
use App\Models\MembershipPlan;
use App\Models\Order;
use App\Models\Package;
use App\Models\PaymentMethod;
use App\Models\PlatformSettings;
use App\Models\Supplier;
use App\Services\Pricing\MembershipPricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-055 2026-11-07 addendum: GET /api/membership/offer feeds the
 * signed-out /membership page. Every figure there is derived from the
 * same seams checkout and subscribe use, never typed.
 */
class MembershipOfferTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->primaryAffiliate();
        PlatformSettings::current()->update(['membership_enabled' => true]);
        PaymentMethod::query()->delete();
        $this->channel('fpx', 0, 100);
        $this->channel('dnqr', 1, 0);
    }

    private function channel(string $code, float $rate, int $flatSen, bool $active = true): void
    {
        PaymentMethod::query()->create([
            'channel_code' => $code,
            'method_key' => "{$code}_chip",
            'label' => $code,
            'category' => $code,
            'gateway' => 'chip',
            'is_active' => $active,
            'percentage_rate' => $rate,
            'flat_fee_sen' => $flatSen,
        ]);
    }

    private function package(string $name, int $costSen, array $gameAttributes = [], array $attributes = []): Package
    {
        $supplier = Supplier::query()->firstOrCreate(['slug' => 'digiflazz'], ['name' => 'Digiflazz', 'api_config' => [], 'is_active' => true]);
        $game = Game::query()->create([
            'name' => 'Game '.uniqid(),
            'slug' => 'game-'.uniqid(),
            'is_active' => true,
            'member_pricing_enabled' => true,
            ...$gameAttributes,
        ]);

        return Package::query()->create([
            'game_id' => $game->id,
            'supplier_id' => $supplier->id,
            'supplier_package_ref' => 'REF'.uniqid(),
            'name' => $name,
            'cost_price' => $costSen,
            'markup_percent' => 10,
            'standard_selling_price' => (int) round($costSen * 1.1),
            'is_active' => true,
            ...$attributes,
        ]);
    }

    private function sold(Package $package, int $times, array $attributes = []): void
    {
        Order::factory()->count($times)->create([
            'package_id' => $package->id,
            'game_id' => $package->game_id,
            'paid_at' => now()->subDays(5),
            ...$attributes,
        ]);
    }

    public function test_it_is_closed_when_membership_is_off(): void
    {
        PlatformSettings::current()->update(['membership_enabled' => false]);

        $this->getJson('/api/membership/offer')->assertForbidden();
    }

    public function test_each_plan_carries_its_quota_real_saving_and_the_lowest_charge(): void
    {
        $this->package('Median', 2000);
        $plan = MembershipPlan::query()->where('name', 'Tier 1')->firstOrFail();
        $plan->update(['fee_sen' => 690, 'quota_sen' => 33500, 'discount_percent' => 50]);

        $first = $this->getJson('/api/membership/offer')->assertOk()->json('plans.0');

        $this->assertSame($plan->id, $first['id']);
        $this->assertSame(33500, $first['quota_sen']);
        $this->assertSame(690, $first['fee_sen']);
        // DuitNow's 1% beats FPX's RM1 on RM6.90: what the customer really pays at the least.
        $this->assertSame(697, $first['lowest_charge_sen']);
        $this->assertSame(4.5, $first['real_savings_percent']);
    }

    public function test_an_inactive_channel_never_sets_the_lowest_charge(): void
    {
        PaymentMethod::query()->where('channel_code', 'dnqr')->update(['is_active' => false]);
        MembershipPlan::query()->where('name', 'Tier 1')->update(['fee_sen' => 690]);

        $this->getJson('/api/membership/offer')->assertOk()->assertJsonPath('plans.0.lowest_charge_sen', 790);
    }

    public function test_examples_are_the_best_sellers_in_the_price_band_with_real_member_prices(): void
    {
        $second = $this->package('284 Diamonds', 2000);
        $first = $this->package('660 UC', 3800);
        $this->sold($second, 2);
        $this->sold($first, 3);

        $examples = $this->getJson('/api/membership/offer')->assertOk()->json('examples');

        $this->assertSame(['660 UC', '284 Diamonds'], array_column($examples, 'package_name'));
        $this->assertSame($first->game->name, $examples[0]['game_name']);
        $this->assertSame($first->standard_selling_price, $examples[0]['regular_sen']);

        $pricing = app(MembershipPricingService::class);
        foreach (MembershipPlan::query()->orderBy('id')->get() as $index => $plan) {
            $this->assertSame(
                $pricing->calculateMemberPrice($first->cost_price, 10.0, (float) $plan->discount_percent),
                $examples[0]['member_sen'][$index],
            );
        }
    }

    public function test_examples_skip_what_a_visitor_could_not_buy_at_a_member_price(): void
    {
        $this->sold($this->package('Cheap', 500), 9);
        $this->sold($this->package('Too dear', 9000), 9);
        $this->sold($this->package('First Topup 250 + 250 Diamonds', 2000), 9);
        $this->sold($this->package('Switched off', 2000, attributes: ['is_active' => false]), 9);
        $this->sold($this->package('Hidden game', 2000, ['is_active' => false]), 9);
        $this->sold($this->package('No member price', 2000, ['member_pricing_enabled' => false]), 9);
        $this->sold($this->package('Sandbox only', 2000), 9, ['is_test' => true]);
        $this->sold($this->package('Long ago', 2000), 9, ['paid_at' => now()->subDays(91)]);
        $this->sold($this->package('Good', 2000), 1);

        $examples = $this->getJson('/api/membership/offer')->assertOk()->json('examples');

        $this->assertSame(['Good'], array_column($examples, 'package_name'));
    }

    public function test_a_same_named_package_in_one_game_shows_once(): void
    {
        $one = $this->package('325 UC', 2000);
        $two = $this->package('325 UC', 2010, attributes: ['game_id' => $one->game_id]);
        $this->sold($one, 2);
        $this->sold($two, 1);

        $this->assertCount(1, $this->getJson('/api/membership/offer')->assertOk()->json('examples'));
    }
}
