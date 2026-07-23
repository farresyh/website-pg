<?php

namespace Tests\Feature\Models;

use App\Models\Order;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\OrderStatusService;
use App\Services\Order\PaymentStatus;
use App\Services\Order\ReferenceNumberService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrder(array $overrides = []): Order
    {
        return Order::query()->create(array_merge([
            'order_number' => 'KRS-TEST-1',
            'reference_number' => null,
            'customer_email' => 'buyer@example.com',
            'player_id' => '123456',
            'cost_price' => 900,
            'reseller_cost_price' => 900,
            'selling_price' => 1000,
            'transaction_fee' => 90,
            'final_amount' => 1090,
            'platform_profit' => 100,
            'reseller_profit' => 0,
            'payment_status' => PaymentStatus::Pending->value,
            'delivery_status' => DeliveryStatus::NotStarted->value,
        ], $overrides));
    }

    public function test_persists_and_casts_payment_and_delivery_status_as_enums(): void
    {
        $order = $this->makeOrder();

        $fresh = Order::query()->findOrFail($order->id);

        $this->assertSame(PaymentStatus::Pending, $fresh->payment_status);
        $this->assertSame(DeliveryStatus::NotStarted, $fresh->delivery_status);
    }

    /**
     * This is the whole point of the Order model existing: proves
     * ReferenceNumberService (ORD-8) actually has somewhere to persist
     * its idempotency guarantee, rather than being a service with no
     * caller.
     */
    public function test_reference_number_is_generated_once_and_reused_on_retry(): void
    {
        $order = $this->makeOrder(['reference_number' => null]);
        $service = new ReferenceNumberService();

        $firstAttempt = $service->resolve($order->reference_number);
        $order->update(['reference_number' => $firstAttempt]);

        $reloaded = Order::query()->findOrFail($order->id);
        $secondAttempt = $service->resolve($reloaded->reference_number);

        $this->assertSame($firstAttempt, $secondAttempt);
        $this->assertStringStartsWith('REF-', $reloaded->reference_number);
    }

    /**
     * Proves OrderStatusService's transitions (ORD-11 guard) actually
     * drive persisted Order state, not just in-memory enum values.
     */
    public function test_order_status_service_transition_persists_through_the_model(): void
    {
        $order = $this->makeOrder([
            'payment_status' => PaymentStatus::Paid->value,
            'delivery_status' => DeliveryStatus::NotStarted->value,
        ]);

        $service = new OrderStatusService();
        $newStatus = $service->startDelivery($order->payment_status, $order->delivery_status);
        $order->update(['delivery_status' => $newStatus->value]);

        $reloaded = Order::query()->findOrFail($order->id);

        $this->assertSame(DeliveryStatus::Processing, $reloaded->delivery_status);
    }

    public function test_supplier_response_is_cast_to_array(): void
    {
        $order = $this->makeOrder([
            'supplier_ref' => 'GV-RAPI-1A2B3C4D5E6F',
            'supplier_response' => ['invoice_number' => 'GV-RAPI-1A2B3C4D5E6F', 'game' => 'Free Fire'],
        ]);

        $reloaded = Order::query()->findOrFail($order->id);

        $this->assertSame('Free Fire', $reloaded->supplier_response['game']);
    }
}
