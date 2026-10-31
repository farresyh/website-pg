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

        if ($refused = $this->refuseOwned($affiliate)) {
            return $refused;
        }

        $new = round((float) $request->validated('markup_pct'), 2);
        // A null ceiling opens no markup (ADR-061 2026-10-31 addendum).
        $max = (float) ($affiliate->max_markup_pct ?? 0);

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

        if ($refused = $this->refuseOwned($affiliate)) {
            return $refused;
        }

        $markup = round((float) $request->validated('markup_pct'), 2);
        $tierPct = $affiliate->storefrontPriceTerms()['tier'];

        $rows = $this->sampleBands($this->visiblePackages($affiliate->id))
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

    /** ADR-061 2026-10-30 addendum decision 3: an owned brand has no markup of its own. */
    private function refuseOwned(object $affiliate): ?JsonResponse
    {
        if (! $affiliate->is_owned) {
            return null;
        }

        return response()->json([
            'message' => 'An owned brand prices as the platform and has no markup of its own.',
            'errors' => ['markup_pct' => ['An owned brand has no markup of its own.']],
        ], 422);
    }

    /**
     * @return array<string, mixed>
     */
    private function state(object $affiliate): array
    {
        $stored = (float) $affiliate->markup_pct;
        $terms = $affiliate->storefrontPriceTerms();

        return [
            'markup_pct' => $stored,
            // What the storefront actually prices at (`storefrontPriceTerms()`):
            // clamped to the ceiling, and 0 for an owned brand (ADR-061
            // 2026-10-30 addendum). Planning addendum decision 12.
            'effective_markup_pct' => $terms['markup'],
            // Null = the platform has set no ceiling (so the brand cannot raise its own markup).
            'max_markup_pct' => $affiliate->max_markup_pct === null ? null : (float) $affiliate->max_markup_pct,
            'is_owned' => (bool) $affiliate->is_owned,
            'wholesale_rate_active' => $terms['tier'] !== null,
        ];
    }

    /**
     * Active packages of the brand's currently-visible games, cheapest
     * first. The hidden-game lookup is filtered by the acting affiliate
     * id explicitly (`withoutAffiliateScope`), matching the rest of the
     * portal controllers.
     *
     * @return Collection<int, Package>
     */
    private function visiblePackages(int $affiliateId): Collection
    {
        $hiddenGameIds = AffiliateGame::hiddenGameIdsFor($affiliateId);

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
