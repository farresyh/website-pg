<?php

namespace App\Services\Supplier\RequestLog;

use App\Jobs\LogSupplierRequestJob;
use GuzzleHttp\TransferStats;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Client\Response as ClientResponse;
use Throwable;

/**
 * ADR-051 decision 2 — hooks Guzzle's per-attempt `on_stats` option
 * rather than Laravel's RequestSending/ResponseReceived events:
 * those events carry only the PSR-7 request/response, no way to
 * attach a call's business context (supplier slug/call_type/order_id),
 * while on_stats is set locally per PendingRequest and so has direct
 * closure access to it. Fires once per actual transfer attempt,
 * including a retried one (ADR-014's retry policy) — each attempt is
 * its own real outbound call and gets its own row, which is more
 * useful for debugging a retry storm than collapsing them into one.
 */
final class SupplierRequestLogger
{
    public static function attach(PendingRequest $client, string $slug, string $callType, ?int $orderId = null): PendingRequest
    {
        return $client->withOptions([
            'on_stats' => function (TransferStats $stats) use ($slug, $callType, $orderId) {
                self::log($stats, $slug, $callType, $orderId);
            },
        ]);
    }

    private static function log(TransferStats $stats, string $slug, string $callType, ?int $orderId): void
    {
        $request = new ClientRequest($stats->getRequest());
        $durationMs = $stats->getTransferTime() !== null ? (int) round($stats->getTransferTime() * 1000) : null;

        $requestPayload = [
            'headers' => SupplierRequestPayloadRedactor::redactHeaders($request->headers()),
            'body' => SupplierRequestPayloadRedactor::redactBody($slug, $request->data() ?: null),
        ];

        if (! $stats->hasResponse()) {
            LogSupplierRequestJob::dispatch([
                'slug' => $slug,
                'call_type' => $callType,
                'order_id' => $orderId,
                'method' => $request->method(),
                'url' => $request->url(),
                'status_code' => null,
                'outcome' => 'exception',
                'duration_ms' => $durationMs,
                'request_payload' => $requestPayload,
                'response_payload' => null,
                'error_message' => self::handlerErrorMessage($stats),
            ]);

            return;
        }

        $response = new ClientResponse($stats->getResponse());

        LogSupplierRequestJob::dispatch([
            'slug' => $slug,
            'call_type' => $callType,
            'order_id' => $orderId,
            'method' => $request->method(),
            'url' => $request->url(),
            'status_code' => $response->status(),
            'outcome' => $response->successful() ? 'success' : 'failure',
            'duration_ms' => $durationMs,
            'request_payload' => $requestPayload,
            'response_payload' => self::responsePayload($slug, $callType, $response),
            'error_message' => null,
        ]);
    }

    /**
     * ADR-051 decision 5 — listProducts' response is a full supplier
     * catalog dump; every other call type's response is small enough
     * to store in full.
     *
     * @return array<string, mixed>|null
     */
    private static function responsePayload(string $slug, string $callType, ClientResponse $response): ?array
    {
        $decoded = $response->json();
        $body = is_array($decoded) ? SupplierRequestPayloadRedactor::redactBody($slug, $decoded) : null;

        if ($callType !== 'listProducts' || $body === null) {
            return $body;
        }

        // Both real adapters nest the catalog under a top-level `data`
        // array — sample from there when present so truncation drops
        // the actual bulk of the payload.
        $items = $body['data'] ?? $body;
        $isList = is_array($items) && array_is_list($items);

        return [
            'item_count' => $isList ? count($items) : null,
            'sample' => $isList ? array_slice($items, 0, 3) : $body,
            'truncated' => $isList,
        ];
    }

    private static function handlerErrorMessage(TransferStats $stats): ?string
    {
        $error = $stats->getHandlerErrorData();

        if ($error instanceof Throwable) {
            return $error->getMessage();
        }

        return is_string($error) ? $error : null;
    }
}
