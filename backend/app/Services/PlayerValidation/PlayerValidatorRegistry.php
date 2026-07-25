<?php

namespace App\Services\PlayerValidation;

use Illuminate\Contracts\Container\Container;

/**
 * Resolves a PlayerValidator by name, keyed on
 * `player_validator_profiles.key` — mirrors PaymentGatewayFactory's
 * role for `payment_methods.gateway`. Only 'mlbb' is bound today
 * (AppServiceProvider); a future validator for another game family
 * with its own region split is added the same way, without touching
 * this class or any existing binding.
 */
final class PlayerValidatorRegistry
{
    /**
     * Single source of truth for which keys have a real bound
     * implementation — StorePlayerValidatorProfileRequest constrains
     * admin-created profiles to this list, so a profile can never be
     * created pointing at a key nothing actually implements (the
     * founder's own concern, 2026-07-25: no visibility into whether a
     * validator was genuinely "plugged in").
     *
     * @var array<string, string> key => human-readable label
     */
    public const AVAILABLE_KEYS = [
        'mlbb' => 'Mobile Legends: Bang Bang',
    ];

    public function __construct(private readonly Container $container) {}

    public function resolve(string $key): PlayerValidator
    {
        $bindingKey = "player-validator.{$key}";

        if (! $this->container->bound($bindingKey)) {
            throw new UnsupportedPlayerValidatorException("No PlayerValidator implementation for key: {$key}");
        }

        return $this->container->make($bindingKey);
    }
}
