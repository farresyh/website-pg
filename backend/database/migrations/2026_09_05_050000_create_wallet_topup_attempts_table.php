<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-073 decision 3(a) / PR-G: the self-serve CHIP wallet top-up flow —
 * `pending -> paid/failed/expired`, mirrors `membership_checkout_attempts`
 * (ADR-068) exactly. `reference` is the value handed to
 * `PaymentRequest::referenceId` (matched back on the CHIP webhook, same
 * role `subscription_number` plays for a membership attempt) — a
 * `wallet_topup_attempts`-owned identifier, never an `orders.order_number`.
 *
 * PR-G planning addendum decision 8: exactly one `pending` row per
 * `Reseller` at a time, enforced transactionally (locking this
 * reseller's own `ledger_accounts` mutex row — the same discipline
 * `ResellerOrderPlacementService`'s idempotency check already uses — not
 * a separate pre-check subject to a race). `expires_at` is stored at
 * creation (`created_at + 30 minutes`) rather than computed on read, so
 * both the "is one already pending" check and
 * `ReconcilePendingWalletTopupsCommand`'s own sweep can query it
 * directly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_topup_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reseller_id')->constrained()->cascadeOnDelete();
            $table->string('reference')->unique();
            // The amount actually credited to the wallet on a paid
            // outcome — never the CHIP charge amount. ADR-073 decision
            // 3(a): "CHIP's transaction fee added on top and borne by
            // the reseller" (same customer-borne-fee pattern as
            // checkout today) — total_charged_sen is that fee-inclusive
            // amount actually charged; amount_sen is what the wallet
            // balance goes up by, mirroring
            // membership_checkout_attempts' fee_sen/total_charged_sen
            // split.
            $table->unsignedInteger('amount_sen');
            $table->unsignedInteger('total_charged_sen');
            $table->string('channel_code');
            $table->string('status')->default('pending');
            $table->string('chip_payment_ref')->nullable();
            // Populated once the CHIP `createPayment()` call succeeds —
            // the portal redirects the reseller here to actually pay.
            // Mirrors `membership_checkout_attempts.checkout_url`.
            $table->string('checkout_url')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();

            // The "one pending attempt per reseller" check queries
            // exactly this shape.
            $table->index(['reseller_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_topup_attempts');
    }
};
