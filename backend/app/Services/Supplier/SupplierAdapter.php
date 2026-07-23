<?php

namespace App\Services\Supplier;

/**
 * Common interface every supplier integration implements (ADAPT-1).
 * Business logic depends only on this contract — never on a raw
 * supplier response shape. Adding a new supplier means implementing
 * this interface, nothing else (ADAPT-3).
 */
interface SupplierAdapter
{
    public function checkBalance(): SupplierResponse;

    public function listProducts(): SupplierResponse;

    public function createOrder(SupplierOrderRequest $request): SupplierResponse;

    public function checkStatus(string $supplierRef): SupplierResponse;

    /**
     * @throws ValidationNotSupportedException when this supplier has no
     *         dedicated validation endpoint (ADR-005)
     */
    public function validatePlayer(string $playerId, ?string $serverId): SupplierResponse;
}
