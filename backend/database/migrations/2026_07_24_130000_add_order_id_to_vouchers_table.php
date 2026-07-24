<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * VCH-3 Path B (§7.5): a voucher created from a failed order links
     * back to it — nullable since standalone (Path A) vouchers have no
     * order. No FK constraint, matching this project's convention of
     * plain reference columns until the referenced table is fully wired
     * (see Order's own game_id/package_id/supplier_id/reseller_id).
     */
    public function up(): void
    {
        Schema::table('vouchers', function (Blueprint $table) {
            $table->unsignedBigInteger('order_id')->nullable()->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('vouchers', function (Blueprint $table) {
            $table->dropColumn('order_id');
        });
    }
};
