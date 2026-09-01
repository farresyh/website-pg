<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-058 (58a): login identities for the reseller portal (ADR-059),
 * authenticated through the separate `reseller` Sanctum guard. One
 * reseller can have several staff users — the schema supports it from
 * day one even though the first onboarding creates exactly one.
 *
 * `password` is nullable: an admin creates the row (ADR-058 58b, RES-2),
 * the reseller sets their own password by accepting the emailed invite
 * (ResellerInviteService / the `reseller_users` password broker). A row
 * with a null password cannot log in — ResellerAuthController::login()
 * rejects it before reaching Hash::check().
 *
 * Deliberately does NOT use the BelongsToReseller trait (unlike every
 * other reseller-owned table) — see the ADR-058 build addendum: this
 * table is only ever read by email at login (no tenant context), by
 * Sanctum as a token's tokenable (which must not be tenant-constrained
 * or auth resolution breaks), and by the admin (cross-tenant).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reseller_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reseller_id')->constrained()->cascadeOnDelete();
            // Explicit standalone index — foreignId()->constrained() has
            // been found once in this codebase not to reliably leave one
            // (packages.supplier_id, migration 2026_07_25_180000); this
            // column is the tenant filter for a future "manage my team"
            // screen and the admin's per-reseller user list.
            $table->index('reseller_id');
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_login_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reseller_users');
    }
};
