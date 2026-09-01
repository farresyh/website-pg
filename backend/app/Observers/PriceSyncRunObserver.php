<?php

namespace App\Observers;

use App\Events\PriceSyncRunUpdated;
use App\Models\PriceSyncRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * ADR-047 decision 1 — the single seam SyncSupplierPricesJob's two
 * `$this->run->update([...])` calls (running, then success/failed)
 * broadcast through. Same discipline as OrderObserver, including the
 * DB::afterCommit()+try/catch shape — see that class's own doc comment
 * for the real bug (a Reverb-unreachable broadcast crashing the run
 * that's being reported on) this guards against.
 */
final class PriceSyncRunObserver
{
    public function updated(PriceSyncRun $run): void
    {
        if ($run->wasChanged('status')) {
            DB::afterCommit(function () use ($run) {
                try {
                    broadcast(new PriceSyncRunUpdated($run));
                } catch (Throwable $e) {
                    Log::warning('Failed to broadcast PriceSyncRunUpdated', [
                        'run_id' => $run->id,
                        'exception' => $e->getMessage(),
                    ]);
                }
            });
        }
    }
}
