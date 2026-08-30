<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ADR-058 58b (RES-4): one row per admin impersonation of a reseller
     * portal. Written when the admin mints the short-lived `reseller`-guard
     * token (`started_at`) and closed when the session ends or the token
     * is revoked (`ended_at`). The real admin identity is `admin_user_id`;
     * `reseller_user_id` is the portal identity the token authenticates as
     * (ADR-058 decision 4 — the session runs under the real `reseller`
     * guard so ADR-057's tenant scope applies unchanged).
     *
     * `personal_access_token_id` is nullable-on-delete: revoking the token
     * (Sanctum row deleted) must not delete the audit trail of the session
     * having happened.
     */
    public function up(): void
    {
        Schema::create('reseller_impersonation_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reseller_id')->constrained('resellers')->cascadeOnDelete();
            $table->foreignId('admin_user_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->foreignId('reseller_user_id')->nullable()->constrained('reseller_users')->nullOnDelete();
            $table->foreignId('personal_access_token_id')
                ->nullable()
                ->constrained('personal_access_tokens')
                ->nullOnDelete();
            $table->string('reason')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->string('ended_reason')->nullable(); // manual | token_expired | reseller_deactivated
            $table->timestamps();

            $table->index('reseller_id');
            $table->index('admin_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reseller_impersonation_sessions');
    }
};
