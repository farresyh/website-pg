<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Voucher extends Model
{
    protected $fillable = [
        'order_id',
        'code',
        'idempotency_key',
        'customer_email',
        'customer_phone',
        'amount',
        'remaining',
        'status',
        'expires_at',
        'reason',
        'created_by',
        'approved_by',
    ];

    protected $casts = [
        'amount' => 'integer',
        'remaining' => 'integer',
        'expires_at' => 'datetime',
    ];

    /**
     * ADR-024 decision #2 — every checkout that spent this voucher
     * (wallet model, may span many orders), most recent first left to
     * the caller. Distinct from `order()` (the inverse of the
     * inherited belongsTo via `order_id`) — that FK is Path B's "this
     * voucher was issued because that order failed," not "this voucher
     * was spent on that order."
     */
    public function redemptions(): HasMany
    {
        return $this->hasMany(VoucherRedemption::class);
    }

    /**
     * VCH-7 (Path B) — the failed order this voucher was issued to
     * compensate, if any (null for a standalone Path A voucher).
     */
    public function sourceOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }
}
