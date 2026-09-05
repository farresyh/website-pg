<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * PR-F build addendum decision 3: a group-message from a WhatsApp group
 * not yet mapped in `reseller_whatsapp_groups` — a short-lived holding
 * row the admin resolves via "Link to Reseller" on `/admin/resellers`,
 * or that `app:prune-reseller-whatsapp-pending-links` deletes after its
 * 24-hour TTL.
 */
class ResellerWhatsAppPendingLink extends Model
{
    // Same "WhatsApp" snake-case pitfall as ResellerWhatsAppGroup — see
    // its own $table comment.
    protected $table = 'reseller_whatsapp_pending_links';

    protected $fillable = [
        'whatsapp_group_id',
        'last_message_preview',
        'last_message_at',
    ];

    protected $casts = [
        'last_message_at' => 'datetime',
    ];
}
