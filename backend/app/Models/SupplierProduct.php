<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A raw, uncurated product listing mirrored from a supplier's own
 * catalog (MID-1) — never customer-facing on its own. See the
 * `supplier_products` migration for why this is a separate table from
 * `Package`.
 */
class SupplierProduct extends Model
{
    protected $fillable = [
        'supplier_id',
        'game_id',
        'external_ref',
        'name',
        'category_raw',
        'price_sen',
        'status_raw',
        'last_synced_at',
    ];

    protected $casts = [
        'price_sen' => 'integer',
        'last_synced_at' => 'datetime',
    ];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }
}
