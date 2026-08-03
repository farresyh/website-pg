<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ADR-022's newest addendum, decision 4: identifies the real-world
     * payment method (e.g. "TnG eWallet") independent of `gateway` —
     * distinct from `channel_code` (gateway-specific, already unique)
     * and from `category` (too coarse: a category legitimately holds
     * several different active methods at once, e.g. TnG and GrabPay
     * both under `ewallet`). PaymentMethodController::updateStatus()
     * blocks activating a row when another row sharing the same
     * `method_key` is already active, so a customer never sees two
     * visually-identical buttons for the same method routed through
     * two different processors.
     *
     * Backfilled from the lowercased channel_code for every existing
     * row — channel_code is already unique, so this produces a unique,
     * non-conflicting method_key for every row that exists today (no
     * two rows represent the same real-world method yet, since only
     * Xendit has ever been seeded). Left nullable, matching this
     * codebase's other denormalized snapshot columns (e.g. orders.
     * payment_gateway) — a null method_key simply never participates
     * in the exclusivity check, rather than needing a NOT NULL
     * migration against existing data.
     */
    public function up(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->string('method_key')->nullable()->after('channel_code');
        });

        DB::table('payment_methods')->orderBy('id')->each(function ($row) {
            DB::table('payment_methods')->where('id', $row->id)->update([
                'method_key' => strtolower($row->channel_code),
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->dropColumn('method_key');
        });
    }
};
