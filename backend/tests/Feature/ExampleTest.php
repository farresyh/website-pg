<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * The backend root (ADR-078 decision 4): a flat JSON identity, no
     * Blade / no `route()` / no closure — so it can never throw
     * `RouteNotFoundException` and never blocks `route:cache`.
     */
    public function test_the_root_returns_a_json_identity(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertExactJson(['service' => 'PekanGame API', 'status' => 'ok']);
    }
}
