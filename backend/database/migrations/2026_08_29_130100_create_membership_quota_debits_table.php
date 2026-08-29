<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-027 Phase 6. Mirrors `voucher_redemptions`' exact purpose: the
 * unique index on `order_id` is the real serialization point against a
 * genuine concurrent double-call to `MembershipQuotaService::decrement()`
 * for the same order (`CheckoutService::requestPayment()` is reachable
 * from both `initiate()` and `resume()`, same race class
 * `VoucherService::redeem()`'s own doc comment already documents for
 * vouchers) — an existence pre-check is only the fast path, this index
 * is what actually prevents double-decrementing one order's quota.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('membership_quota_debits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained('orders')->cascadeOnDelete();
            $table->foreignId('membership_id')->constrained('memberships')->cascadeOnDelete();
            $table->unsignedInteger('amount_sen');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('membership_quota_debits');
    }
};
