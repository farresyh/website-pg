<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-007 / FRAUD-3 — "view history of orders blocked by a given
 * entry." A blocked checkout attempt never creates an Order (FRAUD-2
 * rejects before payment/supplier submission), so this is the only
 * place that history exists. Insert-only, same discipline as
 * price_change_logs/deactivation_logs/order_resend_attempts.
 * cascadeOnDelete is safe here specifically because blacklist_entries
 * is never hard-deleted (is_active toggle only) - this FK exists for
 * schema integrity, not because entries are expected to disappear.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blacklist_hits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('blacklist_entry_id')->constrained('blacklist_entries')->cascadeOnDelete();
            $table->string('player_id')->nullable();
            $table->string('customer_email')->nullable();
            $table->string('customer_phone')->nullable();
            $table->string('ip')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blacklist_hits');
    }
};
