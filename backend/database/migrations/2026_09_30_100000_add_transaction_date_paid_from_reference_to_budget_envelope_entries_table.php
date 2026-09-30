<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-083 2026-09-30 addendum (Bucket C, decision 3) — three real gaps
 * the founder hit using the Envelope Ledger: no way to record an entry
 * on the day money actually moved (only the auto `created_at`), no way
 * to record which account funded it, no reference number. All three
 * optional — a plain OPEX entry may genuinely have none of them known.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('budget_envelope_entries', function (Blueprint $table) {
            $table->date('transaction_date')->nullable()->after('amount_sen');
            $table->string('paid_from')->nullable()->after('description'); // PaidFrom enum
            $table->string('reference_no')->nullable()->after('paid_from');
        });
    }

    public function down(): void
    {
        Schema::table('budget_envelope_entries', function (Blueprint $table) {
            $table->dropColumn(['transaction_date', 'paid_from', 'reference_no']);
        });
    }
};
