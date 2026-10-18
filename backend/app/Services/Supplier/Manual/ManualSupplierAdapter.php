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
 * `createOrder` is the one call that answers: it decides Pending (ADR-125
 * decision 8), which puts the order in the admin's Manual Queue, where a
 * human confirms it through ManualDeliveryService. Nothing is sent
 * anywhere yet; PR 2 posts it to the supplier's WhatsApp group.
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
        return SupplierResponse::pending([
            'manual' => true,
            'reference' => $request->referenceNumber,
            'queued_at' => now()->toISOString(),
        ]);
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
