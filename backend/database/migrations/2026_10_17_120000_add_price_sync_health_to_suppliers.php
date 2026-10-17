<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-015 2026-10-17 addendum (PRD §16 item 106): `last_price_sync_at` is
 * when this supplier's prices last synced successfully (written by
 * SyncSupplierPricesJob); `price_sync_alerted_at` is set while a stale-sync
 * alert is open (CheckPriceSyncStalenessCommand). Existing rows are
 * backfilled to now so the first check after deploy doesn't alert on a
 * supplier that simply has no history in this column yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->timestamp('last_price_sync_at')->nullable()->after('last_test_result');
            $table->timestamp('price_sync_alerted_at')->nullable()->after('last_price_sync_at');
        });

        DB::table('suppliers')->update(['last_price_sync_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropColumn(['last_price_sync_at', 'price_sync_alerted_at']);
        });
    }
};
