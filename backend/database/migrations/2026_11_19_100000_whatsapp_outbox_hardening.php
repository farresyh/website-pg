<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-116 / ADR-075 2026-11-14 addenda.
 * - `customer_notifications.due_at`: when a queued row should go out (its
 *   pacing slot, an earliest send time, or a pacing refusal's retry time),
 *   so the stuck-message alert can tell "late" from "not due yet".
 * - `platform_settings.bot_command_max_age_minutes`: a reseller bot money
 *   command older than this is not run (default 3).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_notifications', function (Blueprint $table) {
            $table->timestamp('due_at')->nullable()->after('status');
            $table->index(['status', 'due_at']);
        });

        Schema::table('platform_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('bot_command_max_age_minutes')->default(3);
        });
    }

    public function down(): void
    {
        Schema::table('customer_notifications', function (Blueprint $table) {
            $table->dropIndex(['status', 'due_at']);
            $table->dropColumn('due_at');
        });

        Schema::table('platform_settings', function (Blueprint $table) {
            $table->dropColumn('bot_command_max_age_minutes');
        });
    }
};
