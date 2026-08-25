<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Package extends Model
{
    protected $fillable = [
        'game_id',
        'name',
        'denomination',
        'cost_price',
        'reseller_cost_price',
        'markup_percent',
        'is_active',
        'deactivated_reason',
        'deactivated_at',
        'supplier_id',
        'supplier_package_ref',
        'sort_order',
    ];

    protected $casts = [
        'denomination' => 'integer',
        'cost_price' => 'integer',
        'reseller_cost_price' => 'integer',
        'markup_percent' => 'decimal:2',
        'is_active' => 'boolean',
        'deactivated_at' => 'datetime',
        'sort_order' => 'integer',
    ];

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }
}
