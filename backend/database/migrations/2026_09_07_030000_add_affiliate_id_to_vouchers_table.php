<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-060 PR-4 decision 5 (PR-4d): a voucher is a brand-scoped customer
 * instrument — one issued on Brand A's storefront is redeemable only on
 * Brand A, the same rule membership session tokens already follow
 * (`session['affiliate_id'] !== $affiliateId`). `vouchers` gains a
 * NOT NULL `affiliate_id`.
 *
 * Backfill: every existing row is the primary brand's (ADR-013 — the one
 * pre-Phase-2 storefront; production is not yet commercially live so this
 * is near-empty). Guarded: a fresh DB with no `is_primary` row (tests
 * never seed) is left untouched, and the NOT NULL flip then applies to
 * an empty table.
 *
 * `restrictOnDelete` (not cascade / null): a voucher is a financial
 * audit record, same stance as `orders.affiliate_id` (ADR-061 PR-B) —
 * RES-6 force-delete is already guarded on earnings = 0, and a brand
 * that ever issued a voucher should not be force-deletable out from
 * under it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vouchers', function (Blueprint $table) {
            $table->foreignId('affiliate_id')->nullable()->after('order_id');
        });

        $primaryId = DB::table('affiliates')->where('is_primary', true)->value('id');

        if ($primaryId !== null) {
            DB::table('vouchers')->whereNull('affiliate_id')->update(['affiliate_id' => $primaryId]);
        }

        Schema::table('vouchers', function (Blueprint $table) {
            $table->unsignedBigInteger('affiliate_id')->nullable(false)->change();
            $table->foreign('affiliate_id')->references('id')->on('affiliates')->restrictOnDelete();
            // AGENTS.md gotcha: a bare foreign()/constrained() has been
            // found not to reliably leave a standalone index on MySQL.
            $table->index('affiliate_id', 'vouchers_affiliate_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('vouchers', function (Blueprint $table) {
            $table->dropForeign(['affiliate_id']);
            $table->dropIndex('vouchers_affiliate_id_index');
            $table->dropColumn('affiliate_id');
        });
    }
};
