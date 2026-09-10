<?php

namespace Tests\Feature\Services\Reseller\Webhook;

use App\Models\Order;
use App\Models\Reseller;
use App\Models\ResellerWebhook;
use App\Models\ResellerWebhookDelivery;
use App\Services\Reseller\Webhook\ResellerWebhookService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ADR-084 PR-3 decision 4: the create/rotate/remove seam for a
 * Reseller's single delivery-webhook endpoint. `secret` is encrypted at
 * rest (not hashed) — every outbound delivery re-derives it to sign.
 */
class ResellerWebhookServiceTest extends TestCase
{
    use RefreshDatabase;

    private function reseller(): Reseller
    {
        return Reseller::query()->create(['business_name' => 'Wallet Reseller', 'is_active' => true]);
    }

    private function service(): ResellerWebhookService
    {
        return app(ResellerWebhookService::class);
    }

    public function test_set_endpoint_creates_the_row_and_returns_the_secret_once(): void
    {
        $reseller = $this->reseller();

        $result = $this->service()->setEndpoint($reseller, 'https://example.test/hook');

        $this->assertStringStartsWith('pgwh_', $result['secret']);
        $this->assertSame('https://example.test/hook', $result['webhook']->url);
        $this->assertTrue($result['webhook']->is_active);
        $this->assertSame(1, ResellerWebhook::query()->where('reseller_id', $reseller->id)->count());
    }

    public function test_secret_is_encrypted_at_rest_not_plaintext_or_hashed(): void
    {
        $reseller = $this->reseller();
        $secret = $this->service()->setEndpoint($reseller, 'https://example.test/hook')['secret'];

        $stored = DB::table('reseller_webhooks')->where('reseller_id', $reseller->id)->value('secret');

        $this->assertNotSame($secret, $stored);
        $this->assertNotSame(hash('sha256', $secret), $stored);
        // Round-trips through the model cast back to the original.
        $this->assertSame($secret, $reseller->fresh()->webhook->secret);
    }

    public function test_a_second_set_endpoint_updates_the_url_and_keeps_the_secret(): void
    {
        $reseller = $this->reseller();
        $first = $this->service()->setEndpoint($reseller, 'https://one.test/hook');

        $second = $this->service()->setEndpoint($reseller->fresh(), 'https://two.test/hook');

        $this->assertNull($second['secret']);
        $hook = $reseller->fresh()->webhook;
        $this->assertSame('https://two.test/hook', $hook->url);
        $this->assertSame($first['secret'], $hook->secret);
    }

    public function test_rotate_secret_replaces_it(): void
    {
        $reseller = $this->reseller();
        $created = $this->service()->setEndpoint($reseller, 'https://one.test/hook');

        $rotated = $this->service()->rotateSecret($created['webhook']);

        $this->assertNotSame($created['secret'], $rotated);
        $this->assertSame($rotated, $reseller->fresh()->webhook->secret);
    }

    public function test_set_active_toggles_without_touching_the_secret(): void
    {
        $reseller = $this->reseller();
        $created = $this->service()->setEndpoint($reseller, 'https://one.test/hook');

        $this->service()->setActive($created['webhook'], false);

        $hook = $reseller->fresh()->webhook;
        $this->assertFalse($hook->is_active);
        $this->assertSame($created['secret'], $hook->secret);
    }

    public function test_remove_deletes_the_endpoint_but_keeps_the_delivery_log(): void
    {
        $reseller = $this->reseller();
        $created = $this->service()->setEndpoint($reseller, 'https://one.test/hook');
        $order = $this->order($reseller);
        ResellerWebhookDelivery::query()->create([
            'reseller_id' => $reseller->id, 'order_id' => $order->id,
            'event' => 'order.delivered', 'event_id' => 'e1', 'payload' => [],
        ]);

        $this->service()->remove($created['webhook']);

        $this->assertNull($reseller->fresh()->webhook);
        $this->assertSame(1, ResellerWebhookDelivery::query()->where('reseller_id', $reseller->id)->count());
    }

    public function test_sign_matches_the_hmac_sha256_scheme(): void
    {
        $signature = ResellerWebhookService::sign('shhh', '{"a":1}');

        $this->assertSame('sha256='.hash_hmac('sha256', '{"a":1}', 'shhh'), $signature);
    }

    private function order(Reseller $reseller): Order
    {
        return Order::query()->create([
            'affiliate_id' => $this->primaryAffiliate()->id,
            'wallet_reseller_id' => $reseller->id,
            'order_number' => 'PG-'.uniqid(),
            'customer_email' => 'buyer@example.test',
            'player_id' => '123456',
            'cost_price' => 900, 'standard_selling_price' => 900, 'selling_price' => 1000,
            'transaction_fee' => 0, 'final_amount' => 1000,
            'platform_profit' => 100, 'affiliate_profit' => 0,
            'payment_status' => 'paid', 'delivery_status' => 'delivered',
        ]);
    }
}
