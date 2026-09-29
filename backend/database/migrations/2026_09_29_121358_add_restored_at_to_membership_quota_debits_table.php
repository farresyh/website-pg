<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M-9, 2026-09-29 audit: `MembershipQuotaService::restore()`'s own
 * idempotency marker — mirrors `voucher_redemptions.status`'s
 * reserved/restored pattern, since this table was previously
 * insert-only (no restore existed yet). Nullable/unindexed: restores are
 * rare (a failed/abandoned checkout), never queried in bulk.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('membership_quota_debits', function (Blueprint $table) {
            $table->timestamp('restored_at')->nullable()->after('amount_sen');
        });
    }

    public function down(): void
    {
        Schema::table('membership_quota_debits', function (Blueprint $table) {
            $table->dropColumn('restored_at');
        });
    }
};
