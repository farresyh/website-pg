<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-106 decision 2: reuses the existing `order_resend_attempts`
 * table (no rename, no new table) for every fulfillment attempt, not
 * just admin-triggered resends. `attempt_type` (`initial`/`resend`/
 * `manual_confirm`) discriminates which of the three write sites
 * produced a given row.
 *
 * `default('resend')` backfills every existing row for free — every
 * row that exists today genuinely IS a resend (this table's only
 * writer until this ADR was `OrderResendService::resend()`), so a
 * plain column default does the backfill in one statement rather than
 * a cross-driver cursor loop (contrast ADR-103's `reference_number`
 * backfill, which needed a per-row DIFFERENT computed value — not the
 * case here, every row gets the identical value).
 *
 * `price_diff_sen` relaxed to nullable — decision 4: an `initial`/
 * `manual_confirm` row stores `null` there ("no comparison was ever
 * made"), genuinely different from a resend's real `0` diff ("a
 * comparison was made and found no difference"). Was NOT NULL since
 * the original migration; every existing row already has a real
 * value, so this is a widening change with no data to migrate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_resend_attempts', function (Blueprint $table) {
            $table->string('attempt_type')->default('resend')->after('order_id');
            $table->integer('price_diff_sen')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('order_resend_attempts', function (Blueprint $table) {
            $table->dropColumn('attempt_type');
            $table->integer('price_diff_sen')->nullable(false)->change();
        });
    }
};
