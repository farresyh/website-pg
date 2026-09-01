<?php

namespace Tests\Feature\Http\Controllers;

use App\Models\AdminUser;
use App\Models\PaymentMethod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Public, no-auth payment-method listing (ADR-011, same reasoning as
 * CatalogController/HeroSlideController) — ADR-022's newest addendum,
 * decision 5. Replaces storefront/src/lib/placeholder-data.ts's
 * hardcoded PLACEHOLDER_PAYMENT_CHANNELS, and is gateway-agnostic on
 * purpose: `gateway`/`method_key` never leave the backend, since which
 * processor handles a channel is an internal routing detail, not
 * something a customer chooses or needs to see.
 */
class PaymentMethodCatalogControllerTest extends TestCase
{
    use RefreshDatabase;

    private function make(array $overrides = []): PaymentMethod
    {
        return PaymentMethod::query()->create(array_merge([
            'channel_code' => 'AMBANK_FPX',
            'method_key' => 'ambank_fpx',
            'label' => 'AmBank',
            'category' => 'fpx',
            'gateway' => 'chip',
            'is_active' => true,
            'percentage_rate' => 0.0,
            'flat_fee_sen' => 210,
        ], $overrides));
    }

    public function test_index_lists_only_active_channels(): void
    {
        $this->make(['channel_code' => 'AMBANK_FPX', 'is_active' => true]);
        $this->make(['channel_code' => 'CIMB_FPX', 'method_key' => 'cimb_fpx', 'is_active' => false]);

        $response = $this->getJson('/api/catalog/payment-methods');

        $response->assertOk();
        $this->assertSame(['AMBANK_FPX'], collect($response->json())->pluck('channel_code')->all());
    }

    public function test_index_never_exposes_gateway_or_method_key(): void
    {
        $this->make();

        $response = $this->getJson('/api/catalog/payment-methods');

        $response->assertOk();
        $channel = $response->json()[0];
        $this->assertSame(['channel_code', 'label', 'category'], array_keys($channel));
    }

    public function test_index_orders_by_category_then_label(): void
    {
        $this->make(['channel_code' => 'GRABPAY', 'method_key' => 'grabpay', 'category' => 'ewallet', 'label' => 'GrabPay']);
        $this->make(['channel_code' => 'AMBANK_FPX', 'method_key' => 'ambank_fpx', 'category' => 'fpx', 'label' => 'AmBank']);

        $response = $this->getJson('/api/catalog/payment-methods');

        $response->assertOk();
        // 'ewallet' sorts before 'fpx' alphabetically.
        $this->assertSame(['GrabPay', 'AmBank'], collect($response->json())->pluck('label')->all());
    }

    /**
     * The real invalidation path: an admin activating a channel via
     * Middleware\PaymentMethodController::updateStatus() must be
     * reflected here without waiting out the 60s TTL — same
     * invalidate-on-write discipline as GameController calling
     * CatalogController::forgetIndexCache().
     */
    public function test_index_invalidates_when_an_admin_activates_a_channel(): void
    {
        $paymentMethod = $this->make(['is_active' => false]);
        $this->getJson('/api/catalog/payment-methods')->assertJsonCount(0);

        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'super_admin']));
        $this->patchJson("/api/middleware/payment-methods/{$paymentMethod->id}/status", ['is_active' => true])
            ->assertOk();

        $this->getJson('/api/catalog/payment-methods')->assertJsonCount(1);
    }

    /**
     * Same real bug class as CatalogController's/HeroSlideController's
     * own regression tests: this app's `database` cache store
     * corrupts a raw Eloquent Collection/Model on the next read.
     */
    public function test_index_survives_a_real_database_cache_round_trip(): void
    {
        config(['cache.default' => 'database']);
        $this->make();

        $first = $this->getJson('/api/catalog/payment-methods');
        $first->assertOk();

        $cached = Cache::store('database')->get('catalog.public.payment_methods');
        $this->assertIsArray($cached);

        $second = $this->getJson('/api/catalog/payment-methods');
        $second->assertOk();
        $this->assertSame('AMBANK_FPX', $second->json()[0]['channel_code']);
    }
}
