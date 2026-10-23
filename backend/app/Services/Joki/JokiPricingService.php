<?php

namespace App\Services\Joki;

use App\Models\JokiRoute;
use App\Models\JokiTier;
use App\Models\Package;

/**
 * ADR-126 decisions 3-5 and the founder's 2026-10-23 answers: the one place
 * a joki price is computed (ORD-9; the client sends ranks and Express
 * only). Checkout, its preview and the `/joki` quote all call quote().
 *
 *  - A star is paid at the rate of the tier it is won in.
 *  - Crossing from below Mythic adds the flat Mythic Grading price, which
 *    guarantees Mythic `grading_lands_at_stars` (15); only stars past it
 *    are charged, at the Mythic band's rate.
 *  - Fewer than `min_stars` paid stars is refused unless Grading is in.
 *  - A route package sells its own climb at its own price, Reguler only;
 *    a quotation route is never sold.
 */
final class JokiPricingService
{
    /**
     * @param  array<string, mixed>  $from  ignored for a route package
     * @param  array<string, mixed>  $to  ignored for a route package
     */
    public function quote(Package $package, array $from, array $to, bool $express): JokiQuote
    {
        $ladder = JokiLadder::current();
        $package->loadMissing('game');

        if (! (bool) $package->game?->is_joki || ! $package->is_active) {
            throw $ladder->invalid('package_id', 'This joki package is not available.');
        }

        $route = JokiRoute::query()->where('package_id', $package->id)->first();

        return $route !== null
            ? $this->routeQuote($ladder, $package, $route, $express)
            : $this->calculatorQuote($ladder, $from, $to, $express);
    }

    private function calculatorQuote(JokiLadder $ladder, array $from, array $to, bool $express): JokiQuote
    {
        $start = $ladder->position($from, 'joki.from');
        $end = $ladder->position($to, 'joki.to');

        if ($end->kind === JokiPosition::GRADING) {
            throw $ladder->invalid('joki.to', 'Choose a target rank.');
        }

        if ($end->index <= $start->index && ! ($start->kind === JokiPosition::GRADING && $end->kind === JokiPosition::MYTHIC)) {
            throw $ladder->invalid('joki.to', 'The target rank must be above the current rank.');
        }

        $settings = $ladder->settings;
        $grading = $start->kind !== JokiPosition::MYTHIC && $end->kind === JokiPosition::MYTHIC;
        $lines = [];

        // Below Mythic: from the current star to the target, or to Legend finished.
        if ($start->kind === JokiPosition::DIVISIONAL) {
            $stopAt = $end->kind === JokiPosition::DIVISIONAL ? $end->index : $ladder->belowMythicStars();
            for ($i = $start->index; $i < $stopAt; $i++) {
                $this->addStar($lines, $ladder->divisionalTierAt($i), $express);
            }
        }

        if ($grading) {
            $lines[] = [
                'label' => "Mythic Grading (to Mythic {$settings->grading_lands_at_stars}★)",
                'stars' => null,
                'rate_rm' => null,
                'amount_rm' => $express ? $settings->grading_express_price_rm : $settings->grading_price_rm,
            ];
        }

        // Mythic and up: from the current Mythic star, or from the star Grading guarantees.
        if ($end->kind === JokiPosition::MYTHIC) {
            $firstStar = $grading ? $settings->grading_lands_at_stars : $start->stars;
            for ($s = $firstStar; $s < $end->stars; $s++) {
                $this->addStar($lines, $ladder->mythicTierAt($s), $express);
            }
        }

        $lines = array_values($lines);
        $paidStars = array_sum(array_map(fn (array $l) => $l['stars'] ?? 0, $lines));
        if (! $grading && $paidStars < $settings->min_stars) {
            throw $ladder->invalid('joki.to', "A joki order is at least {$settings->min_stars} stars.");
        }

        $total = array_sum(array_column($lines, 'amount_rm'));

        return new JokiQuote(
            totalRm: $total,
            lines: $lines,
            from: $start->toArray(),
            to: $end->toArray(),
            isExpress: $express,
            routePackageId: null,
            cheaperRoute: $express ? null : $this->cheaperRoute($start, $end, $total),
            model: $this->model($ladder),
        );
    }

    private function routeQuote(JokiLadder $ladder, Package $package, JokiRoute $route, bool $express): JokiQuote
    {
        if ($route->is_quotation || $route->price_rm === null) {
            throw $ladder->invalid('package_id', 'This route is priced on request. Contact us on WhatsApp.');
        }

        if ($express) {
            throw $ladder->invalid('joki.is_express', 'Express is for the rank calculator only.');
        }

        return new JokiQuote(
            totalRm: $route->price_rm,
            lines: [['label' => $package->name, 'stars' => null, 'rate_rm' => null, 'amount_rm' => $route->price_rm]],
            from: $ladder->position($route->fromRank(), 'package_id')->toArray(),
            to: $ladder->position($route->toRank(), 'package_id')->toArray(),
            isExpress: false,
            routePackageId: $package->id,
            cheaperRoute: null,
            model: $this->model($ladder),
        );
    }

    /** @param  array<string, array{label: string, stars: int, rate_rm: int, amount_rm: int}>  $lines */
    private function addStar(array &$lines, JokiTier $tier, bool $express): void
    {
        $rate = $tier->rateRm($express);
        $lines[$tier->name] ??= ['label' => $tier->name, 'stars' => 0, 'rate_rm' => $rate, 'amount_rm' => 0];
        $lines[$tier->name]['stars']++;
        $lines[$tier->name]['amount_rm'] += $rate;
    }

    /** @return array{package_id: int, name: string, price_rm: int}|null */
    private function cheaperRoute(JokiPosition $start, JokiPosition $end, int $totalRm): ?array
    {
        $route = JokiRoute::query()
            ->with('package:id,name,is_active')
            ->where('is_quotation', false)
            ->where('price_rm', '<', $totalRm)
            ->where('from_tier_id', $start->tier->id)->where('from_division', $start->division)->where('from_stars', $start->stars)
            ->where('to_tier_id', $end->tier->id)->where('to_division', $end->division)->where('to_stars', $end->stars)
            ->orderBy('price_rm')
            ->get()
            ->first(fn (JokiRoute $r) => (bool) $r->package?->is_active);

        return $route === null ? null : ['package_id' => $route->package_id, 'name' => $route->package->name, 'price_rm' => $route->price_rm];
    }

    /** @return array<string, mixed> */
    private function model(JokiLadder $ladder): array
    {
        $settings = $ladder->settings;

        return [
            'tiers' => $ladder->tiers()->map(fn (JokiTier $t) => $t->only([
                'id', 'name', 'kind', 'divisions', 'stars_per_division', 'mythic_from_stars', 'rate_rm', 'express_rate_rm',
            ]))->values()->all(),
            'grading' => [
                'price_rm' => $settings->grading_price_rm,
                'express_price_rm' => $settings->grading_express_price_rm,
                'lands_at_stars' => $settings->grading_lands_at_stars,
            ],
            'min_stars' => $settings->min_stars,
        ];
    }
}
