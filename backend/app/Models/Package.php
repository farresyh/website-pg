<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Package extends Model
{
    protected $fillable = [
        'game_id',
        'name',
        'cost_price',
        'reseller_cost_price',
        'is_active',
        'supplier_id',
        'supplier_package_ref',
        'sort_order',
    ];

    protected $casts = [
        'cost_price' => 'integer',
        'reseller_cost_price' => 'integer',
        'is_active' => 'boolean',
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
