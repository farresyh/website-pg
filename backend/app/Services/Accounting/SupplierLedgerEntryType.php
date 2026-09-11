<?php

namespace App\Services\Accounting;

/**
 * ADR-083 decision 2: the four movement types on `supplier_ledger_entries`.
 * String values match the literals stored in the `type` column exactly —
 * mirrors `App\Services\Ledger\LedgerOwnerType`'s type-safety pattern for
 * a money-column enum, rather than scattering these as magic strings
 * across `SupplierFundingService` and the webhook/response capture points.
 */
enum SupplierLedgerEntryType: string
{
    case Topup = 'TOPUP';
    case OrderDrawdown = 'ORDER_DRAWDOWN';
    case Refund = 'REFUND';
    case ManualAdjustment = 'MANUAL_ADJUSTMENT';

    /**
     * Normalize a value that may already be an enum or a raw string
     * (a DB read) to the enum. Throws \ValueError on an unknown string —
     * a loud failure at a money seam is correct.
     */
    public static function coerce(self|string $value): self
    {
        return $value instanceof self ? $value : self::from($value);
    }
}
