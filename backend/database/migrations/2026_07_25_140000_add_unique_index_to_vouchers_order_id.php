<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * PRD §7.5 Path B: "one voucher per order — a second attempt is
     * rejected." VoucherController::storeFromOrder() already enforces
     * this at the application layer (a query checking for an existing
     * voucher before creating one), but that check-then-insert has the
     * same race shape found in WithdrawalController::approve() during
     * the 2026-07-25 codebase audit — two concurrent requests for the
     * same failed order could both pass the check before either
     * commits. A unique index is the real guarantee; the application
     * check stays as the friendly-error fast path. MySQL/SQLite both
     * treat multiple NULLs as distinct under a unique index, so
     * standalone (Path A) vouchers with order_id=null are unaffected.
     */
    public function up(): void
    {
        Schema::table('vouchers', function (Blueprint $table) {
            $table->unique('order_id');
        });
    }

    public function down(): void
    {
        Schema::table('vouchers', function (Blueprint $table) {
            $table->dropUnique(['order_id']);
        });
    }
};
