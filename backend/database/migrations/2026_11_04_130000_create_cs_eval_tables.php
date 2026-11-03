<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-136 decisions 12 and 28 (PR 3a): the model test. Cases live here, not
 * in the public repo; each run asks every configured model every active case;
 * the founder picks the most natural safe answer per case without seeing
 * which model wrote it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cs_eval_cases', function (Blueprint $table) {
            $table->id();
            $table->string('title', 120);
            $table->string('kind', 10); // safety | quality
            $table->json('messages');
            $table->foreignId('affiliate_id')->nullable()->constrained();
            $table->json('order_facts')->nullable();
            $table->string('expected_action', 10); // reply | handover | any (safety cases where only "no leak" matters)
            $table->string('expected_reason', 20)->nullable();
            $table->json('must_not_contain')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('affiliate_id');
        });

        Schema::create('cs_eval_runs', function (Blueprint $table) {
            $table->id();
            $table->json('models');
            $table->unsignedInteger('cases_count');
            $table->unsignedInteger('knowledge_count');
            $table->foreignId('started_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamp('revealed_at')->nullable();
            $table->timestamps();

            $table->index('started_by');
        });

        Schema::create('cs_eval_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cs_eval_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cs_eval_case_id')->constrained()->cascadeOnDelete();
            $table->string('model', 60);
            $table->char('blind_label', 1);
            $table->string('action', 10)->nullable();
            $table->text('reply')->nullable();
            $table->string('handover_reason', 20)->nullable();
            $table->string('category', 30)->nullable();
            $table->json('entry_ids')->nullable();
            $table->boolean('safety_passed')->nullable();
            $table->json('safety_notes')->nullable();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('cost_usd_micros')->default(0);
            $table->unsignedInteger('latency_ms')->default(0);
            $table->text('error')->nullable();
            $table->boolean('picked')->default(false);
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['cs_eval_run_id', 'cs_eval_case_id', 'model'], 'cs_eval_results_run_case_model_unique');
            $table->index('cs_eval_case_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cs_eval_results');
        Schema::dropIfExists('cs_eval_runs');
        Schema::dropIfExists('cs_eval_cases');
    }
};
