<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Membership\RecordMembershipPaymentRequest;
use App\Models\Membership;
use App\Models\Reseller;
use App\Services\Membership\MembershipFeeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ADR-027 continued addendum decision 15 / Phase 6.5 (grilled
 * 2026-08-29): the admin-side member registry + fee collection, living
 * on /admin/membership alongside the tier config (MembershipPlanController).
 * `super_admin` only, same tier as the tier config.
 *
 * Q14: deliberately NOT gated on `PlatformSettings.membership_enabled` —
 * admin needs to manage members (and record real payments) regardless of
 * whether the customer-facing kill switch is on, same as the tier config
 * itself stays editable pre-launch.
 */
class MembershipController extends Controller
{
    public function __construct(private readonly MembershipFeeService $fees)
    {
    }

    /**
     * Member registry (Q9). Effective status is computed here (Q15):
     * the scheduled flip to `expired` is still inert-until-real-cron, so
     * a member past `expires_at` whose `status` hasn't been flipped yet
     * must still read as expired, not active.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Membership::query()
            ->with(['membershipPlan:id,name,quota_sen', 'reseller:id,business_name'])
            ->withCount('orders');

        if ($resellerId = $request->integer('reseller_id')) {
            $query->where('reseller_id', $resellerId);
        }

        match ($request->query('status')) {
            'active' => $query
                ->where('status', 'active')
                ->where('expires_at', '>=', now()),
            'expired' => $query->where(function ($q) {
                $q->where('status', 'expired')->orWhere('expires_at', '<', now());
            }),
            default => null,
        };

        if ($planId = $request->integer('plan_id')) {
            $query->where('membership_plan_id', $planId);
        }

        if ($search = $request->query('search')) {
            $query->where('email', 'like', "%{$search}%");
        }

        $perPage = (int) $request->query('per_page', 25);

        return response()->json(
            $query->orderBy('created_at', 'desc')
                ->paginate($perPage)
                ->withQueryString()
                ->through(fn (Membership $membership) => $this->present($membership)),
        );
    }

    /**
     * ADR-061 decision 5: the Record Payment modal's brand picker. Only
     * internal brands with their own Membership toggle on can hold a
     * consumer membership.
     */
    public function brands(): JsonResponse
    {
        return response()->json(
            Reseller::query()
                ->where('is_owned', true)
                ->where('membership_enabled', true)
                ->orderBy('business_name')
                ->get(['id', 'business_name'])
                ->map(fn (Reseller $reseller) => [
                    'id' => $reseller->id,
                    'business_name' => $reseller->business_name,
                ]),
        );
    }

    public function recordPayment(RecordMembershipPaymentRequest $request): JsonResponse
    {
        $membership = $this->fees->recordFeePaid(
            (int) $request->validated('reseller_id'),
            $request->validated('email'),
            (int) $request->validated('membership_plan_id'),
            (int) $request->validated('amount_sen'),
            (int) $request->user()->id,
            $request->validated('reason'),
            $request->validated('idempotency_key'),
        );

        return response()->json($this->present(
            $membership->load(['membershipPlan:id,name,quota_sen', 'reseller:id,business_name']),
        ));
    }

    /**
     * The narrow registry shape — quota used is derived (plan quota
     * minus remaining), never a stored column, and effective status is
     * computed (see index()'s own comment).
     *
     * @return array<string, mixed>
     */
    private function present(Membership $membership): array
    {
        $planQuotaSen = $membership->membershipPlan->quota_sen ?? 0;

        return [
            'id' => $membership->id,
            'reseller_id' => $membership->reseller_id,
            'brand_name' => $membership->reseller?->business_name,
            'email' => $membership->email,
            'plan_id' => $membership->membership_plan_id,
            'plan_name' => $membership->membershipPlan->name ?? null,
            'status' => ($membership->status->value === 'expired' || $membership->expires_at?->isPast())
                ? 'expired'
                : 'active',
            'cycle_started_at' => $membership->cycle_started_at?->toIso8601String(),
            'expires_at' => $membership->expires_at?->toIso8601String(),
            'quota_remaining_sen' => $membership->quota_remaining_sen,
            'quota_used_sen' => max(0, $planQuotaSen - $membership->quota_remaining_sen),
            'quota_total_sen' => $planQuotaSen,
            'orders_count' => $membership->orders_count ?? 0,
        ];
    }
}
