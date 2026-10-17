<?php

namespace Tests\Feature\Http\Controllers;

use App\Models\Game;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CheckoutControllerFixtures;
use Tests\TestCase;

/** This file: player ID / server / extra-field input and player validation. */
class CheckoutControllerPlayerInputTest extends TestCase
{
    use CheckoutControllerFixtures;
    use RefreshDatabase;

    public function test_rejects_checkout_for_a_game_requiring_an_extra_field_without_it(): void
    {
        $this->bindGateway();
        ['game' => $game, 'package' => $package] = $this->gameAndPackage([
            'validation_rules' => ['extra_field' => 'zone_id'],
        ]);

        $response = $this->postJson('/api/checkout', $this->payload($game, $package));

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('server_id');
        $this->assertSame(0, Order::query()->count());
    }

    public function test_accepts_checkout_for_a_game_requiring_an_extra_field_when_provided(): void
    {
        $this->bindGateway();
        ['game' => $game, 'package' => $package] = $this->gameAndPackage([
            'validation_rules' => ['extra_field' => 'zone_id'],
        ]);

        $response = $this->postJson('/api/checkout', $this->payload($game, $package, ['server_id' => '5001']));

        $response->assertCreated();
        $this->assertSame('5001', Order::query()->firstOrFail()->server_id);
    }

    /** ADR-097 decision 10 — value-restriction, not just presence, once a game has a defined zone list. */
    public function test_rejects_checkout_for_a_zone_id_game_with_a_value_outside_the_defined_list(): void
    {
        $this->bindGateway();
        ['game' => $game, 'package' => $package] = $this->gameAndPackage([
            'validation_rules' => ['extra_field' => 'zone_id', 'zone_options' => ['SouthEastAsia', 'MENA']],
        ]);

        $response = $this->postJson('/api/checkout', $this->payload($game, $package, ['server_id' => 'SEA']));

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('server_id');
        $this->assertSame(0, Order::query()->count());
    }

    public function test_accepts_checkout_for_a_zone_id_game_with_a_value_matching_the_defined_list(): void
    {
        $this->bindGateway();
        ['game' => $game, 'package' => $package] = $this->gameAndPackage([
            'validation_rules' => ['extra_field' => 'zone_id', 'zone_options' => ['SouthEastAsia', 'MENA']],
        ]);

        $response = $this->postJson('/api/checkout', $this->payload($game, $package, ['server_id' => 'SouthEastAsia']));

        $response->assertCreated();
    }

    /**
     * The real gap this closes: a direct API call could always skip
     * PlayerValidationController entirely — the storefront wizard's
     * "Proceed to Payment" gate is client-side React state only, not a
     * security boundary (ADR-011: guest checkout, no session to own it).
     */
    public function test_rejects_checkout_for_a_validator_enabled_game_with_no_validation_on_record(): void
    {
        $this->bindGateway();
        ['game' => $game, 'package' => $package] = $this->validatorEnabledGameAndPackage();

        $response = $this->postJson('/api/checkout', $this->payload($game, $package));

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('player_id');
        $this->assertSame(0, Order::query()->count());
    }

    public function test_accepts_checkout_for_a_validator_enabled_game_with_a_recent_valid_validation(): void
    {
        $this->bindGateway();
        ['game' => $game, 'package' => $package] = $this->validatorEnabledGameAndPackage();
        $this->recordValidation($game, '123456789');

        $response = $this->postJson('/api/checkout', $this->payload($game, $package, ['player_id' => '123456789']));

        $response->assertCreated();
    }

    public function test_rejects_checkout_when_the_recorded_validation_is_for_a_different_player_id(): void
    {
        $this->bindGateway();
        ['game' => $game, 'package' => $package] = $this->validatorEnabledGameAndPackage();
        $this->recordValidation($game, '999999999');

        $response = $this->postJson('/api/checkout', $this->payload($game, $package, ['player_id' => '123456789']));

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('player_id');
    }

    public function test_rejects_checkout_when_the_recorded_validation_status_is_not_valid(): void
    {
        $this->bindGateway();
        ['game' => $game, 'package' => $package] = $this->validatorEnabledGameAndPackage();
        $this->recordValidation($game, '123456789', overrides: ['status' => 'invalid']);

        $response = $this->postJson('/api/checkout', $this->payload($game, $package, ['player_id' => '123456789']));

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('player_id');
    }

    public function test_rejects_checkout_when_the_recorded_validation_has_expired(): void
    {
        $this->bindGateway();
        ['game' => $game, 'package' => $package] = $this->validatorEnabledGameAndPackage();
        $this->recordValidation($game, '123456789', overrides: ['validated_at' => now()->subMinutes(31)]);

        $response = $this->postJson('/api/checkout', $this->payload($game, $package, ['player_id' => '123456789']));

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('player_id');
    }

    public function test_accepts_checkout_when_the_validated_server_id_matches(): void
    {
        $this->bindGateway();
        ['game' => $game, 'package' => $package] = $this->validatorEnabledGameAndPackage([
            'validation_rules' => ['extra_field' => 'zone_id'],
        ]);
        $this->recordValidation($game, '123456789', '5001');

        $response = $this->postJson('/api/checkout', $this->payload($game, $package, [
            'player_id' => '123456789',
            'server_id' => '5001',
        ]));

        $response->assertCreated();
    }

    /** ADR-097 2026-10-05 addendum, decision 27 — a User-ID-only game never takes a server_id. */
    public function test_rejects_a_server_id_for_a_game_that_takes_none(): void
    {
        $this->bindGateway();
        ['game' => $game, 'package' => $package] = $this->gameAndPackage();

        $response = $this->postJson('/api/checkout', $this->payload($game, $package, ['server_id' => '2001']));

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('server_id');
        $this->assertSame(0, Order::query()->count());
    }

    public function test_rejects_a_non_numeric_player_id_on_a_numeric_game(): void
    {
        $this->bindGateway();
        ['game' => $game, 'package' => $package] = $this->gameAndPackage();

        $response = $this->postJson('/api/checkout', $this->payload($game, $package, ['player_id' => '12345678(2001)']));

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('player_id');
        $this->assertSame(0, Order::query()->count());
    }

    public function test_accepts_a_riot_id_on_a_text_format_game(): void
    {
        $this->bindGateway();
        ['game' => $game, 'package' => $package] = $this->gameAndPackage([
            'validation_rules' => ['player_id_format' => 'text'],
        ]);

        $this->postJson('/api/checkout', $this->payload($game, $package, ['player_id' => 'JettMain#1234']))->assertCreated();
    }
}
