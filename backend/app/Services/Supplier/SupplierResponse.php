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
