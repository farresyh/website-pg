<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR-118 V12: one row of a campaign's fee schedule. Written only by
 * CampaignFeeSchedule; append-only except `to_month`.
 */
class CampaignFee extends Model
{
    protected $fillable = ['campaign_id', 'monthly_fee_sen', 'from_month', 'to_month', 'created_by'];

    protected $casts = [
        'campaign_id' => 'integer',
        'monthly_fee_sen' => 'integer',
        'from_month' => 'date',
        'to_month' => 'date',
    ];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }
}
