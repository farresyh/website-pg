<?php

namespace App\Services\Supplier;

/**
 * Canonical shape every Adapter normalizes into, regardless of how
 * inconsistent the source supplier's own envelope is (ADAPT-2).
 * Business logic reads only this — never a raw supplier response.
 */
final class SupplierResponse
{
    private function __construct(
        public readonly bool $success,
        public readonly mixed $data,
        public readonly ?string $errorCode,
        public readonly ?string $errorMessage,
        public readonly bool $isServerError = false,
        public readonly SupplierOutcome $outcome = SupplierOutcome::Failure,
        // ADR-098, split by ADR-102 decision 5 into two independent
        // FACTS about the supplier's own state that a single
        // $transactionAlreadyFormed boolean used to conflate:
        //
        //  - $resendUnsafeWithSameReference: "a resubmit of this same
        //    reference can only replay a stored result, never
        //    reprocess" — Gamevion's 409/duplicate_reference, or
        //    Digiflazz's own 20-code "Terbentuk Transaksi=Ya" table.
        //    Drives the admin Resend/Retry button's futile-to-click
        //    signal (decision 3), never routing.
        //  - $outcomeConfirmedFailed: "the supplier's own status field
        //    definitively said Gagal" — true for Digiflazz's Gagal
        //    branch, always false for Gamevion (a 409 carries no status
        //    field to confirm anything). Drives decision 4's routing:
        //    a confirmed-Gagal failure goes straight to Failed even
        //    when resendUnsafeWithSameReference is also true, since
        //    "can't safely resubmit" and "outcome unknown" are
        //    different facts — only the combination of unsafe-to-resend
        //    AND NOT confirmed-failed is genuinely ambiguous (Gamevion's
        //    409, or an exception/timeout with no response at all).
        //
        // Neither is a business consequence itself — OrderFulfillmentService,
        // not this class, decides what routing follows (same ADAPT-2
        // split as $outcome itself). Supplier-agnostic: any adapter can
        // set either.
        public readonly bool $resendUnsafeWithSameReference = false,
        public readonly bool $outcomeConfirmedFailed = false,
    ) {}

    public static function success(mixed $data): self
    {
        return new self(true, $data, null, null, outcome: SupplierOutcome::Success);
    }

    /**
     * ADR-032: an async supplier (Digiflazz) accepted the order but
     * hasn't confirmed the final outcome yet — genuinely distinct from
     * both success() (delivered now) and failure() (definitively
     * rejected). $success stays false (no delivery has actually
     * happened), matching every pre-ADR-032 caller that only ever
     * checked ->success — OrderFulfillmentService is the only caller
     * that needs to distinguish Pending from a real Failure, and it
     * does so via ->outcome, not ->success.
     */
    public static function pending(mixed $data): self
    {
        return new self(false, $data, null, null, outcome: SupplierOutcome::Pending);
    }

    /**
     * $isServerError distinguishes "the supplier itself is
     * failing/unreachable" (HTTP 5xx) from a definitive business
     * rejection (4xx - invalid product, insufficient balance, etc.).
     * Only the former should count against CircuitBreakingSupplierAdapter
     * (ADR-019 addendum) - a run of ordinary 4xx rejections isn't
     * evidence the supplier is down, and tripping the breaker on those
     * would block healthy orders for no reason.
     */
    public static function failure(string $errorCode, string $errorMessage, bool $isServerError = false, bool $resendUnsafeWithSameReference = false, bool $outcomeConfirmedFailed = false): self
    {
        return new self(false, null, $errorCode, $errorMessage, $isServerError, SupplierOutcome::Failure, $resendUnsafeWithSameReference, $outcomeConfirmedFailed);
    }
}
