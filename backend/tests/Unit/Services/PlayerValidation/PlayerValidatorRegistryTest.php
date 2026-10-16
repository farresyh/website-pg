<?php

namespace Tests\Unit\Services\PlayerValidation;

use App\Services\PlayerValidation\MlbbPlayerValidator;
use App\Services\PlayerValidation\PlayerValidator;
use App\Services\PlayerValidation\PlayerValidatorRegistry;
use App\Services\PlayerValidation\Providers\RedxGameValidator;
use App\Services\PlayerValidation\UnsupportedPlayerValidatorException;
use Tests\TestCase;

class PlayerValidatorRegistryTest extends TestCase
{
    public function test_resolves_the_mlbb_validator_bound_in_the_app_service_provider(): void
    {
        $registry = $this->app->make(PlayerValidatorRegistry::class);

        $this->assertInstanceOf(MlbbPlayerValidator::class, $registry->resolve('mlbb'));
    }

    public function test_resolves_the_free_fire_validator(): void
    {
        $registry = $this->app->make(PlayerValidatorRegistry::class);

        $this->assertInstanceOf(RedxGameValidator::class, $registry->resolve('freefire'));
    }

    /**
     * The admin can only pick a key from AVAILABLE_KEYS, so a key listed
     * there without a binding would be a validator that always fails.
     */
    public function test_every_selectable_key_has_a_bound_validator(): void
    {
        $registry = $this->app->make(PlayerValidatorRegistry::class);

        foreach (array_keys(PlayerValidatorRegistry::AVAILABLE_KEYS) as $key) {
            $this->assertInstanceOf(PlayerValidator::class, $registry->resolve($key), $key);
        }
    }

    public function test_throws_for_an_unbound_validator_key(): void
    {
        $registry = $this->app->make(PlayerValidatorRegistry::class);

        $this->expectException(UnsupportedPlayerValidatorException::class);

        $registry->resolve('some-unbuilt-game');
    }
}
