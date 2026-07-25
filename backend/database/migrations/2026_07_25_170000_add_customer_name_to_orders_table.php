<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Discovered 2026-07-25 (Payment Methods "Test" tool, run live
     * against the real Xendit sandbox): Xendit's Payment Request API
     * requires a `customer` object with `individual_detail.given_names`
     * for at least the FPX channel, which this platform never
     * collected — guest checkout only ever asked for email/phone/
     * player_id. Nullable so existing rows (all test data, none in
     * production yet) don't break; new checkouts require it via
     * CreateCheckoutRequest.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('customer_name')->nullable()->after('customer_email');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('customer_name');
        });
    }
};
