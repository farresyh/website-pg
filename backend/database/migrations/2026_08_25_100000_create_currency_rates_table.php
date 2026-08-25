<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ADR-033 decision 2: append-only audit of every rate this app has
     * ever fetched from the FX API — never updated in place, only new
     * rows added, matching this codebase's existing audit-table
     * convention (price_change_logs/deactivation_logs/order_resend_attempts).
     * Keyed by (from, to) pair rather than any supplier — so a second
     * non-MYR supplier reuses this same table with zero schema change.
     * No foreign key to `suppliers`: a currency pair is a property of
     * the conversion itself, not any one supplier row.
     */
    public function up(): void
    {
        Schema::create('currency_rates', function (Blueprint $table) {
            $table->id();
            $table->string('from', 3);
            $table->string('to', 3);
            $table->decimal('rate', 20, 10);
            $table->string('source');
            $table->timestamp('fetched_at');
            $table->timestamps();

            $table->index(['from', 'to', 'fetched_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('currency_rates');
    }
};
