<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Raw, uncurated mirror of one supplier's catalog (MID-1), kept
     * separate from `packages` (Admin's curated, customer-facing
     * catalog per PRD §14's Middleware-vs-Admin distinction). Nothing
     * here is ever shown to a customer directly — a row only becomes
     * real inventory when an admin explicitly promotes it into a
     * `Package` (SUPP-3/MID-5). `price_sen` mirrors the integer-sen
     * convention every other money column in this schema uses
     * (`orders`, `packages`) — never a float, even in a staging table.
     */
    public function up(): void
    {
        Schema::create('supplier_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained('suppliers')->cascadeOnDelete();
            $table->string('external_ref'); // the supplier's own product/package id
            $table->string('name');
            $table->string('category_raw')->nullable(); // unfiltered supplier category string (PRD §14: confirmed messy, curated later by admin)
            $table->unsignedInteger('price_sen')->nullable(); // null only if the supplier sent no price for this item
            $table->string('status_raw')->nullable(); // supplier's own status string, not yet mapped to our is_active semantics
            $table->timestamp('last_synced_at');
            $table->timestamps();

            $table->unique(['supplier_id', 'external_ref']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('supplier_products');
    }
};
