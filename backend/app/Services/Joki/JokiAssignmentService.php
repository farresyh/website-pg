<?php

namespace App\Services\Joki;

use App\Models\JokiAssignment;
use App\Models\Order;
use App\Models\Supplier;
use App\Services\Order\DeliveryStatus;
use App\Services\Supplier\Manual\SupplierGroupNotifier;
use App\Services\WhatsApp\WhatsAppGroupService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ADR-126 decision 16: the admin hands a waiting joki order to a penjoki,
 * or moves it to another one. Under the order lock, then the penjoki's own
 * lock (the one ManualSupplierStatusService takes to switch a penjoki off),
 * so it can't race `.done`, `.gagal`, Retry or a switch-off. The order's
 * cost, the agreed payout, never changes. After commit the order and a
 * fresh login link go to the penjoki's group (SupplierGroupNotifier keys a
 * post per assignment); the previous penjoki's link and `.done` stop
 * working (SupplierLoginLinkService, decision 18).
 */
final class JokiAssignmentService
{
    public function __construct(
        private readonly SupplierGroupNotifier $notifier,
        private readonly WhatsAppGroupService $groups,
    ) {}

    public function assign(Order $order, int $toSupplierId, string $assignedBy): Order
    {
        $assigned = DB::transaction(function () use ($order, $toSupplierId, $assignedBy) {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);

            if (! $locked->isJoki() || $locked->is_test) {
                throw ValidationException::withMessages(['order' => ['Only a joki order is assigned to a penjoki.']]);
            }

            if ($locked->delivery_status !== DeliveryStatus::Pending) {
                throw ValidationException::withMessages(['order' => ['Only a joki order that is waiting or in progress can be assigned.']]);
            }

            $to = Supplier::query()->lockForUpdate()->find($toSupplierId);

            if ($to === null || ! $to->isPenjoki() || ! $to->is_active) {
                throw ValidationException::withMessages(['supplier_id' => ['Choose an active penjoki.']]);
            }

            if ($this->groups->activeGroupFor($to) === null) {
                throw ValidationException::withMessages(['supplier_id' => ['This penjoki has no WhatsApp group yet.']]);
            }

            if ($locked->supplier_id === $to->id) {
                throw ValidationException::withMessages(['supplier_id' => ['This order is already with this penjoki.']]);
            }

            JokiAssignment::query()->create([
                'order_id' => $locked->id,
                'from_supplier_id' => $locked->supplier_id,
                'to_supplier_id' => $to->id,
                'assigned_by' => $assignedBy,
            ]);

            // Decision 20: the stale clock restarts with the new penjoki.
            $locked->update(['supplier_id' => $to->id, 'manual_activity_at' => now()]);

            return $locked;
        });

        $this->notifier->orderPosted($assigned->id);

        return $assigned->fresh();
    }
}
