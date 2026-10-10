<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * PRD §16 item 6 (2026-10-10): the SET-9 Telegram sender was never built,
     * so these three columns only ever stored a bot token (plain text, and
     * returned to the admin client) behind a switch that did nothing. Ops
     * alerts go by Plunk email. Production held no token, no chat id and no
     * enabled flag when this was written, so nothing is lost.
     */
    public function up(): void
    {
        Schema::table('platform_settings', function (Blueprint $table) {
            $table->dropColumn(['telegram_notifications_enabled', 'telegram_bot_token', 'telegram_chat_id']);
        });
    }

    public function down(): void
    {
        Schema::table('platform_settings', function (Blueprint $table) {
            $table->boolean('telegram_notifications_enabled')->default(false);
            $table->string('telegram_bot_token')->nullable();
            $table->string('telegram_chat_id')->nullable();
        });
    }
};
