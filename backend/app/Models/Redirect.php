<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** ADR-029 decision 3/9: exact-path redirect, reseller-scoped, hit-counted (addendum 2 decision 15). */
class Redirect extends Model
{
    protected $fillable = [
        'reseller_id',
        'from_path',
        'to_path',
        'status_code',
        'hit_count',
    ];

    protected $casts = [
        'status_code' => 'integer',
        'hit_count' => 'integer',
    ];

    public function reseller(): BelongsTo
    {
        return $this->belongsTo(Reseller::class);
    }
}
