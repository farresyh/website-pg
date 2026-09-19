<?php

namespace Tests\Feature\Providers;

use App\Models\PaymentGateway;
use App\Services\Payment\Chip\ChipGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * ADR-110 PR-C — the `payment-gateway.chip` container binding
 * (`AppServiceProvider`) must never break checkout regardless of
 * deploy-vs-founder-fills-the-form ordering: it prefers the encrypted
 * `payment_gateways` DB row, but falls back to `config('services.chip')`
 * (`.env`-sourced) per-field whenever the DB row or a specific key
 * within it is missing.
 */
class ChipGatewayBindingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * `services.chip.secret_key` is set explicitly here rather than
     * read from whatever's ambient — CI has no real CHIP key
     * configured (unlike a local dev `.env`), so asserting against the
     * environment's own current value would silently pass locally and
     * fail in CI (found live: an empty ambient value also hits a
     * `withToken('')` trim() quirk where the real header becomes
     * `'Bearer'` with no trailing space, not `'Bearer '`).
     */
    public function test_falls_back_to_env_config_when_no_db_row_exists(): void
    {
        config(['services.chip.secret_key' => 'sk_env_fallback']);
        Http::fake(['gate.chip-in.asia/*' => Http::response(['id' => 'x', 'status' => 'paid', 'purchase' => ['total' => 100]], 200)]);

        /** @var ChipGateway $gateway */
        $gateway = app('payment-gateway.chip');
        $gateway->getPayment('purchase-1');

        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer sk_env_fallback'));
    }

    public function test_prefers_db_credentials_over_env_config_when_a_row_exists(): void
    {
        PaymentGateway::query()->create([
            'gateway_key' => 'chip',
            'api_config' => ['secret_key' => 'sk_from_db', 'brand_id' => 'brand-from-db', 'base_url' => 'https://gate.chip-in.asia/api/v1'],
        ]);
        Http::fake(['gate.chip-in.asia/*' => Http::response(['id' => 'x', 'status' => 'paid', 'purchase' => ['total' => 100]], 200)]);

        /** @var ChipGateway $gateway */
        $gateway = app('payment-gateway.chip');
        $gateway->getPayment('purchase-1');

        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer sk_from_db'));
    }

    /**
     * A partial DB row (e.g. the founder saved `secret_key` but the
     * `base_url` key was never included) must not silently resolve to
     * an empty base URL — each field falls back to `config()`
     * independently.
     */
    public function test_falls_back_per_field_when_the_db_row_is_missing_a_key(): void
    {
        config(['services.chip.base_url' => 'https://gate.chip-in.asia/api/v1']);
        PaymentGateway::query()->create([
            'gateway_key' => 'chip',
            'api_config' => ['secret_key' => 'sk_from_db'],
        ]);
        Http::fake(['gate.chip-in.asia/*' => Http::response(['id' => 'x', 'status' => 'paid', 'purchase' => ['total' => 100]], 200)]);

        /** @var ChipGateway $gateway */
        $gateway = app('payment-gateway.chip');
        $gateway->getPayment('purchase-1');

        Http::assertSent(fn ($request) => $request->url() === 'https://gate.chip-in.asia/api/v1/purchases/purchase-1/'
            && $request->hasHeader('Authorization', 'Bearer sk_from_db'));
    }
}
