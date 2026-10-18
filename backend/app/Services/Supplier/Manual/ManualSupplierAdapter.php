<?php

namespace App\Services\Supplier\Manual;

use App\Services\Supplier\SupplierAdapter;
use App\Services\Supplier\SupplierOrderRequest;
use App\Services\Supplier\SupplierResponse;
use App\Services\Supplier\SupplierStatusCheckRequest;
use App\Services\Supplier\UnsupportedSupplierException;
use App\Services\Supplier\ValidationNotSupportedException;

/**
 * ADR-125 decisions 1-2: the adapter behind `driver = manual`, one for
 * every manual supplier (the row is panel data). A manual supplier has no
 * API, so every call here is refused loudly rather than answered with an
 * empty success: an empty catalogue would deactivate its packages, a zero
 * balance would trip the low-balance alert. Callers ask
 * `Supplier::isApiBacked()` first and never get here.
 *
 * `createOrder` is refused until PR 1b adds the Manual Queue (ADR-125
 * 2026-10-18 addendum, decision 25); no manual package can exist before it.
 */
final class ManualSupplierAdapter implements SupplierAdapter
{
    public function checkBalance(): SupplierResponse
    {
        throw $this->unsupported('checkBalance');
    }

    public function listProducts(): SupplierResponse
    {
        throw $this->unsupported('listProducts');
    }

    public function createOrder(SupplierOrderRequest $request): SupplierResponse
    {
        throw $this->unsupported('createOrder');
    }

    public function checkStatus(SupplierStatusCheckRequest $request): SupplierResponse
    {
        throw $this->unsupported('checkStatus');
    }

    public function validatePlayer(string $playerId, ?string $serverId): SupplierResponse
    {
        throw new ValidationNotSupportedException('A manual supplier has no player validation endpoint.');
    }

    private function unsupported(string $method): UnsupportedSupplierException
    {
        return new UnsupportedSupplierException("A manual supplier has no API: {$method} is not available.");
    }
}
