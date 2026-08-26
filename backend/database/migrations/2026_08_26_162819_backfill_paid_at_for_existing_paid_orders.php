<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `paid_at` (2026_08_26_153231_add_paid_at_to_orders_table) only gets
 * set going forward — every order that was already payment_status=paid
 * before that migration ran has paid_at=NULL. Found live: ReportService
 * (RPT-1..3) surfaced a "Latest Order" of 20691 days ago on real dev
 * data — a null paid_at reaching the frontend as epoch-zero, not a
 * chart bug. `created_at` is the best available proxy (checkout-to-paid
 * is near-instant on this platform in practice) — never left as NULL,
 * since ReportService trusts paid_at as the sole source of truth for
 * "when this order was actually paid".
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('orders')
            ->where('payment_status', 'paid')
            ->whereNull('paid_at')
            ->update(['paid_at' => DB::raw('created_at')]);
    }

    /**
     * One-way data backfill — no reliable way to distinguish "was NULL
     * before this migration" from "genuinely paid at its created_at
     * instant" after the fact, so there is nothing safe to reverse.
     */
    public function down(): void {}
};
