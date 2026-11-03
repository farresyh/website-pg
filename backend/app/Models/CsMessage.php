<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * ADR-136 decision 8: one personal-chat message on the customer-support
 * number, masked (CsTextRedactor). Written only by CsMessageRecorder.
 */
class CsMessage extends Model
{
    public const SENDER_CUSTOMER = 'customer';

    public const SENDER_STAFF = 'staff';

    /** Our own send (status card, receipt, voucher, STOP reply): its text lives in customer_notifications. */
    public const SENDER_SYSTEM = 'system';

    public const SOURCE_WEBHOOK = 'webhook';

    public const SOURCE_IMPORT = 'import';

    protected $fillable = [
        'wa_message_id',
        'chat_hash',
        'direction',
        'sender',
        'type',
        'body',
        'order_numbers',
        'customer_notification_id',
        'source',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'order_numbers' => 'array',
            'sent_at' => 'datetime',
        ];
    }
}
