<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Supplier extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'logo_url',
        'is_active',
        'api_config',
        'balance',
        'currency',
    ];

    /**
     * SUPP-5: never returned in full via any API response — defense
     * in depth so a future controller can't leak it via a plain
     * ->toArray()/->toJson() call without an explicit decision to.
     */
    protected $hidden = [
        'api_config',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'api_config' => 'encrypted:array', // SUPP-5: encrypted at rest
        'balance' => 'decimal:2',
    ];
}
