<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-134 decision 13: what an order's cost and profit rest on. Every order
 * placed before ADR-134 was costed at the market mid-rate, so the default is
 * `market`; `OrderFactory` writes `landed` for every new order, and delivery
 * writes it when it re-costs an older one. The default (not a backfill
 * UPDATE) also labels right an order the old code places mid-deploy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('cost_basis', 8)->default('market')->after('real_cost_price_sen');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('cost_basis');
        });
    }
};
