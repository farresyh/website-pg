<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ADR-022's newest addendum, decision 1: denormalized snapshot of
     * which gateway/channel an order checked out with, stamped once at
     * Order creation and never re-derived from the (mutable) payment_methods
     * row — needed so ReconcilePendingPaymentsCommand can resolve the
     * correct PaymentGateway per order once a second gateway exists.
     *
     * Backfilled for existing rows: every real order to date genuinely
     * went through Xendit (no other gateway has ever existed), so
     * payment_gateway='xendit' is fact, not a guess, for any row that
     * has a payment_ref. channel_code is left NULL for pre-existing rows
     * — unrecoverable retroactively, and not needed by reconciliation's
     * gateway-resolution logic, only by future orders' own diagnostics.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('payment_gateway')->nullable()->after('payment_method');
            $table->string('channel_code')->nullable()->after('payment_gateway');
        });

        DB::table('orders')->whereNotNull('payment_ref')->update(['payment_gateway' => 'xendit']);
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['payment_gateway', 'channel_code']);
        });
    }
};
