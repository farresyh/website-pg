<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-027's 2026-08-29 continued addendum, decision 8: `reseller_cost_price`
 * was never actually reseller-specific — it's the platform owner's own
 * guest/standard price (cost_price x (1 + package.markup_percent)). Renamed
 * everywhere it's stored, across every table it appears on, to stop the
 * name implying a reseller-only concept that doesn't exist yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->renameColumn('reseller_cost_price', 'standard_selling_price');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->renameColumn('reseller_cost_price', 'standard_selling_price');
        });

        Schema::table('price_change_logs', function (Blueprint $table) {
            $table->renameColumn('old_reseller_cost_price', 'old_standard_selling_price');
            $table->renameColumn('new_reseller_cost_price', 'new_standard_selling_price');
        });

        Schema::table('order_resend_attempts', function (Blueprint $table) {
            $table->renameColumn('reseller_cost_price_sen', 'standard_selling_price_sen');
        });

        Schema::table('pending_price_changes', function (Blueprint $table) {
            $table->renameColumn('old_reseller_cost_price', 'old_standard_selling_price');
            $table->renameColumn('proposed_reseller_cost_price', 'proposed_standard_selling_price');
        });
    }

    public function down(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->renameColumn('standard_selling_price', 'reseller_cost_price');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->renameColumn('standard_selling_price', 'reseller_cost_price');
        });

        Schema::table('price_change_logs', function (Blueprint $table) {
            $table->renameColumn('old_standard_selling_price', 'old_reseller_cost_price');
            $table->renameColumn('new_standard_selling_price', 'new_reseller_cost_price');
        });

        Schema::table('order_resend_attempts', function (Blueprint $table) {
            $table->renameColumn('standard_selling_price_sen', 'reseller_cost_price_sen');
        });

        Schema::table('pending_price_changes', function (Blueprint $table) {
            $table->renameColumn('old_standard_selling_price', 'old_reseller_cost_price');
            $table->renameColumn('proposed_standard_selling_price', 'proposed_reseller_cost_price');
        });
    }
};
