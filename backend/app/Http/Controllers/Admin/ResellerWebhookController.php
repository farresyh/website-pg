<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreResellerWebhookRequest;
use App\Http\Requests\Admin\UpdateResellerWebhookStatusRequest;
use App\Models\Reseller;
use App\Services\Reseller\Webhook\ResellerWebhookService;
use App\Support\ResellerWebhookPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * ADR-084 PR-3 decision 10: the support-side counterpart to
 * `ResellerPortal\WebhookController` — admin can set/read/rotate/disable
 * a Reseller's delivery webhook and inspect its delivery log.
 * super_admin only, same tier as the rest of `/admin/resellers*`.
 */
class ResellerWebhookController extends Controller
{
    private const DELIVERIES_PER_PAGE = 20;

    public function __construct(private readonly ResellerWebhookService $webhooks) {}

    public function show(Reseller $reseller): JsonResponse
    {
        return response()->json([
            'webhook' => ResellerWebhookPresenter::webhook($reseller->webhook),
        ]);
    }

    /** `secret` is in the response ONLY when it was just generated (first creation). */
    public function store(StoreResellerWebhookRequest $request, Reseller $reseller): JsonResponse
    {
        $result = $this->webhooks->setEndpoint($reseller, $request->validated('url'));

        Log::info('Reseller webhook endpoint set by admin', ['reseller_id' => $reseller->id]);

        return response()->json([
            'webhook' => ResellerWebhookPresenter::webhook($result['webhook']),
            'secret' => $result['secret'],
        ], $result['secret'] !== null ? 201 : 200);
    }

    public function rotateSecret(Reseller $reseller): JsonResponse
    {
        $webhook = $reseller->webhook;

        abort_if($webhook === null, 404, 'No webhook endpoint is configured.');

        Log::info('Reseller webhook secret rotated by admin', ['reseller_id' => $reseller->id]);

        return response()->json([
            'secret' => $this->webhooks->rotateSecret($webhook),
        ]);
    }

    public function updateStatus(UpdateResellerWebhookStatusRequest $request, Reseller $reseller): JsonResponse
    {
        $webhook = $reseller->webhook;

        abort_if($webhook === null, 404, 'No webhook endpoint is configured.');

        $this->webhooks->setActive($webhook, $request->boolean('is_active'));

        return response()->json([
            'webhook' => ResellerWebhookPresenter::webhook($webhook->fresh()),
        ]);
    }

    public function destroy(Reseller $reseller): Response
    {
        $webhook = $reseller->webhook;

        if ($webhook !== null) {
            $this->webhooks->remove($webhook);
            Log::info('Reseller webhook endpoint removed by admin', ['reseller_id' => $reseller->id]);
        }

        return response()->noContent();
    }

    public function deliveries(Reseller $reseller): JsonResponse
    {
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
