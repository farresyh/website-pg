<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * MUI-5 — a validator is now a first-class, admin-created entity
     * ("Mobile Legends Validator") rather than a loose free-typed
     * string tag on `player_region_mappings` — the founder's own
     * correction, 2026-07-25: without this, admin has no visibility
     * into whether a `key` corresponds to anything real on the
     * backend. `key` is constrained (at the FormRequest layer) to
     * PlayerValidatorRegistry::AVAILABLE_KEYS — the finite set of
     * keys that actually have a bound implementation
     * (AppServiceProvider) — so a profile can never be created
     * pointing at nothing. `last_tested_at`/`last_test_result` mirror
     * `payment_methods`' own pattern: a real "Test" action proves the
     * key is genuinely wired up, not just that the row exists.
     */
    public function up(): void
    {
        Schema::create('player_validator_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('key')->unique();
            $table->timestamp('last_tested_at')->nullable();
            $table->string('last_test_result')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('player_validator_profiles');
    }
};
