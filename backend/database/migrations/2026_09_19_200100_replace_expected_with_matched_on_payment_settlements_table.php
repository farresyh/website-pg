<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ADR-110 PR-B addendum (automatic reconciliation, 2026-09-19) —
     * replaces `expected_*` (summed every CHIP-paid record in the
     * file's own calendar date range, regardless of whether it was
     * actually in the file — silently wrong whenever anything near the
     * window's tail end hadn't settled yet, found live on a real
     * production upload) with `matched_*`: summed ONLY over the
     * transactions this specific settlement actually matched (via
     * `chip_settled_transactions`, using its own `local_gross_sen`/
     * `local_fee_sen` snapshot). `status` (`pending`/`matched`/
     * `variance`) is now fully computed — never admin-typed — from a
     * per-transaction gross comparison (`chip_settled_transactions.amount_sen`
     * vs `local_gross_sen`), not a founder-entered bank figure.
     *
     * Backfills the handful of settlement rows that existed before this
     * migration — see the previous migration's own doc comment for why
     * this is safe to automate.
     */
    public function up(): void
    {
        Schema::table('payment_settlements', function (Blueprint $table) {
            $table->unsignedBigInteger('matched_gross_sen')->default(0)->after('date_to');
            $table->unsignedBigInteger('matched_fee_sen')->default(0)->after('matched_gross_sen');
            $table->unsignedBigInteger('matched_net_sen')->default(0)->after('matched_fee_sen');
        });

        $this->backfillMatchedAmountsAndStatus();

        Schema::table('payment_settlements', function (Blueprint $table) {
            $table->dropColumn(['expected_gross_sen', 'expected_fee_sen', 'expected_net_sen']);
        });
    }

    public function down(): void
    {
        Schema::table('payment_settlements', function (Blueprint $table) {
            $table->unsignedBigInteger('expected_gross_sen')->default(0)->after('date_to');
            $table->unsignedBigInteger('expected_fee_sen')->default(0)->after('expected_gross_sen');
            $table->unsignedBigInteger('expected_net_sen')->default(0)->after('expected_fee_sen');
            $table->dropColumn(['matched_gross_sen', 'matched_fee_sen', 'matched_net_sen']);
        });
    }

    private function backfillMatchedAmountsAndStatus(): void
    {
        $settlements = DB::table('payment_settlements')->select('id')->get();

        foreach ($settlements as $settlement) {
            $totals = DB::table('chip_settled_transactions')
                ->where('payment_settlement_id', $settlement->id)
                ->whereNotNull('matched_id')
                ->selectRaw('COALESCE(SUM(local_gross_sen), 0) as gross, COALESCE(SUM(local_fee_sen), 0) as fee')
                ->first();

            $recordedCount = DB::table('chip_settled_transactions')
                ->where('payment_settlement_id', $settlement->id)
                ->count();

            $hasMismatch = DB::table('chip_settled_transactions')
                ->where('payment_settlement_id', $settlement->id)
                ->whereNotNull('matched_id')
                ->whereColumn('amount_sen', '!=', 'local_gross_sen')
                ->exists();

            $hasUnmatched = DB::table('chip_settled_transactions')
                ->where('payment_settlement_id', $settlement->id)
                ->whereNull('matched_id')
                ->exists();

            // An unmatched transaction is just as much a reconciliation
            // failure as a gross mismatch — see
            // SettlementReconciliationService::ingest()'s own comment.
            $status = match (true) {
                $recordedCount === 0 => 'pending',
                $hasMismatch || $hasUnmatched => 'variance',
                default => 'matched',
            };

            DB::table('payment_settlements')->where('id', $settlement->id)->update([
                'matched_gross_sen' => $totals->gross,
                'matched_fee_sen' => $totals->fee,
                'matched_net_sen' => $totals->gross - $totals->fee,
                'status' => $status,
            ]);
        }
    }
};
