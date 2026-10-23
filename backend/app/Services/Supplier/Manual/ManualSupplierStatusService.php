<?php

namespace App\Services\Supplier\Manual;

use App\Models\Order;
use App\Models\Supplier;
use App\Services\Order\DeliveryStatus;
use App\Services\Supplier\SupplierPackageStatusService;
use App\Services\WhatsApp\WhatsAppGroupService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ADR-125 decisions 56/64: a manual supplier's active switch, the one path
 * for both Middleware → Suppliers and Partners → Suppliers. A supplier's
 * `is_active` gates nothing at checkout (only sync and balance jobs read
 * it), so switching a manual supplier off also takes its packages off sale
 * through ADR-046's `supplier_issue` seam, and switching it on restores
 * exactly those. Switching on needs an active WhatsApp group, since every
 * order is posted there.
 */
final class ManualSupplierStatusService
{
    public function __construct(
        private readonly WhatsAppGroupService $groups,
        private readonly SupplierPackageStatusService $packages,
    ) {}

    public function setActive(Supplier $supplier, bool $isActive, int $adminUserId): void
    {
        // ADR-126 F5: the pool is switched by the joki switch only.
        if ($supplier->isAssignmentPool()) {
            throw ValidationException::withMessages(['is_active' => ['The joki pool is switched on and off with Joki itself.']]);
        }

        if ($isActive) {
            $this->groups->assertSupplierMayBeActive($supplier);
        }

        DB::transaction(function () use ($supplier, $isActive, $adminUserId) {
            // ADR-126 decision 19: under the supplier lock JokiAssignmentService
            // also takes, so an Assign can't land on a penjoki being switched off.
            $locked = Supplier::query()->lockForUpdate()->findOrFail($supplier->id);

            if (! $isActive && $locked->isPenjoki() && $this->hasWaitingOrders($locked)) {
                throw ValidationException::withMessages(['is_active' => ['This penjoki still has orders in progress. Reassign them first.']]);
            }

            $supplier->update(['is_active' => $isActive]);

            $isActive
                ? $this->packages->reactivate($supplier, $adminUserId)
                : $this->packages->deactivate($supplier, $adminUserId, 'Manual supplier switched off');
        });
    }

    private function hasWaitingOrders(Supplier $supplier): bool
    {
        return Order::query()
            ->where('supplier_id', $supplier->id)
            ->where('delivery_status', DeliveryStatus::Pending->value)
            ->exists();
    }
}
