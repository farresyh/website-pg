<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ADR-084 PR-3 decision 4: one row per (order, event) webhook — the
     * dead-letter view surfaced in the portal + admin. `DeliverResellerWebhook`
     * updates the same row across its ~5 retry attempts (`attempts`,
     * `last_response_code`, `status`, `next_retry_at`); the
     * `(order_id, event)` unique index is the structural guarantee a
     * reseller never receives a duplicate `order.delivered` / `order.failed`
     * / `order.refunded` for the same order.
     *
     * Separate table, not columns on `orders` — mirrors
     * `reseller_bot_order_notifications` / `reseller_bot_command_logs`
     * keeping channel concerns off the money-critical `Order` model.
     */
    public function up(): void
    {
        Schema::create('reseller_webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reseller_id')->constrained('resellers')->cascadeOnDelete();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->string('event');
            $table->uuid('event_id');
            $table->json('payload');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('status')->default('pending'); // pending | delivered | failed | exhausted
            $table->unsignedSmallInteger('last_response_code')->nullable();
            $table->timestamp('next_retry_at')->nullable();
            $table->timestamps();

            // The portal/admin dead-letter list — newest first, per account.
            $table->index(['reseller_id', 'created_at']);
            // One delivery per order per event, ever (see the class doc comment).
            $table->unique(['order_id', 'event']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reseller_webhook_deliveries');
    }
};
