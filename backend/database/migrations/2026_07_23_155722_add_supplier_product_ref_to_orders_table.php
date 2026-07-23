<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Same snapshotting principle as cost_price/reseller_cost_price
     * (ORD-9): which supplier product to request is Package/Supplier-
     * derived, so it's frozen onto the Order at creation time rather
     * than live-joined — a remapped Package shouldn't change what a
     * historical order actually requested from the supplier.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('supplier_product_ref')->nullable()->after('supplier_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('supplier_product_ref');
        });
    }
};
