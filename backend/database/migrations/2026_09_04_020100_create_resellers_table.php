<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ADR-072/073: the prepaid-wallet reseller entity shared by the
     * Reseller API (ADR-074) and Reseller Bot (ADR-075) channels — the
     * wholesale-buyer relationship, distinct from `Affiliate` (whitelabel
     * storefront partner). No `balance` column: wallet balance is always
     * derived from `LedgerEntry` (ADR-002), owner_type `reseller_wallet`.
     *
     * `reseller_tier_id` is a direct FK, not a subscription state machine
     * (ADR-073 decision 1 — no billing cycle to lapse from). `is_active`
     * (ADR-072 decision 9) is the account-level kill switch checked at the
     * top of the order-placement contract (ADR-073 decision 4); it does
     * not freeze the remaining wallet balance.
     */
    public function up(): void
    {
        Schema::create('resellers', function (Blueprint $table) {
            $table->id();
            $table->string('business_name');
            $table->string('contact_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->foreignId('reseller_tier_id')->nullable()->constrained('reseller_tiers')->restrictOnDelete();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resellers');
    }
};
