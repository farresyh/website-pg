<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ADR-056 (grilled 2026-08-30), decisions 3 & 6: one subscription row
     * per reseller (`reseller_id` unique — a tier change or renewal
     * updates the same row, mirroring `memberships.email`'s one-row-per
     * -identity shape). Kept as its own table rather than columns on
     * `resellers`, same "distinct concern gets its own table" convention
     * `memberships` follows against `Reseller`.
     *
     * State machine (ADR-056 decision 6):
     *  - `active`  — fee paid, `next_charge_at` in the future.
     *  - `grace`   — a charge failed for insufficient earnings; `grace_until`
     *                is ~3 days out. The reseller STILL gets their tier's
     *                wholesale rate during grace (that is the point of grace).
     *  - `lapsed`  — grace elapsed unpaid. Pricing falls back to
     *                `standard_selling_price`. Reactivation is an admin
     *                action (ADR-058), not an automatic retry.
     *
     * `ChargeResellerTierFeesCommand` (scheduled, inert until a real OS
     * cron exists — same pattern as `ResetMembershipCyclesCommand`) drives
     * the transitions. The fee is debited from the reseller's earnings
     * ledger balance (`owner_type='reseller'`), NOT a prepaid deposit
     * wallet — there is no deposit wallet in this phase (ADR-056 decision 7).
     *
     * `reseller_membership_tier_id` is restrict-on-delete: a tier with a
     * live subscriber must never be silently orphaned (the CRUD in ADR-058
     * blocks deleting a tier that has active subscriptions).
     */
    public function up(): void
    {
        Schema::create('reseller_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reseller_id')->unique()->constrained('resellers')->cascadeOnDelete();
            $table->foreignId('reseller_membership_tier_id')->constrained('reseller_membership_tiers')->restrictOnDelete();
            $table->string('status')->default('active'); // active | grace | lapsed
            $table->timestamp('current_period_started_at');
            $table->timestamp('next_charge_at');
            $table->timestamp('grace_until')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('next_charge_at');
            $table->index('reseller_membership_tier_id', 'reseller_subs_tier_id_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reseller_subscriptions');
    }
};
