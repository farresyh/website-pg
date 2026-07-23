<?php

namespace Tests\Unit\Services\Order;

use App\Services\Order\DeliveryStatus;
use App\Services\Order\InvalidOrderTransitionException;
use App\Services\Order\OrderStatusService;
use App\Services\Order\PaymentStatus;
use PHPUnit\Framework\TestCase;

class OrderStatusServiceTest extends TestCase
{
    /**
     * The single most critical guard in the whole system: delivery must
     * never start unless payment is genuinely confirmed. This is the
     * direct path to giving away free game credits (ORD-11).
     */
    public function test_rejects_starting_delivery_when_payment_is_not_paid(): void
    {
        $service = new OrderStatusService();

        $this->expectException(InvalidOrderTransitionException::class);

        $service->startDelivery(PaymentStatus::Pending, DeliveryStatus::NotStarted);
    }

    public function test_starts_delivery_when_payment_is_paid_and_not_yet_started(): void
    {
        $service = new OrderStatusService();

        $result = $service->startDelivery(PaymentStatus::Paid, DeliveryStatus::NotStarted);

        $this->assertSame(DeliveryStatus::Processing, $result);
    }

    /**
     * Guards against double-submission: an order already being processed
     * or already delivered must not be re-submitted to the supplier.
     */
    public function test_rejects_starting_delivery_when_already_processing(): void
    {
        $service = new OrderStatusService();

        $this->expectException(InvalidOrderTransitionException::class);

        $service->startDelivery(PaymentStatus::Paid, DeliveryStatus::Processing);
    }

    public function test_rejects_starting_delivery_when_already_delivered(): void
    {
        $service = new OrderStatusService();

        $this->expectException(InvalidOrderTransitionException::class);

        $service->startDelivery(PaymentStatus::Paid, DeliveryStatus::Delivered);
    }

    /**
     * Retry path (§7.2): admin retries delivery after a prior failure —
     * this must still be allowed, payment already confirmed paid.
     */
    public function test_allows_retry_from_failed_delivery_when_payment_is_paid(): void
    {
        $service = new OrderStatusService();

        $result = $service->startDelivery(PaymentStatus::Paid, DeliveryStatus::Failed);

        $this->assertSame(DeliveryStatus::Processing, $result);
    }

    public function test_marks_delivered_from_processing(): void
    {
        $service = new OrderStatusService();

        $result = $service->markDelivered(DeliveryStatus::Processing);

        $this->assertSame(DeliveryStatus::Delivered, $result);
    }

    public function test_rejects_marking_delivered_when_not_processing(): void
    {
        $service = new OrderStatusService();

        $this->expectException(InvalidOrderTransitionException::class);

        $service->markDelivered(DeliveryStatus::NotStarted);
    }

    public function test_marks_delivery_failed_from_processing(): void
    {
        $service = new OrderStatusService();

        $result = $service->markDeliveryFailed(DeliveryStatus::Processing);

        $this->assertSame(DeliveryStatus::Failed, $result);
    }

    public function test_rejects_marking_delivery_failed_when_not_processing(): void
    {
        $service = new OrderStatusService();

        $this->expectException(InvalidOrderTransitionException::class);

        $service->markDeliveryFailed(DeliveryStatus::NotStarted);
    }
}
