<?php

namespace App\Http\Controllers;

use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Models\Order;
use App\Models\Reseller;
use App\Services\Membership\MembershipSessionTokenService;
use App\Services\Membership\MembershipStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * ADR-027 decision 13 / its 2026-08-29 addendum decisions 24/25: the
 * /membership dashboard's backend. Guest-callable like
 * MembershipOtpController (ADR-011's trust model), but every action
 * here requires the session token (Authorization: Bearer, issued by
 * MembershipOtpController::verify()) instead of a request body field
 * — there's no admin.role gate to reuse, and this identity isn't
 * Sanctum-backed (decision 2's own "lighter than a full account").
 *
 * ADR-055 decision 3: `plans()` is the one deliberately anonymous
 * exception on this controller — the upsell card's data source, no
 * session token needed.
 */
class MembershipController extends Controller
{
    public function __construct(private readonly MembershipSessionTokenService $sessionTokens) {}

    /**
     * ADR-055 decision 3: the public tier listing for the storefront
     * upsell card — `name`/`fee_sen`/`discount_percent` per tier, both
     * tiers (not just the top one, so /membership's own comparison UI
     * has a ready-made source), deliberately no `quota_sen`/`id`/
     * timestamps. Empty array when the kill switch is off — a single
     * contract shape the storefront can render as "no card" without a
     * 404 special case. "Kill switch" here is ADR-061 decision 4's dual
     * gate — the global master AND the storefront brand's own toggle
     * (the primary brand today; `Host`-resolved in ADR-060). Cached in the same scoped, tagged store as the
     * catalog packages (60s TTL), tagged `catalog.packages` so
     * CatalogController::forgetPackagesCacheForMembership() — already
     * flushed on every membership_plans edit and kill-switch toggle —
     * invalidates this endpoint for free.
     */
    public function plans(): JsonResponse
    {
        $enabled = Reseller::primary()->membershipEnabledEffective();

        if (! $enabled) {
            return response()->json([]);
        }

        $plans = Cache::store(config('cache.catalog_packages_store'))
            ->tags(['catalog.packages', 'catalog.public.membership.plans'])
            ->remember(
                'catalog.public.membership.plans',
                60,
                fn () => MembershipPlan::query()
                    ->orderBy('id')
                    ->get()
                    ->map(fn (MembershipPlan $plan) => [
                        'name' => $plan->name,
                        'fee_sen' => $plan->fee_sen,
                        'discount_percent' => (float) $plan->discount_percent,
                    ])
                    ->all(),
            );

        return response()->json($plans);
    }

    public function me(Request $request): JsonResponse
    {
        $email = $this->resolveEmail($request);

        if ($email === null) {
            return response()->json(['message' => 'Invalid or expired session.'], 401);
        }

        $membership = Membership::query()
            ->with('membershipPlan')
            ->where('email', $email)
            ->where('status', MembershipStatus::Active)
            ->first();

        return response()->json([
            'membership' => $membership !== null ? $this->publicMembership($membership) : null,
            'order_history' => $this->orderHistory($email),
        ]);
    }

    private function resolveEmail(Request $request): ?string
    {
        $token = $request->bearerToken();

        return $token !== null ? $this->sessionTokens->resolve($token) : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function publicMembership(Membership $membership): array
    {
        return [
            'tier_name' => $membership->membershipPlan->name,
            'status' => $membership->status->value,
            'expires_at' => $membership->expires_at->toISOString(),
            'cycle_started_at' => $membership->cycle_started_at->toISOString(),
            'quota_remaining_sen' => $membership->quota_remaining_sen,
        ];
    }

    /**
     * Decision 25's dashboard scope — free from Decision 3's existing
     * Order.customer_email match, no new linkage table. Same narrow,
     * customer-safe field subset as TrackOrderController::show().
     *
     * @return array<int, array<string, mixed>>
     */
    private function orderHistory(string $email): array
    {
        return Order::query()
            ->with(['game:id,name,slug', 'package:id,name'])
            ->where('customer_email', $email)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(fn (Order $order) => [
                'order_number' => $order->order_number,
                'game' => $order->game !== null ? ['name' => $order->game->name, 'slug' => $order->game->slug] : null,
                'package_name' => $order->package?->name,
                'final_amount' => $order->final_amount,
                'payment_status' => $order->payment_status->value,
                'delivery_status' => $order->delivery_status->value,
                'created_at' => $order->created_at?->toISOString(),
            ])
            ->all();
    }
}
