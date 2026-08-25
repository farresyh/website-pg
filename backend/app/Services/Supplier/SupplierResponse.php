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
    ) {
    }

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
    public static function failure(string $errorCode, string $errorMessage, bool $isServerError = false): self
    {
        return new self(false, null, $errorCode, $errorMessage, $isServerError, SupplierOutcome::Failure);
    }
}
