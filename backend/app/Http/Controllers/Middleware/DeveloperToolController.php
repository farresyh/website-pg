<?php

namespace App\Http\Controllers\Middleware;

use App\Http\Controllers\Controller;
use App\Http\Requests\Middleware\TestSupplierAdapterRequest;
use App\Models\Supplier;
use App\Services\Supplier\RequestLog\DeveloperTestContext;
use App\Services\Supplier\RequestLog\SupplierRequestPayloadRedactor;
use App\Services\Supplier\SupplierAdapter;
use App\Services\Supplier\SupplierAdapterFactory;
use App\Services\Supplier\SupplierConfigSchema;
use App\Services\Supplier\SupplierOrderRequest;
use App\Services\Supplier\SupplierResponse;
use App\Services\Supplier\SupplierStatusCheckRequest;
use App\Services\Supplier\UnsupportedSupplierException;
use App\Services\Supplier\ValidationNotSupportedException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * ADR-054 (DEV-1/2, MUI-11) — Developer raw API tester. One screen
 * satisfies both DEV-1/2 (§6.18) and MUI-11 (§6.20), which describe
 * the same feature. Every call goes through the real
 * `SupplierAdapter` — never a raw HTTP body forwarded to the supplier
 * (decision 2) — so an admin edits a typed request's fields, not an
 * arbitrary wire payload. Super Admin only, same boundary as every
 * other supplier-facing screen in this area.
 */
class DeveloperToolController extends Controller
{
    /**
     * Decision 3: dry-run (default) builds the exact request object a
     * real call would use and returns it without ever opening a
     * network connection. Decision 4: a live `createOrder` is hard-blocked
     * against a supplier not confirmed to be in sandbox/testing mode —
     * unlike the other 4 (read-only) methods, it can create a real,
     * un-tracked obligation on the supplier's side.
     */
    public function test(TestSupplierAdapterRequest $request, SupplierAdapterFactory $adapters): JsonResponse
    {
        $supplier = Supplier::query()->findOrFail($request->validated('supplier_id'));
        $method = $request->validated('method');
        $dryRun = (bool) $request->validated('dry_run');
        $payload = $request->validated('payload') ?? [];

        $missingKeys = SupplierConfigSchema::missingKeys($supplier->slug, $supplier->api_config ?? []);
        if ($missingKeys !== []) {
            throw ValidationException::withMessages([
                'supplier' => ['This supplier is not fully configured — missing: '.implode(', ', $missingKeys).'.'],
            ]);
        }

        $builtRequest = $this->buildRequest($method, $payload);

        if ($dryRun) {
            return response()->json([
                'dry_run' => true,
                'method' => $method,
                'request' => $this->redact($supplier->slug, $this->requestPreview($builtRequest)),
            ]);
        }

        if ($method === 'createOrder' && SupplierConfigSchema::isSandbox($supplier->slug, $supplier->api_config ?? []) !== true) {
            throw ValidationException::withMessages([
                'method' => ['createOrder cannot be live-fired against this supplier while it is in production mode (or its sandbox/testing mode is unconfirmed). Switch the supplier to sandbox/testing mode first, or use dry-run.'],
            ]);
        }

        try {
            $adapter = $adapters->make($supplier->slug);
        } catch (UnsupportedSupplierException $e) {
            throw ValidationException::withMessages(['supplier' => [$e->getMessage()]]);
        }

        try {
            $response = DeveloperTestContext::runIn(fn () => $this->dispatch($adapter, $method, $builtRequest));
        } catch (ValidationNotSupportedException $e) {
            throw ValidationException::withMessages([
                'method' => [$e->getMessage() !== '' ? $e->getMessage() : 'This supplier has no dedicated validatePlayer endpoint.'],
            ]);
        }

        return response()->json([
            'dry_run' => false,
            'method' => $method,
            'success' => $response->success,
            'outcome' => $response->outcome->value,
            'data' => $this->redact($supplier->slug, $this->responseData($method, $response)),
            'error_code' => $response->errorCode,
            'error_message' => $response->errorMessage,
        ]);
    }

    private function buildRequest(string $method, array $payload): SupplierOrderRequest|SupplierStatusCheckRequest|array|null
    {
        return match ($method) {
            'checkBalance', 'listProducts' => null,
            'checkStatus' => new SupplierStatusCheckRequest(
                supplierRef: $payload['supplier_ref'],
                productRef: $payload['product_ref'] ?? null,
                playerId: $payload['player_id'] ?? null,
                serverId: $payload['server_id'] ?? null,
            ),
            'validatePlayer' => [
                'player_id' => $payload['player_id'],
                'server_id' => $payload['server_id'] ?? null,
            ],
            // Decision 5 — a server-generated, unambiguously-test
            // reference; never admin-typed, never able to collide with
            // a real order's `REF-` reference_number sequence.
            'createOrder' => new SupplierOrderRequest(
                productRef: $payload['product_ref'],
                referenceNumber: 'DEVTEST-'.(string) Str::ulid(),
                playerId: $payload['player_id'],
                serverId: $payload['server_id'] ?? null,
                customerPhone: $payload['customer_phone'] ?? null,
                callbackUrl: $payload['callback_url'] ?? null,
            ),
        };
    }

    private function dispatch(SupplierAdapter $adapter, string $method, mixed $builtRequest): SupplierResponse
    {
        return match ($method) {
            'checkBalance' => $adapter->checkBalance(),
            'listProducts' => $adapter->listProducts(),
            'checkStatus' => $adapter->checkStatus($builtRequest),
            'validatePlayer' => $adapter->validatePlayer($builtRequest['player_id'], $builtRequest['server_id']),
            'createOrder' => $adapter->createOrder($builtRequest),
        };
    }

    private function requestPreview(SupplierOrderRequest|SupplierStatusCheckRequest|array|null $request): ?array
    {
        return is_object($request) ? (array) $request : $request;
    }

    /**
     * listProducts' real response is a full supplier catalog dump —
     * same sampling shape SupplierRequestLogger's own log storage
     * already applies (ADR-051 decision 5), so this screen doesn't
     * dump thousands of rows into the browser either.
     */
    private function responseData(string $method, SupplierResponse $response): mixed
    {
        $data = $response->data;

        if ($method !== 'listProducts' || ! is_array($data) || ! array_is_list($data)) {
            return $data;
        }

        return [
            'item_count' => count($data),
            'sample' => array_slice($data, 0, 5),
            'truncated' => count($data) > 5,
        ];
    }

    /**
     * Decision 8 — the response/preview viewer applies the same
     * redaction the request-log pipeline already uses, even though
     * neither the typed DTOs nor an adapter's normalized SupplierResponse
     * carry credentials today; defensive against a future field that does.
     */
    private function redact(string $slug, mixed $value): mixed
    {
        return is_array($value) ? SupplierRequestPayloadRedactor::redactBody($slug, $value) : $value;
    }
}
