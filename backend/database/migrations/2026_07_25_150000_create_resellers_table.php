<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * PRD §8: "Table exists from MVP (not Phase 2-only): exactly one
     * row is created for the platform owner's own internal storefront,
     * typically with markup_pct = 0, so the pricing/ledger formulas
     * never special-case 'no reseller yet'." Confirmed with the
     * founder 2026-07-25 (during the CheckoutController planning
     * pass) that this was the intended design all along — the
     * platform owner is not a separate concept bolted alongside
     * Reseller, it IS the first Reseller row. `markup_pct`/
     * `max_markup_pct` and `xendit_subaccount_id` exist now (nullable
     * where Phase 2-only) so the schema never needs reshaping when
     * real third-party resellers are onboarded — only new rows get
     * added, per ADR-003.
     */
    public function up(): void
    {
        Schema::create('resellers', function (Blueprint $table) {
            $table->id();
            $table->string('business_name');
            $table->string('contact_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->decimal('markup_pct', 5, 2)->default(0);
            $table->decimal('max_markup_pct', 5, 2)->nullable();
            $table->json('domains')->nullable();
            $table->string('status')->default('active');
            $table->string('xendit_subaccount_id')->nullable(); // Phase 2 (ADR-001 addendum) — OWNED sub-account id
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resellers');
    }
};
