<?php

namespace Tests\Feature\Http\Controllers\Webhooks;

use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\Supplier;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\PaymentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-069 decision 9 — the inbound Digiflazz webhook: the two auth
 * gates (signature + config-driven IP allowlist), the order-match
 * guards (unknown ref, wrong supplier, sku mismatch), outcome routing
 * (Sukses -> Delivered, Gagal -> Failed), the balance fold-in, and
 * the already-finalized no-op that a duplicate delivery / webhook-vs-
 * poll race lands on.
 */
class DigiflazzWebhookControllerTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'wh-secret-abc';

    private const DIGIFLAZZ_IP = '52.74.250.133';

    private function digiflazzSupplier(array $config = []): Supplier
    {
        return Supplier::query()->firstOrCreate(
            ['slug' => 'digiflazz'],
            [
                'name' => 'Digiflazz',
                'currency' => 'IDR',
                'balance' => 5000,
                'api_config' => array_merge(['webhook_secret' => self::SECRET], $config),
            ],
        );
    }

    private function pendingOrder(array $overrides = []): Order
    {
        $supplierId = $overrides['supplier_id'] ?? $this->digiflazzSupplier()->id;

        return Order::query()->create(array_merge([
            'reseller_id' => $this->primaryReseller()->id,
            'order_number' => 'KRS-DGF-1',
            'reference_number' => 'REF-DGF-1',
            'customer_email' => 'buyer@example.com',
            'player_id' => '900000001',
            'server_id' => '1234',
            'supplier_id' => $supplierId,
            'supplier_product_ref' => 'mlbb5',
            'cost_price' => 900,
            'standard_selling_price' => 900,
            'selling_price' => 1000,
            'transaction_fee' => 100,
            'final_amount' => 1100,
            'platform_profit' => 150,
            'reseller_profit' => 0,
            'payment_status' => PaymentStatus::Paid->value,
            'delivery_status' => DeliveryStatus::Pending->value,
            'payment_gateway' => 'chip',
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $data  the `data` object of the payload
     */
    private function sendWebhook(array $data, array $opts = []): \Illuminate\Testing\TestResponse
    {
        $secret = $opts['secret'] ?? self::SECRET;
        $ip = $opts['ip'] ?? self::DIGIFLAZZ_IP;
        $event = $opts['event'] ?? 'update';
        $userAgent = $opts['user_agent'] ?? 'Digiflazz-Hookshot';

        $body = json_encode(['data' => $data], JSON_UNESCAPED_SLASHES);

        $server = [
            'REMOTE_ADDR' => $ip,
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_USER_AGENT' => $userAgent,
        ];

        if ($event !== null) {
            $server['HTTP_X_DIGIFLAZZ_EVENT'] = $event;
        }

        if (! array_key_exists('no_signature', $opts)) {
            $server['HTTP_X_HUB_SIGNATURE'] = 'sha1='.hash_hmac('sha1', $body, $secret);
        }

        return $this->call('POST', '/api/webhooks/digiflazz', [], [], [], $server, $body);
    }

    private function suksesData(Order $order, array $overrides = []): array
    {
        return array_merge([
            'ref_id' => $order->reference_number,
            'customer_no' => '900000001.1234',
            'buyer_sku_code' => $order->supplier_product_ref,
            'status' => 'Sukses',
            'rc' => '00',
            'sn' => 'SN-999888',
            'price' => 4500,
            'buyer_last_saldo' => 3200,
            'message' => 'Transaksi Sukses',
        ], $overrides);
    }

    public function test_a_signed_sukses_callback_finalizes_the_pending_order_as_delivered(): void
    {
        $order = $this->pendingOrder();

        $this->sendWebhook($this->suksesData($order))
            ->assertOk()
            ->assertJson(['message' => 'ok']);

        $order->refresh();
        $this->assertSame(DeliveryStatus::Delivered, $order->delivery_status);
        $this->assertSame('SN-999888', $order->supplier_ref);
        $this->assertNotNull($order->delivered_at);

        $this->assertDatabaseHas('ledger_entries', [
            'reference_type' => 'order',
            'reference_id' => $order->id,
            'type' => 'order_profit',
            'amount' => 150,
        ]);
    }

    public function test_a_sukses_callback_refreshes_the_supplier_balance_from_buyer_last_saldo(): void
    {
        $order = $this->pendingOrder();

        $this->sendWebhook($this->suksesData($order, ['buyer_last_saldo' => 1234]))->assertOk();

        $this->assertEquals(1234, $this->digiflazzSupplier()->fresh()->balance);
    }

    public function test_a_gagal_callback_finalizes_the_pending_order_as_failed(): void
    {
        $order = $this->pendingOrder();

        $this->sendWebhook($this->suksesData($order, ['status' => 'Gagal', 'rc' => '02', 'sn' => null]))
            ->assertOk();

        $this->assertSame(DeliveryStatus::Failed, $order->fresh()->delivery_status);
        $this->assertDatabaseMissing('ledger_entries', [
            'reference_id' => $order->id,
            'type' => 'order_profit',
        ]);
    }

    public function test_a_still_pending_status_is_acknowledged_without_finalizing(): void
    {
        $order = $this->pendingOrder();

        $this->sendWebhook($this->suksesData($order, ['status' => 'Pending', 'rc' => '03']))
            ->assertOk()
            ->assertJson(['message' => 'acknowledged']);

        $this->assertSame(DeliveryStatus::Pending, $order->fresh()->delivery_status);
    }

    public function test_a_create_event_is_handled_the_same_as_update(): void
    {
        $order = $this->pendingOrder();

        $this->sendWebhook($this->suksesData($order), ['event' => 'create'])->assertOk();

        $this->assertSame(DeliveryStatus::Delivered, $order->fresh()->delivery_status);
    }

    public function test_a_resend_event_is_ignored(): void
    {
        $order = $this->pendingOrder();

        $this->sendWebhook($this->suksesData($order), ['event' => 'resend'])
            ->assertOk()
            ->assertJson(['message' => 'ignored']);

        $this->assertSame(DeliveryStatus::Pending, $order->fresh()->delivery_status);
    }

    public function test_a_second_delivery_of_the_same_callback_is_a_no_op(): void
    {
        $order = $this->pendingOrder();

        $this->sendWebhook($this->suksesData($order))->assertOk();
        $this->sendWebhook($this->suksesData($order))
            ->assertOk()
            ->assertJson(['message' => 'already finalized']);

        // Exactly one profit credit, not two.
        $this->assertSame(1, LedgerEntry::query()
            ->where('reference_id', $order->id)
            ->where('type', 'order_profit')
            ->where('owner_type', 'platform')
            ->count());
    }

    public function test_a_webhook_after_the_reconcile_poll_already_finalized_the_order_is_a_no_op(): void
    {
        $order = $this->pendingOrder();

        // The poll backup (CheckSupplierDeliveryJob) got there first.
        app(\App\Services\Fulfillment\OrderFulfillmentService::class)->finalizePendingDelivery(
            $order,
            \App\Services\Supplier\SupplierOutcome::Success,
            'SN-FROM-POLL',
            ['status' => 'Sukses'],
        );

        $this->sendWebhook($this->suksesData($order))
            ->assertOk()
            ->assertJson(['message' => 'already finalized']);

        $order->refresh();
        $this->assertSame(DeliveryStatus::Delivered, $order->delivery_status);
        $this->assertSame('SN-FROM-POLL', $order->supplier_ref);
        $this->assertSame(1, LedgerEntry::query()
            ->where('reference_id', $order->id)
            ->where('type', 'order_profit')
            ->where('owner_type', 'platform')
            ->count());
    }

    public function test_a_bad_signature_is_rejected_401(): void
    {
        $order = $this->pendingOrder();

        $this->sendWebhook($this->suksesData($order), ['secret' => 'wrong-secret'])
            ->assertStatus(401);

        $this->assertSame(DeliveryStatus::Pending, $order->fresh()->delivery_status);
    }

    public function test_a_missing_signature_header_is_rejected_401(): void
    {
        $order = $this->pendingOrder();

        $this->sendWebhook($this->suksesData($order), ['no_signature' => true])
            ->assertStatus(401);
    }

    public function test_a_request_from_an_ip_outside_the_allowlist_is_rejected_403(): void
    {
        $order = $this->pendingOrder();

        $this->sendWebhook($this->suksesData($order), ['ip' => '203.0.113.9'])
            ->assertStatus(403);

        $this->assertSame(DeliveryStatus::Pending, $order->fresh()->delivery_status);
    }

    public function test_the_ip_allowlist_is_config_driven(): void
    {
        config()->set('services.digiflazz.webhook_ips', ['203.0.113.9']);
        $order = $this->pendingOrder();

        $this->sendWebhook($this->suksesData($order), ['ip' => '203.0.113.9'])->assertOk();

        $this->assertSame(DeliveryStatus::Delivered, $order->fresh()->delivery_status);
    }

    public function test_a_callback_is_rejected_503_when_no_webhook_secret_is_configured(): void
    {
        $this->digiflazzSupplier(['webhook_secret' => '']);
        $order = $this->pendingOrder();

        $this->sendWebhook($this->suksesData($order))->assertStatus(503);
    }

    public function test_an_unknown_ref_id_is_404(): void
    {
        $this->digiflazzSupplier();

        $this->sendWebhook($this->suksesData($this->pendingOrder(), ['ref_id' => 'REF-DOES-NOT-EXIST']))
            ->assertStatus(404);
    }

    public function test_a_callback_for_an_order_on_another_supplier_is_rejected_409(): void
    {
        $gamevion = Supplier::query()->create([
            'name' => 'Gamevion', 'slug' => 'gamevion', 'currency' => 'MYR', 'api_config' => [],
        ]);
        $this->digiflazzSupplier();
        $order = $this->pendingOrder(['supplier_id' => $gamevion->id, 'reference_number' => 'REF-GV-1']);

        $this->sendWebhook($this->suksesData($order))->assertStatus(409);

        $this->assertSame(DeliveryStatus::Pending, $order->fresh()->delivery_status);
    }

    public function test_a_buyer_sku_code_mismatch_is_rejected_409(): void
    {
        $order = $this->pendingOrder();

        $this->sendWebhook($this->suksesData($order, ['buyer_sku_code' => 'some-other-sku']))
            ->assertStatus(409);

        $this->assertSame(DeliveryStatus::Pending, $order->fresh()->delivery_status);
    }
}
