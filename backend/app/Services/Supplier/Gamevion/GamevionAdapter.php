<?php

namespace App\Services\Supplier\Gamevion;

use App\Services\Supplier\SupplierAdapter;
use App\Services\Supplier\SupplierCatalogItem;
use App\Services\Supplier\SupplierOrderRequest;
use App\Services\Supplier\SupplierResponse;
use App\Services\Supplier\ValidationNotSupportedException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Adapter for Gamevion (docs.gamevion.com/gamevion-api, OAS 3.1.0,
 * confirmed against the real spec — not assumed). Key traits specific
 * to Gamevion that this adapter absorbs so business logic never has to
 * know about them:
 *  - Auth requires BOTH a Bearer token and an X-API-KEY header.
 *  - Sandbox mode reuses the production URL; only a header/body flag
 *    changes, and it returns a differently-prefixed product shape
 *    (sandbox_* instead of product_*).
 *  - A 409 on order creation means "already submitted" (their
 *    idempotency signal on `referenceNumber`), not a generic failure.
 *  - check-status takes Gamevion's own order id (`invoice_number` from
 *    the create-order response), never our `reference_number`.
 *  - No player-validation endpoint exists at all (ADR-005 fallback
 *    applies to every game on this supplier).
 */
final class GamevionAdapter implements SupplierAdapter
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $bearerToken,
        private readonly string $apiKey,
        private readonly bool $sandbox = false,
        private readonly ?string $proxyUrl = null,
        private readonly int $timeoutSeconds = 10,
        private readonly int $connectTimeoutSeconds = 5,
    ) {
    }

    public function checkBalance(): SupplierResponse
    {
        $response = $this->client()->post('/api/check-balance');

        if ($failure = $this->failureFrom($response)) {
            return $failure;
        }

        $data = $response->json('data', []);

        return SupplierResponse::success([
            'account_name' => $data['user_name'] ?? null,
            'account_email' => $data['user_email'] ?? null,
            'membership' => $data['user_membership'] ?? null,
            'balance' => isset($data['user_balance']) ? (float) $data['user_balance'] : null,
        ]);
    }

    public function listProducts(): SupplierResponse
    {
        $response = $this->client()->post('/api/product', array_filter([
            'sandbox' => $this->sandbox ?: null,
        ]));

        if ($failure = $this->failureFrom($response)) {
            return $failure;
        }

        $items = $response->json('data', []);

        return SupplierResponse::success(array_map(
            fn (array $item) => $this->normalizeProduct($item),
            $items,
        ));
    }

    public function createOrder(SupplierOrderRequest $request): SupplierResponse
    {
        $response = $this->client()->post('/api/order', array_filter([
            'product_code' => $request->productRef,
            'referenceNumber' => $request->referenceNumber,
            'data' => $request->serverId !== null
                ? "{$request->playerId}|{$request->serverId}"
                : $request->playerId,
            'telp' => $request->customerPhone,
            'callback_url' => $request->callbackUrl,
        ], fn ($value) => $value !== null));

        if ($response->status() === 409) {
            return SupplierResponse::failure(
                'duplicate_reference',
                'Gamevion already has an order for this reference number',
            );
        }

        if ($failure = $this->failureFrom($response)) {
            return $failure;
        }

        $data = $response->json('data', []);

        return SupplierResponse::success([
            'supplier_ref' => $data['invoice_number'] ?? null,
            'service_name' => $data['ServiceName'] ?? null,
            'price' => isset($data['price']) ? (float) $data['price'] : null,
            'quantity' => $data['quantity'] ?? null,
            'game' => $data['game'] ?? null,
            'player_id' => $data['uid'] ?? null,
            'server_id' => $data['server'] ?? null,
            'created_at' => $data['created_at'] ?? null,
        ]);
    }

    public function checkStatus(string $supplierRef): SupplierResponse
    {
        $response = $this->client()->post('/api/check-status', [
            'order_id' => $supplierRef,
        ]);

        if ($failure = $this->failureFrom($response)) {
            return $failure;
        }

        $data = $response->json('data', []);

        return SupplierResponse::success([
            'supplier_ref' => $data['invoice'] ?? null,
            'product_name' => $data['product_name'] ?? null,
            'status' => $data['transaction_status'] ?? null,
            'serial_number' => $data['serial_number'] ?? null,
            'note' => $data['note'] ?? null,
            'created_at' => $data['created_at'] ?? null,
        ]);
    }

    public function validatePlayer(string $playerId, ?string $serverId): SupplierResponse
    {
        throw new ValidationNotSupportedException(
            'Gamevion has no player-validation endpoint — validation happens implicitly at order time (ADR-005).',
        );
    }

    /**
     * createOrder() runs inside OrderFulfillmentService::fulfill()'s
     * DB::transaction()/lockForUpdate() (added during the 2026-07-25
     * audit to fix a double-delivery race) — a hung/slow Gamevion
     * response with no client-side timeout would hold that row lock
     * (and the open transaction) for as long as the socket stays open,
     * risking lock-wait timeouts/connection pool exhaustion under load.
     * Every request goes through this one client, so the timeout
     * applies uniformly, not just to createOrder().
     */
    private function client(): PendingRequest
    {
        $client = Http::baseUrl($this->baseUrl)
            ->withHeaders(array_filter([
                'Authorization' => "Bearer {$this->bearerToken}",
                'X-API-KEY' => $this->apiKey,
                'X-ENVIRONMENT' => $this->sandbox ? 'sandbox' : null,
            ]))
            ->timeout($this->timeoutSeconds)
            ->connectTimeout($this->connectTimeoutSeconds)
            ->acceptJson();

        if ($this->proxyUrl !== null) {
            $client = $client->withOptions(['proxy' => $this->proxyUrl]);
        }

        return $client;
    }

    /**
     * Gamevion's error-response body shape isn't defined in the spec
     * for 400/409/422/500/404 (status + description only) — this reads
     * defensively rather than assuming a body exists at all.
     */
    private function failureFrom(Response $response): ?SupplierResponse
    {
        if ($response->failed()) {
            $body = $response->json() ?? [];

            return SupplierResponse::failure(
                (string) $response->status(),
                $body['message'] ?? "Gamevion request failed with HTTP {$response->status()}",
            );
        }

        if (($response->json('error') ?? false) === true) {
            return SupplierResponse::failure(
                (string) ($response->json('code') ?? 'unknown'),
                $response->json('message') ?? 'Unknown Gamevion error',
            );
        }

        return null;
    }

    /**
     * Live mode: product_code/product_price (number)/...
     * Sandbox mode: sandbox_code/sandbox_price (string)/... — a
     * genuinely different field set for the same concept, confirmed
     * from the spec's oneOf. Both collapse to one canonical shape.
     */
    private function normalizeProduct(array $item): SupplierCatalogItem
    {
        if (array_key_exists('sandbox_code', $item)) {
            return new SupplierCatalogItem(
                productRef: $item['sandbox_code'] ?? '',
                name: $item['sandbox_serviceName'] ?? null,
                category: $item['sandbox_category'] ?? null,
                price: isset($item['sandbox_price']) ? (float) $item['sandbox_price'] : null,
                status: $item['sandbox_status'] ?? null,
            );
        }

        return new SupplierCatalogItem(
            productRef: $item['product_code'] ?? '',
            name: $item['product_serviceName'] ?? null,
            category: $item['product_category'] ?? null,
            price: isset($item['product_price']) ? (float) $item['product_price'] : null,
            status: $item['product_status'] ?? null,
        );
    }
}
