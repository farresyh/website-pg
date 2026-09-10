<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ADR-084 PR-3 decision 4: one delivery-webhook endpoint per
     * `Reseller` (wallet) account — `reseller_id` unique. `url` + `secret`
     * are self-managed from the portal (admin can also set/read for
     * support).
     *
     * PR-3 build addendum: `secret` is stored ENCRYPTED (`encrypted` cast
     * on `ResellerWebhook`), not sha256-hashed as decision 4's wording
     * says. An outbound HMAC signature (`X-Hub-Signature-256`) is computed
     * on every delivery, so the sender must be able to reproduce the
     * plaintext — the same reason `Supplier.api_config` (Digiflazz/OpenWA
     * webhook secrets) and `AdminUser.mfa_secret` are encrypted, not
     * hashed. Still shown to the reseller exactly once, at set/rotate time.
     */
    public function up(): void
    {
        Schema::create('reseller_webhooks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reseller_id')->unique()->constrained('resellers')->cascadeOnDelete();
            $table->string('url', 2048);
            $table->text('secret');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reseller_webhooks');
    }
};
