<?php

namespace App\Services\Supplier;

use RuntimeException;

/**
 * Thrown when a supplier slug (Supplier.slug, Order.supplier_id's
 * resolved slug) has no corresponding SupplierAdapter implementation
 * bound — deliberately fails loud rather than silently routing to
 * whichever adapter happens to be default, since a wrong supplier
 * fulfilling a real order is a financial-integrity bug, not a
 * cosmetic one (ADR-031).
 */
final class UnsupportedSupplierException extends RuntimeException
{
}
