<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ADR-028 decision 4: platform-wide ops config, singleton row (not
     * a generic key-value store — fixed columns, matching this
     * codebase's own house style). Currency locked to MYR in the UI
     * (Phase 2 multi-currency, decision 4); maintenance mode gates
     * checkout only (decision 8); Telegram fields are ops-notification
     * settings only — the actual sending mechanism is out of scope
     * (decision 6).
     */
    public function up(): void
    {
        Schema::create('platform_settings', function (Blueprint $table) {
            $table->id();
            $table->string('currency')->default('MYR');
            $table->boolean('maintenance_mode')->default(false);
            $table->text('maintenance_message')->nullable();
            $table->boolean('telegram_notifications_enabled')->default(false);
            $table->string('telegram_bot_token')->nullable();
            $table->string('telegram_chat_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_settings');
    }
};
