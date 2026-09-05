<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\CatalogController;
use App\Http\Controllers\Controller;
use App\Http\Requests\Membership\UpdateMembershipEnabledRequest;
use App\Http\Requests\Membership\UpdateMembershipPlanRequest;
use App\Models\Affiliate;
use App\Models\MembershipPlan;
use App\Models\MembershipPlanChange;
use App\Models\Package;
use App\Models\PlatformSettings;
use App\Services\Pricing\MembershipPricingService;
use App\Services\Pricing\PricingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ADR-027's 2026-08-29 addendum, decisions 14/15: /admin/membership's
 * backend — edit-only against the two fixed membership_plans rows, no
 * create/delete action exists. `super_admin` only, same tier as
 * Settings/Price Sync/SEO (platform-wide business config).
 */
class MembershipPlanController extends Controller
{
    public function __construct(
        private readonly PricingService $pricing,
        private readonly MembershipPricingService $membershipPricing,
    ) {}

    public function index(): JsonResponse
    {
        return response()->json(MembershipPlan::orderBy('id')->get());
    }

    /**
     * Founder ask, 2026-08-29: before saving a discount_percent, show
     * exactly what it does — both the markup% breakdown (package markup
     * -> tier discount -> effective markup, since the discount reduces
     * markup%, not price directly) and the real margin given up per sale
     * ("kos yang ditanggung"), not just a bare percentage or a final RM
     * number alone. One representative package (the founder's own
     * follow-up: a single worked example is enough, not a package list),
     * not tied to a saved tier row — the in-progress (unsaved) form
     * value can be previewed before "Save Tier". Reuses
     * PricingService/MembershipPricingService directly, never a second
     * derivation of the formula — must never drift from what
     * CatalogController actually charges.
     */
    public function preview(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'discount_percent' => ['required', 'numeric', 'min:0'],
        ]);
        $discountPercent = (float) $validated['discount_percent'];

        $package = $this->samplePackage();
        if ($package === null) {
            // A consistent top-level shape (package_name: null), not a
            // bare JSON null — Laravel's response()->json(null) actually
            // serializes to `{}`, an ambiguous signal a consumer would
            // have to special-case; this keeps the contract one shape.
            return response()->json(['package_name' => null]);
        }

        $affiliate = Affiliate::primary();
        $packageMarkupPercent = (float) $package->markup_percent;
        $normalPriceSen = $this->pricing->calculate(
            $package->cost_price,
            $package->standard_selling_price,
            (float) $affiliate->markup_pct,
        )->sellingPrice;
        $effectiveMarkupPercent = $this->membershipPricing->effectiveMarkupPercent($packageMarkupPercent, $discountPercent);
        $memberPriceSen = $this->membershipPricing->calculateMemberPrice(
            $package->cost_price,
            $packageMarkupPercent,
            $discountPercent,
        );

        return response()->json([
            'package_name' => $package->name,
            'package_markup_percent' => round($packageMarkupPercent, 2),
            'discount_percent' => round($discountPercent, 2),
            'effective_markup_percent' => $effectiveMarkupPercent,
            'normal_price_sen' => $normalPriceSen,
            'member_price_sen' => $memberPriceSen,
            'margin_forgone_sen' => $normalPriceSen - $memberPriceSen,
            'savings_percent' => $normalPriceSen > 0
                ? round((1 - $memberPriceSen / $normalPriceSen) * 100, 1)
                : 0.0,
        ]);
    }

    /**
     * The median-priced active package — "typical," not the cheapest or
     * most expensive edge case, per the founder's own "just show me one
     * example" ask.
     */
    private function samplePackage(): ?Package
    {
        $active = Package::query()->where('is_active', true)->orderBy('cost_price')->get();

        if ($active->isEmpty()) {
            return null;
        }

        return $active[intdiv($active->count(), 2)];
    }

    /**
     * Decision 22: writes one membership_plan_changes row per field that
     * actually changed (mirrors price_change_logs/deactivation_logs'
     * "only real changes get logged" discipline) — never a blind diff-
     * less audit row.
     */
    public function update(UpdateMembershipPlanRequest $request, MembershipPlan $membershipPlan): JsonResponse
    {
        $original = $membershipPlan->only(['name', 'fee_sen', 'quota_sen', 'discount_percent']);

        $membershipPlan->update($request->validated());

        foreach ($original as $field => $oldValue) {
            $newValue = $membershipPlan->{$field};

            if ((string) $oldValue !== (string) $newValue) {
                MembershipPlanChange::create([
                    'membership_plan_id' => $membershipPlan->id,
                    'admin_user_id' => $request->user()->id,
                    'field_changed' => $field,
                    'old_value' => (string) $oldValue,
                    'new_value' => (string) $newValue,
                ]);
            }
        }

        // Decision 18: any field write here can change every game's
        // publicly-shown member_price_sen at once — one tagged flush,
        // not scoped to which field actually changed (cheap either way).
        CatalogController::forgetPackagesCacheForMembership();

        return response()->json($membershipPlan);
    }

    /**
     * Decision 20's kill switch — lives on PlatformSettings, not
     * membership_plans, but exposed here since /admin/membership is
     * the screen that owns this feature's own on/off state.
     */
    public function updateEnabled(UpdateMembershipEnabledRequest $request): JsonResponse
    {
        $settings = PlatformSettings::current();
        $settings->update($request->validated());

        // Flipping this changes whether member_price_sen appears in the
        // public catalog response at all (decision 21) — must invalidate
        // the same as an actual tier edit.
        CatalogController::forgetPackagesCacheForMembership();

        return response()->json($settings->only('membership_enabled'));
    }
}
