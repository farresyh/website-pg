<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ADR-094 decision 1 + its 2026-09-15 addendum (decisions 18/19):
     * one row per real outbound supplier call a combo order makes.
     * `leg_number` (added by the addendum, absent from the original
     * decision-1 column list) is `n` in the per-leg idempotency key
     * `{order.reference_number}-L{n}` (decision 7) and the anchor
     * `SupplierFundingService::recordOrderDrawdown()`'s leg-aware dedup
     * (decision 18) keys on via this row's own `id` — without it, two
     * legs generated from the same repeated component
     * (`package_components.quantity` > 1) would be indistinguishable
     * (identical order_id + component_package_id).
     *
     * `status` reuses `App\Services\Order\DeliveryStatus` — the same
     * channel-agnostic enum `orders.delivery_status` already casts to,
     * not a new state machine.
     *
     * `order_id` cascades (a leg has no independent existence without
     * its order); `component_package_id`/`supplier_id` restrict, same
     * referential posture as `package_components`/`packages.supplier_id`.
     */
    public function up(): void
    {
        Schema::create('order_delivery_legs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('component_package_id')->constrained('packages')->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->unsignedTinyInteger('leg_number');
            $table->string('status')->default('not_started');
            $table->string('supplier_reference')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamps();

            $table->unique(['order_id', 'leg_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_delivery_legs');
    }
};
