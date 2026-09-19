<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ADR-110 PR-B, fills ADR-083 decision 7 — one row per uploaded CHIP
 * settlement `.xlsx`. `status` is fully computed at ingest (never
 * admin-typed, since the same-day addendum found live) from a
 * per-transaction gross comparison between `matched_*` (our own
 * records, summed only over the transactions this settlement actually
 * matched — never a calendar-window query) and `file_*` (CHIP's own
 * reported totals, verbatim). `actual_bank_amount_sen` is a purely
 * optional founder annotation with no cadence and no effect on
 * `status` — see `docs/adr.md`'s ADR-110 addendum for the full
 * reasoning.
 */
class PaymentSettlement extends Model
{
    protected $fillable = [
        'date_from',
        'date_to',
        'matched_gross_sen',
        'matched_fee_sen',
        'matched_net_sen',
        'file_gross_sen',
        'file_fee_sen',
        'file_net_sen',
        'actual_bank_amount_sen',
        'status',
        'variance_note',
        'original_filename',
        'admin_user_id',
    ];

    protected $casts = [
        'date_from' => 'date:Y-m-d',
        'date_to' => 'date:Y-m-d',
        'matched_gross_sen' => 'integer',
        'matched_fee_sen' => 'integer',
        'matched_net_sen' => 'integer',
        'file_gross_sen' => 'integer',
        'file_fee_sen' => 'integer',
        'file_net_sen' => 'integer',
        'actual_bank_amount_sen' => 'integer',
    ];

    public function transactions(): HasMany
    {
        return $this->hasMany(ChipSettledTransaction::class);
    }

    public function adminUser(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class);
    }
}
