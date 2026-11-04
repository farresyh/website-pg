<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ADR-118 (2026-11-04 addendum V1–V20): one KOL's marketing push, the
 * parent of its promo codes. Written and read through CampaignService.
 */
class Campaign extends Model
{
    protected $fillable = ['affiliate_id', 'name', 'ended_at', 'created_by'];

    protected $casts = ['affiliate_id' => 'integer', 'ended_at' => 'datetime'];

    public function affiliate(): BelongsTo
    {
        return $this->belongsTo(Affiliate::class);
    }

    public function promoCodes(): HasMany
    {
        return $this->hasMany(PromoCode::class);
    }

    public function hasEnded(): bool
    {
        return $this->ended_at !== null && $this->ended_at->lte(now());
    }
}
