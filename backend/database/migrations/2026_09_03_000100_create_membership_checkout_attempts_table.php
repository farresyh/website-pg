<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-068 decision 2 (+ stress-test S1/S2/S11) — the self-serve
 * membership subscription's pending-payment state, the `orders`
 * analogue for a membership fee paid through the CHIP checkout.
 *
 * Three identifiers, mirroring the Order idempotency pattern:
 *  - `idempotency_key`   client-generated UUID; dedups an HTTP retry of
 *                        POST /api/membership/subscribe.
 *  - `subscription_number` server-generated `MS-XXXXXXXXXX`; the CHIP
 *                        `reference`, the human-readable ref, and the
 *                        `MembershipFeeService::recordFeePaid()`
 *                        idempotency key. The webhook matches on this
 *                        (echoed back as the event `referenceId`) — it
 *                        is known before CHIP is ever called, so a lost
 *                        `createPayment()` response can't strand a
 *                        charged customer without a membership.
 *  - `payment_ref`       the CHIP purchase `id`; null until
 *                        `createPayment()` returns.
 *
 * Two amounts (S1): `fee_sen` (the plan fee — booked to the ledger via
 * recordFeePaid) and `total_charged_sen` (`fee_sen` + the payment
 * channel fee — what CHIP collects, and what the webhook cross-checks
 * against, mirroring `orders.final_amount`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('membership_checkout_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reseller_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->foreignId('membership_plan_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('fee_sen');
            $table->unsignedInteger('total_charged_sen');
            $table->string('channel_code');
            $table->string('subscription_number')->unique();
            $table->string('idempotency_key', 64)->unique();
            $table->string('payment_ref')->nullable()->unique();
            $table->string('checkout_url')->nullable();
            $table->string('status')->default('pending');
            $table->timestamps();

            $table->index(['reseller_id', 'email']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('membership_checkout_attempts');
    }
};
