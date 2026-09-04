<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ADR-075 decision 2: this mapping IS the per-account identifier for
     * the Reseller Bot channel — a message from any member of a mapped,
     * active WhatsApp group is treated as an order attempt from that
     * `Reseller` (wallet) account. `is_active` (PR-F build addendum
     * decision 3) is the unlink/deactivate toggle — a soft off, matching
     * `Reseller.is_active`'s own convention, since a reseller leaving or
     * a dispute should never lose the historical fact that this group
     * once belonged to them.
     */
    public function up(): void
    {
        Schema::create('reseller_whatsapp_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reseller_id')->constrained('resellers')->cascadeOnDelete();
            $table->string('whatsapp_group_id')->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reseller_whatsapp_groups');
    }
};
