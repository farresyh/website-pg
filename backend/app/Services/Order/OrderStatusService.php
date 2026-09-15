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
        if (! in_array($currentDeliveryStatus, [DeliveryStatus::NotStarted, DeliveryStatus::Failed, DeliveryStatus::NeedsReview], true)) {
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
     *
     * ADR-032 decision 6 adds a third: a Pending order too old to
     * safely re-poll (Digiflazz's 90-day re-submit rule) is flagged
     * here too, rather than left polling forever or silently dropped.
     */
    public function markNeedsReview(DeliveryStatus $currentDeliveryStatus): DeliveryStatus
    {
        if (! in_array($currentDeliveryStatus, [DeliveryStatus::Processing, DeliveryStatus::Failed, DeliveryStatus::Pending], true)) {
            throw new InvalidOrderTransitionException(
                "Cannot mark needs review: delivery_status is {$currentDeliveryStatus->value}, must be processing, failed, or pending",
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

    /**
     * ADR-026 addendum (2026-09-16, found shipping ADR-098) — the exit
     * decision 4c's own rationale always assumed existed ("An admin
     * must resolve the order to Delivered or a genuine Failed first")
     * but was never actually built: retry (markNeedsReview's own
     * Processing-reentry path) can structurally never resolve a
     * transactionAlreadyFormed order (ADR-098) — the same reference
     * only ever replays the stored result — and Mark Delivered would be
     * a false claim. Deliberately only reachable from NeedsReview, same
     * discipline as markDeliveredManually() above — an admin's own
     * "I've confirmed this genuinely failed" claim is exactly as
     * consequential as their "I've confirmed this was delivered" claim,
     * and gets the same guarded, single-purpose transition.
     */
    public function markNeedsReviewAsFailed(DeliveryStatus $currentDeliveryStatus): DeliveryStatus
    {
        if ($currentDeliveryStatus !== DeliveryStatus::NeedsReview) {
            throw new InvalidOrderTransitionException(
                "Cannot confirm delivery failed: delivery_status is {$currentDeliveryStatus->value}, must be needs_review",
            );
        }

        return DeliveryStatus::Failed;
    }

    /**
     * ADR-032: a supplier accepted the order but hasn't confirmed the
     * final outcome synchronously (Digiflazz's async Pending) — only
     * reachable from Processing, same entry point fulfill() already
     * uses for a synchronous success/failure.
     */
    public function markPending(DeliveryStatus $currentDeliveryStatus): DeliveryStatus
    {
        if ($currentDeliveryStatus !== DeliveryStatus::Processing) {
            throw new InvalidOrderTransitionException(
                "Cannot mark pending: delivery_status is {$currentDeliveryStatus->value}, must be processing",
            );
        }

        return DeliveryStatus::Pending;
    }

    /**
     * ADR-032 decision 3 — the one money-crediting exit from Pending,
     * reached by OrderFulfillmentService::finalizePendingDelivery()
     * via a supplier webhook or the reconcile poll's own check. A
     * second call once already Delivered throws here (idempotency is
     * enforced by this guard, not by the caller re-checking first).
     */
    public function finalizePendingSuccess(DeliveryStatus $currentDeliveryStatus): DeliveryStatus
    {
        if ($currentDeliveryStatus !== DeliveryStatus::Pending) {
            throw new InvalidOrderTransitionException(
                "Cannot finalize pending delivery as delivered: delivery_status is {$currentDeliveryStatus->value}, must be pending",
            );
        }

        return DeliveryStatus::Delivered;
    }

    /**
     * ADR-032 decision 3/7 — a Pending order's real, terminal failure.
     * Lands on the same Failed state a synchronous rejection would,
     * so the existing Failed-only voucher-issuance gate
     * (VoucherController::storeFromOrder()) applies unchanged — no
     * separate double-compensation guard needed.
     */
    public function finalizePendingFailure(DeliveryStatus $currentDeliveryStatus): DeliveryStatus
    {
        if ($currentDeliveryStatus !== DeliveryStatus::Pending) {
            throw new InvalidOrderTransitionException(
                "Cannot finalize pending delivery as failed: delivery_status is {$currentDeliveryStatus->value}, must be pending",
            );
        }

        return DeliveryStatus::Failed;
    }
}
