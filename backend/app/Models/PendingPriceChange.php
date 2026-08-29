<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR-025 decision #2: a supplier-sourced price swing large enough to
 * cross `PRICE_SWING_THRESHOLD_PERCENT`, blocked from being applied
 * until an admin reviews it. `status` moves pending -> approved/
 * dismissed and is never deleted — a permanent audit trail of every
 * anomaly ever flagged, mirroring PriceChangeLog/DeactivationLog's own
 * append-only convention.
 */
class PendingPriceChange extends Model
{
    protected $fillable = [
        'price_sync_run_id',
        'package_id',
        'old_cost_price',
        'proposed_cost_price',
        'old_standard_selling_price',
        'proposed_standard_selling_price',
        'status',
    ];

    protected $casts = [
        'old_cost_price' => 'integer',
        'proposed_cost_price' => 'integer',
        'old_standard_selling_price' => 'integer',
        'proposed_standard_selling_price' => 'integer',
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
