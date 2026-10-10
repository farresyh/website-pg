<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR-083 2026-10-10 addendum, decision 2 — one line of a
 * `BudgetEnvelopePosting`: a signed amount into or out of one envelope.
 * Everything else (date, description, receipt, void) lives on the posting.
 * Append-only, like the posting.
 */
class BudgetEnvelopeEntry extends Model
{
    const UPDATED_AT = null;

    protected $fillable = [
        'budget_envelope_posting_id',
        'budget_envelope_id',
        'amount_sen',
    ];

    protected $casts = [
        'amount_sen' => 'integer',
    ];

    protected static function booted(): void
    {
        static::updating(function () {
            throw new \LogicException('BudgetEnvelopeEntry rows are append-only — cannot update a persisted row.');
        });

        static::deleting(function () {
            throw new \LogicException('BudgetEnvelopeEntry rows are append-only — cannot delete a persisted row.');
        });
    }

    public function posting(): BelongsTo
    {
        return $this->belongsTo(BudgetEnvelopePosting::class, 'budget_envelope_posting_id');
    }

    public function budgetEnvelope(): BelongsTo
    {
        return $this->belongsTo(BudgetEnvelope::class);
    }
}
