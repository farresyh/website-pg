<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-111 decision 2: captures the supplier's own REAL per-transaction
 * price (converted via `CurrencyRateService::convertToSen()`) at every
 * point a delivery outcome becomes final — distinct from `cost_price`
 * (a catalog snapshot, on its own Price Sync cadence, never the real
 * per-transaction figure this specific delivery actually cost).
 * `orders.real_cost_price_sen` for a non-combo order; per-leg on
 * `order_delivery_legs.real_cost_price_sen` for a combo (decision 2's
 * four write sites: fulfill()'s Success branch, finalizePendingDelivery(),
 * attemptLeg()'s Success branch, finalizePendingDeliveryLeg()). Nullable —
 * null means either not yet delivered, or the FX rate was genuinely
 * unavailable at reconciliation time (decision 6's never-block fallback).
 *
 * `orders.profit_reconciled_flagged` (decision 7): replaces the old
 * `hasNegativeComboProfit()`-derived, combo-only, negative-only signal
 * with a persisted, universal one — fires on any negative reconciled
 * profit OR a material drift from the pre-reconciliation estimate
 * (more than RM1 AND more than 1% of `selling_price`, both conditions).
 * Persisted rather than log-only so Order Detail keeps showing it on a
 * later view, not just at the moment reconciliation happened.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedInteger('real_cost_price_sen')->nullable()->after('platform_profit');
            $table->boolean('profit_reconciled_flagged')->default(false)->after('real_cost_price_sen');
        });

        Schema::table('order_delivery_legs', function (Blueprint $table) {
            $table->unsignedInteger('real_cost_price_sen')->nullable()->after('selling_price_sen');
        });
    }

    public function down(): void
    {
        Schema::table('order_delivery_legs', function (Blueprint $table) {
            $table->dropColumn('real_cost_price_sen');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['real_cost_price_sen', 'profit_reconciled_flagged']);
        });
    }
};
