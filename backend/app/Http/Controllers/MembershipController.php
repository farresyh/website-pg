<?php

namespace App\Http\Controllers;

use App\Http\Requests\Membership\SubscribeRequest;
use App\Models\Affiliate;
use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Models\Order;
use App\Services\Membership\MembershipSessionTokenService;
use App\Services\Membership\MembershipStatus;
use App\Services\Membership\MembershipSubscriptionException;
use App\Services\Membership\MembershipSubscriptionService;
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
    public function __construct(
        private readonly MembershipSessionTokenService $sessionTokens,
        private readonly MembershipSubscriptionService $subscriptions,
    ) {}

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
        $enabled = Affiliate::primary()->membershipEnabledEffective();

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
        $session = $this->resolveSession($request);

        if ($session === null) {
            return response()->json(['message' => 'Invalid or expired session.'], 401);
        }

        [$affiliateId, $email] = [$session['affiliate_id'], $session['email']];

        $membership = Membership::query()
            ->with('membershipPlan')
            ->where('affiliate_id', $affiliateId)
            ->where('email', $email)
            ->where('status', MembershipStatus::Active)
            ->first();

        return response()->json([
            // ADR-068 decision 16: the storefront pre-fills and locks the
            // checkout contact email to this, so a logged-in member's
            // orders can never be split across a mistyped address.
            'email' => $email,
            'membership' => $membership !== null ? $this->publicMembership($membership) : null,
            'order_history' => $this->orderHistory($affiliateId, $email, $membership?->id),
        ]);
    }

    /**
     * ADR-068 decision 6 — the authenticated tier list the /membership
     * subscribe view renders: full plan rows (unlike the anonymous,
     * deliberately-narrow `plans()`), plus the caller's current plan and
     * a per-plan relation so the UI can label and gate each option.
     * S15: an `expired` member is treated as having no current plan —
     * every tier reads `subscribe`, and paying runs recordFeePaid's
     * reactivate branch.
     */
    public function subscribeOptions(Request $request): JsonResponse
    {
        $session = $this->resolveSession($request);

        if ($session === null) {
            return response()->json(['message' => 'Invalid or expired session.'], 401);
        }

        if (! Affiliate::primary()->membershipEnabledEffective()) {
            return response()->json(['message' => 'Membership is not available.'], 403);
        }

        $current = Membership::query()
            ->where('affiliate_id', $session['affiliate_id'])
            ->where('email', $session['email'])
            ->where('status', MembershipStatus::Active)
            ->where('expires_at', '>=', now())
            ->first();

        $currentPlanId = $current?->membership_plan_id;
        $currentDiscount = $currentPlanId !== null
            ? (float) MembershipPlan::query()->whereKey($currentPlanId)->value('discount_percent')
            : null;

        $plans = MembershipPlan::query()
            ->orderBy('id')
            ->get()
            ->map(fn (MembershipPlan $plan) => [
                'id' => $plan->id,
                'name' => $plan->name,
                'fee_sen' => $plan->fee_sen,
                'quota_sen' => $plan->quota_sen,
                'discount_percent' => (float) $plan->discount_percent,
                'relation' => $this->planRelation($plan, $currentPlanId, $currentDiscount),
            ])
            ->all();

        return response()->json([
            'current_plan_id' => $currentPlanId,
            'plans' => $plans,
        ]);
    }

    /**
     * ADR-068 decision 5 — start a self-serve subscription payment. The
     * plan is the only thing trusted from the client (ORD-9); brand,
     * email, and the amount are all resolved server-side. Returns the
     * CHIP checkout URL for the storefront to redirect to.
     */
    public function subscribe(SubscribeRequest $request): JsonResponse
    {
        $session = $this->resolveSession($request);

        if ($session === null) {
            return response()->json(['message' => 'Invalid or expired session.'], 401);
        }

        if (! Affiliate::primary()->membershipEnabledEffective()) {
            return response()->json(['message' => 'Membership is not available.'], 403);
        }

        try {
            $attempt = $this->subscriptions->initiate(
                $session['affiliate_id'],
                $session['email'],
                (int) $request->validated('membership_plan_id'),
                $request->validated('payment_method'),
                $request->validated('channel_properties') ?? [],
                $request->validated('idempotency_key'),
            );
        } catch (MembershipSubscriptionException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        return response()->json([
            'subscription_number' => $attempt->subscription_number,
            'checkout_url' => $attempt->checkout_url,
            'fee_sen' => $attempt->fee_sen,
            'total_charged_sen' => $attempt->total_charged_sen,
        ], 201);
    }

    /**
     * @return 'renew'|'upgrade'|'downgrade'|'subscribe'
     */
    private function planRelation(MembershipPlan $plan, ?int $currentPlanId, ?float $currentDiscount): string
    {
        if ($currentPlanId === null) {
            return 'subscribe';
        }

        if ($plan->id === $currentPlanId) {
            return 'renew';
        }

        // Compare on discount %, not id — "higher tier" means the better
        // member price, which is what the anchor psychology is about
        // (ADR-027 decision 4 / decision 19's Tier 2 > Tier 1 rule).
        return (float) $plan->discount_percent > ($currentDiscount ?? 0.0) ? 'upgrade' : 'downgrade';
    }

    /**
     * ADR-061 decision 5: a session token is only honoured on the brand
     * it was issued for. The storefront brand is `Affiliate::primary()`
     * today (`Host`-resolved in ADR-060); a token from another brand
     * resolves to `null` here, exactly like an expired one.
     *
     * @return array{affiliate_id: int, email: string}|null
     */
    private function resolveSession(Request $request): ?array
    {
        $token = $request->bearerToken();
        $session = $token !== null ? $this->sessionTokens->resolve($token) : null;

        if ($session === null || $session['affiliate_id'] !== Affiliate::primary()->id) {
            return null;
        }

        return $session;
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
     * ADR-068 decision 17: match on customer_email OR membership_id, not
     * email alone. Orders have carried membership_id since ADR-027
     * Phase 6, so an order bought as this member surfaces even when its
     * contact email differs — a legacy order, or one placed before
     * decision 16 bound the checkout email.
     *
     * @return array<int, array<string, mixed>>
     */
    private function orderHistory(int $affiliateId, string $email, ?int $membershipId): array
    {
        return Order::query()
            ->with(['game:id,name,slug', 'package:id,name'])
            ->where('affiliate_id', $affiliateId)
            ->where(function ($query) use ($email, $membershipId): void {
                $query->where('customer_email', $email);

                if ($membershipId !== null) {
                    $query->orWhere('membership_id', $membershipId);
                }
            })
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
