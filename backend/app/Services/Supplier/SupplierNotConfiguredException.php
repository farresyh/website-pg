<?php

namespace App\Services\Supplier;

use RuntimeException;

/**
 * ADR-046 decision 2 — thrown when a supplier's adapter binding is
 * resolved (SupplierAdapterFactory::make()) but its `Supplier` row is
 * missing or its `api_config` is empty. Distinct from
 * UnsupportedSupplierException, which means "no adapter implementation
 * registered for this slug at all" — this means the implementation
 * exists but hasn't been configured through the Supplier Management
 * screen yet.
 */
class SupplierNotConfiguredException extends RuntimeException
{
}
