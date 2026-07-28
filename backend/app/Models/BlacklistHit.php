<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR-007 / FRAUD-3 - one row per blocked checkout attempt. A blocked
 * attempt never creates an Order (FRAUD-2 rejects before payment), so
 * this is the only record that it ever happened.
 */
class BlacklistHit extends Model
{
    protected $fillable = [
        'blacklist_entry_id',
        'player_id',
        'customer_email',
        'customer_phone',
        'ip',
    ];

    public function entry(): BelongsTo
    {
        return $this->belongsTo(BlacklistEntry::class, 'blacklist_entry_id');
    }
}
