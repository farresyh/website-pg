<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * SET-7/SET-11 — replaces the config/checkout.php env-file stopgap
     * with a real, admin-curated table. Unlike Gamevion (which has a
     * real listProducts() catalog to sync), Xendit has no API to
     * report which channels are actually enabled for a given merchant
     * account or what their contracted fee rate is (confirmed against
     * docs.xendit.co, 2026-07-25) — so this is seeded from Xendit's
     * published static channel-code reference (a real registry of
     * valid values, so admin isn't guessing strings), all
     * `is_active = false` by default. Admin must confirm each channel
     * actually works (via their own Xendit Dashboard, or the "Test
     * This Channel" action) before flipping it on.
     *
     * `gateway` (added 2026-07-25, same session): widens the
     * PaymentGateway seam (ADR-001 addendum) from "one gateway
     * hardcoded" to "one gateway per channel row", ahead of the
     * founder's stated intent to shop/split payment gateways once
     * Xendit's fee structure is fully understood. Only 'xendit' is
     * ever seeded/supported today — PaymentGatewayFactory throws for
     * anything else — this is schema readiness only, matching
     * ADR-003's "cheap architecture now, expensive feature later"
     * precedent, not a claim that a second gateway is built.
     */
    public function up(): void
    {
        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->string('channel_code')->unique(); // Xendit's exact value, e.g. AMBANK_FPX, GRABPAY
            $table->string('label'); // customer/admin-facing display name
            $table->string('category'); // UI grouping only (fpx/ewallet/card/...) — fee lookup is per channel_code, not category
            $table->string('gateway')->default('xendit'); // which PaymentGateway adapter owns this channel — see PaymentGatewayFactory
            $table->boolean('is_active')->default(false);
            $table->decimal('percentage_rate', 5, 2)->default(0);
            $table->unsignedInteger('flat_fee_sen')->default(0);
            $table->boolean('requires_issuer')->default(false); // e.g. DuitNow Pay needs channel_properties.issuer_code
            $table->timestamp('last_tested_at')->nullable();
            $table->string('last_test_result')->nullable(); // "success" or "failed: <Xendit error message>"
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_methods');
    }
};
