<?php

use App\Models\AdminUser;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-121: admin MFA. `mfa_secret` / `mfa_enabled` already exist (AUTH-7
 * placeholder columns); `mfa_enabled` now means "MFA is active".
 *
 * Also deletes every admin bearer token: the admin login moves to a cookie
 * session, and AppServiceProvider refuses admin tokens from here on.
 * Affiliate tokens are untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admin_users', function (Blueprint $table) {
            $table->boolean('mfa_required')->default(false)->after('mfa_enabled');
            $table->text('mfa_recovery_codes')->nullable()->after('mfa_required'); // sha256 hashes, JSON
            $table->unsignedBigInteger('mfa_last_timestep')->nullable()->after('mfa_recovery_codes'); // replay guard
        });

        DB::table('personal_access_tokens')
            ->whereIn('tokenable_type', [AdminUser::class, (new AdminUser)->getMorphClass()])
            ->delete();
    }

    public function down(): void
    {
        Schema::table('admin_users', function (Blueprint $table) {
            $table->dropColumn(['mfa_required', 'mfa_recovery_codes', 'mfa_last_timestep']);
        });
    }
};
