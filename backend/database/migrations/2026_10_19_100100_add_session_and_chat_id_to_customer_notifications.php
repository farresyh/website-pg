<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-125 decision 53: the persisted WhatsApp queue also carries the
 * bot session's supplier-group posts. A customer row keeps `phone`; a
 * supplier row has `chat_id` (the group) instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_notifications', function (Blueprint $table) {
            $table->string('session', 20)->default('customer_support')->after('event');
            $table->string('chat_id', 100)->nullable()->after('phone');
        });
    }

    public function down(): void
    {
        Schema::table('customer_notifications', function (Blueprint $table) {
            $table->dropColumn(['session', 'chat_id']);
        });
    }
};
