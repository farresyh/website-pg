<?php

namespace App\Services\Sync;

/**
 * Summary of one ProductSyncService::sync() run (SYNC-2: total/
 * created/updated/duration).
 */
final class ProductSyncResult
{
    public function __construct(
        public readonly int $total,
        public readonly int $created,
        public readonly int $updated,
        public readonly int $durationMs,
    ) {
    }
}
