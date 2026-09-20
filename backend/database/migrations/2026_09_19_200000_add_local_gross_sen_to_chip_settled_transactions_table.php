<?php

use App\Services\Accounting\ChipTransactionMatchType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ADR-110 PR-B addendum (automatic reconciliation, 2026-09-19) —
     * `local_gross_sen`/`local_fee_sen`: our own gross and fee
     * assumption for the matched local record, snapshotted at match
     * time. Null for an unmatched row — there is nothing local to
     * compare against. Mirrors CHIP's own `amount_sen`/`fee_sen` shape
     * on the same row, so the gross-mismatch check and the
     * `payment_settlements.matched_fee_sen` aggregate (feeds
     * `MonthlyAccountingSummaryService`'s "payment processing net
     * gain/(loss)" line) never need to re-join back to whichever of
     * the three tables `matched_type` points to.
     *
     * Backfills the handful of already-matched rows that existed before
     * these columns did — safe to automate (not real production
     * money-ledger data; this table is a diagnostic/reconciliation aid
     * derived from other tables, deterministic to recompute from them).
     */
    public function up(): void
    {
        Schema::table('chip_settled_transactions', function (Blueprint $table) {
            $table->unsignedBigInteger('local_gross_sen')->nullable()->after('matched_id');
            $table->unsignedBigInteger('local_fee_sen')->nullable()->after('local_gross_sen');
        });

        $this->backfillLocalAmounts();
    }

    public function down(): void
    {
        Schema::table('chip_settled_transactions', function (Blueprint $table) {
            $table->dropColumn(['local_gross_sen', 'local_fee_sen']);
        });
    }

    private function backfillLocalAmounts(): void
    {
        DB::table('chip_settled_transactions')
            ->where('matched_type', ChipTransactionMatchType::Order->value)
            ->whereNotNull('matched_id')
            ->update([
                'local_gross_sen' => DB::raw(
                    '(SELECT final_amount FROM orders WHERE orders.id = chip_settled_transactions.matched_id)'
                ),
                'local_fee_sen' => DB::raw(
                    '(SELECT transaction_fee FROM orders WHERE orders.id = chip_settled_transactions.matched_id)'
                ),
            ]);

        DB::table('chip_settled_transactions')
            ->where('matched_type', ChipTransactionMatchType::MembershipCheckoutAttempt->value)
            ->whereNotNull('matched_id')
            ->update([
                'local_gross_sen' => DB::raw(
                    '(SELECT total_charged_sen FROM membership_checkout_attempts WHERE membership_checkout_attempts.id = chip_settled_transactions.matched_id)'
                ),
                'local_fee_sen' => DB::raw(
                    '(SELECT total_charged_sen - fee_sen FROM membership_checkout_attempts WHERE membership_checkout_attempts.id = chip_settled_transactions.matched_id)'
                ),
            ]);

        DB::table('chip_settled_transactions')
            ->where('matched_type', ChipTransactionMatchType::WalletTopupAttempt->value)
            ->whereNotNull('matched_id')
            ->update([
                'local_gross_sen' => DB::raw(
                    '(SELECT total_charged_sen FROM wallet_topup_attempts WHERE wallet_topup_attempts.id = chip_settled_transactions.matched_id)'
                ),
                'local_fee_sen' => DB::raw(
                    '(SELECT total_charged_sen - amount_sen FROM wallet_topup_attempts WHERE wallet_topup_attempts.id = chip_settled_transactions.matched_id)'
                ),
            ]);
    }
};
