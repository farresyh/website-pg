<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VoucherMerge extends Model
{
    protected $fillable = [
        'source_voucher_id',
        'target_voucher_id',
        'reason',
        'merged_by',
    ];

    public function sourceVoucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class, 'source_voucher_id');
    }

    public function targetVoucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class, 'target_voucher_id');
    }
}
