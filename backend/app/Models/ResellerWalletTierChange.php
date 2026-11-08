<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** ADR-139 decision 7: one wallet tier change, applied or waiting for its 1st (KL). */
class ResellerWalletTierChange extends Model
{
    protected $fillable = ['reseller_id', 'from_tier_id', 'to_tier_id', 'effective_on', 'applied_at', 'admin_user_id'];

    protected $casts = [
        'effective_on' => 'date',
        'applied_at' => 'datetime',
    ];

    public function reseller(): BelongsTo
    {
        return $this->belongsTo(Reseller::class);
    }

    public function toTier(): BelongsTo
    {
        return $this->belongsTo(ResellerTier::class, 'to_tier_id')->withTrashed();
    }
}
