<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ADR-076 decision 4: one row per Bot-channel order, created by
     * `ResellerBotService::handleOrder()` right after a successful
     * `placeOrder()` — captures the exact `whatsapp_group_id` that
     * placed the order (`reseller_whatsapp_groups.reseller_id` is not
     * unique, so "the reseller's group" is otherwise ambiguous) and
     * tracks `last_notified_delivery_status` so the `OrderStatusUpdated`
     * listener (decision 5) can send message 2 exactly once per new
     * terminal state reached, not once-ever — a later Resend Delivery
     * flipping Failed→Delivered still gets a fresh message.
     *
     * Deliberately a separate table, not new columns on `orders` —
     * mirrors `reseller_bot_command_logs` already keeping Bot-channel
     * concerns off the shared, money-critical `Order` model.
     *
     * `order_id` unique — exactly one notification row per order, ever
     * (an order is placed through exactly one channel).
     */
    public function up(): void
    {
        Schema::create('reseller_bot_order_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained('orders')->cascadeOnDelete();
            $table->string('whatsapp_group_id');
            $table->string('last_notified_delivery_status')->nullable();
            $table->timestamp('refund_notified_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reseller_bot_order_notifications');
    }
};
