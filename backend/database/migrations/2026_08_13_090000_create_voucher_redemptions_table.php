<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ADR-024 decision #2. One row per redemption attempt of a Voucher
     * against an Order — the audit trail the wallet usage model needs
     * (a Voucher's `remaining` alone can't say when or where it was
     * spent). Never a second path into `ledger_entries`: a Voucher's
     * full liability is already booked once, at issuance
     * (VoucherController::issue()'s `voucher_issued` debit) — this
     * table only tracks which part of that already-booked liability is
     * currently spent (reserved/committed) vs. given back (restored).
     *
     * status lifecycle: reserved (redeemed at checkout, order not yet
     * resolved) -> committed (order delivered, permanent) OR restored
     * (payment never completed, or delivery abandoned via admin's
     * "Issue Voucher" — ADR-024 decision #6). A restored row is never
     * deleted or reused — a fresh redemption gets its own new row.
     */
    public function up(): void
    {
        Schema::create('voucher_redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('voucher_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('amount'); // sen, matches orders.voucher_discount for this order
            $table->string('status')->default('reserved'); // reserved|committed|restored
            $table->timestamps();

            // ADR-024 decision #1/#6: at most one redemption row per
            // order — a single order can only ever apply one voucher,
            // one time (the amount is fixed at checkout, never topped
            // up later), so order_id is unique, not just indexed.
            $table->unique('order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voucher_redemptions');
    }
};
