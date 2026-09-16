<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR-100 — one row per package `PendingReactivationAutoApprover`
 * actually approved. See that migration's own docblock for why this
 * is a sibling table to `deactivation_logs`, not a widened one.
 */
class PackageReactivationLog extends Model
{
    protected $fillable = [
        'package_id',
        'price_sync_run_id',
        'trigger',
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
