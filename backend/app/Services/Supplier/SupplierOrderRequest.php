<?php

namespace App\Services\Supplier;

/**
 * The canonical outgoing order request business logic builds once.
 * Each Adapter transforms it into whatever field names/format a given
 * supplier's API expects (ADAPT-4) — business logic never constructs
 * a supplier-specific request shape itself.
 */
final class SupplierOrderRequest
{
    public function __construct(
        public readonly string $productRef,
        public readonly string $referenceNumber,
        public readonly string $playerId,
        public readonly ?string $serverId = null,
        public readonly ?string $customerPhone = null,
        public readonly ?string $callbackUrl = null,
        // ADR-097 decision 15 — the GAME-level override only
        // ('concat'|'space'|'pipe'|null), read straight off
        // Game.validation_rules. Digiflazz-specific; every other
        // adapter (Gamevion) ignores it. DigiflazzAdapter owns all
        // translation to the real wire character AND the fallback to
        // its own supplier-level default when this is null — the
        // caller never computes or knows the literal separator char.
        public readonly ?string $customerNoSeparator = null,
        // ADR-051 decision 6 — attributes this call's request-log row
        // to the order it belongs to; purely a logging convenience,
        // no adapter branches on it.
        public readonly ?int $orderId = null,
        // ADR-102 2026-10-05 addendum (rc correction): true when this call
        // re-submits a reference the supplier may already hold (a
        // NeedsReview retry). Digiflazz then reads it as a status check;
        // every other adapter ignores it.
        public readonly bool $resubmit = false,
    ) {}
}
