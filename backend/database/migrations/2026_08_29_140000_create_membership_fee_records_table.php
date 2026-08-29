<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-027 continued addendum decision 15 / Phase 6.5 (member registry +
 * fee collection, grilled 2026-08-29): an insert-only audit row per
 * membership-fee payment received. The unique index on `idempotency_key`
 * is the real serialization point against a double-submit of the admin's
 * "Record Payment" action (mirrors `vouchers.idempotency_key`, ADR-035)
 * — the existence pre-check in MembershipFeeService is only the fast
 * path, this index prevents double-booking one fee. Never updated or
 * deleted; the actual money movement lives in `ledger_entries`
 * (type=membership_fee, reference to the membership row).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('membership_fee_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('membership_id')->constrained('memberships')->cascadeOnDelete();
            $table->foreignId('membership_plan_id')->constrained('membership_plans')->restrictOnDelete();
            $table->unsignedInteger('amount_sen');
            $table->foreignId('admin_user_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->string('idempotency_key')->nullable()->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('membership_fee_records');
    }
};
