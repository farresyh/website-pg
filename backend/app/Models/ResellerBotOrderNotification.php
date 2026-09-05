<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR-076 decision 4 — one row per Bot-channel order. See the
 * migration's own doc comment for the full design rationale
 * (group-ambiguity + duplicate-notification problems it solves).
 */
class ResellerBotOrderNotification extends Model
{
    protected $fillable = [
        'order_id',
        'whatsapp_group_id',
        'last_notified_delivery_status',
        'refund_notified_at',
    ];

    protected $casts = [
        'refund_notified_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
