<?php

namespace App\Jobs;

use App\Models\Supplier;
use App\Services\Supplier\SupplierBufferAlert;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * ADR-115 2026-11-05 addendum, decision 6: queued from a drawdown and from a
 * polled balance, so neither a delivery nor a webhook waits on the check.
 * One pending check per supplier is enough: it reads the latest state.
 */
class CheckSupplierBufferJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 60;

    public function __construct(public readonly int $supplierId)
    {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return (string) $this->supplierId;
    }

    public function handle(SupplierBufferAlert $alert): void
    {
        $supplier = Supplier::query()->find($this->supplierId);

        if ($supplier !== null && $supplier->is_active) {
            $alert->check($supplier);
        }
    }
}
