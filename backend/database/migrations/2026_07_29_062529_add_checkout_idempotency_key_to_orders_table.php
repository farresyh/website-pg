<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Client-supplied per-checkout-attempt key (ADR-019's
            // remaining idempotency gap — see CheckoutService::initiate()'s
            // own doc comment). Unique so a race between two near-
            // simultaneous requests carrying the same key fails one of
            // them at the DB layer, not just at an app-level SELECT.
            $table->string('checkout_idempotency_key')->nullable()->unique()->after('order_number');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique(['checkout_idempotency_key']);
            $table->dropColumn('checkout_idempotency_key');
        });
    }
};
