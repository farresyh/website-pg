<?php

namespace App\Observers;

use App\Events\PriceSyncRunUpdated;
use App\Models\PriceSyncRun;

/**
 * ADR-047 decision 1 — the single seam SyncSupplierPricesJob's two
 * `$this->run->update([...])` calls (running, then success/failed)
 * broadcast through. Same discipline as OrderObserver.
 */
final class PriceSyncRunObserver
{
    public function updated(PriceSyncRun $run): void
    {
        if ($run->wasChanged('status')) {
            broadcast(new PriceSyncRunUpdated($run));
        }
    }
}
