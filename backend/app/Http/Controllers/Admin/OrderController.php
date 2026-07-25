<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\PaymentStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ORD-1..7 — read-only for this pass (list + detail). Resolve actions
 * (ORD-7: retry-delivery / voucher issuance) and export (ORD-5) are
 * deliberately out of scope here — this exists to make a real order's
 * outcome visible in the Admin Panel, not to fully close ORD-1..11.
 * See docs/prd.md §14.
 */
class OrderController extends Controller
{
    /**
     * `status` (ORD-2): need_action (paid but delivery failed —
     * customer's money is in, credits never arrived), processing
     * (delivery attempt in flight), completed (delivered), today,
     * or omitted for all.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Order::query()->with(['game:id,name', 'package:id,name']);

        match ($request->query('status')) {
            'need_action' => $query
                ->where('payment_status', PaymentStatus::Paid->value)
                ->where('delivery_status', DeliveryStatus::Failed->value),
            'processing' => $query->where('delivery_status', DeliveryStatus::Processing->value),
            'completed' => $query->where('delivery_status', DeliveryStatus::Delivered->value),
            'today' => $query->whereDate('created_at', now()->toDateString()),
            default => null,
        };

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('order_number', 'like', "%{$search}%")
                    ->orWhere('customer_email', 'like', "%{$search}%");
            });
        }

        $perPage = (int) $request->query('per_page', 25);

        return response()->json(
            $query->orderBy('created_at', 'desc')->paginate($perPage)->withQueryString(),
        );
    }

    /**
     * ORD-6: customer info, game/package, payment info, supplier
     * response — everything needed to confirm a specific order's
     * outcome. Status-history timeline is deferred (would need its
     * own audit table; not built yet).
     */
    public function show(Order $order): JsonResponse
    {
        return response()->json($order->load(['game', 'package', 'supplier', 'reseller']));
    }
}
