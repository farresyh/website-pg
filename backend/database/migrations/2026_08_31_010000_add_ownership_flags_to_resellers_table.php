<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-061 PR-A: replace the `business_name = 'Platform Owner'` magic
 * string with explicit flags. Pure refactor — no behaviour change.
 *
 *  - `is_owned`     — "this storefront is our own brand" (reporting treats
 *                     its margin as internal money, not a payable). Safe
 *                     default false for every third-party reseller.
 *  - `is_primary`   — the single fallback tenant for any context with no
 *                     `Host` to resolve from (console, queue jobs,
 *                     migrations, not-yet-brand-aware admin screens).
 *                     EXACTLY ONE row, and it must be `is_owned`. Never
 *                     deletable (RES-6 guard). Enforced by a portable
 *                     nullable-unique index: the column stores `1` for the
 *                     one primary row and `NULL` for every other reseller
 *                     — both MySQL and SQLite allow unlimited NULLs under a
 *                     UNIQUE index but reject a second `1`. No write path
 *                     ever stores `0` here (see Reseller::$casts note).
 *  - `membership_enabled` — the sole per-reseller capability toggle for
 *                     consumer Membership (ADR-027). Effective only when
 *                     BOTH this AND the global PlatformSettings kill-switch
 *                     are true (Reseller::membershipEnabledEffective()).
 *
 * Backfill: the current `business_name = 'Platform Owner'` row (ADR-013 —
 * there is exactly one) becomes is_owned + is_primary, and inherits the
 * live global membership_enabled value so the dual kill-switch resolves
 * identically to today. Resolved only when that row actually exists — a
 * fresh test DB with no resellers is left untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('resellers', function (Blueprint $table) {
            $table->boolean('is_owned')->default(false)->after('status');
            $table->boolean('is_primary')->nullable()->after('is_owned');
            $table->boolean('membership_enabled')->default(false)->after('is_primary');

            // "Exactly one primary" — NULLs don't collide, a second `1` does.
            $table->unique('is_primary');
        });

        $ownerId = DB::table('resellers')->where('business_name', 'Platform Owner')->value('id');

        if ($ownerId !== null) {
            $globalMembership = DB::table('platform_settings')->value('membership_enabled');

            DB::table('resellers')->where('id', $ownerId)->update([
                'is_owned' => true,
                'is_primary' => true,
                'membership_enabled' => (bool) $globalMembership,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('resellers', function (Blueprint $table) {
            $table->dropUnique('resellers_is_primary_unique');
            $table->dropColumn(['is_owned', 'is_primary', 'membership_enabled']);
        });
    }
};
