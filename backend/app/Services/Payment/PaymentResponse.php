<?php

namespace App\Services\Payment;

/**
 * Canonical shape every payment gateway adapter normalizes into —
 * same pattern as App\Services\Supplier\SupplierResponse, kept as a
 * separate type so the Payment and Supplier adapter families stay
 * decoupled from each other.
 */
final class PaymentResponse
{
    private function __construct(
        public readonly bool $success,
        public readonly mixed $data,
        public readonly ?string $errorCode,
        public readonly ?string $errorMessage,
    ) {
    }

    public static function success(mixed $data): self
    {
        return new self(true, $data, null, null);
    }

    public static function failure(string $errorCode, string $errorMessage): self
    {
        return new self(false, null, $errorCode, $errorMessage);
    }
}
