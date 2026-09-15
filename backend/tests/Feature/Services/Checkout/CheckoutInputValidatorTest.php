<?php

namespace Tests\Feature\Services\Checkout;

use App\Models\Game;
use App\Services\Checkout\CheckoutInputValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-097 decision 19 — the ONE shared rule every order-placement
 * channel (storefront, Reseller API, Reseller Bot) calls instead of
 * duplicating this exact if-chain three times.
 */
class CheckoutInputValidatorTest extends TestCase
{
    use RefreshDatabase;

    private function game(array $validationRules = []): Game
    {
        return Game::query()->create([
            'name' => 'Test Game', 'slug' => 'test-game-'.uniqid(),
            'validation_rules' => $validationRules === [] ? null : $validationRules,
        ]);
    }

    public function test_a_game_with_no_extra_field_never_requires_a_value(): void
    {
        $game = $this->game();

        $this->assertNull((new CheckoutInputValidator)->validate($game, null));
    }

    public function test_a_server_id_game_rejects_a_missing_value(): void
    {
        $game = $this->game(['extra_field' => 'server_id']);

        $error = (new CheckoutInputValidator)->validate($game, null);

        $this->assertSame('server_id', $error['field']);
        $this->assertSame('This game requires a Server ID.', $error['message']);
    }

    public function test_a_server_id_game_rejects_an_empty_string_value(): void
    {
        $game = $this->game(['extra_field' => 'server_id']);

        $this->assertNotNull((new CheckoutInputValidator)->validate($game, ''));
    }

    public function test_a_server_id_game_accepts_any_non_empty_value_no_list_exists_for_it(): void
    {
        $game = $this->game(['extra_field' => 'server_id']);

        $this->assertNull((new CheckoutInputValidator)->validate($game, 'anything'));
    }

    /** ADR-097 decision 7 — no zone_options defined yet preserves free-text behavior exactly. */
    public function test_a_zone_id_game_with_no_options_defined_accepts_any_non_empty_value(): void
    {
        $game = $this->game(['extra_field' => 'zone_id']);

        $this->assertNull((new CheckoutInputValidator)->validate($game, 'SEA'));
    }

    public function test_a_zone_id_game_rejects_a_missing_value(): void
    {
        $game = $this->game(['extra_field' => 'zone_id', 'zone_options' => ['SouthEastAsia', 'MENA']]);

        $error = (new CheckoutInputValidator)->validate($game, null);

        $this->assertSame('This game requires a Zone ID.', $error['message']);
    }

    public function test_a_zone_id_game_rejects_a_value_outside_the_defined_options(): void
    {
        $game = $this->game(['extra_field' => 'zone_id', 'zone_options' => ['SouthEastAsia', 'MENA']]);

        $error = (new CheckoutInputValidator)->validate($game, 'SEA');

        $this->assertSame('server_id', $error['field']);
        $this->assertSame('Invalid Zone ID. Valid options: SouthEastAsia, MENA.', $error['message']);
    }

    public function test_a_zone_id_game_accepts_a_value_that_exactly_matches_an_option(): void
    {
        $game = $this->game(['extra_field' => 'zone_id', 'zone_options' => ['SouthEastAsia', 'MENA']]);

        $this->assertNull((new CheckoutInputValidator)->validate($game, 'SouthEastAsia'));
    }

    /** Exact-match only — Digiflazz doesn't correct/fuzzy-match, so neither does this. */
    public function test_a_zone_id_game_rejects_a_case_mismatched_value(): void
    {
        $game = $this->game(['extra_field' => 'zone_id', 'zone_options' => ['SouthEastAsia']]);

        $this->assertNotNull((new CheckoutInputValidator)->validate($game, 'southeastasia'));
    }
}
