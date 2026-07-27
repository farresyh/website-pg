<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ADR-017 decision #4: one row per admin resend attempt — the
     * "Delivery Logs" history `Order.supplier_response` alone can't
     * provide, since that column is overwritten on every attempt.
     * `package_id` is the package actually used *this* attempt (may
     * differ from the order's own `package_id`, decision #1's
     * same-game swap). `price_diff_sen` is this attempt's live
     * `cost_price` minus the order's original snapshotted
     * `cost_price` — decision #3's "always absorb, always record"
     * reconciliation figure, kept even when the outcome is `failed`
     * (the founder's own instruction: not recording a loss is worse
     * than the loss itself). No FK cascade restriction beyond the
     * order itself — deleting an Order takes its resend history with
     * it, same as any other order-scoped child data; a package being
     * deleted must never delete resend history, so `package_id` is
     * nullable-on-delete, mirroring `price_change_logs`/
     * `deactivation_logs`'s own package_id-cascade choice being about
     * *their* owning row (a sync run's log), not this table's.
     */
    public function up(): void
    {
        Schema::create('order_resend_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('package_id')->nullable()->constrained('packages')->nullOnDelete();
            $table->unsignedInteger('cost_price_sen');
            $table->unsignedInteger('reseller_cost_price_sen');
            $table->integer('price_diff_sen');
            $table->string('outcome');
            $table->json('supplier_response')->nullable();
            $table->string('note')->nullable();
            $table->string('triggered_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_resend_attempts');
    }
};
