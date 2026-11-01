<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-116 2026-11-01 addendum: a contact is a phone, a WhatsApp privacy-id
 * chat (`…@lid`), or both, so STOP/START work for a sender whose number
 * WhatsApp hides. Both stay unique; MySQL allows many NULLs in a unique index.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_contacts', function (Blueprint $table) {
            $table->string('phone', 20)->nullable()->change();
            $table->string('chat_id', 100)->nullable()->unique()->after('phone');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_contacts', function (Blueprint $table) {
            $table->dropUnique(['chat_id']);
            $table->dropColumn('chat_id');
        });
    }
};
