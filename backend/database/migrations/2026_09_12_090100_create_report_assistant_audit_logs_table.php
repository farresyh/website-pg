<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-087 decision 8 — every LLM Report Assistant query is logged and
 * retained 90 days: the natural-language question, the generated SQL
 * (if any — a pure strategic-advice turn has none), and a truncated
 * result-set snapshot. Independent of decision 7's ephemeral chat
 * session — this is the compliance/review trail, not the chat history.
 * Pruned by `report-assistant:prune-audit-logs` (scheduled daily).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_assistant_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_user_id')->constrained()->cascadeOnDelete();
            $table->text('question');
            $table->text('generated_sql')->nullable();
            $table->unsignedInteger('row_count')->nullable();
            // Truncated (see ReportAssistantService) — an audit trail,
            // not a durable data export; never the source of truth for
            // a figure someone acts on later.
            $table->json('result_sample')->nullable();
            $table->text('answer')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_assistant_audit_logs');
    }
};
