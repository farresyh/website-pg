<?php

namespace App\Services\Order;

/**
 * Reflects the Supplier API only — never the payment gateway.
 * See PaymentStatus. These are two independent state machines (ORD-11).
 */
enum DeliveryStatus: string
{
    case NotStarted = 'not_started';

    /**
     * Committed before the supplier call: sent, or about to be, outcome
     * unknown. On an order, a pass or a call is running. On a combo leg too
     * (ADR-103 decision 10), and a stale one means the worker died.
     */
    case Processing = 'processing';
    case Delivered = 'delivered';
    case Failed = 'failed';

    /**
     * ADR-026 (ORD-10) — a supplier order-creation attempt whose real
     * outcome is unknown and cannot be resolved automatically (Gamevion's
     * check-status endpoint needs its own invoice number, which is
     * exactly what's missing in both cases this state covers). Reached
     * either from Processing (a connection failure exhausted every
     * retry layer, or createOrder() returned a fresh duplicate_reference)
     * or from Failed (a stale duplicate_reference recorded before this
     * state existed), or from NotStarted when every job attempt failed
     * (FulfillOrderJob::failed()).
     */
    case NeedsReview = 'needs_review';

    /**
     * ADR-032 — a supplier accepted the order but hasn't confirmed the
     * final outcome yet (Digiflazz's async `Pending`, `rc: 03`).
     * Distinct from Processing (transient, resolved inside fulfill()'s
     * own transaction) — this is a genuinely persistent waiting state,
     * left as-is across requests until a webhook or poll resolves it
     * (OrderFulfillmentService::finalizePendingDelivery()). Reached
     * only from Processing; exits only to Delivered or Failed, never
     * back through the normal retry path.
     */
    case Pending = 'pending';

    /**
     * ADR-094 decision 28 — a combo order whose legs are all final, with
     * at least one Delivered and at least one Failed. Terminal, like
     * Failed: compensation is a separate fact and never changes it.
     * Order-level only; a leg never takes this value.
     */
    case PartiallyDelivered = 'partially_delivered';

    /**
     * ADR-103 decision 14 — the one rule rolling a combo's legs up to its
     * order. An outcome is read only from legs that prove it: no legs, or a
     * mix this doesn't recognise, is NeedsReview, never a known outcome. A
     * leg at the supplier wins, so the order keeps being polled.
     *
     * @param  iterable<self>  $legs
     */
    public static function comboRollup(iterable $legs): self
    {
        $statuses = collect($legs);
        $only = fn (self ...$allowed) => $statuses->every(fn (self $status) => in_array($status, $allowed, true));

        return match (true) {
            $statuses->isEmpty() => self::NeedsReview,
            $statuses->contains(self::Pending) => self::Pending,
            $only(self::Delivered) => self::Delivered,
            $only(self::Failed) => self::Failed,
            $only(self::Delivered, self::Failed) => self::PartiallyDelivered,
            default => self::NeedsReview,
        };
    }

    /** ADR-094 decision 30 — the one rule for "can be compensated". */
    public function isCompensable(): bool
    {
        return $this === self::Failed || $this === self::PartiallyDelivered;
    }
}
