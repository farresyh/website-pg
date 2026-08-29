<?php

namespace App\Http\Controllers;

use App\Models\Membership;
use App\Models\Order;
use App\Services\Membership\MembershipSessionTokenService;
use App\Services\Membership\MembershipStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ADR-027 decision 13 / its 2026-08-29 addendum decisions 24/25: the
 * /membership dashboard's backend. Guest-callable like
 * MembershipOtpController (ADR-011's trust model), but every action
 * here requires the session token (Authorization: Bearer, issued by
 * MembershipOtpController::verify()) instead of a request body field
 * — there's no admin.role gate to reuse, and this identity isn't
 * Sanctum-backed (decision 2's own "lighter than a full account").
 */
class MembershipController extends Controller
{
    public function __construct(private readonly MembershipSessionTokenService $sessionTokens)
    {
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
