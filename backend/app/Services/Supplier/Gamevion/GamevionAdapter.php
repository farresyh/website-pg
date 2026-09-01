<?php

namespace App\Services\Supplier\Gamevion;

use App\Services\Http\TransientFailureRetryPolicy;
use App\Services\Supplier\RequestLog\SupplierRequestLogger;
use App\Services\Supplier\SupplierAdapter;
use App\Services\Supplier\SupplierCatalogItem;
use App\Services\Supplier\SupplierOrderRequest;
use App\Services\Supplier\SupplierResponse;
use App\Services\Supplier\SupplierStatusCheckRequest;
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
 *  - `telp` (phone) on order creation rejects a leading `+` — digits
 *    only, discovered live 2026-07-25 (see normalizePhone()).
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

    /**
     * ADR-046 addendum: always queries Gamevion's real/production
     * account, never the sandbox one — regardless of $this->sandbox
     * (which exists for testing order-routing, a separate concern).
     * Found live: X-ENVIRONMENT: sandbox routes check-balance to a
     * *different* account with its own (fake, test-money) balance, not
     * a sandboxed view of the same real number — a balance-monitoring
     * screen (SUPP-1/DASH-2) showing that instead of the real balance
     * whenever an admin happens to have sandbox mode on is a real
     * money-visibility risk, not a cosmetic one.
     */
    public function checkBalance(): SupplierResponse
    {
        $response = $this->client(forceProduction: true, callType: 'checkBalance')->post('/api/check-balance');

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
        $response = $this->client(callType: 'listProducts')->post('/api/product', array_filter([
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
        $response = $this->client(callType: 'createOrder', orderId: $request->orderId)->post('/api/order', array_filter([
            'product_code' => $request->productRef,
            'referenceNumber' => $request->referenceNumber,
            'data' => $request->serverId !== null
                ? "{$request->playerId}|{$request->serverId}"
                : $request->playerId,
            'telp' => $this->normalizePhone($request->customerPhone),
            // Gamevion's real API requires this key present in the body
            // even when there's no real callback (their own docs example
            // sends "" — confirmed live with Gamevion support 2026-07-29
            // after this array_filter was silently dropping the key
            // entirely whenever $request->callbackUrl was null, which it
            // always was — nothing in this codebase ever sets it. `??
            // ''` means this is never null by the time array_filter's
            // `$value !== null` check runs, so it's never stripped.
            'callback_url' => $request->callbackUrl ?? '',
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

    public function checkStatus(SupplierStatusCheckRequest $request): SupplierResponse
    {
        $response = $this->client(callType: 'checkStatus', orderId: $request->orderId)->post('/api/check-status', [
            'order_id' => $request->supplierRef,
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
    private function client(bool $forceProduction = false, string $callType = 'unknown', ?int $orderId = null): PendingRequest
    {
        $client = Http::baseUrl($this->baseUrl)
            ->withHeaders(array_filter([
                'Authorization' => "Bearer {$this->bearerToken}",
                'X-API-KEY' => $this->apiKey,
                'X-ENVIRONMENT' => ($this->sandbox && ! $forceProduction) ? 'sandbox' : null,
            ]))
            ->timeout($this->timeoutSeconds)
            ->connectTimeout($this->connectTimeoutSeconds)
            ->acceptJson()
            // ADR-014: 3 retries, 200ms/500ms/1s backoff, connection
            // failures and real 5xx only — never a 4xx like the 422
            // "invalid product code" ADR-006's sandbox retest hit.
            // throw:false keeps failureFrom()'s own status/body
            // inspection working unchanged for non-retried failures.
            ->retry([200, 500, 1000], when: TransientFailureRetryPolicy::shouldRetry(), throw: false);

        if ($this->proxyUrl !== null) {
            $client = $client->withOptions(['proxy' => $this->proxyUrl]);
        }

        // ADR-051 — every real outbound call from this adapter gets a
        // supplier_request_logs row, regardless of which of the 4
        // callers above reached this method.
        return SupplierRequestLogger::attach($client, 'gamevion', $callType, $orderId);
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
                isServerError: $response->serverError(),
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
     * Discovered live, 2026-07-25 (real end-to-end checkout test,
     * MLBB): Gamevion's `telp` field rejects a leading `+` — our own
     * checkout stores whatever format the customer typed
     * (`Order.customer_phone`, e.g. "+60123456789"), and Gamevion's
     * validator returned the confusingly-worded "telp field must be
     * between 9 and 13 digits" for a value that *is* 11 digits, just
     * with a `+` prefix that fails their "must be all-digit" check
     * entirely. ADAPT-4's own principle: this is exactly the kind of
     * supplier-specific format quirk the Adapter absorbs, not
     * something business logic (or the customer) should need to know.
     * Strips everything except digits — doesn't attempt to validate or
     * reformat into a specific length, since Gamevion's own real
     * constraint (9-13 digits) is theirs to enforce, not ours to
     * second-guess.
     */
    private function normalizePhone(?string $phone): ?string
    {
        if ($phone === null) {
            return null;
        }

        $digitsOnly = preg_replace('/\D/', '', $phone);

        return $digitsOnly !== '' ? $digitsOnly : null;
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
