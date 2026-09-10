<?php

namespace App\Services\Reseller;

use App\Models\Order;

/**
 * ADR-084 PR-1 decision 5: `ResellerOrderPlacementService::placeOrder()`
 * returns this instead of a bare `Order` so a caller can tell a fresh
 * placement (HTTP 201) from an idempotent replay (HTTP 200 +
 * `Idempotent-Replayed: true`). The Bot channel reads `->order` and
 * ignores `->wasReplay`.
 */
final class ResellerOrderPlacementResult
{
    public function __construct(
        public readonly Order $order,
        public readonly bool $wasReplay,
    ) {}
}
