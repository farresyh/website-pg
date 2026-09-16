<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-103 decisions 1-2: gives a combo leg its own independently
 * regenerable idempotency key, replacing the on-the-fly-derived/
 * regex-parsed `{order.reference_number}-L{n}` scheme.
 *
 *  - `reference_number` (nullable, UNIQUE): the real idempotency key
 *    for this leg, same property `orders.reference_number` already
 *    has. Backfilled below to exactly the value the old derived
 *    formula would have produced, so no in-flight webhook/poll match
 *    changes for historical data — decision 1.
 *  - `resend_unsafe_with_same_reference` (nullable bool): the leg-level
 *    twin of ADR-102 decision 5's `SupplierResponse::$resendUnsafeWithSameReference`,
 *    a narrower need than leg-level Failed/NeedsReview classification
 *    (already solved by ADR-102 decision 8) — telling apart a
 *    genuinely-futile NeedsReview leg from one still worth retrying —
 *    decision 2.
 *
 * Backfill uses a cross-driver join+cursor loop (not a raw
 * UPDATE...JOIN) so it runs identically on sqlite (fast test suite)
 * and real MySQL. Safe as a single-release deploy per decision 9:
 * verified live that production has zero legs in Pending/NeedsReview
 * today, so there is no in-flight state this could strand mid-write.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_delivery_legs', function (Blueprint $table) {
            $table->string('reference_number')->nullable()->unique()->after('leg_number');
            $table->boolean('resend_unsafe_with_same_reference')->nullable()->after('failure_reason');
        });

        DB::table('order_delivery_legs')
            ->join('orders', 'orders.id', '=', 'order_delivery_legs.order_id')
            ->select('order_delivery_legs.id as leg_id', 'order_delivery_legs.leg_number', 'orders.reference_number as order_reference')
            ->orderBy('order_delivery_legs.id')
            ->cursor()
            ->each(function (object $row) {
                DB::table('order_delivery_legs')->where('id', $row->leg_id)->update([
                    'reference_number' => "{$row->order_reference}-L{$row->leg_number}",
                ]);
            });
    }

    public function down(): void
    {
        Schema::table('order_delivery_legs', function (Blueprint $table) {
            $table->dropColumn(['reference_number', 'resend_unsafe_with_same_reference']);
        });
    }
};
