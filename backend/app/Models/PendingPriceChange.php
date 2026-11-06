<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR-138: a supplier price held back while the package keeps selling at
 * its old cost: a big drop, or any big swing in a mass change. `status`
 * moves pending -> approved, auto_applied or superseded (older rows may be
 * dismissed) and is never deleted — a permanent audit trail, mirroring
 * PriceChangeLog/DeactivationLog's own append-only convention.
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
