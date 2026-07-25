<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * MUI-5's "region-based game routing rules" — admin-curated,
     * since no validator API exposes which of our own Game rows a
     * returned country corresponds to. Scoped per
     * `player_validator_profile_id` (a real FK, not a loose string tag
     * — founder correction, 2026-07-25) so a future non-MLBB validator
     * profile's own region split doesn't collide with MLBB's rows,
     * and so mappings are only ever reachable through a validator that
     * genuinely exists.
     *
     * `country_name` is a plain admin-typed label (e.g. "Cambodia" for
     * "KH") — no ISO country-name lookup table exists in this
     * codebase, and building one just to display a label isn't
     * justified when the admin is already the one keying in the code
     * itself.
     */
    public function up(): void
    {
        Schema::create('player_region_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('player_validator_profile_id')->constrained('player_validator_profiles')->cascadeOnDelete();
            $table->string('country_code', 2);
            $table->string('country_name');
            $table->foreignId('game_id')->constrained('games')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['player_validator_profile_id', 'country_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('player_region_mappings');
    }
};
