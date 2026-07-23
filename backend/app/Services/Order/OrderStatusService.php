<?php

namespace App\Services\Order;

final class OrderStatusService
{
    /**
     * Applies to every delivery attempt, including retries — never just
     * the first one (ORD-11). This is the single most direct guard
     * against giving away free game credits without confirmed payment.
     */
    public function startDelivery(PaymentStatus $paymentStatus, DeliveryStatus $currentDeliveryStatus): DeliveryStatus
    {
        if ($paymentStatus !== PaymentStatus::Paid) {
            throw new InvalidOrderTransitionException(
                "Cannot start delivery: payment_status is {$paymentStatus->value}, must be paid",
            );
        }

        if (!in_array($currentDeliveryStatus, [DeliveryStatus::NotStarted, DeliveryStatus::Failed], true)) {
            throw new InvalidOrderTransitionException(
                "Cannot start delivery: delivery_status is already {$currentDeliveryStatus->value}",
            );
        }

        return DeliveryStatus::Processing;
    }

    public function markDelivered(DeliveryStatus $currentDeliveryStatus): DeliveryStatus
    {
        if ($currentDeliveryStatus !== DeliveryStatus::Processing) {
            throw new InvalidOrderTransitionException(
                "Cannot mark delivered: delivery_status is {$currentDeliveryStatus->value}, must be processing",
            );
        }

        return DeliveryStatus::Delivered;
    }

    public function markDeliveryFailed(DeliveryStatus $currentDeliveryStatus): DeliveryStatus
    {
        if ($currentDeliveryStatus !== DeliveryStatus::Processing) {
            throw new InvalidOrderTransitionException(
                "Cannot mark delivery failed: delivery_status is {$currentDeliveryStatus->value}, must be processing",
            );
        }

        return DeliveryStatus::Failed;
    }
}
