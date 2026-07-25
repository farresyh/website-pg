<?php

namespace Tests\Unit\Services\PlayerValidation;

use App\Services\PlayerValidation\MlbbPlayerValidator;
use App\Services\PlayerValidation\PlayerValidatorRegistry;
use App\Services\PlayerValidation\UnsupportedPlayerValidatorException;
use Tests\TestCase;

class PlayerValidatorRegistryTest extends TestCase
{
    public function test_resolves_the_mlbb_validator_bound_in_the_app_service_provider(): void
    {
        $registry = $this->app->make(PlayerValidatorRegistry::class);

        $this->assertInstanceOf(MlbbPlayerValidator::class, $registry->resolve('mlbb'));
    }

    public function test_throws_for_an_unbound_validator_key(): void
    {
        $registry = $this->app->make(PlayerValidatorRegistry::class);

        $this->expectException(UnsupportedPlayerValidatorException::class);

        $registry->resolve('some-unbuilt-game');
    }
}
