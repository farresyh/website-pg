<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ConfirmManualDeliveredRequest;
use App\Http\Requests\Admin\ConfirmManualFailedRequest;
use App\Models\Order;
use App\Models\OrderCredential;
use App\Models\Supplier;
use App\Services\Fulfillment\ManualDeliveryService;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\InvalidOrderTransitionException;
use App\Services\Order\PaymentStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

/**
 * ADR-125 decisions 8/16/42/44: the admin's Manual Queue — every paid,
 * real order on a manual supplier still waiting for a human (Pending, or
 * NeedsReview after a worker died). Any admin may confirm (Q10). Before
 * PR 2's supplier group, the stale flag is the alert.
 */
class ManualQueueController extends Controller
{
    public function __construct(private readonly ManualDeliveryService $manual) {}

    public function index(): JsonResponse
    {
        $orders = Order::query()
            ->with(['game:id,name', 'package:id,name', 'supplier'])
            ->whereHas('supplier', fn ($q) => $q->where('driver', Supplier::DRIVER_MANUAL))
            ->whereIn('delivery_status', [DeliveryStatus::Pending->value, DeliveryStatus::NeedsReview->value])
            ->where('payment_status', PaymentStatus::Paid->value)
            ->where('is_test', false)
            // ADR-126 decision 23: joki has its own board.
            ->notJoki()
            ->oldest('paid_at')
            ->limit(200)
            ->get();

        $wiped = OrderCredential::query()
            ->whereIn('order_id', $orders->pluck('id'))
            ->whereNotNull('purged_at')
            ->pluck('order_id')
            ->flip();

        return response()->json($orders->map(fn (Order $order) => [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'game' => $order->game?->name,
            'package' => $order->package?->name,
            'supplier' => $order->supplier?->name,
            'supplier_id' => $order->supplier_id,
            'player_id' => $order->player_id,
            'delivery_status' => $order->delivery_status->value,
            'paid_at' => $order->paid_at?->toISOString(),
            'is_stale' => $order->isStalePending(),
            'login_wiped' => $wiped->has($order->id),
            'cost_price' => $order->cost_price,
            'selling_price' => $order->selling_price,
        ])->values());
    }

    /**
     * The manual-package form's supplier picker. The full supplier list is
     * super_admin-only middleware config; an admin may add a manual
     * package, so it gets just the active manual suppliers' names.
     */
    public function suppliers(): JsonResponse
    {
        return response()->json(Supplier::query()
            ->where('driver', Supplier::DRIVER_MANUAL)
            // ADR-126: a penjoki or the pool never supplies an ordinary package.
            ->whereNull('joki_role')
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'funding_mode']));
    }

    public function delivered(ConfirmManualDeliveredRequest $request, Order $order): JsonResponse
    {
        return $this->answer(fn () => $this->manual->confirmDelivered(
            $order,
            $request->validated('real_cost_sen'),
            $request->validated('reason'),
            $request->user()->name,
            (int) $request->validated('supplier_id'),
        ));
    }

    public function failed(ConfirmManualFailedRequest $request, Order $order): JsonResponse
    {
        return $this->answer(fn () => $this->manual->confirmFailed($order, $request->validated('reason'), $request->user()->name, (int) $request->validated('supplier_id')));
    }

    public function correctToDelivered(ConfirmManualDeliveredRequest $request, Order $order): JsonResponse
    {
        if (blank($request->validated('reason'))) {
            throw ValidationException::withMessages(['reason' => ['Give a reason for correcting a Failed order.']]);
        }

        return $this->answer(fn () => $this->manual->correctFailedToDelivered(
            $order,
            $request->validated('real_cost_sen'),
            $request->validated('reason'),
            $request->user()->name,
        ));
    }

    /** A second confirmation (two admins, or the bot first) gets "already finished". */
    private function answer(callable $action): JsonResponse
    {
        try {
            $order = $action();
        } catch (InvalidOrderTransitionException) {
            throw ValidationException::withMessages(['order' => ['This order is already finished.']]);
        }

        return response()->json([
            'order_number' => $order->order_number,
            'delivery_status' => $order->delivery_status->value,
        ]);
    }
}
