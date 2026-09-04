<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreAffiliateTierRequest;
use App\Http\Requests\Admin\UpdateAffiliateTierRequest;
use App\Models\AffiliateMembershipTier;
use App\Services\Affiliate\AffiliateSubscriptionStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

/**
 * ADR-058 58b / ADR-056 decision 1: CRUD for the
 * `affiliate_membership_tiers` ladder (the paid B2B wholesale-rate tiers
 * an affiliate subscribes to monthly). super_admin only — route-level
 * `admin.role:super_admin` is the gate. Starts empty, not row-count
 * locked (contrast the consumer `membership_plans`).
 *
 * Delete is soft-delete: `affiliate_subscriptions.affiliate_membership_tier_id`
 * is restrict-on-delete at the DB, so a tier any affiliate was ever on
 * can't be hard-deleted. A tier with an active or grace subscription
 * can't be deleted at all — the affiliate would lose their wholesale
 * rate mid-cycle.
 */
class AffiliateMembershipTierController extends Controller
{
    public function index(): JsonResponse
    {
        $tiers = AffiliateMembershipTier::query()
            // Only count subscriptions of live affiliates — a soft-deleted
            // affiliate's subscription row lingers (soft-delete doesn't
            // cascade) but must not inflate the subscriber count or block
            // the tier's own deletion.
            ->withCount(['subscriptions' => fn ($q) => $q->whereHas('affiliate')])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return response()->json(['tiers' => $tiers]);
    }

    public function store(StoreAffiliateTierRequest $request): JsonResponse
    {
        $tier = AffiliateMembershipTier::query()->create($request->validated());

        return response()->json($tier, 201);
    }

    public function update(UpdateAffiliateTierRequest $request, AffiliateMembershipTier $affiliate_tier): JsonResponse
    {
        $affiliate_tier->update($request->validated());

        return response()->json($affiliate_tier->fresh());
    }

    public function destroy(AffiliateMembershipTier $affiliate_tier): JsonResponse
    {
        $blocking = $affiliate_tier->subscriptions()
            ->whereHas('affiliate') // ignore soft-deleted affiliates' lingering rows
            ->whereIn('status', [
                AffiliateSubscriptionStatus::Active->value,
                AffiliateSubscriptionStatus::Grace->value,
            ])
            ->count();

        if ($blocking > 0) {
            throw ValidationException::withMessages([
                'tier' => ["{$blocking} affiliate(s) are actively subscribed to this tier. Move them to another tier first."],
            ]);
        }

        $affiliate_tier->delete();

        return response()->json(['message' => 'Tier deleted.']);
    }
}
