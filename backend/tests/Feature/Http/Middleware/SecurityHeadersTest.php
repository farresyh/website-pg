<?php

namespace Tests\Feature\Http\Middleware;

use Tests\TestCase;

/**
 * PRD §16 item 110: the API sends HSTS (HTTPS only), Referrer-Policy and
 * Permissions-Policy on every response, errors included.
 */
class SecurityHeadersTest extends TestCase
{
    public function test_an_https_response_carries_hsts_referrer_and_permissions_policy(): void
    {
        $this->get('https://localhost/api/catalog/does-not-exist')
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
    }

    public function test_a_plain_http_response_never_sends_hsts(): void
    {
        $this->get('http://localhost/api/catalog/does-not-exist')
            ->assertHeaderMissing('Strict-Transport-Security')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    }
}
