<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Supplier;
use App\Services\Fulfillment\OrderFulfillmentService;
use App\Services\Order\InvalidOrderTransitionException;
use App\Services\Supplier\SupplierOutcome;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * ADR-069 — the inbound half of ADR-032 decision 4, deferred through
 * ADR-032's own build and ADR-067. Digiflazz's own async-delivery
 * webhook: a `Pending` order (createOrder returned `rc 03`) is
 * finalized here the moment Digiflazz confirms `Sukses`/`Gagal`,
 * instead of waiting on ReconcilePendingDeliveriesCommand's poll.
 *
 * Not behind auth:sanctum — Digiflazz is not an admin user. Two gates:
 *  1. request IP in `config('services.digiflazz.webhook_ips')` (403) —
 *     a config-driven second gate, not the primary auth.
 *  2. `X-Hub-Signature: sha1=<hex>` = HMAC-SHA1 of the raw body with
 *     `Supplier(slug=digiflazz).api_config['webhook_secret']` (401) —
 *     THE auth. Absent secret → every call is rejected (503).
 *
 * Mirrors ChipWebhookController's shape: verify, resolve the order,
 * hand off to the one shared money path (finalizePendingDelivery),
 * return. finalizePendingDelivery() is inline here (not queued like
 * ChipWebhookController's FulfillOrderJob) because it makes no external
 * call — its own lockForUpdate() + idempotency guard already hold, and
 * a duplicate webhook / webhook-vs-poll race lands on an
 * InvalidOrderTransitionException we catch and acknowledge.
 *
 * Prepaid payload shape (developer.digiflazz.com/api/buyer/webhook):
 *   { data: { ref_id, customer_no, buyer_sku_code, message, status,
 *             rc, buyer_last_saldo, sn, price, tele, wa } }
 * `ref_id` is our own `orders.reference_number` (globally unique).
 */
class DigiflazzWebhookController extends Controller
{
    public function __construct(
        private readonly OrderFulfillmentService $fulfillment,
    ) {}

    public function handle(Request $request): JsonResponse
    {
        $allowedIps = (array) config('services.digiflazz.webhook_ips', []);

        if (! in_array($request->ip(), $allowedIps, true)) {
            Log::warning('Rejected Digiflazz webhook: source IP not allowlisted', ['ip' => $request->ip()]);

            return response()->json(['message' => 'forbidden'], 403);
        }

        $secret = Supplier::query()->where('slug', 'digiflazz')->first()?->api_config['webhook_secret'] ?? null;

        if ($secret === null || $secret === '') {
            Log::warning('Rejected Digiflazz webhook: no webhook_secret configured on the digiflazz supplier');

            return response()->json(['message' => 'webhook not configured'], 503);
        }

        $expected = 'sha1='.hash_hmac('sha1', $request->getContent(), $secret);

        if (! hash_equals($expected, (string) $request->header('X-Hub-Signature', ''))) {
            Log::warning('Rejected Digiflazz webhook: invalid signature');

            return response()->json(['message' => 'invalid signature'], 401);
        }

        // Soft — the signature is the real gate; a wrong User-Agent
        // (postpaid/hotel Hookshot, or a probe) is worth a log line but
        // not a rejection.
        if (! str_contains((string) $request->userAgent(), 'Digiflazz-Hookshot')) {
            Log::warning('Digiflazz webhook: unexpected User-Agent', ['user_agent' => $request->userAgent()]);
        }

        $event = (string) $request->header('X-Digiflazz-Event', '');

        if ($event === 'resend') {
            // Hotel transactions only — never a prepaid game top-up.
            return response()->json(['message' => 'ignored']);
        }

        $data = $request->input('data');
        $refId = is_array($data) ? ($data['ref_id'] ?? null) : null;

        if ($refId === null) {
            Log::warning('Rejected Digiflazz webhook: payload has no data.ref_id');

            return response()->json(['message' => 'bad request'], 400);
        }

        $order = Order::query()->with('supplier')->where('reference_number', $refId)->first();

        if ($order === null) {
            // The daily reconcile poll is the backstop — nothing is
            // lost, only delayed.
            Log::warning('Rejected Digiflazz webhook: no order for ref_id', ['ref_id' => $refId]);

            return response()->json(['message' => 'order not found'], 404);
        }

        Log::withContext([
            'order_number' => $order->order_number,
            'ref_id' => $refId,
            'digiflazz_event' => $event ?: '(none)',
        ]);

        if ($order->supplier?->slug !== 'digiflazz') {
            Log::error('Rejected Digiflazz webhook: order belongs to another supplier', [
                'order_supplier' => $order->supplier?->slug,
            ]);

            return response()->json(['message' => 'supplier mismatch'], 409);
        }

        if (($data['buyer_sku_code'] ?? null) !== $order->supplier_product_ref) {
            Log::error('Rejected Digiflazz webhook: buyer_sku_code does not match the order', [
                'expected' => $order->supplier_product_ref,
                'received' => $data['buyer_sku_code'] ?? null,
            ]);

            return response()->json(['message' => 'sku mismatch'], 409);
        }

        $status = $data['status'] ?? null;

        $outcome = match ($status) {
            'Sukses' => SupplierOutcome::Success,
            'Gagal' => SupplierOutcome::Failure,
            default => null,
        };

        // Fold-in (decision 7): a Sukses callback carries the balance
        // after the debit — keeps Dashboard Health fresh for the one
        // supplier with live traffic, no scheduled call needed.
        if ($outcome === SupplierOutcome::Success && isset($data['buyer_last_saldo']) && $order->supplier !== null) {
            $order->supplier->update(['balance' => (float) $data['buyer_last_saldo']]);
        }

        if ($outcome === null) {
            // status is Pending, or something we don't act on.
            return response()->json(['message' => 'acknowledged']);
        }

        try {
            $this->fulfillment->finalizePendingDelivery(
                $order,
                $outcome,
                $outcome === SupplierOutcome::Success ? ($data['sn'] ?? null) : null,
                $data,
            );
        } catch (InvalidOrderTransitionException $e) {
            // Already finalized — synchronously at order time, by the
            // reconcile poll, or by a prior delivery of this webhook.
            // Expected race outcome, not an error (same guard
            // CheckSupplierDeliveryJob already uses).
            Log::info('Digiflazz webhook: order already finalized, ignoring', ['reason' => $e->getMessage()]);

            return response()->json(['message' => 'already finalized']);
        }

        return response()->json(['message' => 'ok']);
    }
}
