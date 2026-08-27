<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ADR-046 decision 8 — mirrors payment_methods.last_tested_at/
     * last_test_result (ADR-022): the result of an on-demand
     * checkBalance() call fired from the Supplier Management screen's
     * "Refresh Balance" button, not a scheduled/passive value.
     */
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->timestamp('last_tested_at')->nullable()->after('currency');
            $table->string('last_test_result')->nullable()->after('last_tested_at');
        });
    }

    public function down(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropColumn(['last_tested_at', 'last_test_result']);
        });
    }
};
