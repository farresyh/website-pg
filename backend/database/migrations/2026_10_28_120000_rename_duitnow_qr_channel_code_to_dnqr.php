<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * CHIP's channel code for DuitNow QR is `dnqr`, not the guessed
 * `duitnow_qr` (confirmed from `GET /payment_methods/`, 2026-10-28). The
 * attempts tables are renamed too: the reconcilers find a purchase's
 * gateway by `payment_methods.channel_code = attempt.channel_code`.
 * `orders.channel_code` is history and is left alone. Idempotent. No cache
 * calls: CI runs migrations with no Redis, and the catalog caches expire in 60s.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! DB::table('payment_methods')->where('channel_code', 'dnqr')->exists()) {
            DB::table('payment_methods')->where('channel_code', 'duitnow_qr')->update(['channel_code' => 'dnqr']);
        }

        DB::table('wallet_topup_attempts')->where('channel_code', 'duitnow_qr')->update(['channel_code' => 'dnqr']);
        DB::table('membership_checkout_attempts')->where('channel_code', 'duitnow_qr')->update(['channel_code' => 'dnqr']);
    }

    public function down(): void
    {
        // Not reversible on purpose: `duitnow_qr` was never a valid CHIP code.
    }
};
