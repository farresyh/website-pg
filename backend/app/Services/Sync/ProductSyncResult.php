<?php

namespace App\Services\Sync;

use Illuminate\Support\Carbon;

/**
 * Summary of one ProductSyncService::sync() run (SYNC-2: total/
 * created/updated/duration). `syncedAt` is the exact timestamp every
 * touched `supplier_products` row's `last_synced_at` was stamped with
 * this run — ADR-015's PackagePriceSyncService uses it to tell "seen
 * in the latest full catalog" apart from "vanished," which a raw
 * created/updated count alone can't distinguish.
 */
final class ProductSyncResult
{
    public function __construct(
        public readonly int $total,
        public readonly int $created,
        public readonly int $updated,
        public readonly int $durationMs,
        public readonly Carbon $syncedAt,
    ) {
    }
}
