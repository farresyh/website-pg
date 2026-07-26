<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    public function priceChangeLogs(): HasMany
    {
        return $this->hasMany(PriceChangeLog::class);
    }

    public function deactivationLogs(): HasMany
    {
        return $this->hasMany(DeactivationLog::class);
    }
}
