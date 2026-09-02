<?php

namespace Tests\Feature\Console;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Guard-only coverage for app:chip-webhook-smoke-test. The command's
 * happy path hits the real CHIP API by design (it is a Smoke/ tool, not
 * automated), but its refusals are safety-critical — running it in the
 * wrong place would leave an orphan CHIP purchase + Order — so those are
 * locked here without any CHIP call.
 */
class ChipWebhookSmokeTestCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config([
            'services.chip.secret_key' => 'sk_test_x',
            'services.chip.brand_id' => 'brand-x',
            'services.chip.base_url' => 'https://gate.chip-in.asia/api/v1',
            'services.chip.callback_url' => 'https://smoke.trycloudflare.com/api/webhooks/chip',
        ]);
    }

    public function test_refuses_to_run_in_production(): void
    {
        $this->app['env'] = 'production';

        $this->artisan('app:chip-webhook-smoke-test')
            ->expectsOutputToContain('Refusing to run with APP_ENV=production')
            ->assertFailed();
    }

    public function test_refuses_when_chip_credentials_are_missing(): void
    {
        config(['services.chip.secret_key' => null]);

        $this->artisan('app:chip-webhook-smoke-test')
            ->expectsOutputToContain('CHIP_SECRET_KEY / CHIP_BRAND_ID not set')
            ->assertFailed();
    }

    public function test_refuses_when_the_callback_url_is_not_publicly_reachable(): void
    {
        config(['services.chip.callback_url' => 'https://kedairuncit-backend.test/api/webhooks/chip']);

        $this->artisan('app:chip-webhook-smoke-test')
            ->expectsOutputToContain('non-public host')
            ->assertFailed();
    }

    public function test_refuses_when_no_supplier_linked_package_exists(): void
    {
        // Credentials + a public callback URL are fine; there is just
        // nothing to order (RefreshDatabase — no packages seeded).
        $this->artisan('app:chip-webhook-smoke-test')
            ->expectsOutputToContain('No active supplier-linked Package found')
            ->assertFailed();
    }
}
