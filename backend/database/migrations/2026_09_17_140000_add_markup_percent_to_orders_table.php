<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-105 decision 3: snapshot the Package `markup_percent` a Member-basis
 * order was priced against, at checkout time — the Member-basis twin of
 * `wholesale_markup_pct` (2026-09-07, same reasoning). Without it,
 * `OrderResendService` has no frozen value to reconcile a resend's
 * `MembershipPricingService::calculateMemberPrice()` call against and
 * falls back to the *target* package's own live `markup_percent`, which
 * can differ from the package this order actually priced against for
 * reasons unrelated to this resend (an admin retuning that package's
 * margin between checkout and resend).
 *
 * Null for every non-Member order — those have no package markup to freeze
 * (Standard reconciles against `standard_selling_price` directly; Affiliate/
 * ResellerWallet already freeze `wholesale_markup_pct`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('markup_percent', 5, 2)->nullable()->after('member_discount_percent');
        });

        // Backfill existing Member-basis rows. The original package
        // markup_percent is recoverable from the checkout-time snapshot
        // already on the row: platform_profit = memberPrice - cost_price,
        // memberPrice = cost_price * (1 + effectiveMarkupPercent/100), so
        // effectiveMarkupPercent = platform_profit / cost_price * 100, and
        // effectiveMarkupPercent = packageMarkupPercent * (1 - discount/100)
        // (MembershipPricingService::effectiveMarkupPercent()), so
        // packageMarkupPercent = effectiveMarkupPercent / (1 - discount/100).
        // Approximate (the original value was already rounded once into
        // sen integers before this reconstruction) — acceptable for a
        // one-off backfill of historical rows, none of which are affected
        // financially by this migration (see ADR-105's own Context: the
        // one Member-basis resend in prod never succeeded/credited).
        // Left null (unrecoverable, not guessed) when discount_percent is
        // 100 — every packageMarkupPercent maps to the same 0% effective
        // rate, so no unique original value exists to reconstruct.
        $rows = DB::table('orders')
            ->select('id', 'platform_profit', 'cost_price', 'member_discount_percent')
            ->where('pricing_basis', 'member')
            ->where('cost_price', '>', 0)
            ->whereNull('markup_percent')
            ->get();

        foreach ($rows as $row) {
            $discount = (float) $row->member_discount_percent;

            if ($discount >= 100.0) {
                continue;
            }

            $effectiveMarkupPercent = $row->platform_profit / $row->cost_price * 100;
            $packageMarkupPercent = round($effectiveMarkupPercent / (1 - $discount / 100), 2);

            DB::table('orders')->where('id', $row->id)->update(['markup_percent' => $packageMarkupPercent]);
        }
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('markup_percent');
        });
    }
};
