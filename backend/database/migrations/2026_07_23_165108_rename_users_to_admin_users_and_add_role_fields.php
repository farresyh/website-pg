<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * PRD §8 names this entity AdminUser, distinct from the storefront's
     * guest-checkout customers (no Customer table exists — see ADR
     * decided 2026-07-24: MVP storefront stays guest-checkout,
     * confirmed against both keroxshop.com's own precedent and the
     * absence of any Customer entity in the PRD data model). Renaming
     * the table for clarity now, before any other table references it.
     */
    public function up(): void
    {
        Schema::rename('users', 'admin_users');

        Schema::table('admin_users', function (Blueprint $table) {
            $table->string('role')->default('admin')->after('email'); // super_admin | admin
            $table->string('phone')->nullable()->after('role');
            $table->boolean('is_active')->default(true)->after('phone');
            $table->text('mfa_secret')->nullable()->after('is_active'); // TOTP secret, encrypted at rest (AUTH-7)
            $table->boolean('mfa_enabled')->default(false)->after('mfa_secret');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('admin_users', function (Blueprint $table) {
            $table->dropColumn(['role', 'phone', 'is_active', 'mfa_secret', 'mfa_enabled']);
        });

        Schema::rename('admin_users', 'users');
    }
};
