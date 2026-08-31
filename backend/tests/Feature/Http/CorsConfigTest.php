<?php

namespace Tests\Feature\Http;

use Tests\TestCase;

/**
 * `config/cors.php`'s `allowed_origins` has silently broken a browser
 * app twice — storefront/ (checkout, player validation) and reseller/
 * (every `/api/reseller/*` read) — because a new Bearer-token Next app
 * on a new port was not added here, and a blocked CORS response never
 * surfaces to the page's own JS. This locks in all three origins so a
 * fourth app (or a config refactor) can't drop one unnoticed.
 */
class CorsConfigTest extends TestCase
{
    public function test_all_three_browser_app_origins_are_allowed(): void
    {
        $origins = config('cors.allowed_origins');

        $this->assertContains('http://localhost:3000', $origins, 'admin/ origin missing');
        $this->assertContains('http://localhost:3001', $origins, 'storefront/ origin missing');
        $this->assertContains('http://localhost:3002', $origins, 'reseller/ portal origin missing');
    }

    public function test_a_reseller_portal_preflight_gets_an_allow_origin_header(): void
    {
        $response = $this->call('OPTIONS', '/api/reseller/dashboard', server: [
            'HTTP_ORIGIN' => 'http://localhost:3002',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
        ]);

        $response->assertHeader('Access-Control-Allow-Origin', 'http://localhost:3002');
    }
}
