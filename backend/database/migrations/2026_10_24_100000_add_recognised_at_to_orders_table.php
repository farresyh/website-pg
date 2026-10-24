<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-127 decision 2: the moment an order became revenue, set once by
 * `LedgerService::creditOrderProfit()` (delivery or a partial order's
 * settlement). Every accounting reader files revenue, COGS and affiliate
 * commission by it.
 *
 * Backfill: the order's first `order_profit` ledger row, which that same
 * seam has always written at recognition; else `delivered_at` (a
 * Delivered order with no ledger row: a sandbox order). Production,
 * 2026-10-24: every Delivered order's ledger month equals its delivered
 * and paid month, so no closed month moves.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('recognised_at')->nullable()->after('delivered_at');
            $table->index('recognised_at');
        });

        $firstProfit = DB::table('ledger_entries')
            ->where('type', 'order_profit')
            ->where('reference_type', 'order')
            ->groupBy('reference_id')
            ->pluck(DB::raw('MIN(created_at)'), 'reference_id');

        foreach ($firstProfit as $orderId => $at) {
            DB::table('orders')->where('id', $orderId)->update(['recognised_at' => $at]);
        }

        DB::table('orders')
            ->whereNull('recognised_at')
            ->where('delivery_status', 'delivered')
            ->update(['recognised_at' => DB::raw('delivered_at')]);
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['recognised_at']);
            $table->dropColumn('recognised_at');
        });
    }
};
