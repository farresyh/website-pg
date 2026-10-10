<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ADR-083 2026-10-10 addendum, decisions 9–14: the month close.
     *
     * `cash_accounts` is a name-only list (decision 14); a balance exists
     * only as the snapshot a close takes. `accounting_period_closes` is one
     * row per closed KL month. A reopen voids the row (`voided_at`) and its
     * allocation posting, never deletes it, so the history stays.
     */
    public function up(): void
    {
        Schema::create('cash_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Decision 14: the one account until the LWF bank account opens.
        DB::table('cash_accounts')->insert([
            'name' => 'Held by Farres (mixed)',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Schema::create('accounting_period_closes', function (Blueprint $table) {
            $table->id();
            $table->date('period_month'); // first day of the closed KL month
            // Decision 12: the month's own operating profit as allocated, plus
            // the drift carried in from earlier closes. allocated = the two.
            $table->bigInteger('operating_profit_sen');
            $table->bigInteger('prior_adjustment_sen');
            $table->bigInteger('allocated_sen');
            $table->foreignId('budget_envelope_posting_id')->nullable()->constrained('budget_envelope_postings')->restrictOnDelete();
            $table->json('profit_lines'); // the decision 11 breakdown, as closed
            $table->json('cash_balances'); // [{cash_account_id, name, balance_sen}]
            $table->json('equation'); // decision 13, as closed
            $table->bigInteger('gap_sen');
            $table->text('gap_note')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->text('void_reason')->nullable();
            $table->timestamps();

            $table->index('period_month');
            $table->index('budget_envelope_posting_id');
        });
    }

    public function down(): void
    {
        Schema::drop('accounting_period_closes');
        Schema::drop('cash_accounts');
    }
};
