<?php

namespace Tests\Feature\Console;

use App\Models\PaymentGateway;
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
            ->expectsOutputToContain('No CHIP secret_key/brand_id configured')
            ->assertFailed();
    }

    /**
     * ADR-110 PR-C — resolves identically to real production traffic:
     * a `payment_gateways` DB row (even a partial one) takes priority
     * over `.env`, per-field.
     */
    public function test_resolves_credentials_from_the_db_row_when_present(): void
    {
        PaymentGateway::query()->create([
            'gateway_key' => 'chip',
            'api_config' => ['secret_key' => 'sk_from_db', 'brand_id' => 'brand-from-db', 'base_url' => 'https://gate.chip-in.asia/api/v1'],
        ]);
        config(['services.chip.secret_key' => null, 'services.chip.brand_id' => null]);

        // Still fails downstream (no supplier-linked package) — proves
        // the credential pre-check passed using the DB row alone.
        $this->artisan('app:chip-webhook-smoke-test')
            ->expectsOutputToContain('No active supplier-linked Package found')
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
