<?php

namespace App\Http\Controllers\ResellerApi;

use App\Exceptions\ResellerApi\ResellerApiException;
use App\Http\Requests\ResellerApi\IndexOrdersRequest;
use App\Http\Requests\ResellerApi\PlaceOrderRequest;
use App\Models\Game;
use App\Models\Order;
use App\Services\Checkout\CheckoutInputValidator;
use App\Services\Ledger\InsufficientBalanceException;
use App\Services\Reseller\IdempotencyKeyPayloadMismatchException;
use App\Services\Reseller\NoResellerTierAssignedException;
use App\Services\Reseller\ResellerCatalogService;
use App\Services\Reseller\ResellerInactiveException;
use App\Services\Reseller\ResellerOrderPlacementRequest;
use App\Services\Reseller\ResellerOrderPlacementService;
use App\Support\ResellerOrderPayload;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * ADR-074 decision 3 + ADR-084 PR-1/PR-2: the Reseller API's order
 * create / status / history endpoints. All money/order logic is
 * delegated (`ResellerCatalogService` resolves the code,
 * `ResellerOrderPlacementService` resolves the tier price and places the
 * order). This controller orchestrates, translates the service's
 * channel-neutral exceptions into the stable `ResellerApiException`
 * envelope, and shapes the reseller-safe response.
 */
class OrderController extends Controller
{
    /** ADR-084 PR-2: `GET /v1/orders` page size when the caller sends no `limit`. */
    private const DEFAULT_LIMIT = 25;

    /** The `publicOrder()` shape, for the `#[Response]` examples. */
    private const ORDER_EXAMPLE = [
        'order_number' => 'PG-7QK2M9X4RJ',
        'product_code' => 'MLMY-86',
        'player_id' => '123456789',
        'server_id' => '2201',
        'price_sen' => 6300,
        'payment_status' => 'paid',
        'delivery_status' => 'delivered',
        'created_at' => '2026-09-10T09:14:52+00:00',
        'delivered_at' => '2026-09-10T09:15:07+00:00',
    ];

    public function __construct(
        private readonly ResellerCatalogService $catalog,
        private readonly ResellerOrderPlacementService $placement,
        private readonly CheckoutInputValidator $checkoutInputValidator,
    ) {}

    #[Endpoint(
        title: 'List orders',
        description: "The caller's own orders, newest first, cursor-paginated. Pass `next_cursor` from the previous response back as `?cursor=` for the next page (`null` = no more). `?limit=` is capped at 100 (default 25); optional `?status=` filters on delivery status and `?created_after=` (ISO 8601) on creation time. A cursor, not a page number, so a new order landing mid-pagination never shifts the window.",
    )]
    #[Response(status: 200, description: 'A page of orders.', examples: [[
        'items' => [self::ORDER_EXAMPLE],
        'next_cursor' => 'eyJpZCI6MTQ4LCJfcG9pbnRzVG9OZXh0SXRlbXMiOnRydWV9',
    ]])]
    #[Response(status: 401, description: '`MISSING_API_KEY` or `INVALID_API_KEY`.', type: self::ERROR_SHAPE, examples: [self::ERROR_401])]
    #[Response(status: 403, description: '`RESELLER_INACTIVE` or `IP_NOT_ALLOWED`.', type: self::ERROR_SHAPE, examples: [self::ERROR_403])]
    #[Response(status: 422, description: '`VALIDATION_FAILED` — an unusable `limit`, `status` or `created_after`.', type: self::ERROR_SHAPE, examples: [[
        'error' => 'VALIDATION_FAILED', 'message' => 'The request payload failed validation.', 'details' => ['status' => ['The selected status is invalid.']],
    ]])]
    #[Response(status: 429, description: '`RATE_LIMITED` — retry after the `Retry-After` header.', type: self::ERROR_SHAPE, examples: [self::ERROR_429])]
    public function index(IndexOrdersRequest $request): JsonResponse
    {
        $reseller = $this->reseller($request);
        $data = $request->validated();

        $query = Order::query()
            ->where('wallet_reseller_id', $reseller->id)
            ->with(['game:id,reseller_code', 'package:id,denomination,catalog_code'])
            ->orderByDesc('id');

        if (isset($data['status'])) {
            $query->where('delivery_status', $data['status']);
        }

        if (isset($data['created_after'])) {
            $query->where('created_at', '>=', Carbon::parse($data['created_after']));
        }

        $page = $query->cursorPaginate($data['limit'] ?? self::DEFAULT_LIMIT);

        return response()->json([
            'items' => $page->getCollection()->map(fn (Order $order) => self::publicOrder($order))->all(),
            'next_cursor' => $page->nextCursor()?->encode(),
        ]);
    }

    #[Endpoint(
        title: 'Place an order',
        description: 'Charges your wallet and submits the order for fulfilment. Send a fresh `idempotency_key` (UUID) per logical order: a replay with the **same** key and payload returns the original order with HTTP 200 and an `Idempotent-Replayed: true` header; the **same** key with a different payload is a 409 conflict.',
    )]
    #[Response(status: 201, description: 'The order was placed.', examples: [self::ORDER_EXAMPLE])]
    #[Response(status: 200, description: 'Idempotent replay — the original order (also carries `Idempotent-Replayed: true`).', examples: [self::ORDER_EXAMPLE])]
    #[Response(status: 401, description: '`MISSING_API_KEY` or `INVALID_API_KEY`.', type: self::ERROR_SHAPE, examples: [self::ERROR_401])]
    #[Response(status: 403, description: '`RESELLER_INACTIVE` or `IP_NOT_ALLOWED`.', type: self::ERROR_SHAPE, examples: [self::ERROR_403])]
    #[Response(status: 409, description: '`IDEMPOTENCY_KEY_CONFLICT` — the key was reused with a different payload.', type: self::ERROR_SHAPE, examples: [self::ERROR_409])]
    #[Response(status: 422, description: '`VALIDATION_FAILED` (with `details`), `UNKNOWN_PRODUCT_CODE`, `NO_TIER_ASSIGNED` or `INSUFFICIENT_BALANCE`.', type: self::ERROR_SHAPE, examples: [self::ERROR_422])]
    #[Response(status: 429, description: '`RATE_LIMITED` — retry after the `Retry-After` header.', type: self::ERROR_SHAPE, examples: [self::ERROR_429])]
    public function store(PlaceOrderRequest $request): JsonResponse
    {
        $reseller = $this->reseller($request);
        $data = $request->validated();

        $package = $this->catalog->resolveByCode($data['product_code']);
        if ($package === null) {
            throw ResellerApiException::unknownProductCode();
        }

        // ADR-097 decision 18/19 — the same presence+value rule the
        // storefront and the Bot enforce, run here (not in
        // PlaceOrderRequest, which has no Game to check against yet —
        // product_code only resolves above) and not inside
        // ResellerOrderPlacementService::placeOrder() (which receives
        // an already-resolved gameId, mirroring exactly where the Bot
        // already does this same check today).
        $game = Game::query()->find($package->game_id);
        if ($game !== null && $error = $this->checkoutInputValidator->validate($game, $data['server_id'] ?? null)) {
            throw ResellerApiException::validationFailed([$error['field'] => [$error['message']]]);
        }

        try {
            $result = $this->placement->placeOrder($reseller, new ResellerOrderPlacementRequest(
                playerId: $data['player_id'],
                serverId: $data['server_id'] ?? null,
                costPriceSen: $package->cost_price,
                standardSellingPriceSen: $package->standard_selling_price,
                idempotencyKey: $data['idempotency_key'],
                supplierProductRef: $package->supplier_package_ref,
                gameId: $package->game_id,
                packageId: $package->id,
                supplierId: $package->supplier_id,
                payloadHash: self::payloadHash($data),
            ));
        } catch (ResellerInactiveException) {
            throw ResellerApiException::resellerInactive();
        } catch (NoResellerTierAssignedException) {
            throw ResellerApiException::noTierAssigned();
        } catch (InsufficientBalanceException) {
            throw ResellerApiException::insufficientBalance();
        } catch (IdempotencyKeyPayloadMismatchException) {
            throw ResellerApiException::idempotencyKeyConflict();
        }

        // ADR-084 PR-1 decision 5: 201 for a fresh placement, 200 +
        // `Idempotent-Replayed: true` for an exact replay.
        $response = response()->json(self::publicOrder($result->order), $result->wasReplay ? 200 : 201);

        if ($result->wasReplay) {
            $response->header('Idempotent-Replayed', 'true');
        }

        return $response;
    }

    #[Endpoint(
        title: 'Get an order',
        description: "The current status of one of the caller's own orders. Poll this as the fallback to the delivery webhook.",
    )]
    #[Response(status: 200, description: 'The order.', examples: [self::ORDER_EXAMPLE])]
    #[Response(status: 401, description: '`MISSING_API_KEY` or `INVALID_API_KEY`.', type: self::ERROR_SHAPE, examples: [self::ERROR_401])]
    #[Response(status: 403, description: '`RESELLER_INACTIVE` or `IP_NOT_ALLOWED`.', type: self::ERROR_SHAPE, examples: [self::ERROR_403])]
    #[Response(status: 404, description: '`ORDER_NOT_FOUND` — no order with that number belongs to the caller.', type: self::ERROR_SHAPE, examples: [self::ERROR_404])]
    #[Response(status: 429, description: '`RATE_LIMITED` — retry after the `Retry-After` header.', type: self::ERROR_SHAPE, examples: [self::ERROR_429])]
    public function show(Request $request, string $orderNumber): JsonResponse
    {
        $reseller = $this->reseller($request);

        $order = Order::query()
            ->where('order_number', $orderNumber)
            ->where('wallet_reseller_id', $reseller->id)
            ->first();

        if ($order === null) {
            throw ResellerApiException::orderNotFound();
        }

        return response()->json(self::publicOrder($order));
    }

    /**
     * ADR-084 PR-1 decision 5: the idempotency payload hash covers the
     * semantic order inputs only — not `idempotency_key` itself (that is
     * the key), and not any transport noise. A stable field order so the
     * same logical request always hashes identically.
     *
     * @param  array<string, mixed>  $data
     */
    private static function payloadHash(array $data): string
    {
        return hash('sha256', json_encode([
            'product_code' => $data['product_code'],
            'player_id' => $data['player_id'],
            'server_id' => $data['server_id'] ?? null,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * Narrow, reseller-safe shape — never `cost_price`/`platform_profit`
     * or any other reseller-private field (ADR-084 decision 2). Extracted
     * to `App\Support\ResellerOrderPayload` in PR-3 so the delivery
     * webhook body and this endpoint can never drift.
     *
     * @return array<string, mixed>
     */
    private static function publicOrder(Order $order): array
    {
        return ResellerOrderPayload::for($order);
    }
}
