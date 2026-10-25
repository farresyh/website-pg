<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-127 F2: the fee CHIP actually charged (`payment.fee_amount`),
 * recorded when the payment is confirmed. Before this it was known only
 * once a settlement file was uploaded. Null = not reported (older rows,
 * or a payment confirmed without CHIP's payment details).
 */
return new class extends Migration
{
    private const TABLES = ['orders', 'wallet_topup_attempts', 'membership_checkout_attempts'];

    public function up(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->unsignedInteger('gateway_fee_sen')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropColumn('gateway_fee_sen');
            });
        }
    }
};
