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
        $service = new OrderStatusService;

        $this->expectException(InvalidOrderTransitionException::class);

        $service->startDelivery(PaymentStatus::Pending, DeliveryStatus::NotStarted);
    }

    public function test_starts_delivery_when_payment_is_paid_and_not_yet_started(): void
    {
        $service = new OrderStatusService;

        $result = $service->startDelivery(PaymentStatus::Paid, DeliveryStatus::NotStarted);

        $this->assertSame(DeliveryStatus::Processing, $result);
    }

    /**
     * Guards against double-submission: an order already being processed
     * or already delivered must not be re-submitted to the supplier.
     */
    public function test_rejects_starting_delivery_when_already_processing(): void
    {
        $service = new OrderStatusService;

        $this->expectException(InvalidOrderTransitionException::class);

        $service->startDelivery(PaymentStatus::Paid, DeliveryStatus::Processing);
    }

    public function test_rejects_starting_delivery_when_already_delivered(): void
    {
        $service = new OrderStatusService;

        $this->expectException(InvalidOrderTransitionException::class);

        $service->startDelivery(PaymentStatus::Paid, DeliveryStatus::Delivered);
    }

    /**
     * Retry path (§7.2): admin retries delivery after a prior failure —
     * this must still be allowed, payment already confirmed paid.
     */
    public function test_allows_retry_from_failed_delivery_when_payment_is_paid(): void
    {
        $service = new OrderStatusService;

        $result = $service->startDelivery(PaymentStatus::Paid, DeliveryStatus::Failed);

        $this->assertSame(DeliveryStatus::Processing, $result);
    }

    public function test_marks_delivered_from_processing(): void
    {
        $service = new OrderStatusService;

        $result = $service->markDelivered(DeliveryStatus::Processing);

        $this->assertSame(DeliveryStatus::Delivered, $result);
    }

    public function test_rejects_marking_delivered_when_not_processing(): void
    {
        $service = new OrderStatusService;

        $this->expectException(InvalidOrderTransitionException::class);

        $service->markDelivered(DeliveryStatus::NotStarted);
    }

    public function test_marks_delivery_failed_from_processing(): void
    {
        $service = new OrderStatusService;

        $result = $service->markDeliveryFailed(DeliveryStatus::Processing);

        $this->assertSame(DeliveryStatus::Failed, $result);
    }

    public function test_rejects_marking_delivery_failed_when_not_processing(): void
    {
        $service = new OrderStatusService;

        $this->expectException(InvalidOrderTransitionException::class);

        $service->markDeliveryFailed(DeliveryStatus::NotStarted);
    }

    /**
     * ADR-026 decision 4b: needs_review must re-enter processing so
     * Resend Delivery works from that state, same as the existing
     * Failed retry path.
     */
    public function test_allows_retry_from_needs_review_when_payment_is_paid(): void
    {
        $service = new OrderStatusService;

        $result = $service->startDelivery(PaymentStatus::Paid, DeliveryStatus::NeedsReview);

        $this->assertSame(DeliveryStatus::Processing, $result);
    }

    /**
     * ADR-026 — a fresh duplicate_reference response arrives while
     * Processing (a first attempt or a retry both set Processing
     * before calling the supplier).
     */
    public function test_marks_needs_review_from_processing(): void
    {
        $service = new OrderStatusService;

        $result = $service->markNeedsReview(DeliveryStatus::Processing);

        $this->assertSame(DeliveryStatus::NeedsReview, $result);
    }

    /**
     * ADR-026 — the other legitimate entry point: a stale
     * duplicate_reference recorded before this state existed,
     * caught by ReconcilePendingDeliveriesCommand's one-time catch-up.
     */
    public function test_marks_needs_review_from_failed(): void
    {
        $service = new OrderStatusService;

        $result = $service->markNeedsReview(DeliveryStatus::Failed);

        $this->assertSame(DeliveryStatus::NeedsReview, $result);
    }

    public function test_rejects_marking_needs_review_when_not_processing_or_failed(): void
    {
        $service = new OrderStatusService;

        $this->expectException(InvalidOrderTransitionException::class);

        $service->markNeedsReview(DeliveryStatus::NotStarted);
    }

    /**
     * ADR-032 decision 6: a Pending order too old to safely re-poll
     * (Digiflazz's 90-day re-submit rule — polling past that creates a
     * NEW transaction instead of checking the old one) is auto-flagged
     * for manual review, the same genuinely-unresolvable-automatically
     * signal ADR-026 already established.
     */
    public function test_marks_needs_review_from_pending(): void
    {
        $service = new OrderStatusService;

        $result = $service->markNeedsReview(DeliveryStatus::Pending);

        $this->assertSame(DeliveryStatus::NeedsReview, $result);
    }

    public function test_marks_delivered_manually_from_needs_review(): void
    {
        $service = new OrderStatusService;

        $result = $service->markDeliveredManually(DeliveryStatus::NeedsReview);

        $this->assertSame(DeliveryStatus::Delivered, $result);
    }

    /**
     * ADR-026 decision 4a: deliberately only reachable from
     * needs_review — no other state lets an admin's own claim
     * substitute for a real supplier confirmation.
     */
    public function test_rejects_marking_delivered_manually_when_not_needs_review(): void
    {
        $service = new OrderStatusService;

        $this->expectException(InvalidOrderTransitionException::class);

        $service->markDeliveredManually(DeliveryStatus::Failed);
    }

    /** ADR-026 addendum (2026-09-16) — the exit decision 4c always assumed existed. */
    public function test_marks_needs_review_as_failed_from_needs_review(): void
    {
        $service = new OrderStatusService;

        $result = $service->markNeedsReviewAsFailed(DeliveryStatus::NeedsReview);

        $this->assertSame(DeliveryStatus::Failed, $result);
    }

    public function test_rejects_confirming_delivery_failed_when_not_needs_review(): void
    {
        $service = new OrderStatusService;

        $this->expectException(InvalidOrderTransitionException::class);

        $service->markNeedsReviewAsFailed(DeliveryStatus::Processing);
    }

    /**
     * ADR-032: a supplier that accepted the order but hasn't confirmed
     * final delivery yet (Digiflazz's async Pending) — only reachable
     * from Processing, same entry point as a normal synchronous
     * success/failure.
     */
    public function test_marks_pending_from_processing(): void
    {
        $service = new OrderStatusService;

        $result = $service->markPending(DeliveryStatus::Processing);

        $this->assertSame(DeliveryStatus::Pending, $result);
    }

    public function test_rejects_marking_pending_when_not_processing(): void
    {
        $service = new OrderStatusService;

        $this->expectException(InvalidOrderTransitionException::class);

        $service->markPending(DeliveryStatus::NotStarted);
    }

    /** ADR-032 decision 3: the webhook/poll-driven exit confirming real delivery. */
    public function test_finalizes_pending_success_from_pending(): void
    {
        $service = new OrderStatusService;

        $result = $service->finalizePendingSuccess(DeliveryStatus::Pending);

        $this->assertSame(DeliveryStatus::Delivered, $result);
    }

    public function test_rejects_finalizing_pending_success_when_not_pending(): void
    {
        $service = new OrderStatusService;

        $this->expectException(InvalidOrderTransitionException::class);

        $service->finalizePendingSuccess(DeliveryStatus::Processing);
    }

    /** ADR-032 decision 3: the webhook/poll-driven exit confirming a real, terminal failure. */
    public function test_finalizes_pending_failure_from_pending(): void
    {
        $service = new OrderStatusService;

        $result = $service->finalizePendingFailure(DeliveryStatus::Pending);

        $this->assertSame(DeliveryStatus::Failed, $result);
    }

    public function test_rejects_finalizing_pending_failure_when_not_pending(): void
    {
        $service = new OrderStatusService;

        $this->expectException(InvalidOrderTransitionException::class);

        $service->finalizePendingFailure(DeliveryStatus::Processing);
    }
}
