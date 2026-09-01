<?php

namespace Tests\Feature\Http\Controllers\Middleware;

use App\Models\AdminUser;
use App\Models\PaymentMethod;
use App\Services\Payment\PaymentGateway;
use App\Services\Payment\PaymentRequest;
use App\Services\Payment\PaymentResponse;
use App\Services\Payment\PaymentWebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

class PaymentMethodControllerTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): void
    {
        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'super_admin']));
    }

    public function test_regular_admin_is_forbidden(): void
    {
        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'admin']));

        $this->getJson('/api/middleware/payment-methods')->assertForbidden();
    }

    private function paymentMethod(array $overrides = []): PaymentMethod
    {
        return PaymentMethod::query()->create(array_merge([
            'channel_code' => 'AMBANK_FPX',
            'method_key' => 'ambank_fpx',
            'label' => 'AmBank',
            'category' => 'fpx',
            'gateway' => 'chip',
            'is_active' => false,
            'percentage_rate' => 0.0,
            'flat_fee_sen' => 210,
        ], $overrides));
    }

    /**
     * Real in-test fake bound through the same 'payment-gateway.chip'
     * container key PaymentGatewayFactory resolves — see
     * CheckoutControllerTest for the same convention.
     */
    private function bindGateway(bool $createSucceeds, ?string $errorCode = null, ?string $errorMessage = null): void
    {
        $gateway = new class($createSucceeds, $errorCode, $errorMessage) implements PaymentGateway
        {
            public function __construct(
                private readonly bool $createSucceeds,
                private readonly ?string $errorCode,
                private readonly ?string $errorMessage,
            ) {}

            public function createPayment(PaymentRequest $request): PaymentResponse
            {
                return $this->createSucceeds
                    ? PaymentResponse::success(['payment_request_id' => 'pr-channel-test'])
                    : PaymentResponse::failure($this->errorCode, $this->errorMessage);
            }

            public function getPayment(string $paymentRequestId): PaymentResponse
            {
                throw new RuntimeException('not used in this test');
            }

            public function verifyWebhookSignature(Request $request): bool
            {
                throw new RuntimeException('not used in this test');
            }

            public function parseWebhookEvent(array $payload): PaymentWebhookEvent
            {
                throw new RuntimeException('not used in this test');
            }
        };

        $this->app->bind('payment-gateway.chip', fn () => $gateway);
    }

    public function test_index_requires_authentication(): void
    {
        $response = $this->getJson('/api/middleware/payment-methods');

        $response->assertUnauthorized();
    }

    public function test_index_lists_all_channels(): void
    {
        $this->actingAsAdmin();
        $this->paymentMethod(['channel_code' => 'AMBANK_FPX']);
        $this->paymentMethod(['channel_code' => 'GRABPAY', 'category' => 'ewallet']);

        $response = $this->getJson('/api/middleware/payment-methods');

        $response->assertOk();
        $this->assertCount(2, $response->json());
    }

    public function test_index_filters_by_category(): void
    {
        $this->actingAsAdmin();
        $this->paymentMethod(['channel_code' => 'AMBANK_FPX', 'category' => 'fpx']);
        $this->paymentMethod(['channel_code' => 'GRABPAY', 'category' => 'ewallet']);

        $response = $this->getJson('/api/middleware/payment-methods?category=ewallet');

        $response->assertOk();
        $this->assertCount(1, $response->json());
        $this->assertSame('GRABPAY', $response->json()[0]['channel_code']);
    }

    public function test_update_status_activates_a_channel(): void
    {
        $this->actingAsAdmin();
        $paymentMethod = $this->paymentMethod();

        $response = $this->patchJson("/api/middleware/payment-methods/{$paymentMethod->id}/status", [
            'is_active' => true,
        ]);

        $response->assertOk();
        $this->assertTrue($paymentMethod->fresh()->is_active);
    }

    /**
     * ADR-022's newest addendum, decision 4: `method_key` identifies
     * the real-world payment method independent of gateway (e.g. "TnG
     * eWallet"), separate from the gateway-specific `channel_code`. At
     * most one row per `method_key` may be active at a time, so a
     * customer never sees two visually-identical buttons for the same
     * real method routed through two different processors.
     */
    public function test_update_status_blocks_activating_a_channel_whose_method_key_is_already_active_elsewhere(): void
    {
        $this->actingAsAdmin();
        $this->paymentMethod([
            'channel_code' => 'TOUCHNGO',
            'method_key' => 'tng',
            'gateway' => 'chip',
            'is_active' => true,
        ]);
        $chipTng = $this->paymentMethod([
            'channel_code' => 'CHIP_TNG',
            'method_key' => 'tng',
            'gateway' => 'chip',
            'is_active' => false,
        ]);

        $response = $this->patchJson("/api/middleware/payment-methods/{$chipTng->id}/status", [
            'is_active' => true,
        ]);

        $response->assertStatus(422);
        $this->assertFalse($chipTng->fresh()->is_active);
    }

    public function test_update_status_allows_activating_a_channel_whose_method_key_is_not_active_elsewhere(): void
    {
        $this->actingAsAdmin();
        $this->paymentMethod([
            'channel_code' => 'TOUCHNGO',
            'method_key' => 'tng',
            'gateway' => 'chip',
            'is_active' => false,
        ]);
        $chipTng = $this->paymentMethod([
            'channel_code' => 'CHIP_TNG',
            'method_key' => 'tng',
            'gateway' => 'chip',
            'is_active' => false,
        ]);

        $response = $this->patchJson("/api/middleware/payment-methods/{$chipTng->id}/status", [
            'is_active' => true,
        ]);

        $response->assertOk();
        $this->assertTrue($chipTng->fresh()->is_active);
    }

    public function test_update_status_allows_deactivating_even_when_another_row_shares_the_method_key(): void
    {
        $this->actingAsAdmin();
        $activeTng = $this->paymentMethod([
            'channel_code' => 'TOUCHNGO',
            'method_key' => 'tng',
            'gateway' => 'chip',
            'is_active' => true,
        ]);
        $this->paymentMethod([
            'channel_code' => 'CHIP_TNG',
            'method_key' => 'tng',
            'gateway' => 'chip',
            'is_active' => false,
        ]);

        $response = $this->patchJson("/api/middleware/payment-methods/{$activeTng->id}/status", [
            'is_active' => false,
        ]);

        $response->assertOk();
        $this->assertFalse($activeTng->fresh()->is_active);
    }

    public function test_update_fee_changes_the_stored_rate(): void
    {
        $this->actingAsAdmin();
        $paymentMethod = $this->paymentMethod();

        $response = $this->patchJson("/api/middleware/payment-methods/{$paymentMethod->id}/fee", [
            'percentage_rate' => 2.5,
            'flat_fee_sen' => 150,
        ]);

        $response->assertOk();
        $paymentMethod->refresh();
        $this->assertSame('2.50', (string) $paymentMethod->percentage_rate);
        $this->assertSame(150, $paymentMethod->flat_fee_sen);
    }

    public function test_test_action_records_success(): void
    {
        $this->actingAsAdmin();
        $this->bindGateway(true);
        $paymentMethod = $this->paymentMethod();

        $response = $this->postJson("/api/middleware/payment-methods/{$paymentMethod->id}/test");

        $response->assertOk();
        $response->assertJsonPath('last_test_result', 'success');
        $this->assertNotNull($paymentMethod->fresh()->last_tested_at);
    }

    public function test_test_action_records_the_exact_failure_message(): void
    {
        $this->actingAsAdmin();
        $this->bindGateway(false, 'INVALID_MERCHANT_SETTINGS', 'Channel not enabled for this account');
        $paymentMethod = $this->paymentMethod();

        $response = $this->postJson("/api/middleware/payment-methods/{$paymentMethod->id}/test");

        $response->assertOk();
        $this->assertSame(
            'failed: [INVALID_MERCHANT_SETTINGS] Channel not enabled for this account',
            $paymentMethod->fresh()->last_test_result,
        );
    }

    /**
     * ADR-014: the unfiltered index() listing is cached (60s TTL) and
     * invalidated on every write — proven end-to-end via updateStatus(),
     * same approach as GameControllerTest's equivalent case.
     */
    public function test_index_caches_the_unfiltered_listing_and_invalidates_on_status_update(): void
    {
        $paymentMethod = $this->paymentMethod(['is_active' => false]);
        $this->actingAsAdmin();

        $this->getJson('/api/middleware/payment-methods')->assertJsonPath('0.is_active', false);

        $this->patchJson("/api/middleware/payment-methods/{$paymentMethod->id}/status", ['is_active' => true])
            ->assertOk();

        $this->getJson('/api/middleware/payment-methods')->assertJsonPath('0.is_active', true);
    }

    public function test_index_does_not_cache_a_filtered_category_request(): void
    {
        $this->paymentMethod(['channel_code' => 'AMBANK_FPX', 'category' => 'fpx']);
        $this->paymentMethod(['channel_code' => 'GRABPAY', 'category' => 'ewallet']);
        $this->actingAsAdmin();

        $this->getJson('/api/middleware/payment-methods')->assertJsonCount(2);

        $filtered = $this->getJson('/api/middleware/payment-methods?category=ewallet');
        $filtered->assertJsonCount(1);
        $filtered->assertJsonPath('0.channel_code', 'GRABPAY');
    }

    /**
     * Regression test for a real bug found live, 2026-07-26 (same bug
     * class as GameControllerTest's/CatalogControllerTest's/
     * HeroSlideControllerTest's own regression tests, found here
     * during a docs-accuracy sweep rather than the original bugfix
     * pass): this app's default `database` cache store corrupts a
     * cached value that still has real objects nested inside it on
     * the next read. PHPUnit's `array` cache driver (phpunit.xml)
     * never serializes at all, so it can't catch this — this test
     * forces the real `database` store.
     */
    public function test_index_survives_a_real_database_cache_round_trip(): void
    {
        config(['cache.default' => 'database']);
        $this->paymentMethod(['channel_code' => 'AMBANK_FPX']);
        $this->actingAsAdmin();

        $first = $this->getJson('/api/middleware/payment-methods');
        $first->assertOk();
        $this->assertSame('AMBANK_FPX', $first->json()[0]['channel_code']);

        $cached = Cache::store('database')->get('catalog.payment_methods.index');
        $this->assertIsArray($cached);

        $second = $this->getJson('/api/middleware/payment-methods');
        $second->assertOk();
        $this->assertSame('AMBANK_FPX', $second->json()[0]['channel_code']);
    }
}
