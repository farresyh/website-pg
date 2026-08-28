<?php

namespace App\Services\Supplier;

/**
 * ADAPT-4: the canonical status-check request business logic builds
 * once. Widened beyond a bare $supplierRef string (ADR-030/032's own
 * original design) after checking Digiflazz's real docs while building
 * DigiflazzAdapter: their check-status is a literal re-submit of the
 * original topup request ("Cek status dapat dilakukan dengan melakukan
 * topup ulang dengan ref id yang sama"), which requires the SAME
 * buyer_sku_code + customer_no as the original transaction, not just
 * its ref_id — a single string can't carry that. Gamevion only ever
 * needs $supplierRef (its own invoice/order id); the rest stay null
 * and are ignored by that adapter, so this is additive for Gamevion's
 * own contract, not a behavior change.
 */
final class SupplierStatusCheckRequest
{
    public function __construct(
        // Gamevion: their invoice/order_id. Digiflazz: our own
        // reference_number, reused as their ref_id.
        public readonly string $supplierRef,
        public readonly ?string $productRef = null,
        public readonly ?string $playerId = null,
        public readonly ?string $serverId = null,
        // ADR-051 decision 6 — same request-log attribution as
        // SupplierOrderRequest::$orderId.
        public readonly ?int $orderId = null,
    ) {
    }
}
