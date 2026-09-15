<?php

namespace App\Services\Fulfillment;

use App\Models\Order;
use App\Models\OrderDeliveryLeg;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\InvalidOrderTransitionException;
use App\Services\Supplier\SupplierAdapterFactory;
use App\Services\Supplier\SupplierOutcome;
use App\Services\Supplier\SupplierStatusCheckRequest;
use Illuminate\Support\Facades\Log;

/**
 * ADR-096 decision 7: the shared body behind both `CheckSupplierDeliveryJob`
 * (the scheduled reconcile poll, ADR-032 decision 5 — unchanged behaviour,
 * now a thin wrapper) and the new admin "Check from Supplier" manual-poll
 * action (`Admin\OrderController::checkSupplier()`) — one status-check +
 * finalize implementation for both callers, never two copies to drift.
 *
 * Every check() call routes a terminal outcome through the exact same
 * locked finalizePendingDelivery()/finalizePendingDeliveryLeg() write
 * path a webhook would use — a duplicate/racing finalize (webhook vs.
 * poll vs. manual click, any pairing) lands on InvalidOrderTransitionException,
 * caught and reported as `applied=false`, never a failure.
 */
final class SupplierDeliveryCheckService
{
    public function __construct(
        private readonly SupplierAdapterFactory $supplierAdapters,
        private readonly OrderFulfillmentService $fulfillment,
    ) {}

    /**
     * @return array{type: string, outcome?: ?string, applied?: bool, data?: mixed, error_code?: ?string, error_message?: ?string, legs?: array}
     */
    public function check(Order $order): array
    {
        if ($order->package?->is_combo) {
            return ['type' => 'combo', 'legs' => $this->checkComboLegs($order)];
        }

        return array_merge(['type' => 'plain'], $this->checkOrder($order));
    }

    /**
     * @return array{outcome: string, applied: bool, data: mixed, error_code: ?string, error_message: ?string}
     */
    private function checkOrder(Order $order): array
    {
        Log::withContext(['order_number' => $order->order_number]);

        // ADR-030 decision 1 / ADR-032 addendum: checkStatus() is a
        // status re-submit keyed on our own reference_number
        // (Digiflazz's `ref_id`) — not supplier_ref, which a genuinely
        // Pending order may not have received yet.
        $result = $this->supplierAdapters->make($order->supplier->slug)->checkStatus(new SupplierStatusCheckRequest(
            supplierRef: $order->reference_number,
            productRef: $order->supplier_product_ref,
            playerId: $order->player_id,
            serverId: $order->server_id,
            // ADR-097 decision 14 — this service builds its own
            // SupplierStatusCheckRequest independently of
            // OrderFulfillmentService (ADR-096's reconcile poll +
            // manual "Check from Supplier"), so it needs this fix too,
            // not just createOrder()'s call site — a mismatched
            // customer_no here breaks Digiflazz's re-submit match.
            customerNoSeparator: $order->game?->customerNoSeparatorOverride(),
            orderId: $order->id,
        ));

        $applied = false;

        try {
            switch ($result->outcome) {
                case SupplierOutcome::Success:
                    $this->fulfillment->finalizePendingDelivery(
                        $order,
                        SupplierOutcome::Success,
                        $result->data['supplier_ref'] ?? null,
                        $result->data,
                    );
                    $applied = true;
                    break;
                case SupplierOutcome::Failure:
                    $this->fulfillment->finalizePendingDelivery(
                        $order,
                        SupplierOutcome::Failure,
                        null,
                        ['error_code' => $result->errorCode, 'error_message' => $result->errorMessage],
                    );
                    $applied = true;
                    break;
                case SupplierOutcome::Pending:
                    // Decision 5: still-Pending stays for the next run — not an error.
                    break;
            }
        } catch (InvalidOrderTransitionException $e) {
            // A webhook (or a prior poll/manual check) already finalized
            // this order before this call's own lock acquisition —
            // expected outcome, not a failure.
            Log::info('Supplier status check skipped: order already finalized', ['reason' => $e->getMessage()]);
        }

        return [
            'outcome' => $result->outcome->value,
            'applied' => $applied,
            'data' => $result->data,
            'error_code' => $result->errorCode,
            'error_message' => $result->errorMessage,
        ];
    }

    /**
     * ADR-094 decision 7 (Phase 3b): one checkStatus() call per
     * still-Pending leg — decision 4's same-supplier-only constraint
     * means every leg resolves to the same adapter, but each carries
     * its own reference (`{order.reference_number}-L{n}`) and
     * component productRef, so a status check for one leg is never
     * conflated with another.
     *
     * @return array<int, array{leg_number: int, outcome: string, applied: bool, data: mixed, error_code: ?string, error_message: ?string}>
     */
    private function checkComboLegs(Order $order): array
    {
        $pendingLegs = OrderDeliveryLeg::query()
            ->where('order_id', $order->id)
            ->where('status', DeliveryStatus::Pending->value)
            ->with('componentPackage.supplier')
            ->get();

        $results = [];

        foreach ($pendingLegs as $leg) {
            $component = $leg->componentPackage;
            $result = $this->supplierAdapters->make($component->supplier->slug)->checkStatus(new SupplierStatusCheckRequest(
                supplierRef: "{$order->reference_number}-L{$leg->leg_number}",
                productRef: $component->supplier_package_ref,
                playerId: $order->player_id,
                serverId: $order->server_id,
                // ADR-097 decision 14 — $order->game, not the leg's
                // component's own game: same-game-only combo (verified
                // at StoreComboPackageRequest) means they're identical.
                customerNoSeparator: $order->game?->customerNoSeparatorOverride(),
                orderId: $order->id,
            ));

            $applied = false;

            try {
                switch ($result->outcome) {
                    case SupplierOutcome::Success:
                        $this->fulfillment->finalizePendingDeliveryLeg(
                            $leg,
                            SupplierOutcome::Success,
                            $result->data['supplier_ref'] ?? null,
                            $result->data,
                        );
                        $applied = true;
                        break;
                    case SupplierOutcome::Failure:
                        $this->fulfillment->finalizePendingDeliveryLeg(
                            $leg,
                            SupplierOutcome::Failure,
                            null,
                            ['error_code' => $result->errorCode, 'error_message' => $result->errorMessage],
                        );
                        $applied = true;
                        break;
                    case SupplierOutcome::Pending:
                        break;
                }
            } catch (InvalidOrderTransitionException $e) {
                Log::info('Supplier status check skipped a combo leg: already finalized', [
                    'leg_id' => $leg->id,
                    'reason' => $e->getMessage(),
                ]);
            }

            $results[] = [
                'leg_number' => $leg->leg_number,
                'outcome' => $result->outcome->value,
                'applied' => $applied,
                'data' => $result->data,
                'error_code' => $result->errorCode,
                'error_message' => $result->errorMessage,
            ];
        }

        return $results;
    }
}
