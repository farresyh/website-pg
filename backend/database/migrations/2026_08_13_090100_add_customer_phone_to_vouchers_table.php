<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ADR-024 decision #3 — checkout's ownership lock matches on
     * customer_email OR customer_phone. Nullable: Path A (standalone,
     * admin-typed) never collected a phone before this and still
     * doesn't require one; Path B (storeFromOrder) now stamps the
     * source order's own customer_phone automatically, giving those
     * vouchers the stronger of the two lock options for free.
     */
    public function up(): void
    {
        Schema::table('vouchers', function (Blueprint $table) {
            $table->string('customer_phone')->nullable()->after('customer_email');
        });
    }

    public function down(): void
    {
        Schema::table('vouchers', function (Blueprint $table) {
            $table->dropColumn('customer_phone');
        });
    }
};
