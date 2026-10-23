<?php

namespace Tests\Feature;

use App\Models\JokiRoute;
use App\Models\JokiSetting;
use App\Models\JokiTier;
use App\Models\Package;
use App\Models\Supplier;
use App\Services\Joki\JokiCatalog;
use App\Services\Joki\JokiPricingService;
use App\Services\Joki\JokiQuote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ADR-126 PR 2b, decisions 3-5 and the founder's 2026-10-23 answers: a star
 * is paid at the rate of the tier it is won in, a division is worth its
 * own stars (Epic V 0★ → Epic IV 0★ = 5 stars), Mythic Grading is a flat
 * price that guarantees Mythic 15★, and Mythic+ is priced on the Mythic
 * star count. Uses the seeded model and the ADR's draft rates:
 * every tier below Mythic RM2, Mythic RM4, Honor RM5, Glory RM6,
 * Immortal RM7, Grading RM39, Express = Reguler + RM1.
 */
class Adr126JokiPricingTest extends TestCase
{
    use RefreshDatabase;

    private function tier(string $name): int
    {
        return JokiTier::query()->where('name', $name)->value('id');
    }

    private function rank(string $tier, ?int $division, ?int $stars): array
    {
        return ['tier_id' => $this->tier($tier), 'division' => $division, 'stars' => $stars];
    }

    private function calculator(): Package
    {
        return app(JokiCatalog::class)->ensure();
    }

    private function quote(array $from, array $to, bool $express = false, ?Package $package = null): JokiQuote
    {
        return app(JokiPricingService::class)->quote($package ?? $this->calculator(), $from, $to, $express);
    }

    private function assertRefused(callable $action, string $field): void
    {
        try {
            $action();
            $this->fail("Expected a refusal on {$field}.");
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors(), json_encode($e->errors()));
        }
    }

    public function test_a_division_is_worth_its_own_stars(): void
    {
        $quote = $this->quote($this->rank('Epic', 5, 0), $this->rank('Epic', 4, 0));

        $this->assertSame(10, $quote->totalRm);
        $this->assertSame(1000, $quote->sellingSen());
        $this->assertSame([['label' => 'Epic', 'stars' => 5, 'rate_rm' => 2, 'amount_rm' => 10]], $quote->lines);
    }

    public function test_stars_cross_divisions_and_tiers_at_each_tiers_rate(): void
    {
        // Epic II 3★: 2 to finish II, 5 in Epic I; then Legend V 0★ → 2★.
        $quote = $this->quote($this->rank('Epic', 2, 3), $this->rank('Legend', 5, 2));

        $this->assertSame([
            ['label' => 'Epic', 'stars' => 7, 'rate_rm' => 2, 'amount_rm' => 14],
            ['label' => 'Legend', 'stars' => 2, 'rate_rm' => 2, 'amount_rm' => 4],
        ], $quote->lines);
        $this->assertSame(18, $quote->totalRm);
    }

    public function test_crossing_into_mythic_adds_grading_which_guarantees_mythic_15(): void
    {
        // Legend I 2★: 3 stars to finish Legend, then Grading.
        $toTen = $this->quote($this->rank('Legend', 1, 2), $this->rank('Mythic', null, 10));
        $this->assertSame([
            ['label' => 'Legend', 'stars' => 3, 'rate_rm' => 2, 'amount_rm' => 6],
            ['label' => 'Mythic Grading (to Mythic 15★)', 'stars' => null, 'rate_rm' => null, 'amount_rm' => 39],
        ], $toTen->lines);
        $this->assertSame(45, $toTen->totalRm);

        // Past the guaranteed 15★, Mythic stars at the Mythic rate.
        $toTwenty = $this->quote($this->rank('Legend', 1, 2), $this->rank('Mythic', null, 20));
        $this->assertSame(65, $toTwenty->totalRm);

        // An account already in grading pays the flat price only.
        $graded = $this->quote($this->rank('Mythic', null, null), $this->rank('Mythic', null, 15));
        $this->assertSame(39, $graded->totalRm);
    }

    public function test_mythic_stars_are_paid_at_the_rate_of_the_band_they_are_won_in(): void
    {
        $quote = $this->quote($this->rank('Mythic', null, 20), $this->rank('Mythical Honor', null, 30));

        $this->assertSame([
            ['label' => 'Mythic', 'stars' => 5, 'rate_rm' => 4, 'amount_rm' => 20],
            ['label' => 'Mythical Honor', 'stars' => 5, 'rate_rm' => 5, 'amount_rm' => 25],
        ], $quote->lines);
        $this->assertSame(45, $quote->totalRm);
    }

    public function test_express_uses_the_express_rates_and_grading_price(): void
    {
        JokiSetting::current()->update(['grading_express_price_rm' => 49]);

        $this->assertSame(15, $this->quote($this->rank('Epic', 5, 0), $this->rank('Epic', 4, 0), express: true)->totalRm);
        $this->assertSame(49 + 3 * 3, $this->quote($this->rank('Legend', 1, 2), $this->rank('Mythic', null, 10), express: true)->totalRm);
    }

    public function test_the_rates_come_from_the_panel_and_are_snapshotted(): void
    {
        JokiTier::query()->where('name', 'Epic')->update(['rate_rm' => 3, 'stars_per_division' => 4]);

        // Two 4-star divisions at RM3.
        $quote = $this->quote($this->rank('Epic', 5, 0), $this->rank('Epic', 3, 0));

        $this->assertSame(24, $quote->totalRm);
        $snapshot = $quote->snapshot();
        $this->assertSame(3, collect($snapshot['tiers'])->firstWhere('name', 'Epic')['rate_rm']);
        $this->assertSame(39, $snapshot['grading']['price_rm']);
        $this->assertSame(15, $snapshot['grading']['lands_at_stars']);
        $this->assertSame('Epic V 0★', $snapshot['from']['label']);
        $this->assertSame('Epic III 0★', $snapshot['to']['label']);
    }

    public function test_an_order_below_the_minimum_stars_is_refused_unless_it_includes_grading(): void
    {
        $this->assertRefused(fn () => $this->quote($this->rank('Epic', 5, 0), $this->rank('Epic', 5, 3)), 'joki.to');

        // 1 star then Grading is fine.
        $this->assertSame(2 + 39, $this->quote($this->rank('Legend', 1, 4), $this->rank('Mythic', null, 5))->totalRm);
    }

    public function test_a_rank_outside_the_model_or_a_target_not_above_the_current_rank_is_refused(): void
    {
        $cases = [
            'joki.from' => [$this->rank('Epic', 6, 0), $this->rank('Legend', 5, 0)],
            'joki.from ' => [$this->rank('Epic', 5, 5), $this->rank('Legend', 5, 0)],
            'joki.to' => [$this->rank('Legend', 5, 0), $this->rank('Epic', 5, 0)],
            'joki.to ' => [$this->rank('Mythic', null, 20), $this->rank('Mythic', null, 1001)],
            'joki.to  ' => [$this->rank('Legend', 5, 0), $this->rank('Mythic', null, null)],
            'joki.to   ' => [$this->rank('Mythical Honor', null, 30), $this->rank('Mythical Honor', null, 30)],
            'joki.from  ' => [$this->rank('Mythical Honor', null, 10), $this->rank('Mythical Glory', null, 60)],
            'joki.from   ' => [['tier_id' => 999, 'division' => 1, 'stars' => 0], $this->rank('Legend', 5, 0)],
        ];

        foreach ($cases as $field => [$from, $to]) {
            $this->assertRefused(fn () => $this->quote($from, $to), trim($field));
        }
    }

    public function test_a_route_package_is_sold_at_its_own_price_and_the_calculator_points_to_it_when_cheaper(): void
    {
        $route = $this->route('Epic II → Mythic', $this->rank('Epic', 2, 0), $this->rank('Mythic', null, 15), 30);

        $sold = $this->quote([], [], false, $route->package);
        $this->assertSame(30, $sold->totalRm);
        $this->assertSame('Epic II 0★', $sold->snapshot()['from']['label']);

        $this->assertRefused(fn () => $this->quote([], [], true, $route->package), 'joki.is_express');

        $calculated = $this->quote($this->rank('Epic', 2, 0), $this->rank('Mythic', null, 15));
        // Epic II 5 + Epic I 5 + Legend 25 = 35 stars, then Grading.
        $this->assertSame(2 * 35 + 39, $calculated->totalRm);
        $this->assertSame(['package_id' => $route->package_id, 'name' => 'Epic II → Mythic', 'price_rm' => 30], $calculated->cheaperRoute);
    }

    public function test_a_quotation_route_cannot_be_bought(): void
    {
        $route = $this->route('Mythic 1000 stars', $this->rank('Mythic', null, 0), $this->rank('Mythical Immortal', null, 1000), null, quotation: true);

        $this->assertRefused(fn () => $this->quote([], [], false, $route->package), 'package_id');
    }

    private function route(string $name, array $from, array $to, ?int $priceRm, bool $quotation = false): JokiRoute
    {
        $package = Package::query()->create([
            'game_id' => $this->calculator()->game_id, 'name' => $name, 'cost_price' => 0, 'standard_selling_price' => 0,
            'supplier_id' => Supplier::assignmentPool()->id, 'supplier_package_ref' => 'JOKI-'.strtoupper(uniqid()), 'is_active' => true,
        ]);

        return JokiRoute::query()->create([
            'package_id' => $package->id,
            'from_tier_id' => $from['tier_id'], 'from_division' => $from['division'], 'from_stars' => $from['stars'],
            'to_tier_id' => $to['tier_id'], 'to_division' => $to['division'], 'to_stars' => $to['stars'],
            'price_rm' => $priceRm, 'is_quotation' => $quotation,
        ]);
    }
}
