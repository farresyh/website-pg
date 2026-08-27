<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `delivered_at` (2026_08_27_090000_add_delivered_at_to_orders_table)
 * only gets set going forward by OrderFulfillmentService's three
 * Delivered-transition sites — every order already
 * delivery_status=delivered before this migration ran has
 * delivered_at=NULL. `updated_at` is the best available proxy (ADR-045
 * decision 24) — not a guaranteed-exact original delivery timestamp,
 * since a row's updated_at could reflect a later, unrelated touch, but
 * never left as NULL for an already-Delivered order.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('orders')
            ->where('delivery_status', 'delivered')
            ->whereNull('delivered_at')
            ->update(['delivered_at' => DB::raw('updated_at')]);
    }

    /**
     * One-way data backfill — no reliable way to distinguish "was NULL
     * before this migration" from "genuinely delivered at its
     * updated_at instant" after the fact, so there is nothing safe to
     * reverse.
     */
    public function down(): void {}
};
