<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-058 (58a): token storage for the `reseller_users` password broker
 * (config/auth.php). Same shape as Laravel's default
 * `password_reset_tokens` — a separate table so a shared email address
 * between an admin_user and a reseller_user never collides on the
 * email-primary-key. Backs both the first-time set-password invite
 * (ResellerInviteService) and any later password reset.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reseller_password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reseller_password_reset_tokens');
    }
};
