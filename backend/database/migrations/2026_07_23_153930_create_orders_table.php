<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A customer's top-up purchase transaction (PRD §8). All monetary
     * fields are snapshotted at order-creation time from Package/
     * Reseller/Settings config (ORD-9) — never live-joined, so
     * historical orders stay accurate if prices change later.
     *
     * game_id/package_id/supplier_id/reseller_id are plain FK-id
     * columns without a formal foreign() constraint: the Game,
     * Package, Supplier, and Reseller tables don't exist yet. Add the
     * constraints in a follow-up migration once those tables land —
     * do not treat this as fully referentially-integrity-checked yet.
     */
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_number')->unique();
            $table->string('reference_number')->nullable()->unique(); // ORD-8 idempotency key, assigned once delivery starts
            $table->string('customer_email');
            $table->string('customer_phone')->nullable();
            $table->string('player_id');
            $table->string('server_id')->nullable();

            $table->unsignedBigInteger('game_id')->nullable();
            $table->unsignedBigInteger('package_id')->nullable();
            $table->unsignedBigInteger('supplier_id')->nullable();
            $table->unsignedBigInteger('reseller_id')->nullable();

            // All money fields: integer sen (CheckoutTotal/PricingBreakdown/LedgerEntry convention)
            $table->unsignedInteger('cost_price');
            $table->unsignedInteger('reseller_cost_price');
            $table->decimal('reseller_markup_pct', 5, 2)->default(0);
            $table->unsignedInteger('selling_price');
            $table->unsignedInteger('voucher_discount')->nullable();
            $table->unsignedInteger('transaction_fee');
            $table->unsignedInteger('final_amount');
            $table->integer('platform_profit'); // signed: a loss-making adjustment is representable
            $table->integer('reseller_profit');

            // Two independent state machines (ORD-11) — never conflate into one status field
            $table->string('payment_status')->default('pending');
            $table->string('delivery_status')->default('not_started');

            $table->string('payment_method')->nullable();
            $table->string('payment_ref')->nullable(); // gateway's own reference (e.g. Xendit payment_request_id)
            $table->string('supplier_ref')->nullable(); // supplier's own order id (e.g. Gamevion invoice_number)
            $table->json('supplier_response')->nullable(); // PII-redacted (MID-10)

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
