<?php

namespace App\Services\Fulfillment;

use App\Models\Order;
use App\Models\OrderDeliveryLeg;
use App\Models\OrderResendAttempt;
use App\Services\Accounting\SupplierFundingService;
use App\Services\Currency\CurrencyRateService;
use App\Services\Currency\CurrencyRateUnavailableException;
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
        private readonly CurrencyRateService $currencyRates,
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
     *
     * $recordAttempt (ADR-106 addendum, 2026-09-21): OrderResendService::resend()
     * calls this internally after already writing its own, richer
     * `attempt_type=resend` row (package swap, live-cost reconciliation)
     * — passes false here so this method's own generic initial/retry
     * write never double-books the same attempt. Every other caller
     * (FulfillOrderJob — the webhook, checkout, reseller placement, both
     * reconciliation paths, and retryDelivery()'s plain retry) leaves
     * this at its true default, since none of them write a row of their
     * own.
     */
    public function fulfill(Order $order, ?string $triggeredBy = null, ?string $note = null, bool $recordAttempt = true): Order
    {
        // ADR-094 decision 7: a combo order (Order.package.is_combo)
        // diverts to its own leg-loop sub-flow before any of the
        // single-ref guards below — it has no supplier_product_ref/
        // supplier_id of its own to check (ADR-094 decision 3). Never
        // reached via OrderResendService::resend() (decision 10 rejects
        // every combo resend), so $recordAttempt has no combo case to
        // gate — not threaded through fulfillCombo().
        if ($order->package?->is_combo) {
            return $this->fulfillCombo($order, $triggeredBy, $note);
        }

        // ADR-083 decision 3 — captured here, written to the supplier
        // funding ledger only AFTER the transaction below commits (see
        // the bottom of this method). Stays null unless the Success
        // branch actually runs and the adapter reported a price.
        $drawdownPrice = null;

        $delivered = DB::transaction(function () use ($order, &$drawdownPrice, $triggeredBy, $note, $recordAttempt) {
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

            // ADR-106 decision 3 — captured BEFORE this call's own
            // update() advances delivery_status past NotStarted, so it
            // genuinely reflects "was this order's very first
            // fulfillment attempt" rather than re-deriving it later from
            // an already-mutated value. A resend/retry always arrives
            // here from Failed/NeedsReview/Pending, never NotStarted, so
            // this is false on every call except a true first attempt.
            $wasNotStarted = $locked->delivery_status === DeliveryStatus::NotStarted;

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

                if ($recordAttempt) {
                    $this->recordFulfillmentAttempt($locked, $wasNotStarted, $triggeredBy, $note);
                }

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

                if ($recordAttempt) {
                    $this->recordFulfillmentAttempt($locked, $wasNotStarted, $triggeredBy, $note);
                }

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

            // ADR-111 decision 2/3: real per-transaction cost, captured
            // regardless of the feature flag; only USED to recompute
            // platform_profit when the flag is enabled (decision 8).
            // Closes this branch's own gap ADR-111's Context called out
            // as the biggest latent one — a plain successful delivery
            // (first attempt or retry) never reconciled platform_profit
            // against anything beyond the checkout-time catalog estimate.
            $realCostPriceSen = $this->captureRealCostSen($result->data, $locked->supplier->currency);

            $updateData = [
                'supplier_ref' => $result->data['supplier_ref'] ?? null,
                'supplier_response' => $result->data,
                'delivery_status' => $this->orderStatus->markDelivered($processingStatus)->value,
                'delivered_at' => now(),
                'real_cost_price_sen' => $realCostPriceSen,
            ];

            if ($realCostPriceSen !== null && config('services.real_cost_reconciliation.enabled', false)) {
                $updateData += $this->reconcileRealCostProfit($locked, $realCostPriceSen);
            }

            $locked->update($updateData);

            if ($recordAttempt) {
                $this->recordFulfillmentAttempt($locked, $wasNotStarted, $triggeredBy, $note);
            }

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
    private function fulfillCombo(Order $order, ?string $triggeredBy = null, ?string $note = null): Order
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

            $this->attemptLeg($order, $leg, $triggeredBy, $note);
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
    private function attemptLeg(Order $order, OrderDeliveryLeg $leg, ?string $triggeredBy = null, ?string $note = null): void
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

        // ADR-106 addendum (2026-09-21) — same reasoning as fulfill()'s
        // own $wasNotStarted: captured once, from the locked leg, before
        // this call's own update() advances it past NotStarted, and by
        // reference so the catch block below can still write a correct
        // `initial`-vs-`retry` attempt row even when the try block's own
        // transaction rolled back. Defaults false (never a false
        // 'initial') for the theoretical case an exception fires before
        // the locked leg is even read.
        $wasLegNotStarted = false;

        try {
            DB::transaction(function () use ($order, $leg, &$drawdownPrice, &$legReferenceNumber, &$wasLegNotStarted, $triggeredBy, $note) {
                $lockedLeg = OrderDeliveryLeg::query()->lockForUpdate()->findOrFail($leg->id);

                if (in_array($lockedLeg->status, [DeliveryStatus::Delivered, DeliveryStatus::Pending], true)) {
                    // Lost a race with another attempt at this same leg —
                    // nothing to do, the other attempt already owns it.
                    return;
                }

                $wasLegNotStarted = $lockedLeg->status === DeliveryStatus::NotStarted;

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

                    $this->recordLegAttempt($lockedLeg, $wasLegNotStarted, $triggeredBy, $note, $result->data);

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

                    $this->recordLegAttempt($lockedLeg, $wasLegNotStarted, $triggeredBy, $note, [
                        'error_code' => $result->errorCode,
                        'error_message' => $result->errorMessage,
                    ]);

                    Log::warning($requiresManualReview ? 'Combo leg ambiguous — needs manual review' : 'Combo leg failed', [
                        'leg_id' => $lockedLeg->id,
                        'error_code' => $result->errorCode,
                        'error_message' => $result->errorMessage,
                    ]);

                    return;
                }

                // ADR-111 decision 2 — the leg-scoped twin of fulfill()'s
                // own capture; no order-level platform_profit recompute
                // here (that's resolveComboOutcome()'s job, once every
                // leg has settled).
                $realLegCostPriceSen = $this->captureRealCostSen($result->data, $component->supplier->currency);

                $lockedLeg->update([
                    'status' => DeliveryStatus::Delivered->value,
                    'supplier_reference' => $result->data['supplier_ref'] ?? null,
                    'delivered_at' => now(),
                    'real_cost_price_sen' => $realLegCostPriceSen,
                ]);

                $this->recordLegAttempt($lockedLeg, $wasLegNotStarted, $triggeredBy, $note, $result->data);

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

            DB::transaction(function () use ($legId, $e, $legReferenceNumber, $wasLegNotStarted, $triggeredBy, $note) {
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

                $this->recordLegAttempt($lockedLeg, $wasLegNotStarted, $triggeredBy, $note, ['error_message' => $e->getMessage()]);
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

                // ADR-107 decision 2, cost input widened by ADR-111
                // decision 3/8: real per-transaction cost when the flag
                // is enabled (decision 2's leg-level capture, falling
                // back to this leg's own live catalog cost only if ITS
                // real cost is missing — a genuinely unavailable FX rate
                // at that one leg's delivery moment, decision 6); the
                // flag disabled restores the exact pre-ADR-111 live
                // catalog-cost-only aggregation (decision 8's kill
                // switch). Never a frozen snapshot either way — there
                // isn't one for cost, only `selling_price_sen` (decision 1).
                $useRealCost = config('services.real_cost_reconciliation.enabled', false);
                $costTotal = (int) $legs->sum(function (OrderDeliveryLeg $leg) use ($useRealCost) {
                    if ($useRealCost && $leg->real_cost_price_sen !== null) {
                        return $leg->real_cost_price_sen;
                    }

                    return $leg->componentPackage?->cost_price ?? 0;
                });
                $reconciledPlatformProfit = $locked->selling_price - $costTotal - $locked->affiliate_profit;

                // Decision 3: never block delivery over this — a combo
                // can finalize via webhook/scheduled poll with no admin
                // present to supply an override reason (ADR-105 decision
                // 4's gate is deliberately NOT reused here). The order
                // still delivers, the (possibly negative) figure is
                // recorded as-is, and ADR-111 decision 7's persisted
                // `profit_reconciled_flagged` (any negative result, OR a
                // material drift from the pre-reconciliation estimate —
                // both universal, not combo-only any more) is the
                // after-the-fact signal an admin needs to actually
                // notice it — the UI-facing half of the same signal.
                $flagged = $this->isProfitDriftFlagged($locked->platform_profit, $reconciledPlatformProfit, $locked->selling_price);

                if ($flagged) {
                    Log::warning('Combo order platform profit reconciled negative or drifted materially on final delivery', [
                        'order_id' => $locked->id,
                        'reconciled_platform_profit' => $reconciledPlatformProfit,
                        'cost_total' => $costTotal,
                        'used_real_cost' => $useRealCost,
                        'affiliate_profit' => $locked->affiliate_profit,
                        'frozen_selling_price' => $locked->selling_price,
                    ]);
                }

                $locked->update([
                    'delivery_status' => $newStatus->value,
                    'delivered_at' => now(),
                    'platform_profit' => $reconciledPlatformProfit,
                    'profit_reconciled_flagged' => $flagged,
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

                // ADR-111 decision 2 — the async counterpart to
                // attemptLeg()'s own capture, for a Digiflazz rc=03 leg
                // whose real price only ever arrives via webhook/poll.
                $realLegCostPriceSen = $this->captureRealCostSen(
                    is_array($supplierResponse) ? $supplierResponse : null,
                    $lockedLeg->supplier->currency,
                );

                $lockedLeg->update([
                    'status' => $deliveredStatus->value,
                    'supplier_reference' => $supplierRef ?? $lockedLeg->supplier_reference,
                    'delivered_at' => now(),
                    'real_cost_price_sen' => $realLegCostPriceSen,
                ]);

                Log::info('Combo leg finalized as delivered');

                $this->resolvePendingLegAttempt($lockedLeg->id, 'success', $supplierResponse);

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

            $this->resolvePendingLegAttempt($lockedLeg->id, 'failed', $supplierResponse);

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

                // ADR-111 decision 2/3 — the async counterpart to
                // fulfill()'s own Success branch: a Digiflazz rc=03
                // order's real price only ever arrives here (webhook or
                // reconcile poll), never at the initial Pending response.
                $realCostPriceSen = $this->captureRealCostSen(
                    is_array($supplierResponse) ? $supplierResponse : null,
                    $locked->supplier->currency,
                );

                $updateData = [
                    'supplier_ref' => $supplierRef ?? $locked->supplier_ref,
                    'supplier_response' => $supplierResponse ?? $locked->supplier_response,
                    'delivery_status' => $deliveredStatus->value,
                    'delivered_at' => now(),
                    'real_cost_price_sen' => $realCostPriceSen,
                ];

                if ($realCostPriceSen !== null && config('services.real_cost_reconciliation.enabled', false)) {
                    $updateData += $this->reconcileRealCostProfit($locked, $realCostPriceSen);
                }

                $locked->update($updateData);

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
     * THIS method at all (OrderResendService::assertSameGamePackage()
     * rejects every combo resend, ADR-094 decision 10) — its `whereNull`
     * guard keeps it scoped to order-level rows regardless. A combo
     * DOES have its own leg-scoped counterpart now, resolvePendingLegAttempt()
     * below, for the retryDelivery()/attemptLeg() path resend() can
     * never reach.
     *
     * ADR-106 decision 2 (2026-09-18): this query is entirely
     * type-agnostic (no `attempt_type` filter), so it keeps working
     * unchanged now that an `initial` attempt can also land on
     * `outcome=pending` (a first-ever call that happens to hit
     * Digiflazz's async rc=03) — `recordFulfillmentAttempt()` below
     * writes that row, this method resolves it exactly like it always
     * resolved a resend's pending row. Still a genuine no-op for an
     * order whose very first attempt landed on a terminal outcome
     * (nothing pending to resolve).
     */
    private function resolvePendingResendAttempt(int $orderId, string $outcome, mixed $supplierResponse): void
    {
        OrderResendAttempt::query()
            ->where('order_id', $orderId)
            ->whereNull('order_delivery_leg_id')
            ->where('outcome', 'pending')
            ->latest('id')
            ->first()
            ?->update(['outcome' => $outcome, 'supplier_response' => $supplierResponse]);
    }

    /**
     * ADR-106 addendum (2026-09-21) — the leg-scoped twin of
     * resolvePendingResendAttempt() above: a combo leg's own `pending`
     * attempt row (written by attemptLeg()'s Pending branch) is
     * resolved here once finalizePendingDeliveryLeg() (webhook or poll)
     * settles it, so a leg that went Pending never leaves its own
     * attempt history stuck at `outcome=pending` forever.
     */
    private function resolvePendingLegAttempt(int $legId, string $outcome, mixed $supplierResponse): void
    {
        OrderResendAttempt::query()
            ->where('order_delivery_leg_id', $legId)
            ->where('outcome', 'pending')
            ->latest('id')
            ->first()
            ?->update(['outcome' => $outcome, 'supplier_response' => $supplierResponse]);
    }

    /**
     * ADR-106 decision 3, widened by its 2026-09-21 addendum: the
     * durable counterpart to the admin's synthesized "Initial Delivery"
     * row — writes an `order_resend_attempts` row for EVERY fulfillment
     * attempt now, not just the first (`attempt_type` discriminates
     * `initial` vs `retry`), called from inside fulfill()'s own
     * transaction (all three outcome branches) right after $locked's
     * delivery_status/supplier_response have been updated for this
     * call's real outcome, so it only ever commits alongside that
     * outcome — never orphaned by a mid-flight exception.
     *
     * `triggered_by`/`note` come from wherever this call originated —
     * an admin's `retryDelivery()` click, or null for every
     * system-triggered dispatch (CHIP webhook, checkout, reseller
     * placement, both reconciliation paths). `package_id`/
     * `cost_price_sen`/`standard_selling_price_sen` are the order's own
     * checkout-frozen values — a plain retry never swaps package (only
     * `OrderResendService::resend()` can, ADR-017). `price_diff_sen` is
     * always null here (decision 4) — no live-cost comparison is ever
     * made on this path, unlike a resend's real diff.
     */
    private function recordFulfillmentAttempt(Order $locked, bool $wasNotStarted, ?string $triggeredBy, ?string $note): void
    {
        OrderResendAttempt::query()->create([
            'order_id' => $locked->id,
            'attempt_type' => $wasNotStarted ? 'initial' : 'retry',
            'package_id' => $locked->package_id,
            'cost_price_sen' => $locked->cost_price,
            'standard_selling_price_sen' => $locked->standard_selling_price,
            'price_diff_sen' => null,
            'outcome' => match ($locked->delivery_status) {
                DeliveryStatus::Delivered => 'success',
                DeliveryStatus::Pending => 'pending',
                // Mirrors OrderResendService::resend()'s own match —
                // NeedsReview collapses into 'failed' here too, the
                // same established convention this table's outcome
                // column already uses for a resend attempt.
                default => 'failed',
            },
            'supplier_response' => $locked->supplier_response,
            'note' => $note,
            'triggered_by' => $triggeredBy,
        ]);
    }

    /**
     * ADR-106 addendum (2026-09-21) — the leg-scoped twin of
     * recordFulfillmentAttempt() above, closing decision 1's own
     * "non-combo only for now" deferral: a combo leg's own mutable
     * columns (`status`/`failure_reason`/`supplier_reference`) get
     * overwritten on every attempt exactly like a plain order's used to
     * before ADR-106 — this writes the leg's own durable per-attempt
     * row instead, called from all 4 of attemptLeg()'s write sites
     * (Pending/Failure/Success inside its transaction, plus the
     * Throwable catch's own separate one) right after $lockedLeg's
     * status is updated for this call's real outcome, so it only ever
     * commits alongside that outcome.
     *
     * `package_id` here is the LEG's own component package, not the
     * order's (a combo order's own `package_id` is the combo SKU
     * itself, meaningless per-leg). `standard_selling_price_sen` reads
     * the leg's own frozen `selling_price_sen` (ADR-107 decision 1).
     * `cost_price_sen` reads the component's LIVE cost — no frozen
     * snapshot exists for it (same reason `resolveComboOutcome()`'s own
     * profit reconciliation reads it live, see that method's doc
     * comment) — and `price_diff_sen` stays null (grill Q3, deferred):
     * this addendum closes the missing-audit-trail gap, not a
     * leg-level cost-drift reconciliation, which is ADR-107's own
     * domain.
     */
    private function recordLegAttempt(OrderDeliveryLeg $lockedLeg, bool $wasLegNotStarted, ?string $triggeredBy, ?string $note, mixed $supplierResponseData): void
    {
        OrderResendAttempt::query()->create([
            'order_id' => $lockedLeg->order_id,
            'order_delivery_leg_id' => $lockedLeg->id,
            'attempt_type' => $wasLegNotStarted ? 'initial' : 'retry',
            'package_id' => $lockedLeg->component_package_id,
            'cost_price_sen' => $lockedLeg->componentPackage->cost_price,
            'standard_selling_price_sen' => $lockedLeg->selling_price_sen,
            'price_diff_sen' => null,
            'outcome' => match ($lockedLeg->status) {
                DeliveryStatus::Delivered => 'success',
                DeliveryStatus::Pending => 'pending',
                default => 'failed',
            },
            'supplier_response' => $supplierResponseData,
            'note' => $note,
            'triggered_by' => $triggeredBy,
        ]);
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
     *
     * ADR-106 decision 3 (2026-09-18) — the third gap this ADR found:
     * this method overwrites `Order.supplier_response`/`delivery_status`
     * exactly like fulfill() does, via a third code path with no
     * `order_resend_attempts` row of its own before now. Writes an
     * `attempt_type=manual_confirm` row so the durable log shows how a
     * needs_review order actually reached Delivered — outcome is always
     * 'success' (this method exists only to confirm a delivery, never a
     * failure), `note`/`triggered_by` come from this call's own params.
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

            OrderResendAttempt::query()->create([
                'order_id' => $locked->id,
                'attempt_type' => 'manual_confirm',
                'package_id' => $locked->package_id,
                'cost_price_sen' => $locked->cost_price,
                'standard_selling_price_sen' => $locked->standard_selling_price,
                'price_diff_sen' => null,
                'outcome' => 'success',
                'supplier_response' => $locked->supplier_response,
                'note' => $note,
                'triggered_by' => $confirmedBy,
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

            // ADR-094's 2026-09-21 addendum decision 26 — OrderController's
            // own isPartialComboDelivery() guard runs on the unlocked
            // $order, before this transaction even starts; a leg delivering
            // in that gap (a concurrent retry/webhook) would otherwise go
            // unnoticed and this method would blindly confirm the whole
            // order Failed on stale data, over-compensating a customer who
            // already received that leg's goods once Issue Voucher runs.
            // Re-checked here, inside the lock, on $locked (not $order) —
            // same defense-in-depth pattern as isAlreadyCompensated() above.
            if ($locked->isPartialComboDelivery()) {
                throw new OrderFulfillmentException(
                    "Order #{$locked->id} has a partial delivery — refusing to confirm the whole order failed",
                );
            }

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
     * ADR-111 decision 2/6: converts the supplier's own real
     * per-transaction price (`$resultData['price']`, when present) into
     * MYR sen via the shared `CurrencyRateService::convertToSen()`
     * boundary. Returns null — never throws, never blocks delivery —
     * both when no price was reported and when the FX rate is
     * genuinely unavailable (no live fetch ever succeeded AND nothing
     * was ever stored for that pair); the caller's own reconciliation
     * step already treats a null real cost as "fall back to today's
     * existing catalog-cost behavior for this one delivery."
     */
    private function captureRealCostSen(?array $resultData, string $currency): ?int
    {
        if (! isset($resultData['price'])) {
            return null;
        }

        try {
            return $this->currencyRates->convertToSen((float) $resultData['price'], $currency);
        } catch (CurrencyRateUnavailableException $e) {
            Log::warning('Real-cost reconciliation: FX rate unavailable, falling back to catalog cost for this delivery', [
                'currency' => $currency,
                'exception' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * ADR-111 decision 3: the one basis-agnostic residual formula,
     * `platform_profit = selling_price (frozen) − real_cost − affiliate_profit`
     * — the same identity ADR-105 decision 8 established, now fed by
     * the real per-transaction cost instead of a catalog snapshot.
     * `affiliate_profit` is never touched: whatever checkout or
     * `OrderResendService::resend()`'s own per-basis formula already
     * determined for it stays exactly as-is (decision 3's own text) —
     * only `platform_profit` absorbs the gap between what was assumed
     * and what was actually paid. Returns the fields to merge into the
     * caller's own `update()` call, never writes directly, so this
     * stays a plain calculation the caller applies alongside whatever
     * else that same call is already updating (decision 2's "no extra
     * query, no extra transaction").
     *
     * @return array{platform_profit: int, profit_reconciled_flagged: bool}
     */
    private function reconcileRealCostProfit(Order $locked, int $realCostPriceSen): array
    {
        $reconciledProfit = $locked->selling_price - $realCostPriceSen - $locked->affiliate_profit;
        $flagged = $this->isProfitDriftFlagged($locked->platform_profit, $reconciledProfit, $locked->selling_price);

        if ($flagged) {
            Log::warning('Real-cost profit reconciliation flagged this delivery', [
                'order_id' => $locked->id,
                'estimated_platform_profit' => $locked->platform_profit,
                'reconciled_platform_profit' => $reconciledProfit,
                'real_cost_price_sen' => $realCostPriceSen,
            ]);
        }

        return [
            'platform_profit' => $reconciledProfit,
            'profit_reconciled_flagged' => $flagged,
        ];
    }

    /**
     * ADR-111 decision 7: the universal visibility signal — fires on any
     * negative reconciled profit (unconditionally), OR a material drift
     * from the pre-reconciliation estimate that's BOTH more than RM1
     * (100 sen) AND more than 1% of `selling_price` (a flat sen
     * threshold alone under-protects a large order; a percentage alone
     * over-fires on a small, normally-volatile one). Starting numbers,
     * not empirically tuned — revisit once real resend/combo volume at
     * varied order sizes exists (ADR-111's own Consequence-to-track).
     */
    private function isProfitDriftFlagged(int $previousEstimatedProfit, int $reconciledProfit, int $sellingPrice): bool
    {
        if ($reconciledProfit < 0) {
            return true;
        }

        $driftSen = abs($reconciledProfit - $previousEstimatedProfit);

        return $driftSen > 100 && $driftSen > (int) round($sellingPrice * 0.01);
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
