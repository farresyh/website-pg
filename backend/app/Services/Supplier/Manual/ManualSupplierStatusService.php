<?php

namespace App\Services\Supplier\Manual;

use App\Models\Supplier;
use App\Services\Supplier\SupplierPackageStatusService;
use App\Services\WhatsApp\WhatsAppGroupService;
use Illuminate\Support\Facades\DB;

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
        if ($isActive) {
            $this->groups->assertSupplierMayBeActive($supplier);
        }

        DB::transaction(function () use ($supplier, $isActive, $adminUserId) {
            $supplier->update(['is_active' => $isActive]);

            $isActive
                ? $this->packages->reactivate($supplier, $adminUserId)
                : $this->packages->deactivate($supplier, $adminUserId, 'Manual supplier switched off');
        });
    }
}
