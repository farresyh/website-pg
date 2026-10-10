<?php

namespace App\Models;

use App\Services\Accounting\EnvelopePostingType;
use App\Services\Accounting\ExpenseCategory;
use App\Services\Accounting\FundType;
use App\Services\Accounting\PaidFrom;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * ADR-083 2026-10-10 addendum, decision 2 — one Envelope Ledger action: the
 * header (type, date, receipt, counterparty, void link) over its lines
 * (`BudgetEnvelopeEntry`). Append-only: a mistake is voided by a reversal
 * posting (`reverses_posting_id`), never edited or deleted. Written only by
 * `BudgetEnvelopeService`.
 */
class BudgetEnvelopePosting extends Model
{
    const UPDATED_AT = null;

    protected $fillable = [
        'type',
        'amount_sen',
        'transaction_date',
        'description',
        'counterparty',
        'fund_type',
        'expense_category',
        'reference_no',
        'receipt_path',
        'reverses_posting_id',
        'void_reason',
        'created_by',
    ];

    protected $casts = [
        'type' => EnvelopePostingType::class,
        'amount_sen' => 'integer',
        'transaction_date' => 'date:Y-m-d',
        'counterparty' => PaidFrom::class,
        'fund_type' => FundType::class,
        'expense_category' => ExpenseCategory::class,
    ];

    protected static function booted(): void
    {
        static::updating(function () {
            throw new \LogicException('BudgetEnvelopePosting rows are append-only — cannot update a persisted row.');
        });

        static::deleting(function () {
            throw new \LogicException('BudgetEnvelopePosting rows are append-only — cannot delete a persisted row.');
        });
    }

    public function lines(): HasMany
    {
        return $this->hasMany(BudgetEnvelopeEntry::class)->orderBy('id');
    }

    /** The posting that voided this one, if any. */
    public function reversal(): HasOne
    {
        return $this->hasOne(self::class, 'reverses_posting_id');
    }

    public function reversesPosting(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_posting_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'created_by');
    }
}
