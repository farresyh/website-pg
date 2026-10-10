<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR-083 2026-10-10 addendum, decisions 9–13 — one closed KL month.
 * Written only by `MonthCloseService`. A reopen sets `voided_at`; the row
 * itself is kept.
 */
class AccountingPeriodClose extends Model
{
    protected $fillable = [
        'period_month',
        'operating_profit_sen',
        'prior_adjustment_sen',
        'allocated_sen',
        'budget_envelope_posting_id',
        'profit_lines',
        'cash_balances',
        'equation',
        'gap_sen',
        'gap_note',
        'closed_by',
        'voided_at',
        'voided_by',
        'void_reason',
    ];

    protected $casts = [
        'period_month' => 'date:Y-m-d',
        'operating_profit_sen' => 'integer',
        'prior_adjustment_sen' => 'integer',
        'allocated_sen' => 'integer',
        'profit_lines' => 'array',
        'cash_balances' => 'array',
        'equation' => 'array',
        'gap_sen' => 'integer',
        'voided_at' => 'datetime',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('voided_at');
    }

    public function posting(): BelongsTo
    {
        return $this->belongsTo(BudgetEnvelopePosting::class, 'budget_envelope_posting_id');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'closed_by');
    }
}
