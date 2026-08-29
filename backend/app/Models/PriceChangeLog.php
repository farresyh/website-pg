<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR-015 decision #2: one row per *actual* price change Price
 * Propagation applies to a Package (no-ops are never logged) — the
 * audit trail this feature exists to provide, since propagation has
 * no approval gate in either direction.
 */
class PriceChangeLog extends Model
{
    protected $fillable = [
        'price_sync_run_id',
        'package_id',
        'old_cost_price',
        'new_cost_price',
        'old_standard_selling_price',
        'new_standard_selling_price',
    ];

    protected $casts = [
        'old_cost_price' => 'integer',
        'new_cost_price' => 'integer',
        'old_standard_selling_price' => 'integer',
        'new_standard_selling_price' => 'integer',
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
