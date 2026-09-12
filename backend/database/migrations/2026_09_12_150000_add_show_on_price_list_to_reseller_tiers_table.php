<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-091: the public Reseller Price List page shows up to 3
 * `reseller_tiers` rows, admin-selected — never auto-picked by lowest
 * markup, since a special/negotiated 0% tier (e.g. a hand-picked "SSS"
 * deal) must stay excludable regardless of how cheap it is. Display
 * order reuses the existing `sort_order` column; this migration only
 * adds the inclusion flag. Max-3 is enforced in
 * Store/UpdateResellerTierRequest, not at the DB.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reseller_tiers', function (Blueprint $table) {
            $table->boolean('show_on_price_list')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('reseller_tiers', function (Blueprint $table) {
            $table->dropColumn('show_on_price_list');
        });
    }
};
