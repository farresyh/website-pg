<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR-118 V30: a campaign's fee for one closed month, frozen by the close
 * (CampaignFeeSchedule::freezeMonth() is the only writer). Append-only: the
 * one change allowed is voiding it, which a reopen does with its close.
 */
class CampaignMonthStatement extends Model
{
    protected $fillable = [
        'campaign_id', 'accounting_period_close_id', 'period_month', 'monthly_fee_sen', 'revenue_share_bp', 'revenue_share_cap_sen',
        'sales_sen', 'profit_sen', 'share_before_limits_sen', 'share_sen', 'payable_sen', 'limited_by', 'lines', 'voided_at',
    ];

    protected $casts = [
        'campaign_id' => 'integer',
        'accounting_period_close_id' => 'integer',
        'period_month' => 'date',
        'monthly_fee_sen' => 'integer',
        'revenue_share_bp' => 'integer',
        'revenue_share_cap_sen' => 'integer',
        'sales_sen' => 'integer',
        'profit_sen' => 'integer',
        'share_before_limits_sen' => 'integer',
        'share_sen' => 'integer',
        'payable_sen' => 'integer',
        'lines' => 'array',
        'voided_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $statement) {
            if (array_keys($statement->getDirty()) !== ['voided_at'] || $statement->getOriginal('voided_at') !== null) {
                throw new \LogicException('A campaign month statement is frozen — only voiding it is allowed.');
            }
        });

        static::deleting(function () {
            throw new \LogicException('A campaign month statement is frozen — it cannot be deleted.');
        });
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function close(): BelongsTo
    {
        return $this->belongsTo(AccountingPeriodClose::class, 'accounting_period_close_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('voided_at');
    }
}
