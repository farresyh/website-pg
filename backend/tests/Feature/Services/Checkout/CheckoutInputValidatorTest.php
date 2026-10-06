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

        $this->assertNull((new CheckoutInputValidator)->validate($game, '123', null));
    }

    public function test_a_server_id_game_rejects_a_missing_value(): void
    {
        $game = $this->game(['extra_field' => 'server_id']);

        $error = (new CheckoutInputValidator)->validate($game, '123', null);

        $this->assertSame('server_id', $error['field']);
        $this->assertSame('This game requires a Server ID.', $error['message']);
    }

    public function test_a_server_id_game_rejects_an_empty_string_value(): void
    {
        $game = $this->game(['extra_field' => 'server_id']);

        $this->assertNotNull((new CheckoutInputValidator)->validate($game, '123', ''));
    }

    public function test_a_server_id_game_accepts_a_digits_only_value(): void
    {
        $game = $this->game(['extra_field' => 'server_id']);

        $this->assertNull((new CheckoutInputValidator)->validate($game, '123', '2001'));
    }

    /** ADR-097 decision 7 — no zone_options defined yet preserves free-text behavior exactly. */
    public function test_a_zone_id_game_with_no_options_defined_accepts_any_non_empty_value(): void
    {
        $game = $this->game(['extra_field' => 'zone_id']);

        $this->assertNull((new CheckoutInputValidator)->validate($game, '123', 'SEA'));
    }

    public function test_a_zone_id_game_rejects_a_missing_value(): void
    {
        $game = $this->game(['extra_field' => 'zone_id', 'zone_options' => ['SouthEastAsia', 'MENA']]);

        $error = (new CheckoutInputValidator)->validate($game, '123', null);

        $this->assertSame('This game requires a Zone ID.', $error['message']);
    }

    public function test_a_zone_id_game_rejects_a_value_outside_the_defined_options(): void
    {
        $game = $this->game(['extra_field' => 'zone_id', 'zone_options' => ['SouthEastAsia', 'MENA']]);

        $error = (new CheckoutInputValidator)->validate($game, '123', 'SEA');

        $this->assertSame('server_id', $error['field']);
        $this->assertSame('Invalid Zone ID. Valid options: SouthEastAsia, MENA.', $error['message']);
    }

    public function test_a_zone_id_game_accepts_a_value_that_exactly_matches_an_option(): void
    {
        $game = $this->game(['extra_field' => 'zone_id', 'zone_options' => ['SouthEastAsia', 'MENA']]);

        $this->assertNull((new CheckoutInputValidator)->validate($game, '123', 'SouthEastAsia'));
    }

    /** Exact-match only — Digiflazz doesn't correct/fuzzy-match, so neither does this. */
    public function test_a_zone_id_game_rejects_a_case_mismatched_value(): void
    {
        $game = $this->game(['extra_field' => 'zone_id', 'zone_options' => ['SouthEastAsia']]);

        $this->assertNotNull((new CheckoutInputValidator)->validate($game, '123', 'southeastasia'));
    }

    /**
     * ADR-097 2026-10-05 addendum, decisions 27-28 — each game's full
     * player-input contract, rejected with a field-specific reason.
     */
    private function assertRejects(Game $game, ?string $playerId, ?string $serverId, string $field, string $message): void
    {
        $error = (new CheckoutInputValidator)->validate($game, $playerId, $serverId);

        $this->assertNotNull($error, "expected '{$playerId}' / '{$serverId}' to be rejected");
        $this->assertSame([$field, $message], [$error['field'], $error['message']]);
    }

    public function test_a_user_id_only_game_rejects_any_server_id_value(): void
    {
        $this->assertRejects($this->game(), '123456', 'tq', 'server_id', 'This game does not take a Server ID.');
        $this->assertRejects($this->game(['extra_field' => null]), '123456', '.order', 'server_id', 'This game does not take a Server ID.');
    }

    public function test_a_user_id_only_game_treats_an_empty_server_id_as_absent(): void
    {
        $this->assertNull((new CheckoutInputValidator)->validate($this->game(), '123456', ''));
    }

    public function test_a_numeric_player_id_rejects_letters_brackets_and_symbols(): void
    {
        $game = $this->game();

        foreach (['12a34', '12345678(2001)', '1234-5', 'JettMain#1234'] as $value) {
            $this->assertRejects($game, $value, null, 'player_id', 'User ID must contain digits only.');
        }
    }

    public function test_a_numeric_player_id_keeps_leading_zeros(): void
    {
        $this->assertNull((new CheckoutInputValidator)->validate($this->game(), '0012345', null));
    }

    public function test_an_unset_format_is_numeric(): void
    {
        $this->assertRejects($this->game(['player_id_format' => null]), 'abc', null, 'player_id', 'User ID must contain digits only.');
    }

    public function test_a_text_player_id_accepts_a_riot_id(): void
    {
        $game = $this->game(['player_id_format' => 'text']);

        foreach (['JettMain#1234', 'a.b_c-d', '123456'] as $value) {
            $this->assertNull((new CheckoutInputValidator)->validate($game, $value, null), $value);
        }
    }

    public function test_a_text_player_id_rejects_other_symbols(): void
    {
        $this->assertRejects($this->game(['player_id_format' => 'text']), 'Jett(Main)', null, 'player_id', 'User ID may only contain letters, digits and # . _ -');
    }

    public function test_inner_whitespace_is_rejected_on_both_fields(): void
    {
        $this->assertRejects($this->game(), '1234 5678', null, 'player_id', 'User ID must not contain spaces.');
        $this->assertRejects($this->game(['player_id_format' => 'text']), "Jett\nMain", null, 'player_id', 'User ID must not contain spaces.');
        $this->assertRejects($this->game(['extra_field' => 'server_id']), '123', '20 01', 'server_id', 'Server ID must not contain spaces.');
    }

    public function test_a_missing_player_id_is_rejected(): void
    {
        $this->assertRejects($this->game(), '', null, 'player_id', 'User ID is required.');
        $this->assertRejects($this->game(), null, null, 'player_id', 'User ID is required.');
    }

    public function test_a_server_id_game_rejects_a_non_digit_value(): void
    {
        $this->assertRejects($this->game(['extra_field' => 'server_id']), '123', '(2001)', 'server_id', 'Server ID must contain digits only.');
    }

    public function test_both_fields_are_capped_at_64_characters(): void
    {
        $this->assertRejects($this->game(), str_repeat('1', 65), null, 'player_id', 'User ID must not be longer than 64 characters.');
        $this->assertNull((new CheckoutInputValidator)->validate($this->game(), str_repeat('1', 64), null));
        $this->assertRejects($this->game(['extra_field' => 'server_id']), '1', str_repeat('2', 65), 'server_id', 'Server ID must not be longer than 64 characters.');
    }

    public function test_a_zone_game_with_no_options_still_rejects_whitespace(): void
    {
        $this->assertRejects($this->game(['extra_field' => 'zone_id']), '123', 'South East', 'server_id', 'Zone ID must not contain spaces.');
    }
}
