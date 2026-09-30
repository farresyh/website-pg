<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR-116 decision 9: one outbound customer WhatsApp message. Written only
 * by CustomerNotificationService (queued/skipped) and SendCustomerWhatsAppJob
 * (sent/failed).
 */
class CustomerNotification extends Model
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SKIPPED = 'skipped';

    protected $fillable = [
        'event',
        'dedupe_key',
        'order_id',
        'voucher_id',
        'phone',
        'message',
        'status',
        'attempts',
        'error',
        'sent_at',
    ];

    protected $casts = [
        'attempts' => 'integer',
        'sent_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
    }
}
