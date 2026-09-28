<?php

namespace Tests\Feature\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Item 26 (found 2026-09-24, fixed 2026-09-28): this backend is API-only
 * with no named `login` route. Without `bootstrap/app.php`'s
 * `redirectGuestsTo(fn () => null)` override, a request that doesn't send
 * `Accept: application/json` (any plain `curl`, not a real frontend — every
 * real frontend always sends it) crashed 500 with `RouteNotFoundException`
 * instead of a clean 401. `$this->call()` (not `getJson()`) deliberately
 * omits the `Accept` header to reproduce that exact case.
 */
class UnauthenticatedApiRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_unauthenticated_request_with_no_accept_header_gets_a_clean_401(): void
    {
        $response = $this->call('GET', '/api/affiliate/orders');

        $response->assertStatus(401);
        $response->assertJson(['message' => 'Unauthenticated.']);
    }

    public function test_reseller_portal_route_also_gets_a_clean_401(): void
    {
        $response = $this->call('GET', '/api/reseller-portal/orders');

        $response->assertStatus(401);
        $response->assertJson(['message' => 'Unauthenticated.']);
    }
}
