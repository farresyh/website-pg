<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Short-lived record of a storefront "Validate Player ID" attempt.
     * Exists so CheckoutController can enforce validation server-side
     * (a direct API call bypassing a disabled "Proceed to Payment"
     * button must still be rejected — same class of gap the
     * packages.supplier_package_ref unique-index fix closed for
     * double-promote) without re-hitting the fragile third-party
     * validator APIs a second time at checkout. Freshness (a short TTL,
     * enforced in application code against `validated_at`) is what
     * makes a row usable at checkout time, not a database constraint.
     *
     * `status` mirrors the four states the storefront reacts to:
     * invalid | region_unknown | wrong_region | valid. Plain string,
     * not a DB enum — same convention as `games.category`.
     */
    public function up(): void
    {
        Schema::create('player_validations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained('games')->cascadeOnDelete();
            $table->string('player_id');
            $table->string('server_id')->nullable();
            $table->string('status');
            $table->string('country_code', 2)->nullable();
            $table->string('nickname')->nullable();
            $table->string('provider')->nullable();
            $table->timestamp('validated_at');
            $table->timestamps();

            $table->index(['game_id', 'player_id', 'server_id', 'validated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('player_validations');
    }
};
