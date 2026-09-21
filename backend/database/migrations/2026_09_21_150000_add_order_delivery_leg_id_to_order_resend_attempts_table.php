<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-106 addendum (2026-09-21) — closes decision 1's own "non-combo
 * only for now" deferral: reuses this table for a combo leg's own
 * attempt history too, rather than a parallel `order_delivery_leg_attempts`
 * table (ADR-106 decision 2's own "one table, discriminator column"
 * precedent — see this addendum's own Rationale for why a second table
 * was considered and rejected). `order_id` is still always set (a leg
 * belongs to an order); `order_delivery_leg_id` is set only for a
 * leg-scoped row, null for an order-level one — exactly the same
 * "narrower column, broader table" shape `attempt_type` itself already
 * established. `nullOnDelete()` mirrors `package_id`'s own convention:
 * a leg being removed must never delete its own attempt history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_resend_attempts', function (Blueprint $table) {
            $table->foreignId('order_delivery_leg_id')->nullable()->after('order_id')
                ->constrained('order_delivery_legs')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('order_resend_attempts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('order_delivery_leg_id');
        });
    }
};
