<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR-116 decision 9: one outbound WhatsApp message. Written only by
 * WhatsAppOutbox (queued/skipped) and SendCustomerWhatsAppJob (sent/failed/
 * skipped at send). Since ADR-125 decision 53 a `bot` row is a supplier
 * group post: `chat_id` is the group and `phone` is empty.
 */
class CustomerNotification extends Model
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SKIPPED = 'skipped';

    public const SESSION_CUSTOMER_SUPPORT = 'customer_support';

    public const SESSION_BOT = 'bot';

    protected $fillable = [
        'session',
        'event',
        'dedupe_key',
        'order_id',
        'voucher_id',
        'phone',
        'chat_id',
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
