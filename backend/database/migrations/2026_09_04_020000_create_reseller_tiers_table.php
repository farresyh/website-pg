<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ADR-073 decision 1: the fee-less prepaid-wallet tier ladder a
     * `Reseller` (wallet) account is assigned to. Unlike
     * `affiliate_membership_tiers`, this has no `monthly_fee_sen` and no
     * lapse/grace state machine — assignment is a direct FK swap on
     * `resellers.reseller_tier_id`, effective immediately, no billing
     * cycle. `markup_percent` is applied over supplier `cost_price`, same
     * math as ADR-056 decision 2. Admin-CRUD, starts empty.
     */
    public function up(): void
    {
        Schema::create('reseller_tiers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->decimal('markup_percent', 5, 2);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reseller_tiers');
    }
};
