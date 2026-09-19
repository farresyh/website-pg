<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ADR-110 PR-B addendum — one row per CHIP transaction actually
     * matched during a settlement `.xlsx` ingest. `transaction_id`
     * (CHIP's own purchase UUID) is the unique dedup key: re-uploading
     * the same file, or a file whose date range overlaps an earlier
     * one, is safe by construction because ingest checks this table
     * before counting a row toward a new upload's totals.
     *
     * `matched_type`/`matched_id` identifies which of the three tables a
     * CHIP `Transaction ID` belongs to (`ChipTransactionMatchType`:
     * `Order`, `MembershipCheckoutAttempt`, `WalletTopupAttempt`) — a raw
     * string enum + plain integer id, resolved by an explicit accessor,
     * never Eloquent's `morphTo()`/`morphMap()` (this codebase has never
     * used Eloquent's polymorphic relations anywhere — the same
     * `owner_type`/`owner_id` idiom `LedgerOwnerType` already
     * established, ADR-057/059). Both nullable — a settled-but-unmatched
     * transaction (in CHIP's file, but no local record found) is still
     * recorded for the audit trail, just with no match.
     */
    public function up(): void
    {
        Schema::create('chip_settled_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('transaction_id')->unique();
            $table->string('matched_type')->nullable();
            $table->unsignedBigInteger('matched_id')->nullable();
            $table->unsignedBigInteger('amount_sen');
            $table->unsignedBigInteger('fee_sen');
            $table->unsignedBigInteger('net_amount_sen');
            $table->string('acquirer');
            $table->date('settled_on');
            $table->foreignId('payment_settlement_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chip_settled_transactions');
    }
};
