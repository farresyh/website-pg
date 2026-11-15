<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-102 2026-11-15 addendum: a supplier result that contradicts an order's
 * or combo leg's final status, one append-only row each. It used to live in
 * `orders.supplier_response.late_supplier_result`, which a resend or retry
 * rewrites whole, held one result, and had no leg. Production held no such
 * evidence on 2026-11-15, so nothing is copied.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('late_supplier_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->index()->constrained();
            $table->foreignId('order_delivery_leg_id')->nullable()->index()->constrained();
            $table->string('outcome', 16);
            $table->string('supplier_reference', 128);
            $table->json('payload')->nullable();
            $table->timestamps();

            // A redelivered webhook or a repeated poll is the same fact: one row, one alert.
            $table->unique(['supplier_reference', 'outcome']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('late_supplier_results');
    }
};
