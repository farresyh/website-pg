<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-084 PR-1 decision 6: an optional per-key IP allowlist (empty / null
 * = any IP, so a serverless or shared-infra reseller still works) plus
 * `last_used_ip` surfaced in the portal so a reseller can spot anomalous
 * use. The bearer key stays the only credential — this is hardening
 * around it, not a second factor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reseller_api_keys', function (Blueprint $table) {
            $table->json('allowed_ips')->nullable()->after('key_hash');
            $table->string('last_used_ip', 45)->nullable()->after('last_used_at');
        });
    }

    public function down(): void
    {
        Schema::table('reseller_api_keys', function (Blueprint $table) {
            $table->dropColumn(['allowed_ips', 'last_used_ip']);
        });
    }
};
