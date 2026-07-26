<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR-016 Sync Details modal: one row per package
 * PackagePriceSyncService::deactivate() actually turned off on a given
 * run — the deactivation counterpart to PriceChangeLog, since
 * Deactivation Detection's own "only actual changes get logged"
 * discipline (ADR-015 decision #2) applies here too.
 */
class DeactivationLog extends Model
{
    protected $fillable = [
        'price_sync_run_id',
        'package_id',
    ];

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }

    public function priceSyncRun(): BelongsTo
    {
        return $this->belongsTo(PriceSyncRun::class);
    }
}
