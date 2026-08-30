<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreResellerTierRequest;
use App\Http\Requests\Admin\UpdateResellerTierRequest;
use App\Models\ResellerMembershipTier;
use App\Services\Reseller\ResellerSubscriptionStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

/**
 * ADR-058 58b / ADR-056 decision 1: CRUD for the
 * `reseller_membership_tiers` ladder (the paid B2B wholesale-rate tiers
 * a reseller subscribes to monthly). super_admin only — route-level
 * `admin.role:super_admin` is the gate. Starts empty, not row-count
 * locked (contrast the consumer `membership_plans`).
 *
 * Delete is soft-delete: `reseller_subscriptions.reseller_membership_tier_id`
 * is restrict-on-delete at the DB, so a tier any reseller was ever on
 * can't be hard-deleted. A tier with an active or grace subscription
 * can't be deleted at all — the reseller would lose their wholesale
 * rate mid-cycle.
 */
class ResellerMembershipTierController extends Controller
{
    public function index(): JsonResponse
    {
        $tiers = ResellerMembershipTier::query()
            // Only count subscriptions of live resellers — a soft-deleted
            // reseller's subscription row lingers (soft-delete doesn't
            // cascade) but must not inflate the subscriber count or block
            // the tier's own deletion.
            ->withCount(['subscriptions' => fn ($q) => $q->whereHas('reseller')])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return response()->json(['tiers' => $tiers]);
    }

    public function store(StoreResellerTierRequest $request): JsonResponse
    {
        $tier = ResellerMembershipTier::query()->create($request->validated());

        return response()->json($tier, 201);
    }

    public function update(UpdateResellerTierRequest $request, ResellerMembershipTier $reseller_tier): JsonResponse
    {
        $reseller_tier->update($request->validated());

        return response()->json($reseller_tier->fresh());
    }

    public function destroy(ResellerMembershipTier $reseller_tier): JsonResponse
    {
        $blocking = $reseller_tier->subscriptions()
            ->whereHas('reseller') // ignore soft-deleted resellers' lingering rows
            ->whereIn('status', [
                ResellerSubscriptionStatus::Active->value,
                ResellerSubscriptionStatus::Grace->value,
            ])
            ->count();

        if ($blocking > 0) {
            throw ValidationException::withMessages([
                'tier' => ["{$blocking} reseller(s) are actively subscribed to this tier. Move them to another tier first."],
            ]);
        }

        $reseller_tier->delete();

        return response()->json(['message' => 'Tier deleted.']);
    }
}
