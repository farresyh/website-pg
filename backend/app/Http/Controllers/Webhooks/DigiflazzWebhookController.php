<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderDeliveryLeg;
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
 * `ref_id` is our own `orders.reference_number` (globally unique) —
 * or, for a combo order (ADR-094 decision 7, Phase 3b), that same
 * value with a `-L{n}` suffix identifying which `order_delivery_legs`
 * row this call is for. The suffix is parsed off before the exact-
 * column `Order` lookup below, which always matches on the bare
 * reference_number either way.
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
        $rawRefId = is_array($data) ? ($data['ref_id'] ?? null) : null;

        if ($rawRefId === null) {
            Log::warning('Rejected Digiflazz webhook: payload has no data.ref_id');

            return response()->json(['message' => 'bad request'], 400);
        }

        // ADR-094 decision 7 (Phase 3b): a combo leg's ref_id is the
        // order's own reference_number plus -L{n} — parse it off before
        // the exact-column lookup, which always matches on the bare
        // value either way.
        $refId = $rawRefId;
        $legNumber = null;

        if (preg_match('/^(.+)-L(\d+)$/', $rawRefId, $matches) === 1) {
            $refId = $matches[1];
            $legNumber = (int) $matches[2];
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
                'ref_id' => $rawRefId,
            ]);

            return response()->json(['message' => 'order not found'], 404);
        }

        Log::withContext([
            'order_number' => $order->order_number,
            'ref_id' => $rawRefId,
            'digiflazz_event' => $event ?: '(none)',
        ]);

        if ($legNumber !== null) {
            return $this->handleComboLeg($order, $legNumber, $data);
        }

        if ($order->supplier?->slug !== 'digiflazz') {
            Log::error('Rejected Digiflazz webhook: order belongs to another supplier', [
                'order_supplier' => $order->supplier?->slug,
            ]);

            return response()->json(['message' => 'supplier mismatch'], 409);
        }

        // Case-insensitive: confirmed in prod 2026-09-15 that Digiflazz's
        // `update` event lowercases buyer_sku_code even though their
        // `create` event and our own stored supplier_product_ref (synced
        // from their product list) are uppercase — an exact `!==` here
        // rejected every real `update` callback, silently stranding
        // delivery_status at Pending until the ~10-min poll caught up.
        if (! self::skuMatches($data['buyer_sku_code'] ?? null, $order->supplier_product_ref)) {
            Log::error('Rejected Digiflazz webhook: buyer_sku_code does not match the order', [
                'expected' => $order->supplier_product_ref,
                'received' => $data['buyer_sku_code'] ?? null,
            ]);

            return response()->json(['message' => 'sku mismatch'], 409);
        }

        $outcome = $this->outcomeFrom($data);

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

    /**
     * ADR-094 decision 7 (Phase 3b): the combo counterpart to the plain
     * flow above — every check re-scoped to the LEG's own component
     * package (a combo `Order` has no `supplier_product_ref`/`supplier`
     * of its own, decision 3) and finalization goes through
     * finalizePendingDeliveryLeg(), not finalizePendingDelivery().
     */
    private function handleComboLeg(Order $order, int $legNumber, mixed $data): JsonResponse
    {
        $leg = OrderDeliveryLeg::query()
            ->where('order_id', $order->id)
            ->where('leg_number', $legNumber)
            ->with('componentPackage.supplier')
            ->first();

        if ($leg === null) {
            Log::warning('Rejected Digiflazz webhook: no matching order_delivery_legs row for this leg number', [
                'leg_number' => $legNumber,
            ]);

            return response()->json(['message' => 'leg not found'], 404);
        }

        $component = $leg->componentPackage;

        if ($component?->supplier?->slug !== 'digiflazz') {
            Log::error('Rejected Digiflazz webhook: combo leg belongs to another supplier', [
                'leg_supplier' => $component?->supplier?->slug,
            ]);

            return response()->json(['message' => 'supplier mismatch'], 409);
        }

        if (! self::skuMatches($data['buyer_sku_code'] ?? null, $component->supplier_package_ref)) {
            Log::error('Rejected Digiflazz webhook: buyer_sku_code does not match the leg\'s component package', [
                'expected' => $component->supplier_package_ref,
                'received' => $data['buyer_sku_code'] ?? null,
            ]);

            return response()->json(['message' => 'sku mismatch'], 409);
        }

        $outcome = $this->outcomeFrom($data);

        if ($outcome === SupplierOutcome::Success && isset($data['buyer_last_saldo'])) {
            $component->supplier->update(['balance' => (float) $data['buyer_last_saldo']]);
        }

        if ($outcome === null) {
            return response()->json(['message' => 'acknowledged']);
        }

        try {
            $this->fulfillment->finalizePendingDeliveryLeg(
                $leg,
                $outcome,
                $outcome === SupplierOutcome::Success ? ($data['sn'] ?? null) : null,
                $data,
            );
        } catch (InvalidOrderTransitionException $e) {
            Log::info('Digiflazz webhook: combo leg already finalized, ignoring', ['reason' => $e->getMessage()]);

            return response()->json(['message' => 'already finalized']);
        }

        return response()->json(['message' => 'ok']);
    }

    private function outcomeFrom(mixed $data): ?SupplierOutcome
    {
        return match ($data['status'] ?? null) {
            'Sukses' => SupplierOutcome::Success,
            'Gagal' => SupplierOutcome::Failure,
            default => null,
        };
    }

    /**
     * Case-insensitive on purpose (see the callers' own comment): a real
     * `mlbb_my_14_pg1` vs `MLBB_MY_14_PG1` mismatch here is Digiflazz's
     * own casing inconsistency between events, not a genuine wrong-SKU
     * payload — a null on either side never matches.
     */
    private static function skuMatches(?string $received, ?string $expected): bool
    {
        return $received !== null && $expected !== null && strcasecmp($received, $expected) === 0;
    }
}
