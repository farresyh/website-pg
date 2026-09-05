<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** ADR-029 addendum 2 decision 13: admin-authored head/body_end script, null affiliate_id = global. */
class SeoScript extends Model
{
    protected $fillable = [
        'affiliate_id',
        'name',
        'location',
        'code',
        'priority',
        'is_active',
    ];

    protected $casts = [
        'priority' => 'integer',
        'is_active' => 'boolean',
    ];

    public function affiliate(): BelongsTo
    {
        return $this->belongsTo(Affiliate::class);
    }
}
