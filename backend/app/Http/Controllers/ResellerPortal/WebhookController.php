<?php

namespace App\Http\Controllers\ResellerPortal;

use App\Http\Requests\ResellerPortal\StoreWebhookRequest;
use App\Http\Requests\ResellerPortal\UpdateWebhookStatusRequest;
use App\Services\Reseller\Webhook\ResellerWebhookService;
use App\Support\ResellerWebhookPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ADR-084 PR-3 decision 4/10: full self-service delivery-webhook
 * management for a Reseller (wallet) portal user — endpoint URL, secret
 * (shown once, rotatable), active toggle, and the dead-letter delivery
 * log. Reuses `ResellerWebhookService` as-is, the same seam
 * `Admin\ResellerWebhookController` uses for the support path.
 */
class WebhookController extends Controller
{
    private const DELIVERIES_PER_PAGE = 20;

    public function __construct(private readonly ResellerWebhookService $webhooks) {}

    public function show(Request $request): JsonResponse
    {
        $reseller = $this->reseller($request);

        return response()->json([
            'webhook' => ResellerWebhookPresenter::webhook($reseller->webhook),
        ]);
    }

    /**
     * Set the endpoint (first call) or update its URL. `secret` is in the
     * response ONLY on first creation — never retrievable again; use
     * `rotateSecret()` to replace it.
     */
    public function store(StoreWebhookRequest $request): JsonResponse
    {
        $reseller = $this->reseller($request);

        $result = $this->webhooks->setEndpoint($reseller, $request->validated('url'));

        return response()->json([
            'webhook' => ResellerWebhookPresenter::webhook($result['webhook']),
            'secret' => $result['secret'],
        ], $result['secret'] !== null ? 201 : 200);
    }

    /** Replace the signing secret. The new plaintext is in this response only. */
    public function rotateSecret(Request $request): JsonResponse
    {
        $reseller = $this->reseller($request);
        $webhook = $reseller->webhook;

        abort_if($webhook === null, 404, 'No webhook endpoint is configured.');

        return response()->json([
            'secret' => $this->webhooks->rotateSecret($webhook),
        ]);
    }

    public function updateStatus(UpdateWebhookStatusRequest $request): JsonResponse
    {
        $reseller = $this->reseller($request);
        $webhook = $reseller->webhook;

        abort_if($webhook === null, 404, 'No webhook endpoint is configured.');

        $this->webhooks->setActive($webhook, $request->boolean('is_active'));

        return response()->json([
            'webhook' => ResellerWebhookPresenter::webhook($webhook->fresh()),
        ]);
    }

    public function destroy(Request $request): Response
    {
        $reseller = $this->reseller($request);
        $webhook = $reseller->webhook;

        if ($webhook !== null) {
            $this->webhooks->remove($webhook);
        }

        return response()->noContent();
    }

    public function deliveries(Request $request): JsonResponse
    {
        $reseller = $this->reseller($request);

        $page = $reseller->webhookDeliveries()
            ->with('order:id,order_number')
            ->latest('id')
            ->paginate(self::DELIVERIES_PER_PAGE);

        return response()->json([
            'data' => collect($page->items())->map(fn ($delivery) => ResellerWebhookPresenter::delivery($delivery))->all(),
            'current_page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            'per_page' => $page->perPage(),
            'total' => $page->total(),
        ]);
    }
}
