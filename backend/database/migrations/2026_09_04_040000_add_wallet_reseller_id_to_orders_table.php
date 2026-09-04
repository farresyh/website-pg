<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ADR-073 decision 5: `orders.reseller_id` (renamed conceptually by
     * ADR-072 to mean "which Affiliate/brand this order's storefront
     * belongs to") stays as-is and defaults to the primary Affiliate for
     * a wallet order — there is no branding/whitelabel involved, the
     * reseller is buying directly from the platform's own catalog. This
     * new nullable FK identifies which `Reseller` (wallet) account
     * placed it, for their own order-history and for reporting — two
     * columns for two different meanings, not one FK overloaded both
     * ways. `restrictOnDelete()` mirrors `affiliate_id`'s own treatment
     * (order history must outlive a "deleted" tenant, and a Reseller
     * with order history can't be hard-deleted out from under it).
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('wallet_reseller_id')->nullable()->after('affiliate_id')
                ->constrained('resellers')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('wallet_reseller_id');
        });
    }
};
