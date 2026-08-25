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

    /**
     * @return SupplierResponse whose `data`, on success, is a
     *         SupplierCatalogItem[]
     */
    public function listProducts(): SupplierResponse;

    public function createOrder(SupplierOrderRequest $request): SupplierResponse;

    public function checkStatus(SupplierStatusCheckRequest $request): SupplierResponse;

    /**
     * @throws ValidationNotSupportedException when this supplier has no
     *         dedicated validation endpoint (ADR-005)
     */
    public function validatePlayer(string $playerId, ?string $serverId): SupplierResponse;
}
