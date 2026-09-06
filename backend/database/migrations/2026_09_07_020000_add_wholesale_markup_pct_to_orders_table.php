<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-060 PR-4b: snapshot the wholesale tier markup an order was priced
 * against, for the `affiliate` and `reseller-wallet` bases (ORD-9 —
 * snapshot every price input). Without it `OrderResendService` cannot
 * recompute profit correctly for those bases on a package swap: it would
 * route them through the standard chain, silently wrong for a wallet
 * order (a latent bug, already live).
 *
 * Null for a `standard` / `member` order — those have no tier markup.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('wholesale_markup_pct', 5, 2)->nullable()->after('affiliate_markup_pct');
        });

        // Backfill existing `reseller-wallet` rows (production has real
        // ones from the live Bot channel; `affiliate` has none yet). The
        // tier markup is recoverable exactly from the stored snapshot:
        // platform_profit = wholesaleBase - cost_price, and wholesaleBase
        // = round(cost_price * (1 + pct/100)), so
        // pct = round((platform_profit + cost_price) / cost_price - 1) * 100,
        // and `markup_percent` is decimal(*,2) so a 2dp round recovers the
        // original value. Done in PHP for MySQL/sqlite portability (integer
        // division differs) — the row count is small.
        $rows = DB::table('orders')
            ->select('id', 'platform_profit', 'cost_price')
            ->whereIn('pricing_basis', ['reseller-wallet', 'affiliate'])
            ->where('cost_price', '>', 0)
            ->whereNull('wholesale_markup_pct')
            ->get();

        foreach ($rows as $row) {
            $wholesaleBase = $row->platform_profit + $row->cost_price;
            $pct = round(($wholesaleBase / $row->cost_price - 1) * 100, 2);

            DB::table('orders')->where('id', $row->id)->update(['wholesale_markup_pct' => $pct]);
        }
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('wholesale_markup_pct');
        });
    }
};
