<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-115 2026-11-05 addendum: the low-balance email's claim (decision 7),
 * and an index for the 60-day daily-spend read (decision 4).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->timestamp('low_balance_alerted_at')->nullable()->after('price_sync_alerted_at');
        });

        Schema::table('supplier_ledger_entries', function (Blueprint $table) {
            $table->index(['supplier_id', 'type', 'created_at'], 'sle_supplier_type_created_idx');
        });
    }

    public function down(): void
    {
        Schema::table('supplier_ledger_entries', function (Blueprint $table) {
            $table->dropIndex('sle_supplier_type_created_idx');
        });

        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropColumn('low_balance_alerted_at');
        });
    }
};
