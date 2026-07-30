<?php

namespace Tests\Feature\Console;

use App\Models\Game;
use App\Models\PlayerValidation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-021 — a player_validations row is written on every storefront
 * validation attempt, not just completed orders, with no TTL previously.
 */
class PrunePlayerValidationsCommandTest extends TestCase
{
    use RefreshDatabase;

    private function game(): Game
    {
        return Game::query()->create(['name' => 'Mobile Legends', 'slug' => 'mobile-legends']);
    }

    private function validation(Game $game, array $overrides = []): PlayerValidation
    {
        return PlayerValidation::query()->create(array_merge([
            'game_id' => $game->id,
            'player_id' => '123456',
            'server_id' => null,
            'status' => 'valid',
            'validated_at' => now(),
        ], $overrides));
    }

    public function test_deletes_a_validation_row_older_than_the_retention_window(): void
    {
        config(['services.player_validation.retention_days' => 7]);
        $game = $this->game();
        $stale = $this->validation($game);
        $stale->forceFill(['validated_at' => now()->subDays(8)])->save();

        $this->artisan('app:prune-player-validations')->assertExitCode(0);

        $this->assertSame(0, PlayerValidation::query()->count());
    }

    public function test_keeps_a_validation_row_within_the_retention_window(): void
    {
        config(['services.player_validation.retention_days' => 7]);
        $game = $this->game();
        $fresh = $this->validation($game);
        $fresh->forceFill(['validated_at' => now()->subDays(2)])->save();

        $this->artisan('app:prune-player-validations')->assertExitCode(0);

        $this->assertSame(1, PlayerValidation::query()->count());
    }

    public function test_only_prunes_rows_older_than_the_configured_window_not_the_default(): void
    {
        config(['services.player_validation.retention_days' => 30]);
        $game = $this->game();
        $withinConfiguredWindow = $this->validation($game);
        $withinConfiguredWindow->forceFill(['validated_at' => now()->subDays(10)])->save();

        $this->artisan('app:prune-player-validations')->assertExitCode(0);

        $this->assertSame(1, PlayerValidation::query()->count());
    }
}
