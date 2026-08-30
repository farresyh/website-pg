<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-061 decision 5 (PR-B): a verification code issued from brand A's
 * storefront must not verify at brand B. `membership_otp_codes` gains a
 * `reseller_id` — `OtpService::verify()` filters on it alongside the
 * email. No unique constraint here (the table holds many rows per email
 * by design); a `(reseller_id, email)` index backs the verify lookup.
 *
 * Backfill: every existing row is the primary brand's. Guarded the same
 * way as the memberships migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('membership_otp_codes', function (Blueprint $table) {
            $table->foreignId('reseller_id')->nullable()->after('id');
        });

        $primaryId = DB::table('resellers')->where('is_primary', true)->value('id');

        if ($primaryId !== null) {
            DB::table('membership_otp_codes')->whereNull('reseller_id')->update(['reseller_id' => $primaryId]);
        }

        Schema::table('membership_otp_codes', function (Blueprint $table) {
            $table->unsignedBigInteger('reseller_id')->nullable(false)->change();
            $table->foreign('reseller_id')->references('id')->on('resellers')->cascadeOnDelete();
            $table->index(['reseller_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::table('membership_otp_codes', function (Blueprint $table) {
            $table->dropForeign(['reseller_id']);
            $table->dropIndex(['reseller_id', 'email']);
            $table->dropColumn('reseller_id');
        });
    }
};
