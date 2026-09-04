<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * PR-F build addendum decision 3: a group-message from a WhatsApp
     * group not yet in `reseller_whatsapp_groups` gets a short-lived
     * holding row here (never a direct-message — the trust boundary is
     * group membership, ADR-075 decision 3, so a DM has no reseller to
     * even tentatively identify) instead of being silently dropped. The
     * admin links it to a `Reseller` from `/admin/resellers` (the row is
     * then deleted, its job done); an unmatched row past the 24-hour TTL
     * is pruned (`app:prune-reseller-whatsapp-pending-links`) rather than
     * accumulating stale noise. `last_message_at` bumps on every repeat
     * message from the same still-unlinked group, so the TTL measures
     * "still no admin action", not "first ever seen".
     */
    public function up(): void
    {
        Schema::create('reseller_whatsapp_pending_links', function (Blueprint $table) {
            $table->id();
            $table->string('whatsapp_group_id')->unique();
            $table->string('last_message_preview')->nullable();
            $table->timestamp('last_message_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reseller_whatsapp_pending_links');
    }
};
