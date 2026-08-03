<?php

namespace App\Services\Payment;

use App\Services\Order\PaymentStatus;

/**
 * Canonical shape every payment gateway adapter normalizes into —
 * same pattern as App\Services\Supplier\SupplierResponse, kept as a
 * separate type so the Payment and Supplier adapter families stay
 * decoupled from each other.
 *
 * `status` is a typed PaymentStatus, not a raw gateway string, so a
 * business-logic consumer (ReconcilePendingPaymentsCommand) can never
 * end up matching against one gateway's specific status vocabulary
 * (Xendit's SUCCEEDED/EXPIRED/... vs. CHIP's paid/error/...) — every
 * PaymentGateway::createPayment()/getPayment() implementation is
 * forced by the type system to translate its own raw status into this
 * enum before returning, the same normalize-at-the-adapter discipline
 * parseWebhookEvent() already had (ADR-006's SupplierAdapter
 * philosophy, applied here after a real gap was found while building
 * ChipGateway — see ADR-022's newest addendum). The raw gateway string
 * can still be carried inside `data` for logging/diagnostics; it must
 * never be what a caller branches on.
 */
final class PaymentResponse
{
    private function __construct(
        public readonly bool $success,
        public readonly mixed $data,
        public readonly ?PaymentStatus $status,
        public readonly ?string $errorCode,
        public readonly ?string $errorMessage,
    ) {
    }

    public static function success(mixed $data, ?PaymentStatus $status = null): self
    {
        return new self(true, $data, $status, null, null);
    }

    public static function failure(string $errorCode, string $errorMessage): self
    {
        return new self(false, null, null, $errorCode, $errorMessage);
    }
}
