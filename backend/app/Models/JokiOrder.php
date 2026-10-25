<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR-126 decisions 1, 3, 7, 13: what one joki order bought — the ranks,
 * Express, the customer's hero/role/play-window requests — and a snapshot
 * of the quote (lines, rates, rank model, grading rule), payout share and
 * terms it was sold under. Written with the order, in OrderFactory's
 * transaction. `current_rank` is the latest progress (decision 10).
 */
class JokiOrder extends Model
{
    protected $fillable = [
        'order_id', 'from_rank', 'to_rank', 'current_rank', 'current_rank_at', 'is_express', 'hero_request', 'role', 'play_window', 'quote', 'payout_share_pct', 'terms_snapshot',
    ];

    protected $casts = [
        'from_rank' => 'array',
        'to_rank' => 'array',
        'current_rank' => 'array',
        'current_rank_at' => 'datetime',
        'is_express' => 'boolean',
        'quote' => 'array',
        'payout_share_pct' => 'float',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
