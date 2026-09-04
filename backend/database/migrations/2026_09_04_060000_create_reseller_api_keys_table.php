<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ADR-074 decision 1: a `Reseller` (wallet) account's machine-facing
     * credential for the Reseller API channel — deliberately its own
     * table/auth mechanism, not a scoped ability on the portal-login
     * `reseller` Sanctum guard token (see the ADR's own rationale).
     *
     * `key_hash` stores `hash('sha256', $plainKey)` — the plaintext key
     * is shown to the admin exactly once, at creation, and is never
     * persisted or retrievable again (same trust model Sanctum's own
     * `PersonalAccessToken` uses internally). `revoked_at` rather than
     * a hard delete: preserves the audit trail of what a now-dead key
     * was, matches `Package.deactivated_at`'s own soft-off idiom.
     */
    public function up(): void
    {
        Schema::create('reseller_api_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reseller_id')->constrained('resellers')->cascadeOnDelete();
            $table->string('name');
            $table->string('key_hash')->unique();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reseller_api_keys');
    }
};
