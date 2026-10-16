<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-123 decision 8: usage and abuse record of the public Game ID
 * Checker. Deliberately not `player_validations`: nothing that approves
 * a checkout or resend reads this table, so a public check can never
 * approve a payment. Pruned with `player_validations`' retention window.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('public_player_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('player_validator_profile_id')->constrained('player_validator_profiles')->cascadeOnDelete();
            $table->string('player_id');
            $table->string('server_id')->nullable();
            $table->string('status');
            $table->string('nickname')->nullable();
            $table->foreignId('matched_game_id')->nullable()->constrained('games')->nullOnDelete();
            $table->boolean('cache_hit')->default(false);
            $table->timestamp('created_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('public_player_checks');
    }
};
