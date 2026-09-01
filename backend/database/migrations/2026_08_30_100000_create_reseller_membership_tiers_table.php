<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ADR-056 (grilled 2026-08-30), decision 1: the paid B2B wholesale-rate
     * ladder a reseller subscribes to monthly. Deliberately NOT seeded and
     * NOT locked at a fixed row count — unlike `membership_plans`, whose
     * exactly-2-row shape exists only for ADR-027 decision 4's consumer
     * anchor/decoy psychology. This table is admin-CRUD (add/edit/remove
     * freely, the CRUD surface lands in ADR-058) and starts empty.
     *
     * `markup_percent` is applied over supplier `cost_price`, never over
     * `standard_selling_price` and never over `package.markup_percent` —
     * ADR-056 decision 2 keeps the two markup systems fully independent.
     * A reseller's effective wholesale base = `cost_price × (1 +
     * markup_percent/100)` for their active/grace subscription, or
     * `standard_selling_price` (the guest price) when no tier is active
     * (ADR-056 decision 3). No `quota_sen` column — reseller volume is
     * unlimited by design (ADR-056 decision 1, contrast ADR-027 decision 7).
     */
    public function up(): void
    {
        Schema::create('reseller_membership_tiers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedInteger('monthly_fee_sen');
            $table->decimal('markup_percent', 5, 2);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reseller_membership_tiers');
    }
};
