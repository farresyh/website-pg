<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // ADR-018 decision #1: a sandbox order is a real Order row —
            // this single boolean is what scopes it out of every real
            // admin/ledger path (Admin\OrderController's permanent
            // where('is_test', false), OrderFulfillmentService::
            // creditProfit()'s guard), rather than a parallel schema.
            $table->boolean('is_test')->default(false)->after('reference_number');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('is_test');
        });
    }
};
