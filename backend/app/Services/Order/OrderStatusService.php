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

        // ADR-026 decision 4b: NeedsReview allows re-entry so "Resend
        // Delivery" works from that state too, same as the existing
        // Failed retry path.
        if (!in_array($currentDeliveryStatus, [DeliveryStatus::NotStarted, DeliveryStatus::Failed, DeliveryStatus::NeedsReview], true)) {
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

    /**
     * ADR-026 — two legitimate entry points, not one: a fresh
     * duplicate_reference response arrives while Processing (a first
     * attempt or OrderFulfillmentService::fulfill()'s own retry both
     * set Processing before calling the supplier); a stale one is
     * caught by ReconcilePendingDeliveriesCommand's one-time catch-up
     * of rows still sitting at Failed from before this state existed.
     */
    public function markNeedsReview(DeliveryStatus $currentDeliveryStatus): DeliveryStatus
    {
        if (!in_array($currentDeliveryStatus, [DeliveryStatus::Processing, DeliveryStatus::Failed], true)) {
            throw new InvalidOrderTransitionException(
                "Cannot mark needs review: delivery_status is {$currentDeliveryStatus->value}, must be processing or failed",
            );
        }

        return DeliveryStatus::NeedsReview;
    }

    /**
     * ADR-026 decision 4a — the one exit from NeedsReview that isn't
     * "retry" (markNeedsReview's Processing path already covers a
     * retry ending cleanly): an admin manually confirmed the real
     * outcome via Gamevion's own dashboard. Deliberately only reachable
     * from NeedsReview, never from a plain Failed order — no other
     * state in this system lets an admin's own claim substitute for a
     * real supplier confirmation.
     */
    public function markDeliveredManually(DeliveryStatus $currentDeliveryStatus): DeliveryStatus
    {
        if ($currentDeliveryStatus !== DeliveryStatus::NeedsReview) {
            throw new InvalidOrderTransitionException(
                "Cannot manually mark delivered: delivery_status is {$currentDeliveryStatus->value}, must be needs_review",
            );
        }

        return DeliveryStatus::Delivered;
    }
}
