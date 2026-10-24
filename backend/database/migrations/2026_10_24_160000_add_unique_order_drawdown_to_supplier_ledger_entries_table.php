<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-127 decision 11: the database, not an app-level `exists()`, keeps an
 * order (or a combo leg) to one drawdown. A plain unique on
 * (reference_type, reference_id, type) would also forbid a second
 * MANUAL_ADJUSTMENT correction of the same transfer, which is allowed, so
 * the key is a virtual column that is NULL for every other type. Production
 * had no duplicate drawdown on 2026-10-24.
 */
return new class extends Migration
{
    public function up(): void
    {
        // MySQL reads `||` as OR, sqlite has no CONCAT() before 3.44.
        $key = DB::getDriverName() === 'sqlite'
            ? "reference_type || ':' || reference_id"
            : "CONCAT(reference_type, ':', reference_id)";

        Schema::table('supplier_ledger_entries', function (Blueprint $table) use ($key) {
            $table->string('drawdown_key')->nullable()
                ->virtualAs("CASE WHEN type = 'ORDER_DRAWDOWN' THEN {$key} END");
        });

        Schema::table('supplier_ledger_entries', function (Blueprint $table) {
            $table->unique('drawdown_key');
        });
    }

    public function down(): void
    {
        Schema::table('supplier_ledger_entries', function (Blueprint $table) {
            $table->dropUnique(['drawdown_key']);
            $table->dropColumn('drawdown_key');
        });
    }
};
