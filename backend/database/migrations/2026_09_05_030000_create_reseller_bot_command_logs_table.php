<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * PR-F build addendum decision 2: narrow, failure-only log — a
     * successful bot order is already visible on `/admin/orders` (the
     * Source column), logging it again here would be pure duplication.
     * Written only when a command is unrecognized, or recognized but
     * fails placement/catalog resolution (mirrors `ClientErrorController`'s
     * single-log-sink shape, not `supplier_request_logs`' full CRUD
     * screen). `reseller_id` nullOnDelete + nullable: a failure can occur
     * before a group is ever linked to a `Reseller` at all (an
     * unrecognized command from a still-pending group). 7-day retention
     * (`app:prune-reseller-bot-command-logs`) — matches `player_validations`'
     * own window, since `raw_command` can carry a player/server ID, the
     * same PII class.
     */
    public function up(): void
    {
        Schema::create('reseller_bot_command_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reseller_id')->nullable()->constrained('resellers')->nullOnDelete();
            $table->string('whatsapp_group_id');
            $table->string('raw_command');
            $table->string('failure_reason');
            $table->timestamps();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reseller_bot_command_logs');
    }
};
