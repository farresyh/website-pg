<?php

namespace Tests\Feature\Http\Controllers\Middleware;

use App\Models\AdminUser;
use App\Models\PaymentGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ADR-110 PR-C — CHIP credential `.env`→DB migration.
 */
class PaymentGatewayControllerTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): AdminUser
    {
        $admin = AdminUser::factory()->create(['role' => 'super_admin']);
        Sanctum::actingAs($admin);

        return $admin;
    }

    public function test_regular_admin_is_forbidden(): void
    {
        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'admin']));

        $this->getJson('/api/middleware/payment-gateways')->assertForbidden();
    }

    public function test_index_never_leaks_secret_key_and_reports_status(): void
    {
        $this->actingAsAdmin();
        PaymentGateway::query()->create([
            'gateway_key' => 'chip',
            'api_config' => ['secret_key' => 'sk_live_super_secret', 'brand_id' => 'brand-uuid-123', 'base_url' => 'https://gate.chip-in.asia/api/v1'],
        ]);

        $response = $this->getJson('/api/middleware/payment-gateways')->assertOk();

        $response->assertJsonMissing(['api_config']);
        $body = $response->json();
        $this->assertStringNotContainsString('sk_live_super_secret', $response->getContent());
        $this->assertSame('chip', $body[0]['gateway_key']);
        $this->assertTrue($body[0]['has_credentials']);
        $this->assertSame(['secret_key'], $body[0]['configured_secret_keys']);
        $this->assertSame('brand-uuid-123', $body[0]['visible_config']['brand_id']);
        $this->assertSame('https://gate.chip-in.asia/api/v1', $body[0]['visible_config']['base_url']);
        $this->assertArrayNotHasKey('secret_key', $body[0]['visible_config']);
    }

    public function test_update_creates_the_row_when_it_does_not_exist_yet(): void
    {
        $this->actingAsAdmin();
        Http::fake(['gate.chip-in.asia/*' => Http::response('"pem-key"', 200)]);

        $this->assertSame(0, PaymentGateway::query()->count());

        $response = $this->putJson('/api/middleware/payment-gateways/chip', [
            'api_config' => ['secret_key' => 'sk_live_new', 'brand_id' => 'brand-uuid-123', 'base_url' => 'https://gate.chip-in.asia/api/v1'],
        ])->assertOk();

        $this->assertSame(1, PaymentGateway::query()->count());
        $gateway = PaymentGateway::query()->where('gateway_key', 'chip')->first();
        $this->assertSame('sk_live_new', $gateway->api_config['secret_key']);
        $this->assertTrue($response->json('connection_probe.connection_ok'));
    }

    /**
     * ADR-046 decision 3's own discipline, mirrored here: a blank/omitted
     * secret in the request must never be read as "clear it" — only
     * the keys actually present in the request overwrite their
     * counterpart, every other existing key survives.
     */
    public function test_update_merges_onto_the_existing_config_without_clearing_untouched_keys(): void
    {
        $this->actingAsAdmin();
        Http::fake(['gate.chip-in.asia/*' => Http::response('"pem-key"', 200)]);

        PaymentGateway::query()->create([
            'gateway_key' => 'chip',
            'api_config' => ['secret_key' => 'sk_live_old', 'brand_id' => 'brand-uuid-123', 'base_url' => 'https://gate.chip-in.asia/api/v1'],
        ]);

        $this->putJson('/api/middleware/payment-gateways/chip', [
            'api_config' => ['brand_id' => 'brand-uuid-456'],
        ])->assertOk();

        $gateway = PaymentGateway::query()->where('gateway_key', 'chip')->first();
        $this->assertSame('sk_live_old', $gateway->api_config['secret_key']);
        $this->assertSame('brand-uuid-456', $gateway->api_config['brand_id']);
        $this->assertSame('https://gate.chip-in.asia/api/v1', $gateway->api_config['base_url']);
    }

    public function test_update_probes_the_connection_against_the_just_saved_config(): void
    {
        $this->actingAsAdmin();
        Http::fake(['gate.chip-in.asia/*' => Http::response(null, 401)]);

        $response = $this->putJson('/api/middleware/payment-gateways/chip', [
            'api_config' => ['secret_key' => 'sk_bad', 'brand_id' => 'brand-uuid-123', 'base_url' => 'https://gate.chip-in.asia/api/v1'],
        ])->assertOk();

        $this->assertFalse($response->json('connection_probe.connection_ok'));
        $this->assertSame('HTTP 401', $response->json('connection_probe.error'));

        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer sk_bad')
            && $request->url() === 'https://gate.chip-in.asia/api/v1/public_key/');
    }

    public function test_update_reports_connection_not_ok_when_no_secret_key_is_configured(): void
    {
        $this->actingAsAdmin();

        $response = $this->putJson('/api/middleware/payment-gateways/chip', [
            'api_config' => ['brand_id' => 'brand-uuid-123'],
        ])->assertOk();

        $this->assertFalse($response->json('connection_probe.connection_ok'));
        Http::assertNothingSent();
    }
}
