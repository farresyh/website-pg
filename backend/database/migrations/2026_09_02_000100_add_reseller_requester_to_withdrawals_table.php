<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-059 59c: a reseller now originates its own withdrawal from the
 * portal, so `requested_by` (an `admin_users` id in the WTH-1..5 flow)
 * no longer always applies. It becomes nullable, and a nullable
 * `reseller_user_id` records who on the reseller side asked.
 *
 * Why not just reuse `requested_by` for a reseller_user id: the admin
 * approve step's maker-checker rule compares `$admin->id ===
 * $withdrawal->requested_by` ("a different Super Admin must approve") —
 * a reseller_user id sharing an integer with the approving admin's id
 * would wrongly block. With `requested_by = null` for reseller-
 * originated rows, that check can never false-positive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('withdrawals', function (Blueprint $table) {
            $table->unsignedBigInteger('requested_by')->nullable()->change();
            $table->foreignId('reseller_user_id')
                ->nullable()
                ->after('requested_by')
                ->constrained('reseller_users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('withdrawals', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reseller_user_id');
            $table->unsignedBigInteger('requested_by')->nullable(false)->change();
        });
    }
};
