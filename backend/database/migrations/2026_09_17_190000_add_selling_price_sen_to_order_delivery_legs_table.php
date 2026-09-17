<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-107 decision 1 (build-time revision — see the ADR's own build
 * addendum): freezes each leg's own component-package selling price at
 * `seedDeliveryLegs()` time. Feeds decision 4's partial-combo voucher
 * apportionment (proportional weights across Failed legs), which is
 * the ONLY consumer of this snapshot — decision 2's platformProfit
 * reconciliation was revised, before this migration was written, to
 * read `componentPackage->cost_price` LIVE at final resolution instead
 * of a frozen cost snapshot (matching ADR-105 decision 1's own
 * established precedent for the non-combo case, and avoiding a
 * currency-conversion dependency inside the real-time delivery path —
 * `component_package_id`'s `cost_price` is already MYR-sen, ADR-033/067,
 * unlike the raw supplier-currency figure `SupplierFundingService::
 * recordOrderDrawdown()` records). So no `cost_price_sen` column here,
 * only `selling_price_sen`.
 *
 * Backfill (cross-driver join+cursor, same shape as ADR-103's
 * `reference_number` backfill) sets every existing leg's snapshot to
 * its component's CURRENT `standard_selling_price` — a one-off,
 * approximate figure for any pre-existing dev/test row. No live
 * financial consequence: confirmed via prod query (ADR-107's own
 * Context) that the one real combo order ever placed delivered both
 * legs clean on the first attempt, so `suggestedPartialVoucherAmount()`
 * (the only reader of this column) has never actually been invoked
 * against it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_delivery_legs', function (Blueprint $table) {
            $table->unsignedInteger('selling_price_sen')->nullable()->after('component_package_id');
        });

        DB::table('order_delivery_legs')
            ->join('packages', 'packages.id', '=', 'order_delivery_legs.component_package_id')
            ->select('order_delivery_legs.id as leg_id', 'packages.standard_selling_price as selling_price')
            ->orderBy('order_delivery_legs.id')
            ->cursor()
            ->each(function (object $row) {
                DB::table('order_delivery_legs')->where('id', $row->leg_id)->update([
                    'selling_price_sen' => $row->selling_price,
                ]);
            });
    }

    public function down(): void
    {
        Schema::table('order_delivery_legs', function (Blueprint $table) {
            $table->dropColumn('selling_price_sen');
        });
    }
};
