<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-004 2026-11-12 addendum, decisions 2, 5 and 8.
 *
 * `vouchers.source`: `customer_cash` (the customer's own money, from a
 * failed non-sandbox order or a merge of only such vouchers) or `gift`.
 * `issue()` and `merge()` always write it; the default is the safe side for
 * a raw insert: customer cash can be neither revoked nor booked as income.
 *
 * `voucher_cash_refunds`: one bank-transfer payout per voucher.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vouchers', function (Blueprint $table) {
            $table->string('source', 20)->default('customer_cash')->after('order_id');
            $table->string('revoked_reason', 500)->nullable()->after('revoked_at');
            // Plain, like vouchers.created_by/approved_by: a constraint makes SQLite rebuild `vouchers`,
            // and the rebuild's drop cascades into voucher_merges / voucher_redemptions.
            $table->unsignedBigInteger('revoked_by')->nullable()->after('revoked_reason');
        });

        // Backfill in id order: a merge target is always newer than its sources.
        $sources = [];
        foreach (DB::table('vouchers')->orderBy('id')->get(['id', 'order_id']) as $voucher) {
            $mergedFrom = DB::table('voucher_merges')->where('target_voucher_id', $voucher->id)->pluck('source_voucher_id');

            $source = $mergedFrom->isNotEmpty()
                ? ($mergedFrom->every(fn ($id) => ($sources[$id] ?? 'gift') === 'customer_cash') ? 'customer_cash' : 'gift')
                : ($voucher->order_id !== null && DB::table('orders')->where('id', $voucher->order_id)->where('is_test', false)->exists() ? 'customer_cash' : 'gift');

            $sources[$voucher->id] = $source;
            DB::table('vouchers')->where('id', $voucher->id)->update(['source' => $source]);
        }

        Schema::create('voucher_cash_refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('voucher_id')->unique()->constrained();
            $table->unsignedInteger('amount_sen');
            $table->date('transferred_on');
            $table->string('bank_name', 100);
            $table->string('bank_account_no', 50);
            $table->string('bank_account_holder', 150);
            $table->string('bank_reference', 100);
            $table->string('proof_path');
            $table->string('reason', 500);
            $table->foreignId('created_by')->index()->constrained('admin_users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voucher_cash_refunds');

        Schema::table('vouchers', function (Blueprint $table) {
            $table->dropColumn(['source', 'revoked_reason', 'revoked_by']);
        });
    }
};
