<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ADR-024 decision #1 — stamped at Order::create() time (alongside
     * the already-existing voucher_discount) so CheckoutService's
     * requestPayment() can find which voucher to redeem after a
     * gateway confirms success without needing the original
     * CheckoutRequest — it's reachable from both initiate() and
     * resume(), and resume() only ever has the Order itself to work
     * from. Nullable: most orders never use a voucher at all. No FK
     * constraint, matching this table's existing convention for
     * game_id/package_id/supplier_id/reseller_id (added before those
     * tables existed) — vouchers did exist first here, but kept
     * consistent rather than a one-off exception.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedBigInteger('voucher_id')->nullable()->after('reseller_id');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('voucher_id');
        });
    }
};
