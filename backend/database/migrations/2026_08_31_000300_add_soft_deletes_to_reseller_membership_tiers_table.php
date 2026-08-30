<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ADR-058 58b: tier deletion (its CRUD lands here) is soft-delete.
     * `reseller_subscriptions.reseller_membership_tier_id` is
     * restrict-on-delete, so a hard delete of a tier any reseller was
     * ever on (even now-lapsed) fails at the DB. Soft-delete keeps the
     * row resolvable for historical subscription / `reseller_tier_changes`
     * display while removing it from the admin's assignable list. The
     * controller still blocks the delete outright when an active/grace
     * subscription points at it.
     */
    public function up(): void
    {
        Schema::table('reseller_membership_tiers', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('reseller_membership_tiers', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
