<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A bot-session group nobody has linked yet (ADR-075's "send a test
 * message" onboarding); the admin links it to a reseller or a supplier.
 */
class WhatsAppPendingLink extends Model
{
    protected $table = 'whatsapp_pending_links';

    protected $fillable = [
        'whatsapp_group_id',
        'last_message_preview',
        'last_message_at',
    ];

    protected $casts = [
        'last_message_at' => 'datetime',
    ];
}
