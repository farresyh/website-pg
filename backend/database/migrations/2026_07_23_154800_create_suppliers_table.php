<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A game credit supplier integrated via the Adapter layer (PRD §8,
     * §6.21). `api_config` holds whatever credentials/endpoints that
     * supplier's Adapter needs (shape varies per supplier — Gamevion's
     * Adapter needs base_url/bearer_token/api_key/sandbox, a different
     * supplier might need something else entirely) — stored as `text`
     * because the Eloquent `encrypted:array` cast produces ciphertext
     * far longer than a varchar(255) (SUPP-5: encrypted at rest).
     *
     * `balance` mirrors what the supplier's own API last reported
     * (DASH-2, refreshed via Adapter::checkBalance()) — this is NOT
     * our own financial ledger and is unrelated to ADR-002; it is a
     * plain mutable cache of a third party's number, not a source of
     * truth for our money.
     */
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('logo_url')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('api_config'); // encrypted at rest via Eloquent cast (SUPP-5)
            $table->decimal('balance', 15, 2)->nullable();
            $table->string('currency', 3)->default('MYR');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('suppliers');
    }
};
