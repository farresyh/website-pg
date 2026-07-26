<?php

namespace App\Services\Sync;

/**
 * Summary of one PackagePriceSyncService::apply() pass — folded into
 * the owning PriceSyncRun's `stats` column alongside Stage 1's own
 * ProductSyncResult numbers.
 */
final class PackagePriceSyncResult
{
    /**
     * @param  int[]  $affectedGameIds  distinct Game ids whose
     *         packages() listing changed — GameController cache
     *         invalidation keys off this, not off every package.
     */
    public function __construct(
        public readonly int $priceChanged,
        public readonly int $deactivated,
        public readonly array $affectedGameIds,
    ) {
    }
}
