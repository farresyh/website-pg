<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Player-ID validation here is unrelated to a Game's fulfillment
     * supplier (`supplier_mappings[].supports_validation`, GAME-12) —
     * Gamevion has zero validation endpoints for any game (ADR-005
     * addendum). This is a separate, third-party signal (unofficial
     * MLBB nickname/region checkers), so it gets its own field rather
     * than overloading `supports_validation`. Nullable/off by default
     * on every Game row since only MLBB-family games have a validator
     * built at all right now.
     *
     * `player_validator_profile_id` points at an admin-created
     * PlayerValidatorProfile row (MUI-5), not a free-typed string —
     * founder correction, 2026-07-25: a raw string key gave no
     * visibility into whether it corresponded to anything real on the
     * backend. `nullOnDelete()` since deleting a validator profile
     * should gracefully turn validation off for games that used it,
     * not break them.
     *
     * `player_validator_enabled` is a deliberate kill-switch separate
     * from the profile assignment itself — lets an admin hide the
     * storefront "Validate Player ID" button instantly (e.g. all
     * upstream providers down) without unassigning the game's
     * validator profile.
     */
    public function up(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->foreignId('player_validator_profile_id')
                ->nullable()
                ->after('validation_rules')
                ->constrained('player_validator_profiles')
                ->nullOnDelete();
            $table->boolean('player_validator_enabled')->default(false)->after('player_validator_profile_id');
        });
    }

    public function down(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->dropConstrainedForeignId('player_validator_profile_id');
            $table->dropColumn(['player_validator_enabled']);
        });
    }
};
