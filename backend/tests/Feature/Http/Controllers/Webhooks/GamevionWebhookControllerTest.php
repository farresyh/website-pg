<?php

namespace Tests\Feature\Http\Controllers\Webhooks;

use App\Models\Game;
use App\Models\Order;
use App\Models\OrderDeliveryLeg;
use App\Models\Package;
use App\Models\Supplier;
use App\Models\SupplierLedgerEntry;
use App\Services\Backup\BackupFailureAlerter;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\PaymentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Mockery;
use Tests\TestCase;

/**
 * ADR-137 decision 9 and 14: the signed `order.updated` webhook finalizes a
 * Pending order or combo leg through the same mapping as the order calls.
 */
class GamevionWebhookControllerTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'gv-webhook-secret';

    private function gamevion(): Supplier
    {
        return Supplier::query()->firstOrCreate(['slug' => 'gamevion'], [
            'name' => 'Gamevion', 'currency' => 'MYR',
            'api_config' => ['base_url' => 'https://gamevion.cloud', 'api_key' => 'gvk_x', 'webhook_secret' => self::SECRET],
        ]);
    }

    private function pendingOrder(array $overrides = []): Order
    {
        return Order::query()->create(array_merge(['placed_via' => 'storefront',
            'affiliate_id' => $this->primaryAffiliate()->id,
            'order_number' => 'KRS-GV-1',
            'reference_number' => 'REF-GV-1',
            'customer_email' => 'buyer@example.com',
            'player_id' => '123456789',
            'server_id' => '2001',
            'supplier_id' => $this->gamevion()->id,
            'supplier_product_ref' => 'mlbb_sgmy:86',
            'cost_price' => 420,
            'standard_selling_price' => 480,
            'selling_price' => 500,
            'transaction_fee' => 100,
            'final_amount' => 600,
            'platform_profit' => 80,
            'affiliate_profit' => 0,
            'payment_status' => PaymentStatus::Paid->value,
            'delivery_status' => DeliveryStatus::Pending->value,
            'payment_gateway' => 'chip',
        ], $overrides));
    }

    private function send(array $order, array $opts = []): TestResponse
    {
        $body = json_encode(['event' => $opts['event'] ?? 'order.updated', 'order' => $order], JSON_UNESCAPED_SLASHES);
        $timestamp = (string) ($opts['timestamp'] ?? now()->timestamp);
        $signature = 'v1='.hash_hmac('sha256', $timestamp.'.'.$body, $opts['secret'] ?? self::SECRET);

        return $this->call('POST', '/api/webhooks/gamevion', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_GAMEVION_TIMESTAMP' => $timestamp,
            'HTTP_X_GAMEVION_SIGNATURE' => $signature,
        ], $body);
    }

    private function gvOrder(string $status, string $clientRef = 'REF-GV-1'): array
    {
        return ['order_id' => 'GVA-1', 'order_status' => $status, 'client_ref' => $clientRef, 'price' => 4.2, 'currency' => 'MYR', 'message' => ''];
    }

    public function test_a_successful_update_delivers_the_order_and_records_the_drawdown_at_face_value(): void
    {
        $order = $this->pendingOrder();

        $this->send($this->gvOrder('successful'))->assertOk()->assertJson(['message' => 'ok']);

        $order->refresh();
        $this->assertSame(DeliveryStatus::Delivered, $order->delivery_status);
        $this->assertSame('GVA-1', $order->supplier_ref);
        $this->assertSame(420, $order->real_cost_price_sen);
        $this->assertDatabaseHas('supplier_ledger_entries', ['reference_type' => 'order', 'reference_id' => $order->id, 'type' => 'ORDER_DRAWDOWN']);
    }

    public function test_a_repeated_update_is_idempotent(): void
    {
        $order = $this->pendingOrder();

        $this->send($this->gvOrder('successful'))->assertOk();
        $this->send($this->gvOrder('successful'))->assertOk();

        $this->assertSame(1, SupplierLedgerEntry::query()->where('reference_id', $order->id)->count());
    }

    public function test_an_error_update_fails_the_order_with_no_drawdown(): void
    {
        $order = $this->pendingOrder();

        $this->send($this->gvOrder('error'))->assertOk();

        $this->assertSame(DeliveryStatus::Failed, $order->fresh()->delivery_status);
        $this->assertDatabaseMissing('supplier_ledger_entries', ['reference_id' => $order->id]);
    }

    public function test_a_pending_or_unknown_status_changes_nothing(): void
    {
        $order = $this->pendingOrder();

        $this->send($this->gvOrder('pending'))->assertOk()->assertJson(['message' => 'acknowledged']);
        $this->send($this->gvOrder('weird'))->assertOk()->assertJson(['message' => 'acknowledged']);

        $this->assertSame(DeliveryStatus::Pending, $order->fresh()->delivery_status);
    }

    public function test_a_refund_after_delivery_alerts_admins_and_keeps_the_order_delivered(): void
    {
        $alerter = Mockery::mock(BackupFailureAlerter::class);
        $alerter->shouldReceive('alert')->once()->withArgs(fn ($context, $message, $area) => $area === 'Supplier refund' && str_contains($message, 'KRS-GV-1'));
        $this->app->instance(BackupFailureAlerter::class, $alerter);
        $order = $this->pendingOrder();
        $this->send($this->gvOrder('successful'))->assertOk();

        $this->send($this->gvOrder('refunded'))->assertOk();

        $this->assertSame(DeliveryStatus::Delivered, $order->fresh()->delivery_status);
    }

    public function test_a_combo_leg_is_finalized_by_its_own_reference(): void
    {
        $supplier = $this->gamevion();
        $game = Game::query()->create(['name' => 'MLBB', 'slug' => 'mlbb-gv-combo']);
        $component = Package::query()->create([
            'game_id' => $game->id, 'name' => '86 Diamonds', 'denomination' => 86, 'cost_price' => 420, 'standard_selling_price' => 480,
            'supplier_id' => $supplier->id, 'supplier_package_ref' => 'mlbb_sgmy:86',
        ]);
        $combo = Package::query()->create(['game_id' => $game->id, 'name' => 'Combo', 'is_combo' => true, 'denomination' => 86, 'cost_price' => 420, 'standard_selling_price' => 480]);
        $combo->components()->attach($component->id, ['quantity' => 1, 'sort_order' => 0]);
        $order = $this->pendingOrder(['game_id' => $game->id, 'package_id' => $combo->id, 'supplier_id' => null, 'supplier_product_ref' => null]);
        $leg = OrderDeliveryLeg::query()->create([
            'order_id' => $order->id, 'component_package_id' => $component->id, 'supplier_id' => $supplier->id,
            'leg_number' => 1, 'reference_number' => 'REF-GV-1-L1', 'status' => DeliveryStatus::Pending->value,
        ]);

        $this->send($this->gvOrder('successful', 'REF-GV-1-L1'))->assertOk();

        $this->assertSame(DeliveryStatus::Delivered, $leg->fresh()->status);
        $this->assertSame(DeliveryStatus::Delivered, $order->fresh()->delivery_status);
    }

    public function test_a_bad_signature_or_a_stale_timestamp_is_rejected(): void
    {
        $order = $this->pendingOrder();

        $this->send($this->gvOrder('successful'), ['secret' => 'wrong'])->assertUnauthorized();
        $this->send($this->gvOrder('successful'), ['timestamp' => now()->subMinutes(6)->timestamp])->assertUnauthorized();

        $this->assertSame(DeliveryStatus::Pending, $order->fresh()->delivery_status);
    }

    public function test_an_order_of_another_supplier_is_rejected(): void
    {
        $other = Supplier::query()->create(['name' => 'Digiflazz', 'slug' => 'digiflazz', 'currency' => 'IDR', 'api_config' => []]);
        $this->gamevion();
        $this->pendingOrder(['supplier_id' => $other->id]);

        $this->send($this->gvOrder('successful'))->assertStatus(409);
    }

    public function test_an_unknown_reference_is_a_benign_404_and_other_events_are_ignored(): void
    {
        $this->gamevion();

        $this->send($this->gvOrder('successful', 'REF-NOPE'))->assertNotFound();
        $this->send($this->gvOrder('successful'), ['event' => 'schedule.updated'])->assertOk()->assertJson(['message' => 'ignored']);
    }
}
