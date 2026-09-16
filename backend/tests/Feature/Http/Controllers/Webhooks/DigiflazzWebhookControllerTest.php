<?php

namespace Tests\Feature\Http\Controllers\Webhooks;

use App\Models\Game;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\OrderDeliveryLeg;
use App\Models\Package;
use App\Models\Supplier;
use App\Services\Fulfillment\OrderFulfillmentService;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\PaymentStatus;
use App\Services\Supplier\SupplierOutcome;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
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
            'affiliate_id' => $this->primaryAffiliate()->id,
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
            'affiliate_profit' => 0,
            'payment_status' => PaymentStatus::Paid->value,
            'delivery_status' => DeliveryStatus::Pending->value,
            'payment_gateway' => 'chip',
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $data  the `data` object of the payload
     */
    private function sendWebhook(array $data, array $opts = []): TestResponse
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
        // The raw webhook `data` object is stored verbatim as the
        // supplier_response audit trail (informational — `price` is
        // never used to compute anything, exactly as the synchronous
        // path never trusts the supplier's echoed price).
        $this->assertSame('Sukses', $order->supplier_response['status']);
        $this->assertSame('SN-999888', $order->supplier_response['sn']);

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

    /** ADR-098 — rc=44 (Saldo tidak cukup) has Terbentuk Transaksi=Tidak, genuinely retriable, so it stays a plain Failed. */
    public function test_a_gagal_callback_finalizes_the_pending_order_as_failed(): void
    {
        $order = $this->pendingOrder();

        $this->sendWebhook($this->suksesData($order, ['status' => 'Gagal', 'rc' => '44', 'message' => 'Saldo tidak cukup', 'sn' => null]))
            ->assertOk();

        $this->assertSame(DeliveryStatus::Failed, $order->fresh()->delivery_status);
        $this->assertDatabaseMissing('ledger_entries', [
            'reference_id' => $order->id,
            'type' => 'order_profit',
        ]);
    }

    /**
     * ADR-098, reclassified by ADR-102 decision 4 — rc=02 "Transaksi
     * Gagal" has Terbentuk Transaksi=Ya: unsafe to resubmit the same
     * reference, but Digiflazz's own `status` field DID confirm the
     * outcome (Gagal) — a known result, not an ambiguous one. Now
     * routes straight to Failed (Issue Voucher immediately available),
     * superseding this test's own original ADR-098 expectation. Real
     * incident this originally fixed: order PG-JLOMUJ1H23NE — see
     * ADR-102's Context for why needs_review was the wrong landing
     * spot for a genuinely-known outcome.
     */
    public function test_a_terminal_rc_gagal_callback_finalizes_the_pending_order_as_failed(): void
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
        app(OrderFulfillmentService::class)->finalizePendingDelivery(
            $order,
            SupplierOutcome::Success,
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

    public function test_a_signed_request_from_an_ip_outside_the_allowlist_is_still_processed(): void
    {
        // ADR-069 stress-test Q1 — the IP allowlist is a soft signal
        // (log only), never a gate: the signature is the real auth and
        // `$request->ip()` becomes an edge IP behind any proxy/CDN.
        $order = $this->pendingOrder();

        $this->sendWebhook($this->suksesData($order), ['ip' => '203.0.113.9'])->assertOk();

        $this->assertSame(DeliveryStatus::Delivered, $order->fresh()->delivery_status);
    }

    public function test_an_empty_webhook_ips_config_disables_the_ip_check(): void
    {
        config()->set('services.digiflazz.webhook_ips', []);
        $order = $this->pendingOrder();

        $this->sendWebhook($this->suksesData($order), ['ip' => '198.51.100.7'])->assertOk();

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

    /**
     * Regression: found in prod 2026-09-15 — Digiflazz's `update` event
     * lowercases buyer_sku_code even though `supplier_product_ref` (and
     * their own `create` event) is uppercase. An exact `!==` compare
     * rejected every real `update` callback, stranding delivery_status
     * at Pending until the ~10-min reconcile poll caught up.
     */
    public function test_a_differently_cased_buyer_sku_code_still_matches(): void
    {
        $order = $this->pendingOrder(['supplier_product_ref' => 'MLBB_MY_14_PG1']);

        $this->sendWebhook($this->suksesData($order, ['buyer_sku_code' => 'mlbb_my_14_pg1']))
            ->assertOk()
            ->assertJson(['message' => 'ok']);

        $this->assertSame(DeliveryStatus::Delivered, $order->fresh()->delivery_status);
    }

    /**
     * ADR-094 decision 7 (Phase 3b): a combo leg's ref_id carries the
     * -L{n} suffix — parsed off before the order lookup, then routed
     * through finalizePendingDeliveryLeg() re-scoped to the leg's own
     * component package, never the order's own (null) supplier fields.
     */
    private function comboOrderWithPendingLeg(array $overrides = []): array
    {
        $supplier = $this->digiflazzSupplier();
        $game = Game::query()->create(['name' => 'MLBB Combo Test', 'slug' => 'mlbb-combo-test-'.uniqid()]);
        $component = Package::query()->create([
            'game_id' => $game->id, 'name' => 'Component', 'denomination' => 100,
            'cost_price' => 500, 'standard_selling_price' => 600, 'markup_percent' => 20,
            'supplier_id' => $supplier->id, 'supplier_package_ref' => 'dgf-combo-leg-sku',
        ]);
        $combo = Package::query()->create([
            'game_id' => $game->id, 'name' => 'Combo', 'is_combo' => true,
            'denomination' => 100, 'cost_price' => 500, 'standard_selling_price' => 600, 'markup_percent' => 20,
        ]);
        $combo->components()->attach($component->id, ['quantity' => 1, 'sort_order' => 0]);

        $order = Order::query()->create(array_merge([
            'affiliate_id' => $this->primaryAffiliate()->id,
            'order_number' => 'KRS-DGF-COMBO-1',
            'reference_number' => 'REF-DGF-COMBO-1',
            'customer_email' => 'buyer@example.com',
            'game_id' => $game->id,
            'package_id' => $combo->id,
            'player_id' => '900000001',
            'server_id' => '1234',
            'supplier_id' => null,
            'supplier_product_ref' => null,
            'cost_price' => 500,
            'standard_selling_price' => 600,
            'selling_price' => 700,
            'transaction_fee' => 100,
            'final_amount' => 800,
            'platform_profit' => 100,
            'affiliate_profit' => 0,
            'payment_status' => PaymentStatus::Paid->value,
            'delivery_status' => DeliveryStatus::Pending->value,
        ], $overrides));

        $leg = OrderDeliveryLeg::query()->create([
            'order_id' => $order->id,
            'component_package_id' => $component->id,
            'supplier_id' => $supplier->id,
            'leg_number' => 1,
            'status' => DeliveryStatus::Pending->value,
        ]);

        return [$order, $leg, $component];
    }

    public function test_a_sukses_callback_for_a_combo_leg_finalizes_that_leg_and_the_order(): void
    {
        [$order, $leg, $component] = $this->comboOrderWithPendingLeg();

        $this->sendWebhook([
            'ref_id' => $order->reference_number.'-L1',
            'customer_no' => '900000001.1234',
            'buyer_sku_code' => $component->supplier_package_ref,
            'status' => 'Sukses',
            'rc' => '00',
            'sn' => 'SN-COMBO-LEG-1',
            'price' => 480,
            'buyer_last_saldo' => 3200,
        ])->assertOk()->assertJson(['message' => 'ok']);

        $leg->refresh();
        $this->assertSame(DeliveryStatus::Delivered, $leg->status);
        $this->assertSame('SN-COMBO-LEG-1', $leg->supplier_reference);
        $this->assertSame(DeliveryStatus::Delivered, $order->fresh()->delivery_status);

        $this->assertDatabaseHas('supplier_ledger_entries', [
            'reference_type' => 'order_delivery_leg',
            'reference_id' => $leg->id,
        ]);
    }

    /** ADR-098 — rc=44 is genuinely retriable (Terbentuk Transaksi=Tidak), so it stays a plain Failed. */
    public function test_a_gagal_callback_for_a_combo_leg_finalizes_that_leg_as_failed(): void
    {
        [$order, $leg] = $this->comboOrderWithPendingLeg();
        $component = $leg->componentPackage;

        $this->sendWebhook([
            'ref_id' => $order->reference_number.'-L1',
            'customer_no' => '900000001.1234',
            'buyer_sku_code' => $component->supplier_package_ref,
            'status' => 'Gagal',
            'rc' => '44',
            'message' => 'Saldo tidak cukup',
        ])->assertOk();

        $this->assertSame(DeliveryStatus::Failed, $leg->fresh()->status);
        // Sole leg, all-Failed — clean retryable Failed, not needs_review.
        $this->assertSame(DeliveryStatus::Failed, $order->fresh()->delivery_status);
    }

    /**
     * ADR-098, reclassified by ADR-102 decision 4/8 — rc=02 has
     * Terbentuk Transaksi=Ya (unsafe to resubmit), but Digiflazz's own
     * `status` field confirmed the outcome, so this leg (and, being the
     * sole leg here, the whole order) now lands on Failed instead of
     * needs_review — no new per-leg infra needed, this falls straight
     * out of decision 4's split-flag routing.
     */
    public function test_a_terminal_rc_gagal_callback_for_a_combo_leg_finalizes_that_leg_as_failed(): void
    {
        [$order, $leg] = $this->comboOrderWithPendingLeg();
        $component = $leg->componentPackage;

        $this->sendWebhook([
            'ref_id' => $order->reference_number.'-L1',
            'customer_no' => '900000001.1234',
            'buyer_sku_code' => $component->supplier_package_ref,
            'status' => 'Gagal',
            'rc' => '02',
            'message' => 'Transaksi Gagal',
        ])->assertOk();

        $this->assertSame(DeliveryStatus::Failed, $leg->fresh()->status);
        $this->assertSame(DeliveryStatus::Failed, $order->fresh()->delivery_status);
    }

    public function test_a_combo_leg_sku_mismatch_is_rejected_409(): void
    {
        [$order, $leg] = $this->comboOrderWithPendingLeg();

        $this->sendWebhook([
            'ref_id' => $order->reference_number.'-L1',
            'buyer_sku_code' => 'wrong-sku',
            'status' => 'Sukses',
            'sn' => 'SN-X',
        ])->assertStatus(409);

        $this->assertSame(DeliveryStatus::Pending, $leg->fresh()->status);
    }

    /**
     * Regression: same prod 2026-09-15 bug as the plain-order case above,
     * re-scoped to a combo leg's own component package ref.
     */
    public function test_a_differently_cased_combo_leg_buyer_sku_code_still_matches(): void
    {
        [$order, $leg, $component] = $this->comboOrderWithPendingLeg();
        $component->update(['supplier_package_ref' => 'MLBB_MY_14_PG1']);

        $this->sendWebhook([
            'ref_id' => $order->reference_number.'-L1',
            'customer_no' => '900000001.1234',
            'buyer_sku_code' => 'mlbb_my_14_pg1',
            'status' => 'Sukses',
            'rc' => '00',
            'sn' => 'SN-COMBO-LEG-CASE',
            'buyer_last_saldo' => 3200,
        ])->assertOk()->assertJson(['message' => 'ok']);

        $this->assertSame(DeliveryStatus::Delivered, $leg->fresh()->status);
    }

    public function test_a_callback_for_a_nonexistent_leg_number_is_rejected_404(): void
    {
        [$order] = $this->comboOrderWithPendingLeg();

        $this->sendWebhook([
            'ref_id' => $order->reference_number.'-L99',
            'buyer_sku_code' => 'irrelevant',
            'status' => 'Sukses',
            'sn' => 'SN-X',
        ])->assertStatus(404);
    }

    public function test_a_duplicate_combo_leg_callback_is_acknowledged_without_double_crediting(): void
    {
        [$order, $leg, $component] = $this->comboOrderWithPendingLeg();
        $payload = [
            'ref_id' => $order->reference_number.'-L1',
            'buyer_sku_code' => $component->supplier_package_ref,
            'status' => 'Sukses',
            'sn' => 'SN-COMBO-LEG-1',
        ];

        $this->sendWebhook($payload)->assertOk();
        $this->sendWebhook($payload)->assertOk()->assertJson(['message' => 'already finalized']);

        $this->assertSame(1, LedgerEntry::query()->where('reference_id', $order->id)->where('type', 'order_profit')->where('owner_type', 'platform')->count());
    }
}
