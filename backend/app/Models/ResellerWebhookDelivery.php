<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR-084 PR-3 decision 4: one row per (order, event) webhook, updated
 * in place across `DeliverResellerWebhook`'s retry attempts. The
 * portal/admin dead-letter view reads these; `status` is
 * pending | delivered | failed | exhausted.
 */
class ResellerWebhookDelivery extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_FAILED = 'failed';

    public const STATUS_EXHAUSTED = 'exhausted';

    protected $fillable = [
        'reseller_id',
        'order_id',
        'event',
        'event_id',
        'payload',
        'attempts',
        'status',
        'last_response_code',
        'next_retry_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'attempts' => 'integer',
        'last_response_code' => 'integer',
        'next_retry_at' => 'datetime',
    ];

    public function reseller(): BelongsTo
    {
        return $this->belongsTo(Reseller::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
