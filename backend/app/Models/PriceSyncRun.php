<?php

namespace App\Models;

use App\Services\Sync\PackagePriceSyncResult;
use App\Services\Sync\ProductSyncResult;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * ADR-015 decision #5: one row per `SyncSupplierPricesJob` run —
 * `status` (queued/running/success/failed) is what
 * `/middleware/price-sync` polls while a run is in flight.
 */
class PriceSyncRun extends Model
{
    protected $fillable = [
        'status',
        'triggered_by',
        'started_at',
        'finished_at',
        'stats',
        'error_message',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'stats' => 'array',
    ];

    /**
     * The counters every run starts with (ADR-015, ADR-033 addendum
     * decision 3, ADR-015 2026-10-17 addendum). `synced_suppliers` lists the
     * suppliers whose job finished their sync (ADR-137 2026-11-05 decision 3).
     *
     * @return array<string, mixed>
     */
    public static function emptyStats(): array
    {
        return [
            'catalog_total' => 0, 'catalog_created' => 0, 'catalog_updated' => 0, 'catalog_pruned' => 0,
            'price_changed' => 0, 'deactivated' => 0, 'floor_rejected' => 0, 'price_anomalies' => 0,
            'fx_rates_used' => [],
            'failed_suppliers' => [],
            'synced_suppliers' => [],
        ];
    }

    public function recordSupplierSynced(string $slug, ProductSyncResult $catalog, PackagePriceSyncResult $packages): void
    {
        $this->mutateStats(function (array $stats) use ($slug, $catalog, $packages) {
            $stats['catalog_total'] += $catalog->total;
            $stats['catalog_created'] += $catalog->created;
            $stats['catalog_updated'] += $catalog->updated;
            $stats['catalog_pruned'] += $catalog->pruned;
            $stats['price_changed'] += $packages->priceChanged;
            $stats['deactivated'] += $packages->deactivated;
            $stats['floor_rejected'] += $packages->floorRejected;
            $stats['price_anomalies'] += $packages->anomaliesFlagged;

            if ($catalog->fxRateUsed !== null) {
                $stats['fx_rates_used'][] = $catalog->fxRateUsed;
            }

            $stats['synced_suppliers'][] = $slug;

            return $stats;
        });
    }

    public function recordSupplierFailed(string $slug, string $error): void
    {
        $this->mutateStats(function (array $stats) use ($slug, $error) {
            $stats['failed_suppliers'][] = ['slug' => $slug, 'error' => $error];

            return $stats;
        });
    }

    /**
     * The batch's `finally`. Counts the suppliers that synced, never the
     * failures: a timed-out job's failed() hook may run after this.
     */
    public function finishBatch(int $supplierCount): void
    {
        DB::transaction(function () use ($supplierCount) {
            $run = self::query()->lockForUpdate()->findOrFail($this->id);
            $synced = count($run->stats['synced_suppliers'] ?? []);

            $run->update([
                'status' => match (true) {
                    $synced === 0 => 'failed',
                    $synced < $supplierCount => 'partial',
                    default => 'success',
                },
                'finished_at' => now(),
                'error_message' => $synced === 0
                    ? (collect($run->stats['failed_suppliers'] ?? [])->last()['error'] ?? 'Every supplier sync job failed or timed out.')
                    : null,
            ]);
        });

        $this->refresh();
    }

    /** One supplier job's counts, merged under a row lock so jobs never overwrite each other. */
    private function mutateStats(callable $change): void
    {
        DB::transaction(function () use ($change) {
            $run = self::query()->lockForUpdate()->findOrFail($this->id);
            $run->update(['stats' => $change(array_merge(self::emptyStats(), $run->stats ?? []))]);
        });

        $this->refresh();
    }

    public function priceChangeLogs(): HasMany
    {
        return $this->hasMany(PriceChangeLog::class);
    }

    public function deactivationLogs(): HasMany
    {
        return $this->hasMany(DeactivationLog::class);
    }
}
