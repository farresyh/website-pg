<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ADR-083 2026-10-10 addendum, decision 2: one `budget_envelope_postings`
     * header per action (type, date, description, receipt, counterparty,
     * void link), and `budget_envelope_entries` become its lines (envelope +
     * signed amount only).
     *
     * The old single-row entries cannot be mapped onto posting types without
     * guessing (is a "capital injection" a loan or share capital?), and
     * production held none when this was written. So this refuses to run
     * over existing rows rather than guess: re-record them as postings after
     * deciding what each one was.
     */
    public function up(): void
    {
        $existing = DB::table('budget_envelope_entries')->count();
        if ($existing > 0) {
            throw new RuntimeException(
                "budget_envelope_entries holds {$existing} row(s) in the pre-posting shape. ".
                'ADR-083 2026-10-10 addendum: export them (Envelope Ledger → Export CSV), decide each '.
                'one\'s posting type, empty the table, migrate, then re-record them as postings.'
            );
        }

        Schema::drop('budget_envelope_entries');

        Schema::create('budget_envelope_postings', function (Blueprint $table) {
            $table->id();
            $table->string('type'); // EnvelopePostingType
            // Signed: the action's own size (see BudgetEnvelopeService::headerAmount()); a reversal carries the negation.
            $table->bigInteger('amount_sen');
            $table->date('transaction_date');
            $table->text('description');
            $table->string('counterparty')->nullable(); // PaidFrom director
            $table->string('fund_type')->nullable(); // FundType, funding only
            $table->string('expense_category')->nullable(); // ExpenseCategory
            $table->string('reference_no')->nullable();
            $table->string('receipt_path')->nullable();
            // Unique: a posting can be reversed at most once (DB backstop to the service's own check).
            $table->foreignId('reverses_posting_id')->nullable()->unique()->constrained('budget_envelope_postings')->restrictOnDelete();
            $table->text('void_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index('type');
            $table->index('counterparty');
            $table->index('transaction_date');
        });

        Schema::create('budget_envelope_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('budget_envelope_posting_id')->constrained('budget_envelope_postings')->restrictOnDelete();
            $table->foreignId('budget_envelope_id')->constrained('budget_envelopes')->restrictOnDelete();
            $table->bigInteger('amount_sen'); // signed — positive=in, negative=out
            $table->timestamp('created_at')->useCurrent();

            $table->index('budget_envelope_posting_id');
            $table->index('budget_envelope_id');
        });
    }

    public function down(): void
    {
        Schema::drop('budget_envelope_entries');
        Schema::drop('budget_envelope_postings');

        // The pre-posting shape, as left by the 2026-09-28 and 2026-09-30 migrations.
        Schema::create('budget_envelope_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('budget_envelope_id')->constrained('budget_envelopes')->restrictOnDelete();
            $table->string('category');
            $table->integer('amount_sen');
            $table->date('transaction_date')->nullable();
            $table->text('description');
            $table->string('paid_from')->nullable();
            $table->string('reference_no')->nullable();
            $table->string('receipt_path')->nullable();
            $table->foreignId('reverses_entry_id')->nullable()->constrained('budget_envelope_entries')->restrictOnDelete();
            $table->text('void_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index('budget_envelope_id');
            $table->index('category');
        });
    }
};
