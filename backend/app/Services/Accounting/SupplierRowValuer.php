<?php

namespace App\Services\Accounting;

use App\Models\SupplierLedgerEntry;
use Illuminate\Support\Collection;

/**
 * Supplier ledger rows in sen, signed like their amounts, built by
 * `FundedCostOfSales::valuer()`. A foreign-currency row from the pool's
 * opening on costs what the pool gave it; any other row, its units at the
 * supplier's rate (1.0 for MYR), rounded once per supplier.
 */
final class SupplierRowValuer
{
    /**
     * @param  array<int, float|null>  $rates  supplier_id => MYR per unit
     * @param  array<int, MovingAveragePool>  $pools  supplier_id => pool, foreign-currency suppliers only
     */
    public function __construct(private readonly array $rates, private readonly array $pools) {}

    public function canValue(SupplierLedgerEntry $row): bool
    {
        return $this->pooled($row)
            ? ($this->pools[(int) $row->supplier_id]->costs[$row->id] ?? null) !== null
            : ($this->rates[(int) $row->supplier_id] ?? null) !== null;
    }

    /** @param  Collection<int, SupplierLedgerEntry>  $rows */
    public function value(Collection $rows): int
    {
        [$pooled, $rated] = $rows->partition(fn (SupplierLedgerEntry $row) => $this->pooled($row));

        $pooledSen = (int) $pooled->sum(fn (SupplierLedgerEntry $row) => $this->pools[(int) $row->supplier_id]->costs[$row->id] ?? 0);

        // Rounded once per supplier, never per row: per-row rounding would drift a sen per order from the true total.
        $ratedSen = (int) $rated->groupBy('supplier_id')
            ->sum(fn (Collection $group, int $supplierId) => (int) round((float) $group->sum(fn ($row) => (float) $row->amount) * ($this->rates[$supplierId] ?? 0.0) * 100));

        return $pooledSen + $ratedSen;
    }

    private function pooled(SupplierLedgerEntry $row): bool
    {
        return isset($this->pools[(int) $row->supplier_id]) && $row->created_at >= MovingAveragePool::opensAt();
    }
}
