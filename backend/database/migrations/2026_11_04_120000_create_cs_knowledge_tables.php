<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-136 PR 2 (decisions 2, 10, 16, 17, 30): the approved knowledge the
 * assistant will answer from, the nightly suggestions an admin reviews
 * (masked customer question + staff answer, never read by the model), and
 * the chats an admin chose to ignore (founder and staff tests).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cs_knowledge_entries', function (Blueprint $table) {
            $table->id();
            $table->string('category', 30);
            $table->json('questions');
            $table->text('answer');
            // Null = every brand. Affiliates are soft-deleted, so the FK never fires.
            $table->foreignId('affiliate_id')->nullable()->constrained();
            // A deleted game takes its knowledge with it.
            $table->foreignId('game_id')->nullable()->constrained()->cascadeOnDelete();
            $table->date('valid_until')->nullable();
            $table->string('status', 10);
            $table->foreignId('approved_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedInteger('use_count')->default(0);
            $table->timestamps();

            $table->index('status');
            $table->index('affiliate_id');
            $table->index('game_id');
            $table->index('approved_by');
        });

        Schema::create('cs_knowledge_suggestions', function (Blueprint $table) {
            $table->id();
            $table->char('chat_hash', 64);
            // cs_messages.id of the question's first message; no FK so the 2-year prune of messages never touches this row.
            $table->unsignedBigInteger('first_message_id')->unique();
            $table->text('question');
            $table->text('answer');
            $table->json('order_numbers')->nullable();
            $table->foreignId('affiliate_id')->nullable()->constrained();
            $table->timestamp('asked_at');
            $table->string('status', 10);
            $table->foreignId('cs_knowledge_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'asked_at']);
            $table->index('chat_hash');
            $table->index('affiliate_id');
            $table->index('cs_knowledge_entry_id');
            $table->index('reviewed_by');
        });

        Schema::create('cs_ignored_chats', function (Blueprint $table) {
            $table->id();
            $table->char('chat_hash', 64)->unique();
            $table->foreignId('ignored_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamps();

            $table->index('ignored_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cs_ignored_chats');
        Schema::dropIfExists('cs_knowledge_suggestions');
        Schema::dropIfExists('cs_knowledge_entries');
    }
};
