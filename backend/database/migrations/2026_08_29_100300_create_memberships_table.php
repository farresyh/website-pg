<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ADR-027 decision 3 + its 2026-08-29 addendum decision 27: identity
     * is email-keyed (the OTP delivery channel moved from WhatsApp/phone
     * to Plunk email), one row per email — renewing updates the same
     * row rather than inserting a new one. `cycle_started_at` anchors
     * decision 7's rolling 30-day quota reset to this member's own
     * subscription date, not a shared calendar cycle. `expires_at` is
     * the fee-paid-through date (decision 11's manual/hybrid billing);
     * a lapsed member's status flips to `expired` rather than the row
     * being deleted, so order-history linkage (decision 3) survives.
     * `membership_plan_id` is restrict-on-delete: membership_plans is a
     * fixed 2-row table with no delete path (decision 15), and a real
     * subscriber must never be silently orphaned if that ever changes.
     */
    public function up(): void
    {
        Schema::create('memberships', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->foreignId('membership_plan_id')->constrained('membership_plans')->restrictOnDelete();
            $table->string('status')->default('active');
            $table->timestamp('cycle_started_at');
            $table->unsignedInteger('quota_remaining_sen');
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('memberships');
    }
};
