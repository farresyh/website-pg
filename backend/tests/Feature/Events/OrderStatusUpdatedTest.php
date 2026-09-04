<?php

namespace Tests\Feature\Events;

use App\Events\OrderStatusUpdated;
use App\Models\Order;
use App\Models\Supplier;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\PaymentStatus;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * ADR-047 decisions 1/2/6 — OrderObserver's broadcast seam. Public-channel
 * safety (no PrivateChannel, no internal financial/operational fields in
 * the payload) is asserted directly, not just assumed from the class's own
 * doc comment — this is the exact boundary TrackOrderController's own doc
 * comment calls out as the thing that must never leak.
 */
class OrderStatusUpdatedTest extends TestCase
{
    use RefreshDatabase;

    private function order(array $overrides = []): Order
    {
        $supplierId = Supplier::query()->firstOrCreate(
            ['slug' => 'test-supplier'],
            ['name' => 'Test Supplier', 'api_config' => [], 'currency' => 'MYR'],
        )->id;

        return Order::query()->create(array_merge([
            'affiliate_id' => $this->primaryAffiliate()->id,
            'order_number' => 'KRS-TEST-BROADCAST-1',
            'customer_email' => 'buyer@example.com',
            'player_id' => '123456',
            'server_id' => '1234',
            'supplier_id' => $supplierId,
            'supplier_product_ref' => 'FFP5',
            'cost_price' => 900,
            'standard_selling_price' => 900,
            'selling_price' => 1000,
            'transaction_fee' => 100,
            'final_amount' => 1100,
            'platform_profit' => 100,
            'affiliate_profit' => 0,
            'payment_status' => PaymentStatus::Pending->value,
            'delivery_status' => DeliveryStatus::NotStarted->value,
        ], $overrides));
    }

    public function test_updating_payment_status_broadcasts_order_status_updated(): void
    {
        Event::fake([OrderStatusUpdated::class]);

        $order = $this->order();
        $order->update(['payment_status' => PaymentStatus::Paid->value]);

        Event::assertDispatched(
            OrderStatusUpdated::class,
            fn (OrderStatusUpdated $event) => $event->order->is($order),
        );
    }

    public function test_updating_delivery_status_broadcasts_order_status_updated(): void
    {
        Event::fake([OrderStatusUpdated::class]);

        $order = $this->order(['payment_status' => PaymentStatus::Paid->value]);
        $order->update(['delivery_status' => DeliveryStatus::Delivered->value]);

        Event::assertDispatched(
            OrderStatusUpdated::class,
            fn (OrderStatusUpdated $event) => $event->order->is($order),
        );
    }

    public function test_updating_an_unrelated_field_does_not_broadcast(): void
    {
        Event::fake([OrderStatusUpdated::class]);

        $order = $this->order();
        $order->update(['customer_email' => 'someone-else@example.com']);

        Event::assertNotDispatched(OrderStatusUpdated::class);
    }

    public function test_broadcasts_on_a_public_channel_keyed_by_order_number(): void
    {
        $event = new OrderStatusUpdated($this->order(['order_number' => 'KRS-TEST-BROADCAST-2']));

        $channels = $event->broadcastOn();

        $this->assertCount(1, $channels);
        $this->assertInstanceOf(Channel::class, $channels[0]);
        $this->assertNotInstanceOf(PrivateChannel::class, $channels[0]);
        $this->assertSame('order.KRS-TEST-BROADCAST-2', $channels[0]->name);
    }

    public function test_broadcast_payload_excludes_internal_financial_and_operational_fields(): void
    {
        $order = $this->order([
            'payment_ref' => 'xendit-ref-123',
            'supplier_ref' => 'gamevion-ref-456',
            'supplier_response' => ['raw' => 'internal supplier payload'],
        ]);

        $payload = (new OrderStatusUpdated($order))->broadcastWith();

        foreach (['cost_price', 'standard_selling_price', 'platform_profit', 'affiliate_profit', 'supplier_response', 'payment_ref', 'supplier_ref'] as $internalField) {
            $this->assertArrayNotHasKey($internalField, $payload, "broadcastWith() must never leak '{$internalField}'");
        }

        $this->assertSame($order->order_number, $payload['order_number']);
        $this->assertSame($order->payment_status->value, $payload['payment_status']);
        $this->assertSame($order->delivery_status->value, $payload['delivery_status']);
        $this->assertSame($order->final_amount, $payload['final_amount']);
    }
}
