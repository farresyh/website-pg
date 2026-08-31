<?php

namespace App\Http\Controllers\Reseller;

use App\Http\Controllers\Controller;
use App\Services\Reseller\ResellerEarningsService;
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
    public function __construct(private readonly ResellerEarningsService $earnings) {}

    public function show(Request $request): JsonResponse
    {
        $reseller = $request->user()->reseller;
        $subscription = $reseller->subscription()->with('tier')->first();

        return response()->json([
            'subscription' => $subscription === null ? null : [
                'tier_name' => $subscription->tier->name,
                'monthly_fee_sen' => (int) $subscription->tier->monthly_fee_sen,
                'wholesale_markup_percent' => (float) $subscription->tier->markup_percent,
                'status' => $subscription->status->value,
                'current_period_started_at' => $subscription->current_period_started_at?->toIso8601String(),
                'next_charge_at' => $subscription->next_charge_at?->toIso8601String(),
                'grace_until' => $subscription->grace_until?->toIso8601String(),
            ],
            'charge_history' => $this->earnings->tierFeeHistory($reseller),
        ]);
    }
}
