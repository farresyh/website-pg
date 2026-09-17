<?php

namespace App\Services\Fulfillment;

use App\Models\Order;
use App\Models\OrderDeliveryLeg;
use App\Models\OrderResendAttempt;
use App\Services\Accounting\SupplierFundingService;
use App\Services\Ledger\LedgerOwnerType;
use App\Services\Ledger\LedgerService;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\OrderStatusService;
use App\Services\Order\ReferenceNumberService;
use App\Services\Supplier\SupplierAdapterFactory;
use App\Services\Supplier\SupplierOrderRequest;
use App\Services\Supplier\SupplierOutcome;
use App\Services\Voucher\VoucherService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Orchestrates checkout COMPLETION — submitting a paid order to the
 * supplier and recording the outcome. Assumes payment_status is
 * already genuinely Paid (set by whatever verifies and processes the
 * payment-gateway webhook — that verification/idempotency check
 * happens before this is ever called, not inside it).
 *
 * ADR-026 (ORD-10): a duplicate_reference response — Gamevion's own
 * idempotency signal that a prior attempt for this reference_number
 * already reached them — routes straight to delivery_status=needs_review
 * instead of a plain failed, regardless of whether this call is a
 * fresh first attempt or a retry (ReconcilePendingDeliveriesCommand's
 * own re-dispatch included). No automated resolution is attempted
 * beyond that: Gamevion's check-status endpoint needs its own invoice
 * number, which duplicate_reference never supplies, and neither its
 * API nor its dashboard support a reference-number lookup (confirmed
 * live, see ADR-026's Context) — only an admin manually cross-checking
 * Gamevion's dashboard can resolve it (markDeliveredManually() below).
 */
final class OrderFulfillmentService
{
    public function __construct(
        private readonly OrderStatusService $orderStatus,
        private readonly ReferenceNumberService $referenceNumbers,
        private readonly SupplierAdapterFactory $supplierAdapters,
        private readonly LedgerService $ledger,
        private readonly VoucherService $vouchers,
        private readonly SupplierFundingService $supplierFunding,
    ) {}

    /**
     * A webhook sender (Xendit's own docs call this out as expected
     * behavior) can deliver the same payment-confirmed event twice —
     * PAY-2 requires that this never causes duplicate supplier orders
     * or duplicate credit delivery. The Order row is re-read here
     * with lockForUpdate() inside a transaction (same pattern as
     * LedgerService::withdraw()/VoucherService::redeem()) so a second,
     * genuinely concurrent call blocks until the first commits, then
     * observes the already-advanced delivery_status and is rejected by
     * OrderStatusService's guard — never generates a second
     * reference_number or submits a second supplier order for the
     * same Order.
     */
    public function fulfill(Order $order): Order
    {
        // ADR-094 decision 7: a combo order (Order.package.is_combo)
        // diverts to its own leg-loop sub-flow before any of the
        // single-ref guards below — it has no supplier_product_ref/
        // supplier_id of its own to check (ADR-094 decision 3).
        if ($order->package?->is_combo) {
            return $this->fulfillCombo($order);
        }

        // ADR-083 decision 3 — captured here, written to the supplier
        // funding ledger only AFTER the transaction below commits (see
        // the bottom of this method). Stays null unless the Success
        // branch actually runs and the adapter reported a price.
        $drawdownPrice = null;

        $delivered = DB::transaction(function () use ($order, &$drawdownPrice) {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);

            // ADR-102 decision 1: the real, final defense-in-depth
            // check — everything upstream (OrderController's guards,
            // OrderResendService::assertResendable()) is a friendly
            // pre-check that can still go stale between "admin clicked
            // resend" and "this job actually acquired the row lock" (an
            // admin issuing a voucher/wallet-refund in that exact gap).
            // Checked inside the lock, before anything else, so a
            // resend can never deliver on top of compensation already
            // given regardless of which entry point raced which.
            if ($locked->isAlreadyCompensated()) {
                throw new OrderFulfillmentException(
                    "Order #{$locked->id} has already been compensated (voucher issued or wallet refunded) — refusing to fulfill",
                );
            }

            // ORD-11's central guard: delivery may only start once
            // payment is genuinely paid. OrderStatusService throws
            // otherwise — the single most direct path to giving away
            // free game credits without confirmed payment, never
            // bypassed here.
            $processingStatus = $this->orderStatus->startDelivery($locked->payment_status, $locked->delivery_status);

            if ($locked->supplier_product_ref === null) {
                throw new OrderFulfillmentException(
                    "Order #{$locked->id} has no supplier_product_ref set — cannot submit to supplier",
                );
            }

            // ADR-031: one Package = one supplier, fixed at
            // package-curation/checkout time — resolved fresh on every
            // call (including a retry) rather than cached on this
            // service, since a resend (OrderResendService) can change
            // which supplier an order targets between attempts.
            if ($locked->supplier_id === null) {
                throw new OrderFulfillmentException(
                    "Order #{$locked->id} has no supplier_id set — cannot resolve a SupplierAdapter",
                );
            }

            $adapter = $this->supplierAdapters->make($locked->supplier->slug);

            // ORD-8, narrowed by ADR-102 decision 9: reused on every
            // retry EXCEPT one deliberate case — a resend initiated
            // from Failed always gets a fresh reference. Safe because
            // Failed (post decision 4) always means confirmed
            // non-delivery, so there's no ambiguity a fresh reference
            // could double up on; it also makes a package swap (ADR-017)
            // genuinely effective again, since a fresh reference is
            // never subject to the replay behavior a resubmitted
            // already-formed reference has. A resend from NeedsReview
            // keeps reusing the stored value unchanged (resolve()) —
            // reuse IS the safety mechanism there, so Digiflazz's/
            // Gamevion's own dedup on the same reference still protects
            // a genuinely-unknown-outcome order from a real double
            // delivery. Combo orders never reach this line (fulfillCombo()
            // diverts before it) — decision 9 excludes them, see ADR-103.
            $referenceNumber = $locked->delivery_status === DeliveryStatus::Failed
                ? $this->referenceNumbers->generate()
                : $this->referenceNumbers->resolve($locked->reference_number);

            // ADR-014: extends whatever context the caller already set
            // (order_number, from the webhook/job) with the ORD-8 key
            // Gamevion itself is called with — the two together are
            // what a support conversation ("customer's order didn't
            // arrive") and a raw Gamevion dashboard lookup have in
            // common.
            Log::withContext(['reference_number' => $referenceNumber]);

            $locked->update([
                'reference_number' => $referenceNumber,
                'delivery_status' => $processingStatus->value,
            ]);

            $result = $adapter->createOrder(new SupplierOrderRequest(
                productRef: $locked->supplier_product_ref,
                referenceNumber: $referenceNumber,
                playerId: $locked->player_id,
                serverId: $locked->server_id,
                customerPhone: $locked->customer_phone,
                // ADR-097 decision 15 — Digiflazz-specific, ignored by
                // every other adapter.
                customerNoSeparator: $locked->game?->customerNoSeparatorOverride(),
                orderId: $locked->id,
            ));

            // ADR-032: branches on the adapter's normalized outcome,
            // never on raw success/failure alone — a Pending response
            // (async supplier, e.g. Digiflazz) is neither a clean
            // delivery nor a rejection, and must never be mistaken for
            // either.
            if ($result->outcome === SupplierOutcome::Pending) {
                $locked->update([
                    'delivery_status' => $this->orderStatus->markPending($processingStatus)->value,
                    'supplier_response' => $result->data,
                ]);

                Log::info('Delivery pending — awaiting async supplier confirmation', [
                    'supplier_response' => $result->data,
                ]);

                return $locked->fresh();
            }

            if ($result->outcome === SupplierOutcome::Failure) {
                // ADR-026 / ADR-098, reclassified by ADR-102 decision 4:
                // needs_review is narrowed to a GENUINELY unknown
                // outcome — unsafe to resubmit with the same reference
                // AND not a confirmed Gagal. Gamevion's 409/
                // duplicate_reference is exactly that (unsafe, not
                // confirmed) and still routes here unchanged. Digiflazz's
                // own 20-code "Terbentuk Transaksi=Ya" table used to
                // route here too, but that was ADR-098's own modeling
                // flaw: Digiflazz's `status` field already told the
                // platform the definitive outcome (Gagal) for those
                // codes — "can't safely resubmit" isn't the same fact
                // as "outcome unknown". A confirmed Gagal now always
                // reaches Failed instead, whether or not resubmitting
                // is unsafe, so Issue Voucher is available immediately
                // instead of needing a Confirm-Failed detour first.
                $requiresManualReview = $result->resendUnsafeWithSameReference && ! $result->outcomeConfirmedFailed;

                $locked->update([
                    'delivery_status' => $requiresManualReview
                        ? $this->orderStatus->markNeedsReview($processingStatus)->value
                        : $this->orderStatus->markDeliveryFailed($processingStatus)->value,
                    'supplier_response' => [
                        'error_code' => $result->errorCode,
                        'error_message' => $result->errorMessage,
                    ],
                ]);

                // ADR-014: the one line a file-log admin actually needs
                // to notice without watching the Admin Orders screen —
                // a business-level failure (this branch) never throws,
                // so without this line it would be silent until someone
                // looks. Grep by reference_number/order_number to find
                // the matching webhook/job lines for full context.
                Log::warning($requiresManualReview ? 'Delivery ambiguous — needs manual review' : 'Delivery failed', [
                    'error_code' => $result->errorCode,
                    'error_message' => $result->errorMessage,
                ]);

                return $locked->fresh();
            }

            $locked->update([
                'supplier_ref' => $result->data['supplier_ref'] ?? null,
                'supplier_response' => $result->data,
                'delivery_status' => $this->orderStatus->markDelivered($processingStatus)->value,
                'delivered_at' => now(),
            ]);

            $this->creditProfit($locked);

            // ADR-024 decision #6 — both payment and delivery succeeded,
            // the third and final outcome of the voucher-redemption
            // three-outcome model: any reserved redemption this order
            // made is now permanent, never restored. No-op if this
            // order never used a voucher.
            $this->vouchers->commit($locked->id);

            if (isset($result->data['price'])) {
                $drawdownPrice = (float) $result->data['price'];
            }

            return $locked->fresh();
        });

        if ($drawdownPrice !== null) {
            $this->supplierFunding->recordOrderDrawdown($delivered, $drawdownPrice);
        }

        return $delivered;
    }

    /**
     * ADR-094 decision 7: the combo counterpart to fulfill()'s
     * single-supplier-call path. Three short, separately-committed
     * steps rather than one transaction spanning the whole order —
     * holding lockForUpdate() across up to 3 outbound HTTP round-trips
     * (decision 20's leg cap) would be the exact lock-contention class
     * ADR-077 already fixed once in production:
     *
     *  1. Advance the order to Processing and seed `order_delivery_legs`
     *     from `package->components` on the first attempt only (a retry
     *     finds its legs already there and reuses them — decision 7's
     *     idempotent-by-construction leg loop).
     *  2. Attempt every not-yet-terminal leg exactly once, each its own
     *     short lock+transaction+one-HTTP-call cycle, in `leg_number`
     *     order.
     *  3. Aggregate the legs' resulting statuses into the order's own
     *     delivery_status (resolveComboOutcome()).
     */
    private function fulfillCombo(Order $order): Order
    {
        DB::transaction(function () use ($order) {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);

            // ADR-102 decision 1 — see fulfill()'s identical guard for
            // the full reasoning; the combo path needs the same
            // final-defense check inside its own lock.
            if ($locked->isAlreadyCompensated()) {
                throw new OrderFulfillmentException(
                    "Order #{$locked->id} has already been compensated (voucher issued or wallet refunded) — refusing to fulfill",
                );
            }

            $processingStatus = $this->orderStatus->startDelivery($locked->payment_status, $locked->delivery_status);
            $referenceNumber = $this->referenceNumbers->resolve($locked->reference_number);

            Log::withContext(['reference_number' => $referenceNumber]);

            $locked->update([
                'reference_number' => $referenceNumber,
                'delivery_status' => $processingStatus->value,
            ]);

            if (! OrderDeliveryLeg::query()->where('order_id', $locked->id)->exists()) {
                $this->seedDeliveryLegs($locked);
            }
        });

        $order = $order->fresh();

        $legs = OrderDeliveryLeg::query()->where('order_id', $order->id)->orderBy('leg_number')->get();

        foreach ($legs as $leg) {
            // Delivered = already succeeded; Pending = already submitted,
            // awaiting an async supplier confirmation (Digiflazz) —
            // neither is ever re-submitted. Everything else (NotStarted,
            // Failed) gets exactly one attempt this pass, same "one call
            // per invocation" discipline fulfill() itself uses.
            if (in_array($leg->status, [DeliveryStatus::Delivered, DeliveryStatus::Pending], true)) {
                continue;
            }

            $this->attemptLeg($order, $leg);
        }

        return $this->resolveComboOutcome($order);
    }

    /**
     * ADR-094 decision 1/4: one row per real leg — `package_components`
     * quantity > 1 (a repeated component) expands into that many leg
     * rows here, not a single row with a multiplier, so each one gets
     * its own `leg_number`/idempotency key/supplier_reference.
     */
    private function seedDeliveryLegs(Order $order): void
    {
        $legNumber = 1;

        foreach ($order->package->components as $component) {
            $quantity = (int) $component->pivot->quantity;

            for ($i = 0; $i < $quantity; $i++) {
                OrderDeliveryLeg::query()->create([
                    'order_id' => $order->id,
                    'component_package_id' => $component->id,
                    'supplier_id' => $component->supplier_id,
                    'leg_number' => $legNumber,
                    'status' => DeliveryStatus::NotStarted->value,
                    // ADR-107 decision 1 — frozen at the one moment this
                    // leg is created (checkout, synchronous inside
                    // fulfillCombo()'s first pass); feeds decision 4's
                    // partial-combo voucher apportionment. Each expanded
                    // leg already represents one unit (the quantity loop
                    // above), so this is the component's own per-unit
                    // price, never multiplied by $quantity.
                    'selling_price_sen' => $component->standard_selling_price,
                ]);

                $legNumber++;
            }
        }
    }

    /**
     * ADR-094 decision 7/26 (idempotency key): `{reference_number}-L{n}`
     * extends the existing ORD-8 scheme rather than replacing it — one
     * real supplier call, one leg row updated, its own short
     * lock+transaction. ADR-026's duplicate_reference ambiguity applies
     * per leg exactly as it does per order: routes to NeedsReview, never
     * a plain Failed, since it's evidence a prior attempt for *this leg's*
     * reference already reached the supplier.
     *
     * ADR-094 2026-09-15 addendum (post-Phase-4 resilience grill): the
     * inner `DB::transaction()` only ever throws for a genuinely
     * unexpected reason — a business-level rejection is a clean
     * `SupplierResponse::failure()` return, handled below, never an
     * exception. Found live: `fulfillCombo()`'s own first transaction
     * (advancing the *order* to Processing) already commits before this
     * loop starts, so an uncaught exception here used to strand the
     * order at Processing forever — `resolveComboOutcome()` never ran.
     * The ordinary single-supplier `fulfill()` doesn't have this gap
     * (its Processing transition and its one supplier call share the
     * *same* transaction, so an exception there rolls both back to a
     * safe, retryable pre-attempt state) — this is specific to decision
     * 7's deliberate per-leg-transaction design (avoiding a lock held
     * across up to 3 sequential HTTP calls, ADR-077's own fixed lock-
     * contention class).
     */
    private function attemptLeg(Order $order, OrderDeliveryLeg $leg): void
    {
        $drawdownPrice = null;
        $legId = $leg->id;

        // ADR-103 decision 3 — captured OUTSIDE the transaction below by
        // reference: a thrown exception mid-call rolls back everything
        // written inside that transaction, including the reference
        // persist, but this PHP variable survives the rollback — the
        // catch block re-persists it in its own (committed) transaction
        // so a genuinely-attempted reference is never silently lost.
        $legReferenceNumber = null;

        try {
            DB::transaction(function () use ($order, $leg, &$drawdownPrice, &$legReferenceNumber) {
                $lockedLeg = OrderDeliveryLeg::query()->lockForUpdate()->findOrFail($leg->id);

                if (in_array($lockedLeg->status, [DeliveryStatus::Delivered, DeliveryStatus::Pending], true)) {
                    // Lost a race with another attempt at this same leg —
                    // nothing to do, the other attempt already owns it.
                    return;
                }

                // ADR-103 decision 3 — mirrors ADR-102 decision 9 at leg
                // granularity: NotStarted keeps today's derived value
                // (now persisted instead of re-derived every call);
                // Failed mints a fresh reference (decision 4's ULID
                // suffix — safe, this leg is confirmed non-delivered);
                // NeedsReview reuses the stored value unchanged (the
                // double-delivery protection, same reasoning as
                // ORD-8/decision 9). Persisted BEFORE the supplier call
                // (not after) so a thrown exception below still leaves
                // this attempt's reference queryable.
                $legReferenceNumber = match ($lockedLeg->status) {
                    DeliveryStatus::Failed => "{$order->reference_number}-L{$lockedLeg->leg_number}-".Str::ulid(),
                    DeliveryStatus::NeedsReview => $lockedLeg->reference_number,
                    default => $lockedLeg->reference_number ?? "{$order->reference_number}-L{$lockedLeg->leg_number}",
                };

                Log::withContext(['reference_number' => $legReferenceNumber]);

                $lockedLeg->update(['reference_number' => $legReferenceNumber]);

                $component = $lockedLeg->componentPackage;
                $adapter = $this->supplierAdapters->make($component->supplier->slug);

                $result = $adapter->createOrder(new SupplierOrderRequest(
                    productRef: $component->supplier_package_ref,
                    referenceNumber: $legReferenceNumber,
                    playerId: $order->player_id,
                    serverId: $order->server_id,
                    customerPhone: $order->customer_phone,
                    // ADR-097 decision 15/14 — `$order->game`, not the
                    // leg's component's own game: a combo's components
                    // are enforced same-game at save time
                    // (StoreComboPackageRequest), so they're identical
                    // anyway, and $order already carries it.
                    customerNoSeparator: $order->game?->customerNoSeparatorOverride(),
                    orderId: $order->id,
                ));

                if ($result->outcome === SupplierOutcome::Pending) {
                    $lockedLeg->update(['status' => DeliveryStatus::Pending->value]);

                    Log::info('Combo leg pending — awaiting async supplier confirmation', ['leg_id' => $lockedLeg->id]);

                    return;
                }

                if ($result->outcome === SupplierOutcome::Failure) {
                    // ADR-098 / ADR-102 decision 4 — same split-flag
                    // routing fulfill()'s own Failure branch uses.
                    $requiresManualReview = $result->resendUnsafeWithSameReference && ! $result->outcomeConfirmedFailed;

                    $lockedLeg->update(array_merge([
                        'status' => $requiresManualReview ? DeliveryStatus::NeedsReview->value : DeliveryStatus::Failed->value,
                        'failure_reason' => $result->errorMessage,
                    ], $requiresManualReview ? [
                        // ADR-103 decision 2 — populated only when this
                        // leg actually lands on NeedsReview, feeding
                        // decision 8's OR-rollup. A Failed leg never
                        // needs it: decision 3 always mints it a fresh
                        // reference on retry, so it can never be
                        // genuinely futile the way a NeedsReview leg can.
                        'resend_unsafe_with_same_reference' => true,
                    ] : []));

                    Log::warning($requiresManualReview ? 'Combo leg ambiguous — needs manual review' : 'Combo leg failed', [
                        'leg_id' => $lockedLeg->id,
                        'error_code' => $result->errorCode,
                        'error_message' => $result->errorMessage,
                    ]);

                    return;
                }

                $lockedLeg->update([
                    'status' => DeliveryStatus::Delivered->value,
                    'supplier_reference' => $result->data['supplier_ref'] ?? null,
                    'delivered_at' => now(),
                ]);

                if (isset($result->data['price'])) {
                    $drawdownPrice = (float) $result->data['price'];
                }
            });
        } catch (Throwable $e) {
            // Same ambiguity duplicate_reference already handles above
            // — we genuinely don't know whether the supplier received
            // this leg's request before things broke, so the safe
            // default is identical: NeedsReview, never Failed (Failed
            // implies "nothing happened, safe to retry from scratch,"
            // not a safe assumption here). A subsequent retry re-submits
            // under the same idempotency key (decision 7) — if the
            // supplier really did see it, duplicate_reference detection
            // catches that safely, same as it always has. Deliberately
            // NOT wrapping the recordOrderDrawdown() call below this
            // try block — that runs only after a real Delivered commit,
            // so a failure there is a bookkeeping concern, never a
            // reason to relabel a delivery that genuinely already
            // succeeded back to "needs review".
            Log::error('Combo leg attempt threw unexpectedly — marking NeedsReview rather than stranding the order at Processing', [
                'leg_id' => $legId,
                'exception' => $e->getMessage(),
            ]);

            DB::transaction(function () use ($legId, $e, $legReferenceNumber) {
                $lockedLeg = OrderDeliveryLeg::query()->lockForUpdate()->findOrFail($legId);

                if (in_array($lockedLeg->status, [DeliveryStatus::Delivered, DeliveryStatus::Pending], true)) {
                    // Same race-guard as the main attempt above — another
                    // concurrent attempt already resolved this leg.
                    return;
                }

                $lockedLeg->update(array_merge([
                    'status' => DeliveryStatus::NeedsReview->value,
                    'failure_reason' => 'Delivery attempt failed unexpectedly: '.$e->getMessage(),
                ], $legReferenceNumber !== null ? [
                    // ADR-103 decisions 1/3 — the transaction that
                    // computed this reference and attempted the actual
                    // supplier call rolled back with the exception;
                    // re-persisted here so a NeedsReview leg reached via
                    // this path still reuses the SAME reference it was
                    // genuinely attempted under, not a stale/null one.
                    'reference_number' => $legReferenceNumber,
                ] : []));
            });

            return;
        }

        if ($drawdownPrice !== null) {
            $this->supplierFunding->recordOrderDrawdown($order, $drawdownPrice, $leg->fresh());
        }
    }

    /**
     * ADR-094 decision 9: rolls every leg's current status up into the
     * order's own delivery_status. Precedence, most conservative first:
     *
     *  - any leg Pending → order Pending (still genuinely in flight;
     *    a no-op if the order is already sitting at Pending itself —
     *    re-evaluating an incomplete leg set from a Phase 3b webhook/
     *    poll call must never re-throw markPending()'s Processing-only
     *    guard).
     *  - any leg NeedsReview → order NeedsReview (real ambiguity always
     *    wins — never guess a duplicate_reference leg either way).
     *  - every leg Delivered → order Delivered. ADR-107 decision 2:
     *    `platform_profit` is reconciled once, right here, as a
     *    money-conservation residual (`order.selling_price (frozen) −
     *    Σ live componentPackage->cost_price across every leg −
     *    affiliate_profit`) — the same identity ADR-105 decision 8
     *    established for the single-package case, aggregated across
     *    legs. `affiliate_profit` itself is never touched — it keeps
     *    the exact value frozen at checkout (decision 2's own call:
     *    combo has no single "target package" resend concept to
     *    reconcile it against). Credit profit once, commit any reserved
     *    voucher once — order-level, not per-leg, matching ORD-9.
     *  - every leg Failed → order Failed (clean, ordinary failure —
     *    nothing was delivered, "Resend Delivery" retries normally).
     *  - otherwise (a genuine mix of Delivered + Failed, no Pending/
     *    NeedsReview) → order NeedsReview — decision 9's actual partial-
     *    delivery case: the player already has some of the goods, a
     *    human must decide (Issue Voucher for the failed leg's value,
     *    never an automated partial compensation).
     *
     * Callable from two entry states — Processing (fulfillCombo()'s own
     * synchronous pass) or Pending (Phase 3b's finalizePendingDeliveryLeg(),
     * once a webhook/poll resolves one of possibly several Digiflazz
     * legs an earlier pass left Pending) — so every Delivered/Failed
     * transition dispatches to whichever of markDelivered()/
     * finalizePendingSuccess() (or markDeliveryFailed()/
     * finalizePendingFailure()) actually matches the order's current
     * state, rather than assuming Processing.
     */
    private function resolveComboOutcome(Order $order): Order
    {
        return DB::transaction(function () use ($order) {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
            // componentPackage:cost_price eager-loaded here (not a
            // separate query below) — ADR-107 decision 2 needs each
            // leg's live cost at exactly this moment, and a
            // query-builder pluck() would bypass the model's enum cast
            // anyway (real models needed so `status` compares against
            // DeliveryStatus below, not a bare string).
            $legs = OrderDeliveryLeg::query()->where('order_id', $locked->id)->with('componentPackage:id,cost_price')->get();
            $statuses = $legs->pluck('status');
            $enteringFromPending = $locked->delivery_status === DeliveryStatus::Pending;

            if ($statuses->contains(DeliveryStatus::Pending)) {
                // Still incomplete. Only Processing→Pending is a real
                // transition; Pending re-evaluated as still-Pending
                // (another leg resolved but at least one remains) is a
                // deliberate no-op — markPending() only accepts
                // Processing as its source.
                if (! $enteringFromPending) {
                    $locked->update(['delivery_status' => $this->orderStatus->markPending($locked->delivery_status)->value]);
                }

                return $locked->fresh();
            }

            if ($statuses->contains(DeliveryStatus::NeedsReview)) {
                $locked->update(['delivery_status' => $this->orderStatus->markNeedsReview($locked->delivery_status)->value]);

                return $locked->fresh();
            }

            if ($statuses->every(fn (DeliveryStatus $status) => $status === DeliveryStatus::Delivered)) {
                $newStatus = $enteringFromPending
                    ? $this->orderStatus->finalizePendingSuccess($locked->delivery_status)
                    : $this->orderStatus->markDelivered($locked->delivery_status);

                // ADR-107 decision 2 — see this method's own doc comment.
                // Reads live cost, never a frozen snapshot (there isn't
                // one for cost — only `selling_price_sen`, decision 1).
                $liveCostTotal = (int) $legs->sum(fn (OrderDeliveryLeg $leg) => $leg->componentPackage?->cost_price ?? 0);
                $reconciledPlatformProfit = $locked->selling_price - $liveCostTotal - $locked->affiliate_profit;

                // Decision 3: never block delivery over this — a combo
                // can finalize via webhook/scheduled poll with no admin
                // present to supply an override reason (ADR-105 decision
                // 4's gate is deliberately NOT reused here). The order
                // still delivers, the (possibly negative) figure is
                // recorded as-is, and this line is the after-the-fact
                // signal an admin needs to actually notice it — the
                // Order Detail response's `combo_profit_reconciled_negative`
                // flag (derived from the stored value, not a separate
                // column) is the UI-facing half of the same signal.
                if ($reconciledPlatformProfit < 0) {
                    Log::warning('Combo order platform profit reconciled negative on final delivery', [
                        'order_id' => $locked->id,
                        'reconciled_platform_profit' => $reconciledPlatformProfit,
                        'live_cost_total' => $liveCostTotal,
                        'affiliate_profit' => $locked->affiliate_profit,
                        'frozen_selling_price' => $locked->selling_price,
                    ]);
                }

                $locked->update([
                    'delivery_status' => $newStatus->value,
                    'delivered_at' => now(),
                    'platform_profit' => $reconciledPlatformProfit,
                ]);

                $this->creditProfit($locked);
                $this->vouchers->commit($locked->id);

                return $locked->fresh();
            }

            if ($statuses->every(fn (DeliveryStatus $status) => $status === DeliveryStatus::Failed)) {
                $newStatus = $enteringFromPending
                    ? $this->orderStatus->finalizePendingFailure($locked->delivery_status)
                    : $this->orderStatus->markDeliveryFailed($locked->delivery_status);

                $locked->update(['delivery_status' => $newStatus->value]);

                return $locked->fresh();
            }

            // Mixed Delivered + Failed, no Pending/NeedsReview present —
            // decision 9's partial-delivery case. markNeedsReview()
            // already accepts Processing, Failed, *and* Pending as a
            // source, so no dispatch is needed here.
            $locked->update(['delivery_status' => $this->orderStatus->markNeedsReview($locked->delivery_status)->value]);

            return $locked->fresh();
        });
    }

    /**
     * ADR-094 decision 7 (Phase 3b): the leg-level counterpart to
     * finalizePendingDelivery() — reached by DigiflazzWebhookController
     * (primary, after parsing the -L{n} suffix) or
     * CheckSupplierDeliveryJob's combo branch (poll backup), never
     * called directly from fulfillCombo() itself. $outcome must be
     * Success or Failure, same restriction finalizePendingDelivery()
     * itself enforces.
     *
     * Idempotent by construction: OrderStatusService::finalizePendingSuccess()/
     * finalizePendingFailure() both require the LEG to currently be
     * Pending, so a duplicate webhook delivery for the same leg observes
     * the already-advanced state and throws InvalidOrderTransitionException
     * — same guard finalizePendingDelivery() already relies on at the
     * order level, reused here unchanged since both operate on a plain
     * DeliveryStatus value.
     */
    public function finalizePendingDeliveryLeg(OrderDeliveryLeg $leg, SupplierOutcome $outcome, ?string $supplierRef = null, mixed $supplierResponse = null, bool $resendUnsafeWithSameReference = false, bool $outcomeConfirmedFailed = false): Order
    {
        if ($outcome === SupplierOutcome::Pending) {
            throw new OrderFulfillmentException(
                "finalizePendingDeliveryLeg() cannot be called with outcome=pending for leg #{$leg->id} — a Pending leg is not yet finalized",
            );
        }

        $order = $leg->order;
        $drawdownPrice = null;

        DB::transaction(function () use ($leg, $outcome, $supplierRef, $supplierResponse, $resendUnsafeWithSameReference, $outcomeConfirmedFailed, &$drawdownPrice) {
            $lockedLeg = OrderDeliveryLeg::query()->lockForUpdate()->findOrFail($leg->id);

            Log::withContext(['order_delivery_leg_id' => $lockedLeg->id, 'leg_number' => $lockedLeg->leg_number]);

            if ($outcome === SupplierOutcome::Success) {
                $deliveredStatus = $this->orderStatus->finalizePendingSuccess($lockedLeg->status);

                $lockedLeg->update([
                    'status' => $deliveredStatus->value,
                    'supplier_reference' => $supplierRef ?? $lockedLeg->supplier_reference,
                    'delivered_at' => now(),
                ]);

                Log::info('Combo leg finalized as delivered');

                if (is_array($supplierResponse) && isset($supplierResponse['price'])) {
                    $drawdownPrice = (float) $supplierResponse['price'];
                }

                return;
            }

            // ADR-098 / ADR-102 decision 4 — same split-flag routing as
            // finalizePendingDelivery()'s own Failure branch.
            $requiresManualReview = $resendUnsafeWithSameReference && ! $outcomeConfirmedFailed;

            $failedStatus = $requiresManualReview
                ? $this->orderStatus->markNeedsReview($lockedLeg->status)
                : $this->orderStatus->finalizePendingFailure($lockedLeg->status);

            $lockedLeg->update(array_merge([
                'status' => $failedStatus->value,
                'failure_reason' => is_array($supplierResponse) ? ($supplierResponse['error_message'] ?? null) : null,
            ], $requiresManualReview ? [
                // ADR-103 decision 2 — same leg-level flag attemptLeg()
                // writes, populated here for the async (webhook/poll)
                // finalize path too, only when this leg actually lands
                // on NeedsReview (see attemptLeg()'s identical comment).
                'resend_unsafe_with_same_reference' => true,
            ] : []));

            Log::warning($requiresManualReview ? 'Combo leg finalized as needs-review' : 'Combo leg finalized as failed', ['supplier_response' => $supplierResponse]);
        });

        if ($drawdownPrice !== null) {
            $this->supplierFunding->recordOrderDrawdown($order, $drawdownPrice, $leg->fresh());
        }

        return $this->resolveComboOutcome($order->fresh());
    }

    /**
     * ADR-032 decision 3 — the one money path out of Pending, reached
     * by a supplier webhook (primary) or the reconcile poll's own
     * check (backup, CheckSupplierDeliveryJob), never called directly
     * from fulfill() itself. $outcome must be Success or Failure — a
     * Pending order is by definition not yet finalized, so passing
     * Pending here is a caller bug, not a legitimate state.
     *
     * Idempotent by construction: OrderStatusService::finalizePendingSuccess()/
     * finalizePendingFailure() both require the order to currently be
     * Pending, so a second call (e.g. a duplicate webhook delivery)
     * observes the already-advanced state and throws
     * InvalidOrderTransitionException — same lock-then-guard pattern
     * fulfill() itself already uses for the identical PAY-2 reason.
     */
    public function finalizePendingDelivery(Order $order, SupplierOutcome $outcome, ?string $supplierRef = null, mixed $supplierResponse = null, bool $resendUnsafeWithSameReference = false, bool $outcomeConfirmedFailed = false): Order
    {
        if ($outcome === SupplierOutcome::Pending) {
            throw new OrderFulfillmentException(
                "finalizePendingDelivery() cannot be called with outcome=pending for order #{$order->id} — a Pending order is not yet finalized",
            );
        }

        $drawdownPrice = null;

        $finalized = DB::transaction(function () use ($order, $outcome, $supplierRef, $supplierResponse, $resendUnsafeWithSameReference, $outcomeConfirmedFailed, &$drawdownPrice) {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);

            Log::withContext(['reference_number' => $locked->reference_number]);

            if ($outcome === SupplierOutcome::Success) {
                $deliveredStatus = $this->orderStatus->finalizePendingSuccess($locked->delivery_status);

                $locked->update([
                    'supplier_ref' => $supplierRef ?? $locked->supplier_ref,
                    'supplier_response' => $supplierResponse ?? $locked->supplier_response,
                    'delivery_status' => $deliveredStatus->value,
                    'delivered_at' => now(),
                ]);

                $this->creditProfit($locked);
                $this->vouchers->commit($locked->id);
                $this->resolvePendingResendAttempt($locked->id, 'success', $locked->supplier_response);

                Log::info('Pending delivery finalized as delivered', ['supplier_ref' => $supplierRef]);

                // ADR-083 decision 3 — the Digiflazz webhook (or the
                // reconcile poll's own checkStatus() re-submit) carries
                // the real `price` here; a `Pending` order's own initial
                // response never did, so this is the only place a
                // Digiflazz drawdown gets recorded.
                if (is_array($supplierResponse) && isset($supplierResponse['price'])) {
                    $drawdownPrice = (float) $supplierResponse['price'];
                }

                return $locked->fresh();
            }

            // ADR-098 / ADR-102 decision 4 — a Pending order's terminal
            // Failure can itself be a confirmed-Gagal or genuinely-
            // ambiguous case (this is exactly the path the real
            // PG-JLOMUJ1H23NE incident took: createOrder() returned
            // Pending, the reconcile poll's checkStatus() got a terminal
            // rc). Same split-flag routing as fulfill()'s own
            // synchronous Failure branch — a confirmed Gagal (Digiflazz)
            // routes to Failed even when the same reference is unsafe
            // to resubmit; only a genuinely unknown outcome (Gamevion's
            // duplicate_reference — which never reaches Pending in the
            // first place — or an unexpected exception) routes to
            // NeedsReview.
            $requiresManualReview = $resendUnsafeWithSameReference && ! $outcomeConfirmedFailed;

            $failedStatus = $requiresManualReview
                ? $this->orderStatus->markNeedsReview($locked->delivery_status)
                : $this->orderStatus->finalizePendingFailure($locked->delivery_status);

            $locked->update([
                'supplier_response' => $supplierResponse ?? $locked->supplier_response,
                'delivery_status' => $failedStatus->value,
            ]);
            $this->resolvePendingResendAttempt($locked->id, 'failed', $locked->supplier_response);

            // Deliberately no voucher/retail-ledger action here — a
            // Pending order finalized as Failed (or NeedsReview) lands
            // on a state a synchronous rejection would too, so the
            // existing Failed-only voucher-issuance gate
            // (VoucherController::storeFromOrder()) applies unchanged —
            // and, for NeedsReview, is deliberately withheld until an
            // admin looks, same as every other NeedsReview case.
            // No cash was ever taken from OUR ledger for this order, so
            // there is nothing to reverse (ADR-004).
            //
            // ADR-083 decision 3 (grilled 2026-09-11): no supplier-ledger
            // action here either — a `Pending` response never carried a
            // `price` (see the Success branch above), so nothing was
            // ever recorded as drawn down for this order; a `Gagal`
            // after `Pending` writes no `REFUND`. If a real Digiflazz
            // account is later found to actually deduct saldo at
            // `Pending` submission and restore it on `Gagal`, this needs
            // a deliberate `REFUND`/`MANUAL_ADJUSTMENT` branch added
            // here — don't assume it.
            Log::warning($requiresManualReview ? 'Pending delivery finalized as needs-review' : 'Pending delivery finalized as failed', ['supplier_response' => $supplierResponse]);

            return $locked->fresh();
        });

        if ($drawdownPrice !== null) {
            $this->supplierFunding->recordOrderDrawdown($finalized, $drawdownPrice);
        }

        return $finalized;
    }

    /**
     * 2026-09-15 bugfix (Delivery Logs outcome bug A): a resend of a
     * `Pending`-outcome async order (Digiflazz rc=03) used to record its
     * `order_resend_attempts.outcome` as a hard 'failed' the moment the
     * resend's own fulfill() call returned — coercing "not yet resolved"
     * into "definitely failed" — and never revisited that row once the
     * real outcome later arrived via webhook, the scheduled reconcile
     * poll, or ADR-096's manual "Check from Supplier" button. All three
     * of those callers funnel through this same finalizePendingDelivery(),
     * so correcting the historical attempt row here (instead of in each
     * caller separately) keeps it accurate regardless of which one
     * actually resolved it.
     *
     * "The most recent still-pending attempt for this order" is
     * unambiguous, not a guess: `reference_number` is reused (never
     * regenerated) across every retry/resend of the same order
     * (ReferenceNumberService's own doc comment), and `assertResendable()`
     * blocks a NEW resend while the order is Pending — so at most one
     * order_resend_attempts row for a given order can genuinely be
     * "awaiting an async answer" at a time. A combo order never reaches
     * here at all (OrderResendService::assertSameGamePackage() rejects
     * every combo resend, ADR-094 decision 10), so no leg-side
     * counterpart is needed.
     *
     * A no-op (correctly) when this order was never resent — the
     * INITIAL attempt writes no order_resend_attempts row at all
     * (docs/prd.md §16 backlog: that's a separate, deliberately deferred
     * gap, not this fix's job).
     */
    private function resolvePendingResendAttempt(int $orderId, string $outcome, mixed $supplierResponse): void
    {
        OrderResendAttempt::query()
            ->where('order_id', $orderId)
            ->where('outcome', 'pending')
            ->latest('id')
            ->first()
            ?->update(['outcome' => $outcome, 'supplier_response' => $supplierResponse]);
    }

    /**
     * ADR-026 decision 4a — the one exit from `needs_review` that isn't
     * a retry: an admin manually cross-referenced Gamevion's own
     * dashboard (no automated lookup exists, see this class's own
     * doc comment) and confirmed the real invoice. Deliberately requires
     * that invoice number as input, not just a confirm click — it
     * closes the exact `supplier_ref` gap that caused the ambiguity in
     * the first place, giving this order the same audit trail a normal
     * delivery would have had. Same lock/transaction discipline as
     * fulfill() itself, and the same ledger-credit + voucher-commit
     * calls a normal successful delivery makes — this order genuinely
     * is delivered now, just confirmed by a human instead of a live API
     * response.
     */
    public function markDeliveredManually(Order $order, string $supplierRef, ?string $note, string $confirmedBy): Order
    {
        return DB::transaction(function () use ($order, $supplierRef, $note, $confirmedBy) {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);

            $deliveredStatus = $this->orderStatus->markDeliveredManually($locked->delivery_status);

            Log::withContext(['reference_number' => $locked->reference_number]);

            $locked->update([
                'supplier_ref' => $supplierRef,
                'supplier_response' => [
                    'manually_confirmed' => true,
                    'confirmed_by' => $confirmedBy,
                    'note' => $note,
                    'confirmed_at' => now()->toISOString(),
                ],
                'delivery_status' => $deliveredStatus->value,
                'delivered_at' => now(),
            ]);

            $this->creditProfit($locked);
            $this->vouchers->commit($locked->id);

            Log::info('Delivery manually confirmed after needs_review', [
                'supplier_ref' => $supplierRef,
                'confirmed_by' => $confirmedBy,
            ]);

            return $locked->fresh();
        });
    }

    /**
     * ADR-026 addendum (2026-09-16, found shipping ADR-098) — the
     * NeedsReview exit decision 4c's own rationale always assumed
     * existed but was never built: "An admin must resolve the order to
     * Delivered or a genuine Failed first" before Issue Voucher
     * becomes available. Structurally necessary for a
     * transactionAlreadyFormed order (ADR-098) — retry can never change
     * that outcome, so without this, that class of order has no exit
     * at all. Lands on plain Failed; Issue Voucher (VoucherController::
     * storeFromOrder(), unchanged) is a deliberately separate,
     * admin-triggered second step — this method credits nothing and
     * touches no ledger/voucher itself.
     *
     * Unlike markDeliveredManually()'s supplier_response OVERWRITE
     * (correct there — delivery succeeded, the prior ambiguous data no
     * longer matters), this MERGES: the original error_code/
     * error_message (the actual evidence this failed) stays, with the
     * admin's own confirmation appended alongside it, not replacing it.
     */
    public function confirmDeliveryFailed(Order $order, string $note, string $confirmedBy): Order
    {
        return DB::transaction(function () use ($order, $note, $confirmedBy) {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);

            $failedStatus = $this->orderStatus->markNeedsReviewAsFailed($locked->delivery_status);

            Log::withContext(['reference_number' => $locked->reference_number]);

            $locked->update([
                'supplier_response' => array_merge(
                    is_array($locked->supplier_response) ? $locked->supplier_response : [],
                    [
                        'confirmed_failed_by' => $confirmedBy,
                        'note' => $note,
                        'confirmed_at' => now()->toISOString(),
                    ],
                ),
                'delivery_status' => $failedStatus->value,
            ]);

            Log::warning('Delivery confirmed genuinely failed after needs_review', [
                'confirmed_by' => $confirmedBy,
            ]);

            return $locked->fresh();
        });
    }

    /**
     * Every delivered order writes exactly two order_profit credit
     * entries (PRD §8 LedgerEntry) — kept as two rows even though MVP
     * has only the single internal owner-affiliate, so this never needs
     * to change when Phase 2 onboards real third-party affiliates.
     *
     * ADR-018 decision #6: the single, explicit guard that keeps a
     * sandbox order from ever reaching the real ledger — chosen over a
     * parallel "sandbox fulfillment service" so every other line above
     * this method stays 100% shared and unduplicated between real and
     * test orders.
     */
    private function creditProfit(Order $order): void
    {
        if ($order->is_test) {
            return;
        }

        $this->ledger->credit(LedgerOwnerType::Platform, null, $order->platform_profit, 'order_profit', 'order', $order->id);
        $this->ledger->credit(LedgerOwnerType::Affiliate, $order->affiliate_id, $order->affiliate_profit, 'order_profit', 'order', $order->id);
    }
}
