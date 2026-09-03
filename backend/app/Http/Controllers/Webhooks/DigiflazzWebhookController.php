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
 * Not behind auth:sanctum — Digiflazz is not an admin user. Auth:
 *  - `X-Hub-Signature: sha1=<hex>` = HMAC-SHA1 of the raw body with
 *    `Supplier(slug=digiflazz).api_config['webhook_secret']` (401) —
 *    THE auth. Absent secret → every call is rejected (503).
 *  - `config('services.digiflazz.webhook_ips')` — a SOFT
 *    defence-in-depth signal (log only, never a 403): `$request->ip()`
 *    stops being the real client IP the moment a proxy/CDN sits in
 *    front (ADR-069 stress-test Q1). Empty config = check disabled.
 *
 * A 404 from this route is BENIGN and expected: `fulfill()` writes
 * `reference_number` and calls Digiflazz inside one DB::transaction(),
 * so a `create` event racing that uncommitted transaction finds no
 * row. The `update` event (post-commit) or the reconcile poll
 * finalizes it. Do not alarm on 404s here.
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
        // --- The HMAC signature is THE auth. Everything else is a soft
        // signal. Check it first: it needs the webhook_secret, so an
        // absent secret is a 503 (config gap), not a 401. ---
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

        // ADR-069 stress-test Q1 — the IP allowlist is a SOFT
        // defence-in-depth signal, not a gate: `$request->ip()` becomes
        // an edge IP the moment anything (Cloudflare, a load balancer)
        // sits in front, and a hard 403 there is a silent webhook
        // outage that only the ~10-min poll would paper over. An empty
        // `webhook_ips` config disables the check entirely.
        $allowedIps = array_values(array_filter((array) config('services.digiflazz.webhook_ips', [])));

        if ($allowedIps !== [] && ! in_array($request->ip(), $allowedIps, true)) {
            Log::warning('Digiflazz webhook: source IP not in the allowlist (processing anyway — signature verified)', [
                'ip' => $request->ip(),
            ]);
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
            // ADR-069 stress-test Q2 — a 404 here is BENIGN and
            // expected in normal operation: `fulfill()` writes
            // `reference_number` and calls Digiflazz inside one
            // DB::transaction(), so a `create` event that races our own
            // uncommitted transaction sees no row. The later `update`
            // event (after the txn commits) or the reconcile poll
            // finalizes it — nothing is lost, only delayed.
            Log::info('Digiflazz webhook: no order yet for ref_id — a create event likely raced fulfillment; the update event or poll will finalize', [
                'ref_id' => $refId,
            ]);

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
