<?php

namespace App\Http\Controllers\Affiliate\Storefront;

use App\Http\Controllers\Affiliate\Concerns\AssertsAffiliateWritable;
use App\Http\Controllers\CatalogController;
use App\Http\Controllers\Controller;
use App\Http\Requests\Affiliate\Storefront\PreviewMarkupRequest;
use App\Http\Requests\Affiliate\Storefront\UpdateMarkupRequest;
use App\Models\AffiliateGame;
use App\Models\AffiliateMarkupChange;
use App\Models\Package;
use App\Services\Pricing\PricingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * ADR-060 PR-6 — the Pricing tab. The affiliate sets `markup_pct` (their
 * retail margin on top of the platform's wholesale price) and previews
 * the effect against real packages from their own visible catalog.
 *
 * The preview reuses `PricingService::calculateForAffiliate` — the exact
 * math the catalog and checkout price against — so a preview can never
 * diverge from the real charge. The wholesale-tier side of that math
 * (`wholesaleTierMarkupPct()`) is null while the subscription is lapsed;
 * the response flags that so the tab can explain the shrunken margin.
 */
class PricingController extends Controller
{
    use AssertsAffiliateWritable;

    public function __construct(private readonly PricingService $pricing) {}

    public function show(Request $request): JsonResponse
    {
        $affiliate = $request->user()->affiliateOwner();

        return response()->json($this->state($affiliate));
    }

    public function update(UpdateMarkupRequest $request): JsonResponse
    {
        $affiliate = $request->user()->affiliateOwner();
        $this->assertWritable($affiliate);

        $new = round((float) $request->validated('markup_pct'), 2);
        $max = (float) $affiliate->max_markup_pct;

        if ($new > $max) {
            return response()->json([
                'message' => "Your markup can be at most {$max}% — this ceiling is set by the platform.",
                'errors' => ['markup_pct' => ["Maximum allowed is {$max}%."]],
            ], 422);
        }

        $old = (float) $affiliate->markup_pct;

        // Compare at the column's own 2-dp precision — never raw float
        // equality on a value that crossed JSON + a decimal cast.
        if (number_format($new, 2) !== number_format($old, 2)) {
            $affiliate->update(['markup_pct' => $new]);

            $user = $request->user();
            AffiliateMarkupChange::create([
                'affiliate_id' => $affiliate->id,
                'old_pct' => $old,
                'new_pct' => $new,
                'source' => 'portal',
                'changed_by_id' => $user->id,
                'changed_by_label' => trim($user->name.' <'.$user->email.'>'),
                'created_at' => now(),
            ]);

            CatalogController::forgetCacheForBrand($affiliate->id);
        }

        return response()->json($this->state($affiliate->refresh()));
    }

    public function preview(PreviewMarkupRequest $request): JsonResponse
    {
        $affiliate = $request->user()->affiliateOwner();
        $markup = round((float) $request->validated('markup_pct'), 2);
        $tierPct = $affiliate->wholesaleTierMarkupPct();

        $rows = $this->sampleBands($this->visiblePackages())
            ->map(function (Package $package) use ($tierPct, $markup) {
                $breakdown = $this->pricing->calculateForAffiliate(
                    $package->cost_price,
                    $package->standard_selling_price,
                    $tierPct,
                    $markup,
                );

                return [
                    'game_name' => $package->game?->name,
                    'package_name' => $package->name,
                    'customer_price_sen' => $breakdown->sellingPrice,
                    'your_margin_sen' => $breakdown->affiliateProfit,
                ];
            })
            ->values();

        return response()->json([
            'markup_pct' => $markup,
            'rows' => $rows,
            'wholesale_rate_active' => $tierPct !== null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function state(object $affiliate): array
    {
        $stored = (float) $affiliate->markup_pct;
        $max = (float) $affiliate->max_markup_pct;

        return [
            'markup_pct' => $stored,
            // What the storefront actually prices at — clamped, so a
            // stale higher value never reaches a customer if the admin
            // lowered the ceiling (planning addendum decision 12).
            'effective_markup_pct' => min($stored, $max),
            'max_markup_pct' => $max,
            'wholesale_rate_active' => $affiliate->wholesaleTierMarkupPct() !== null,
        ];
    }

    /**
     * Active packages of the brand's currently-visible games, cheapest
     * first. `AffiliateGame` is `BelongsToAffiliate`-scoped, so the
     * hidden-id lookup is already this tenant's.
     *
     * @return Collection<int, Package>
     */
    private function visiblePackages(): Collection
    {
        $hiddenGameIds = AffiliateGame::query()->where('is_visible', false)->pluck('game_id');

        return Package::query()
            ->where('is_active', true)
            ->whereHas('game', fn ($q) => $q->where('is_active', true)->whereNotIn('id', $hiddenGameIds))
            ->with('game:id,name')
            ->orderBy('standard_selling_price')
            ->get();
    }

    /**
     * Low / mid / high price bands — three illustrative rows, or fewer
     * when the catalog has fewer than three packages.
     *
     * @param  Collection<int, Package>  $packages
     * @return Collection<int, Package>
     */
    private function sampleBands(Collection $packages): Collection
    {
        $count = $packages->count();

        if ($count <= 3) {
            return $packages->values();
        }

        return collect([
            $packages->first(),
            $packages->values()->get(intdiv($count, 2)),
            $packages->last(),
        ]);
    }
}
