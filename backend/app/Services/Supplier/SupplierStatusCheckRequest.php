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
 * its ref_id — a single string can't carry that. Gamevion's v2 API
 * (ADR-137) reads our reference first and, only when it never saw it,
 * posts the same order, so it needs the same fields.
 */
final class SupplierStatusCheckRequest
{
    public function __construct(
        // Our own reference_number: Digiflazz's ref_id, Gamevion's client_ref.
        public readonly string $supplierRef,
        public readonly ?string $productRef = null,
        public readonly ?string $playerId = null,
        public readonly ?string $serverId = null,
        // ADR-097 decision 14/15 — same GAME-level separator override
        // as SupplierOrderRequest::$customerNoSeparator, needed here
        // too: Digiflazz's checkStatus is a literal re-submit of the
        // original topup request, so a mismatched customer_no on the
        // re-submit is as wrong as one on the original createOrder().
        public readonly ?string $customerNoSeparator = null,
        // ADR-051 decision 6 — same request-log attribution as
        // SupplierOrderRequest::$orderId.
        public readonly ?int $orderId = null,
    ) {}
}
