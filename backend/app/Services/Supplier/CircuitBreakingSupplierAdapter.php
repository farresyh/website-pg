<?php

namespace App\Services\Supplier;

use App\Services\CircuitBreaker\CircuitBreaker;

/**
 * Wraps a real SupplierAdapter (e.g. GamevionAdapter) with a
 * per-supplier circuit breaker (ADR-019 addendum,
 * foundation-security.md §6, DASH-2). A decorator, not a change to
 * GamevionAdapter itself, so any current/future SupplierAdapter
 * implementation gets the same protection for free (ADAPT-3).
 *
 * validatePlayer() is deliberately NOT guarded: on this project's one
 * real adapter it throws ValidationNotSupportedException synchronously,
 * with no network call made at all (ADR-005) - there is no "supplier
 * down" failure mode to trip on, so wrapping it would only add a
 * confusing extra branch for no protective value.
 */
final class CircuitBreakingSupplierAdapter implements SupplierAdapter
{
    public function __construct(
        private readonly SupplierAdapter $inner,
        private readonly CircuitBreaker $breaker,
    ) {
    }

    public function checkBalance(): SupplierResponse
    {
        return $this->guarded(fn () => $this->inner->checkBalance());
    }

    public function listProducts(): SupplierResponse
    {
        return $this->guarded(fn () => $this->inner->listProducts());
    }

    public function createOrder(SupplierOrderRequest $request): SupplierResponse
    {
        return $this->guarded(fn () => $this->inner->createOrder($request));
    }

    public function checkStatus(string $supplierRef): SupplierResponse
    {
        return $this->guarded(fn () => $this->inner->checkStatus($supplierRef));
    }

    public function validatePlayer(string $playerId, ?string $serverId): SupplierResponse
    {
        return $this->inner->validatePlayer($playerId, $serverId);
    }

    /**
     * Only a genuine server error (isServerError - HTTP 5xx, "the
     * supplier itself is failing") trips the breaker. A definitive
     * business rejection (4xx - invalid product, insufficient balance,
     * a duplicate-reference 409) is treated as a health signal too:
     * the supplier is clearly up and gave a coherent answer, so it
     * resets the failure count exactly like a real success would.
     * Without this split, three ordinary "insufficient balance" orders
     * in a row would wrongly block every other order/game on a
     * perfectly healthy supplier.
     */
    private function guarded(callable $call): SupplierResponse
    {
        if ($this->breaker->isOpen()) {
            return SupplierResponse::failure(
                'CIRCUIT_OPEN',
                'Supplier circuit breaker is open - too many recent failures, calls are paused for a cooldown window.',
            );
        }

        $response = $call();

        if ($response->isServerError) {
            $this->breaker->recordFailure();
        } else {
            $this->breaker->recordSuccess();
        }

        return $response;
    }
}
