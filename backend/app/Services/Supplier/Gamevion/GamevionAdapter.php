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
use Illuminate\Support\Sleep;

/**
 * Adapter for Gamevion's API at gamevion.cloud ("Gamevion Dokumentasi API"
 * v62.25, ADR-137). What it absorbs so business logic never sees it:
 *  - One `Authorization: Bearer gvk_…` key; every price is MYR.
 *  - Our product reference is `{game}:{sku}`: a sku is unique only inside
 *    its game.
 *  - An order is usually `pending` first; the final status comes by the
 *    signed webhook (GamevionWebhookController) or a lookup.
 *  - Our reference is Gamevion's `client_ref`. A repeat POST with the same
 *    `client_ref` returns the original order and never charges twice, and
 *    `GET /api/orders/{client_ref}` answering 404 means it was never made.
 *    So a re-submit reads first and posts only after a 404 (decision 13):
 *    an error on that post answers for the order.
 *  - `error`, `cancelled` and `refunded` return the balance (decision 14).
 */
final class GamevionAdapter implements SupplierAdapter
{
    /** Player fields checkout can fill; a game requiring any other is not synced (decision 16). */
    private const SUPPORTED_FIELDS = ['userid', 'serverid'];

    /** Pricelist calls are limited to 40 a minute per account; 1.6 s apart stays under it. */
    private const PRICELIST_PAUSE_MS = 1600;

    /** Error codes for which Gamevion made no order and took no balance. */
    private const NO_ORDER_CODES = ['invalid_request', 'insufficient_balance', 'not_found', 'price_changed', 'invalid_player_id'];

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly ?string $proxyUrl = null,
        private readonly int $timeoutSeconds = 10,
        private readonly int $connectTimeoutSeconds = 5,
    ) {}

    public function checkBalance(): SupplierResponse
    {
        $response = $this->client('checkBalance')->get('/api/balance');

        if ($failure = $this->failureFrom($response)) {
            return $failure;
        }

        return SupplierResponse::success([
            'account_name' => $response->json('username'),
            'account_email' => null,
            'membership' => null,
            'balance' => $response->json('balance') !== null ? (float) $response->json('balance') : null,
        ]);
    }

    /**
     * Every `gametopup` game whose required fields checkout can fill, one
     * pricelist each. Any failed call fails the whole sync: a partial
     * catalog would read as products vanishing and switch packages off.
     */
    public function listProducts(): SupplierResponse
    {
        $games = $this->client('listProducts')->get('/api/games');

        if ($failure = $this->failureFrom($games)) {
            return $failure;
        }

        $items = [];
        $first = true;

        foreach ((array) $games->json() as $game) {
            if (($game['kind'] ?? null) !== 'gametopup' || empty($game['code'])) {
                continue;
            }

            $fields = $this->client('listProducts')->get("/api/games/{$game['code']}/fields");

            if ($failure = $this->failureFrom($fields)) {
                return $failure;
            }

            if (! self::fieldsSupported((array) $fields->json('fields'))) {
                continue;
            }

            if (! $first) {
                Sleep::for(self::PRICELIST_PAUSE_MS)->milliseconds();
            }
            $first = false;

            $pricelist = $this->client('listProducts')->get("/api/pricelist/{$game['code']}");

            if ($failure = $this->failureFrom($pricelist)) {
                return $failure;
            }

            foreach ((array) $pricelist->json('prices') as $price) {
                $items[] = $this->catalogItem($game, $price);
            }
        }

        return SupplierResponse::success($items);
    }

    public function createOrder(SupplierOrderRequest $request): SupplierResponse
    {
        if ($request->resubmit) {
            return $this->lookupThenPost($request->referenceNumber, $request->productRef, $request->playerId, $request->serverId, $request->orderId);
        }

        return $this->post($request->referenceNumber, $request->productRef, $request->playerId, $request->serverId, $request->orderId);
    }

    /** Our reference is the `client_ref`, so a check reads it and posts only when Gamevion never made it. */
    public function checkStatus(SupplierStatusCheckRequest $request): SupplierResponse
    {
        return $this->lookupThenPost($request->supplierRef, (string) $request->productRef, (string) $request->playerId, $request->serverId, $request->orderId);
    }

    public function validatePlayer(string $playerId, ?string $serverId): SupplierResponse
    {
        // `/api/validate` needs the game code, which this interface does not carry (ADR-005 fallback).
        throw new ValidationNotSupportedException('Gamevion player validation needs the game code; validation happens at order time (ADR-005).');
    }

    /**
     * The one mapping from a Gamevion order object to our outcome, shared
     * by the order calls and the webhook (decision 14). A delivered order
     * that is later `refunded` is the webhook's concern, not this mapping's.
     *
     * @param  array<string, mixed>  $order
     */
    public static function responseFor(array $order): SupplierResponse
    {
        $data = [
            'supplier_ref' => $order['order_id'] ?? null,
            'status' => $order['order_status'] ?? null,
            'price' => isset($order['price']) ? (float) $order['price'] : null,
            'message' => $order['message'] ?? null,
        ];

        return match ($order['order_status'] ?? null) {
            'successful' => SupplierResponse::success($data),
            'pending' => SupplierResponse::pending($data),
            'error', 'cancelled', 'refunded' => SupplierResponse::failure(
                (string) $order['order_status'],
                (string) (($order['message'] ?? '') ?: "Gamevion order {$order['order_status']}"),
                outcomeConfirmedFailed: true,
            ),
            default => SupplierResponse::failure('unknown', 'Gamevion returned an order with no known status', resendUnsafeWithSameReference: true),
        };
    }

    private function lookupThenPost(string $reference, string $productRef, string $playerId, ?string $serverId, ?int $orderId): SupplierResponse
    {
        $lookup = $this->client('checkStatus', $orderId)->get('/api/orders/'.rawurlencode($reference));

        if ($lookup->status() === 404) {
            return $this->post($reference, $productRef, $playerId, $serverId, $orderId);
        }

        return $this->failureFrom($lookup) ?? self::responseFor((array) $lookup->json());
    }

    private function post(string $reference, string $productRef, string $playerId, ?string $serverId, ?int $orderId): SupplierResponse
    {
        [$game, $sku] = array_pad(explode(':', $productRef, 2), 2, '');

        $response = $this->client('createOrder', $orderId)->post('/api/order', array_filter([
            'game' => $game,
            'denom' => $sku,
            'userid' => $playerId,
            'serverid' => $serverId,
            'client_ref' => $reference,
        ], fn ($value) => $value !== null && $value !== ''));

        return $this->failureFrom($response) ?? self::responseFor((array) $response->json());
    }

    /**
     * Errors carry a machine `code`. A no-order code is a confirmed failure;
     * everything else (a concurrent repeat, a rate limit, 5xx, an auth
     * failure, an unreadable body) leaves the outcome unknown: Pending with
     * a same-reference poll (decision 13). 401/403 count against the breaker
     * like a 5xx (decision 12).
     */
    private function failureFrom(Response $response): ?SupplierResponse
    {
        if ($response->successful() && is_array($response->json())) {
            return null;
        }

        $code = (string) ($response->json('code') ?? $response->status());
        $message = (string) ($response->json('message') ?? "Gamevion request failed with HTTP {$response->status()}");

        if (in_array($code, self::NO_ORDER_CODES, true)) {
            return SupplierResponse::failure($code, $message, outcomeConfirmedFailed: true);
        }

        return SupplierResponse::failure(
            $code,
            $message,
            isServerError: $response->serverError() || in_array($response->status(), [401, 403], true),
            resendUnsafeWithSameReference: true,
        );
    }

    /** @param  list<array<string, mixed>>  $fields */
    private static function fieldsSupported(array $fields): bool
    {
        foreach ($fields as $field) {
            if (! empty($field['required']) && ! in_array($field['key'] ?? null, self::SUPPORTED_FIELDS, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $game
     * @param  array<string, mixed>  $price
     */
    private function catalogItem(array $game, array $price): SupplierCatalogItem
    {
        $amount = isset($price['price']) ? (float) $price['price'] : null;

        return new SupplierCatalogItem(
            productRef: "{$game['code']}:{$price['sku']}",
            name: $price['label'] ?? null,
            category: $game['name'] ?? $game['code'],
            price: $amount,
            status: ($price['available'] ?? false) ? 'active' : 'inactive',
            groupLabel: $game['name'] ?? $game['code'],
            type: $price['group'] ?? null,
            rawPrice: $amount,
            rawCurrency: $amount !== null ? 'MYR' : null,
        );
    }

    /**
     * createOrder() runs inside OrderFulfillmentService's row lock, so every
     * call keeps the short timeout. The shared retry covers connection
     * failures and 5xx only; repeating a POST is safe because the same
     * `client_ref` never charges twice.
     */
    private function client(string $callType, ?int $orderId = null): PendingRequest
    {
        $client = Http::baseUrl($this->baseUrl)
            ->withToken($this->apiKey)
            ->withHeaders(['X-Lang' => 'en'])
            ->timeout($this->timeoutSeconds)
            ->connectTimeout($this->connectTimeoutSeconds)
            ->acceptJson()
            ->retry([200, 500, 1000], when: TransientFailureRetryPolicy::shouldRetry(), throw: false);

        if ($this->proxyUrl !== null) {
            $client = $client->withOptions(['proxy' => $this->proxyUrl]);
        }

        return SupplierRequestLogger::attach($client, 'gamevion', $callType, $orderId);
    }
}
