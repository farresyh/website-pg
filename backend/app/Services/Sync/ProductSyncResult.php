<?php

namespace App\Services\Sync;

use Illuminate\Support\Carbon;

/**
 * Summary of one ProductSyncService::sync() run (SYNC-2: total/
 * created/updated/pruned/duration). `syncedAt` is the exact timestamp
 * every touched `supplier_products` row's `last_synced_at` was stamped
 * with this run — ADR-015's PackagePriceSyncService uses it to tell
 * "seen in the latest full catalog" apart from "vanished," which a raw
 * created/updated count alone can't distinguish. `pruned` (ADR-067) is
 * how many un-promoted rows this run deleted for no longer being in
 * the supplier's catalog.
 */
final class ProductSyncResult
{
    /**
     * @param  array{from: string, to: string, rate: float}|null  $fxRateUsed  ADR-033 addendum: null for a MYR
     *                                                                         supplier (no conversion happened); the exact rate this run converted every non-MYR price with
     *                                                                         otherwise — folded into PriceSyncRun.stats.fx_rates_used for the Price Sync Center's own display.
     */
    public function __construct(
        public readonly int $total,
        public readonly int $created,
        public readonly int $updated,
        public readonly int $durationMs,
        public readonly Carbon $syncedAt,
        public readonly ?array $fxRateUsed = null,
        public readonly int $pruned = 0,
    ) {}
}
