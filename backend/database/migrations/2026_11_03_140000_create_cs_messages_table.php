<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-136 PR 1 (decisions 8, 19, 33): every personal-chat message on the
 * customer-support number, masked. The chat is a keyed hash, never the
 * phone; order numbers sit in their own column; our own sends carry no text
 * (their content is in customer_notifications).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cs_messages', function (Blueprint $table) {
            $table->id();
            $table->string('wa_message_id', 191)->unique();
            $table->char('chat_hash', 64);
            $table->string('direction', 3);
            $table->string('sender', 10);
            $table->string('type', 20);
            $table->text('body')->nullable();
            $table->json('order_numbers')->nullable();
            $table->foreignId('customer_notification_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source', 10);
            $table->timestamp('sent_at');
            $table->timestamps();

            $table->index(['chat_hash', 'sent_at']);
            $table->index('customer_notification_id');
        });

        Schema::table('customer_notifications', function (Blueprint $table) {
            $table->string('wa_message_id', 191)->nullable()->after('sent_at');
            $table->index('wa_message_id');
        });
    }

    public function down(): void
    {
        Schema::table('customer_notifications', function (Blueprint $table) {
            $table->dropIndex(['wa_message_id']);
            $table->dropColumn('wa_message_id');
        });

        Schema::dropIfExists('cs_messages');
    }
};
