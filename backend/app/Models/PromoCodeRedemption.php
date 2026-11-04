<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR-118 R9/V9: one order's use of a promo code — reserved before the
 * payment link is shown, committed on delivery, restored (in whole or in
 * part) when the payment fails or the order is compensated.
 */
class PromoCodeRedemption extends Model
{
    public const STATUS_RESERVED = 'reserved';

    public const STATUS_COMMITTED = 'committed';

    public const STATUS_RESTORED = 'restored';

    protected $fillable = ['promo_code_id', 'order_id', 'amount_sen', 'restored_sen', 'status', 'restored_at', 'over_cap'];

    protected $casts = [
        'amount_sen' => 'integer',
        'restored_sen' => 'integer',
        'restored_at' => 'datetime',
        'over_cap' => 'boolean',
    ];

    public function promoCode(): BelongsTo
    {
        return $this->belongsTo(PromoCode::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** What this redemption still holds against its code's budget. */
    public function heldSen(): int
    {
        return $this->amount_sen - $this->restored_sen;
    }
}
