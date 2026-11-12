<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR-004 2026-11-12 addendum: a customer-cash voucher's balance paid out by
 * bank transfer. Written only by `VoucherService::refundInCash()`, one per
 * voucher, never edited. `created_at` is the dated fact the books read.
 */
class VoucherCashRefund extends Model
{
    protected $fillable = [
        'voucher_id',
        'amount_sen',
        'transferred_on',
        'bank_name',
        'bank_account_no',
        'bank_account_holder',
        'bank_reference',
        'proof_path',
        'reason',
        'created_by',
    ];

    protected $casts = [
        'amount_sen' => 'integer',
        'transferred_on' => 'date:Y-m-d',
    ];

    protected $hidden = ['proof_path'];

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'created_by');
    }
}
