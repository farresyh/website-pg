<?php

namespace App\Services\Order;

/**
 * Reflects the Supplier API only — never the payment gateway.
 * See PaymentStatus. These are two independent state machines (ORD-11).
 */
enum DeliveryStatus: string
{
    case NotStarted = 'not_started';
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
     * state existed). Never reached from NotStarted.
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
}
