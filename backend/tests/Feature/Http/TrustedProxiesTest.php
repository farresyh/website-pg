<?php

namespace Tests\Feature\Http;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * `bootstrap/app.php` trusts exactly Cloudflare's proxy ranges (not `*`)
 * so that once `api.pekangame.space` is proxied by Cloudflare
 * (ADR-020 decision 8 / the 2026-09-06 cutover) `$request->ip()` still
 * resolves the real visitor — the value the per-IP rate limiters, the
 * FRAUD-4 `CheckoutVelocityGuard`, and the auth/impersonation audit
 * logs all key on. A regression to `at: '*'` (or dropping a range)
 * would let any caller spoof `X-Forwarded-For`.
 */
class TrustedProxiesTest extends TestCase
{
    private function ipRoute(): void
    {
        Route::get('/__test__/client-ip', fn () => ['ip' => request()->ip()]);
    }

    public function test_real_client_ip_is_read_from_x_forwarded_for_when_the_peer_is_a_cloudflare_edge(): void
    {
        $this->ipRoute();

        // 172.64.0.0/13 is a published Cloudflare range; Cloudflare puts
        // the true visitor IP as the right-most X-Forwarded-For entry.
        $this->call('GET', '/__test__/client-ip', server: [
            'REMOTE_ADDR' => '172.64.10.20',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.77',
        ])->assertOk()->assertJson(['ip' => '203.0.113.77']);
    }

    public function test_x_forwarded_for_is_ignored_when_the_peer_is_not_a_cloudflare_edge(): void
    {
        $this->ipRoute();

        // A direct caller (not behind Cloudflare) cannot spoof its IP by
        // sending its own X-Forwarded-For header.
        $this->call('GET', '/__test__/client-ip', server: [
            'REMOTE_ADDR' => '198.51.100.5',
            'HTTP_X_FORWARDED_FOR' => '10.0.0.1',
        ])->assertOk()->assertJson(['ip' => '198.51.100.5']);
    }

    public function test_forwarded_proto_from_a_cloudflare_edge_marks_the_request_secure(): void
    {
        Route::get('/__test__/secure', fn () => ['secure' => request()->secure()]);

        $this->call('GET', '/__test__/secure', server: [
            'REMOTE_ADDR' => '104.16.5.5',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ])->assertOk()->assertJson(['secure' => true]);
    }
}
