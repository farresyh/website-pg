<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-136 PR 3b (decisions 11, 18, 25, 27, 33): the assistant's one settings
 * row (mode starts Off), the Shadow drafts it writes and never sends, with
 * their nightly grade, and the model-written version of each knowledge
 * suggestion.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cs_assistant_settings', function (Blueprint $table) {
            $table->id();
            $table->string('mode', 10)->default('off');
            $table->string('answer_model', 60)->default('claude-haiku-5-5');
            $table->string('judge_model', 60)->default('claude-sonnet-5-5');
            $table->unsignedSmallInteger('reassure_minutes')->default(15);
            $table->unsignedSmallInteger('handover_minutes')->default(30);
            $table->unsignedSmallInteger('manual_handover_minutes')->default(180);
            $table->unsignedSmallInteger('burst_wait_seconds')->default(30);
            $table->unsignedInteger('daily_cost_cap_sen')->default(1000);
            $table->unsignedSmallInteger('live_min_drafts')->default(20);
            $table->unsignedTinyInteger('live_min_accuracy_pct')->default(90);
            $table->timestamp('cost_cap_tripped_at')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamps();
            $table->index('updated_by');
        });

        DB::table('cs_assistant_settings')->insert(['created_at' => now(), 'updated_at' => now()]);

        Schema::create('cs_bot_drafts', function (Blueprint $table) {
            $table->id();
            $table->char('chat_hash', 64);
            // cs_messages.id of the burst's last customer message: one draft per burst (decision 33).
            $table->unsignedBigInteger('last_message_id')->unique();
            $table->unsignedBigInteger('first_message_id');
            $table->text('question');
            $table->json('order_numbers')->nullable();
            $table->foreignId('affiliate_id')->nullable()->constrained();
            $table->timestamp('asked_at');
            $table->string('model', 60);
            $table->string('action', 10);
            $table->text('reply')->nullable();
            $table->string('handover_reason', 20)->nullable();
            $table->string('category', 30)->nullable();
            $table->json('entry_ids')->nullable();
            $table->json('violations')->nullable();
            $table->text('error')->nullable();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('cost_usd_micros')->default(0);
            // Decision 18's nightly pairing and grade.
            $table->text('staff_answer')->nullable();
            $table->string('grade', 25)->nullable();
            $table->text('grade_note')->nullable();
            $table->string('judge_model', 60)->nullable();
            $table->unsignedInteger('judge_cost_usd_micros')->default(0);
            $table->timestamp('judged_at')->nullable();
            $table->string('admin_grade', 25)->nullable();
            $table->foreignId('graded_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamps();
            $table->index('chat_hash');
            $table->index(['judged_at', 'asked_at']);
            $table->index('affiliate_id');
            $table->index('graded_by');
            $table->index('created_at');
        });

        Schema::table('cs_knowledge_suggestions', function (Blueprint $table) {
            $table->text('ai_question')->nullable()->after('answer');
            $table->text('ai_answer')->nullable()->after('ai_question');
            $table->string('ai_category', 30)->nullable()->after('ai_answer');
            // The entry the model thinks this question already belongs to ("update entry X").
            $table->unsignedBigInteger('ai_entry_id')->nullable()->after('ai_category');
            $table->unsignedInteger('ai_cost_usd_micros')->default(0)->after('ai_entry_id');
            $table->timestamp('ai_written_at')->nullable()->after('ai_cost_usd_micros');
        });
    }

    public function down(): void
    {
        Schema::table('cs_knowledge_suggestions', function (Blueprint $table) {
            $table->dropColumn(['ai_question', 'ai_answer', 'ai_category', 'ai_entry_id', 'ai_cost_usd_micros', 'ai_written_at']);
        });
        Schema::dropIfExists('cs_bot_drafts');
        Schema::dropIfExists('cs_assistant_settings');
    }
};
