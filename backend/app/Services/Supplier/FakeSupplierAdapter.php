<?php

namespace App\Services\Supplier;

use Illuminate\Support\Str;

/**
 * ADR-018 decision #5: the sandbox's stand-in for GamevionAdapter — no
 * network call of any kind, ever. Constructed fresh per resend attempt
 * (Middleware\SandboxOrderController::resend()) with the outcome the
 * admin picked in the reused Resend Delivery modal, so the exact same
 * `SupplierAdapter` contract OrderFulfillmentService/OrderResendService
 * already depend on can be exercised deterministically, in either
 * direction, without depending on Gamevion's own sandbox (confirmed
 * broken, ADR-006) or risking a real production order.
 */
final class FakeSupplierAdapter implements SupplierAdapter
{
    public function __construct(
        private readonly bool $simulateSuccess,
        private readonly ?string $errorCode = null,
        private readonly ?string $errorMessage = null,
    ) {
    }

    public function checkBalance(): SupplierResponse
    {
        return SupplierResponse::success([
            'account_name' => 'Sandbox',
            'account_email' => null,
            'membership' => null,
            'balance' => null,
        ]);
    }

    public function listProducts(): SupplierResponse
    {
        return SupplierResponse::success([]);
    }

    public function createOrder(SupplierOrderRequest $request): SupplierResponse
    {
        if (! $this->simulateSuccess) {
            return SupplierResponse::failure(
                $this->errorCode ?? 'sandbox_simulated_failure',
                $this->errorMessage ?? 'Simulated delivery failure (sandbox).',
            );
        }

        return SupplierResponse::success([
            'supplier_ref' => 'SANDBOX-'.Str::upper(Str::random(10)),
            'service_name' => 'Sandbox Simulated Delivery',
            'price' => null,
            'quantity' => 1,
            'game' => null,
            'player_id' => $request->playerId,
            'server_id' => $request->serverId,
            'created_at' => now()->toISOString(),
        ]);
    }

    public function checkStatus(SupplierStatusCheckRequest $request): SupplierResponse
    {
        return SupplierResponse::success([
            'supplier_ref' => $request->supplierRef,
            'product_name' => 'Sandbox Simulated Delivery',
            'status' => $this->simulateSuccess ? 'delivered' : 'failed',
            'serial_number' => null,
            'note' => null,
            'created_at' => now()->toISOString(),
        ]);
    }

    public function validatePlayer(string $playerId, ?string $serverId): SupplierResponse
    {
        throw new ValidationNotSupportedException(
            'FakeSupplierAdapter has no player-validation endpoint — sandbox orders never validate against a real supplier.',
        );
    }
}
