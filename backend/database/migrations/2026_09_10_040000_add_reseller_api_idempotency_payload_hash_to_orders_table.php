<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-084 PR-1 decision 5: a hash of the Reseller API order payload
 * (`product_code` + `player_id` + `server_id`), stored alongside the
 * existing `checkout_idempotency_key`, so a replay of the same
 * `idempotency_key` with a *different* payload is a 409 conflict rather
 * than silently returning the original order.
 *
 * Nullable: only the Reseller API sets it. Storefront checkout and the
 * Reseller Bot (whose idempotency key derives from a WhatsApp message id,
 * not a client-supplied payload) leave it null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('reseller_api_idempotency_payload_hash', 64)
                ->nullable()
                ->after('checkout_idempotency_key');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('reseller_api_idempotency_payload_hash');
        });
    }
};
