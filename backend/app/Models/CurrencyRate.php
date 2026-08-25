<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * ADR-033 decision 2: one row per successful FX API fetch, append-only
 * — never updated, never deleted. `CurrencyRateService` is the sole
 * writer.
 */
class CurrencyRate extends Model
{
    protected $fillable = [
        'from',
        'to',
        'rate',
        'source',
        'fetched_at',
    ];

    protected $casts = [
        'rate' => 'float',
        'fetched_at' => 'datetime',
    ];
}
