<?php

namespace App\Http\Controllers\Affiliate;

use App\Http\Controllers\Controller;
use App\Services\Affiliate\AffiliateEarningsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ADR-059 decision 2: the Subscription screen — current wholesale tier,
 * fee, next charge date, grace / lapse status, and the charge history.
 * Read-only: assigning / reactivating a tier stays an admin action
 * (ADR-058 58b, RES-3).
 */
class SubscriptionController extends Controller
{
    public function __construct(private readonly AffiliateEarningsService $earnings) {}

    public function show(Request $request): JsonResponse
    {
        $affiliate = $request->user()->affiliateOwner();
        $subscription = $affiliate->subscription()->with('tier')->first();

        return response()->json([
            'subscription' => $subscription === null ? null : [
                'tier_name' => $subscription->tier->name,
                'monthly_fee_sen' => (int) $subscription->tier->monthly_fee_sen,
                // The tier's `markup_percent` is the platform's wholesale
                // margin over its true cost — never exposed to the
                // affiliate (founder call 2026-09-08). They see the tier
                // name + the fee they pay, not our cost structure.
                'status' => $subscription->status->value,
                'current_period_started_at' => $subscription->current_period_started_at?->toIso8601String(),
                'next_charge_at' => $subscription->next_charge_at?->toIso8601String(),
                'grace_until' => $subscription->grace_until?->toIso8601String(),
            ],
            'charge_history' => $this->earnings->tierFeeHistory($affiliate),
        ]);
    }
}
