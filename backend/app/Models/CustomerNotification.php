<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR-116 decision 9: one outbound WhatsApp message. Written only by
 * WhatsAppOutbox (queued/skipped) and SendCustomerWhatsAppJob (sent/failed/
 * skipped at send). Since ADR-125 decision 53 a `bot` row is a supplier
 * group post: `chat_id` is the group and `phone` is empty. Since the ADR-116
 * 2026-11-14 addendum this table is the one WhatsApp outbox: every bot reply
 * to a reseller or supplier group is a `bot` row too.
 */
class CustomerNotification extends Model
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SKIPPED = 'skipped';

    /** OpenWA could not say whether the message went out; never re-sent automatically. */
    public const STATUS_UNKNOWN = 'unknown';

    /** A read reply that could not go out within its lifetime. */
    public const STATUS_EXPIRED = 'expired';

    /** An order's "received" reply overtaken by a newer status for the same order. */
    public const STATUS_SUPERSEDED = 'superseded';

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
        'due_at',
        'attempts',
        'error',
        'sent_at',
        'wa_message_id',
    ];

    protected $casts = [
        'attempts' => 'integer',
        'due_at' => 'datetime',
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
