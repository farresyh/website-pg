<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-083 2026-09-30 addendum (Bucket C, decision 5) — `source_channel`
 * (wise/airwallex/bank) is the payment *rail*, never *whose* money
 * funded a top-up. Optional — same `PaidFrom` enum the Envelope Ledger's
 * `paid_from` uses (decision 8: one shared list, not two).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_transfers', function (Blueprint $table) {
            $table->string('paid_by')->nullable()->after('source_channel'); // PaidFrom enum
        });
    }

    public function down(): void
    {
        Schema::table('supplier_transfers', function (Blueprint $table) {
            $table->dropColumn('paid_by');
        });
    }
};
