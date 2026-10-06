<?php

namespace App\Services\Fulfillment;

use App\Models\Order;
use App\Models\OrderResendAttempt;
use App\Models\Package;
use App\Models\PlayerValidation;
use App\Services\Checkout\CheckoutInputValidator;
use App\Services\Order\DeliveryStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * ADR-017: the richer counterpart to OrderFulfillmentService's own
 * plain idempotent resend (ORD-7's retryDelivery) — lets an admin swap
 * to a different Package (same Game only, decision #1), reconciles
 * that package's live cost against what the customer actually paid
 * (decision #3), and records every attempt in `order_resend_attempts`
 * (decision #4), not just the latest one.
 *
 * Deliberately does not touch OrderFulfillmentService's own interface
 * — this only prepares the Order row (package/profit fields) before
 * delegating the actual supplier call and delivery-status transition
 * to it unchanged, same "deepen the module, don't reshape the seam"
 * discipline as ADR-001/ADR-006/ADR-014.
 */
final class OrderResendService
{
    public function __construct(
        private readonly OrderFulfillmentService $fulfillment,
        private readonly CheckoutInputValidator $checkoutInputValidator = new CheckoutInputValidator,
    ) {}

    /**
     * ADR-105 2026-10-06 decision 12 — the one owner of every resend rule.
     * Reads only; never throws for a business rule (a broken one becomes
     * `blockedReason`, so the admin preview can show it per package).
     * Decisions 9-10: one profit rule for every basis — the affiliate's
     * checkout share is kept, and the platform takes whatever is left of
     * what the customer actually paid after this package's live cost.
     */
    public function preflight(Order $order, Package $targetPackage): ResendImpact
    {
        [$blockedField, $blockedReason] = $this->blockedBy($order, $targetPackage) ?? [null, null];
        $liveCostPrice = $targetPackage->cost_price;

        return new ResendImpact(
            costPriceSen: $liveCostPrice,
            costDiffSen: $liveCostPrice - $order->cost_price,
            affiliateProfitSen: $order->affiliate_profit,
            platformProfitSen: $order->selling_price - $liveCostPrice - $order->affiliate_profit,
            blockedField: $blockedField,
            blockedReason: $blockedReason,
        );
    }

    /**
     * ADR-105 2026-10-06 decision 15 — every active package of the
     * order's game with its preflight() impact, for the admin preview
     * (admin and sandbox). Admin-only: cost and profit are shown.
     *
     * @return list<array<string, mixed>>
     */
    public function options(Order $order): array
    {
        return Package::query()
            ->where('game_id', $order->game_id)
            ->where('is_active', true)
            ->orderBy('cost_price')
            ->orderBy('id')
            ->get()
            ->map(fn (Package $package) => [
                'id' => $package->id,
                'name' => $package->name,
                'supplier_package_ref' => $package->supplier_package_ref,
                'impact' => $this->preflight($order, $package)->toArray(),
            ])
            ->all();
    }

    /**
     * @param  $overrideReason  ADR-105 decision 11 — required only when
     *                         this attempt's live cost leaves the
     *                         platform a loss on any basis.
     *
     * @throws ValidationException when preflight() blocks the resend, a
     *                             loss has no override reason, a corrected
     *                             ID breaks the game's contract, or the
     *                             game's player-ID validation is missing.
     */
    public function resend(Order $order, Package $targetPackage, ?string $note, ?string $triggeredBy, ?string $playerId = null, ?string $serverId = null, ?string $overrideReason = null): Order
    {
        // ADR-105 2026-10-06 decision 14: re-checked and written under the
        // row lock, so a second overlapping resend sees this one's write
        // (or its Processing state) instead of overwriting it.
        [$locked, $costBefore] = DB::transaction(function () use ($order, $targetPackage, $playerId, $serverId, $overrideReason) {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
            $costBefore = $locked->cost_price;

            $impact = $this->preflight($locked, $targetPackage);
            $impact->assertAllowed($overrideReason);

            // ADR-102 decision 10: a corrected ID is applied before the
            // player-ID checks, so the corrected value is what gets
            // validated and sent. An empty server_id clears it; an empty
            // player_id means "no correction given".
            if ($playerId !== null && $playerId !== '') {
                $locked->player_id = $playerId;
            }
            if ($serverId !== null) {
                $locked->server_id = $serverId !== '' ? $serverId : null;
            }

            // ADR-097 2026-10-05 addendum, decision 31 — a corrected ID is
            // re-sent to the supplier, so it meets the same per-game
            // contract as checkout. An untouched order resends as placed.
            $corrected = ($playerId !== null && $playerId !== '') || $serverId !== null;
            if ($corrected && $locked->game !== null
                && $error = $this->checkoutInputValidator->validate($locked->game, $locked->player_id, $locked->server_id)) {
                throw ValidationException::withMessages([$error['field'] => [$error['message']]]);
            }

            $this->assertPlayerIdIsValidatedIfRequired($locked);

            if ($impact->overrideRequired()) {
                Log::warning('Admin resend accepted a loss below cost', [
                    'order_id' => $locked->id,
                    'pricing_basis' => $locked->pricing_basis?->value,
                    'live_cost_price' => $impact->costPriceSen,
                    'affiliate_profit' => $impact->affiliateProfitSen,
                    'resulting_platform_profit' => $impact->platformProfitSen,
                    'override_reason' => $overrideReason,
                ]);
            }

            // Decision 13: cost_price follows the package actually sent;
            // selling_price/final_amount/transaction_fee never move
            // (ADR-017 decision 2). affiliate_profit is unchanged by
            // definition (decision 10) and so is not written.
            $locked->update([
                'package_id' => $targetPackage->id,
                'supplier_id' => $targetPackage->supplier_id,
                'supplier_product_ref' => $targetPackage->supplier_package_ref,
                'player_id' => $locked->player_id,
                'server_id' => $locked->server_id,
                'cost_price' => $impact->costPriceSen,
                'platform_profit' => $impact->platformProfitSen,
            ]);

            return [$locked, $costBefore];
        });

        // ADR-106 addendum (2026-09-21): $recordAttempt=false — this
        // method writes its own, richer `attempt_type=resend` row below.
        $result = $this->fulfillment->fulfill($locked->fresh(), recordAttempt: false);

        // Decision 14: the row names what fulfill() actually sent, which
        // an overlapping resend may have changed after our commit.
        $sentPackage = $result->package_id === $targetPackage->id ? $targetPackage : $result->package;

        OrderResendAttempt::query()->create([
            'order_id' => $result->id,
            'attempt_type' => 'resend',
            'package_id' => $result->package_id,
            'cost_price_sen' => $result->cost_price,
            'standard_selling_price_sen' => $sentPackage?->standard_selling_price ?? 0,
            // Decision 13: against the cost assigned before this attempt.
            'price_diff_sen' => $result->cost_price - $costBefore,
            // 2026-09-15 bugfix: a still-Pending async result self-corrects
            // later via OrderFulfillmentService::resolvePendingResendAttempt().
            'outcome' => match ($result->delivery_status) {
                DeliveryStatus::Delivered => 'success',
                DeliveryStatus::Pending => 'pending',
                default => 'failed',
            },
            'supplier_response' => $result->supplier_response,
            // ADR-106 addendum (2026-09-21), grill Q7: a real $note wins;
            // the override reason is the fallback.
            'note' => $note ?: $overrideReason,
            'triggered_by' => $triggeredBy,
        ]);

        return $result;
    }

    /**
     * ADR-105 2026-10-06 decision 16 — a resend refused at attempt time
     * leaves a row the admin can see in Delivery & Activity Logs.
     */
    public function recordRejection(Order $order, Package $targetPackage, string $reason, ?string $triggeredBy): void
    {
        OrderResendAttempt::query()->create([
            'order_id' => $order->id,
            'attempt_type' => 'resend',
            'package_id' => $targetPackage->id,
            'cost_price_sen' => $targetPackage->cost_price,
            'standard_selling_price_sen' => $targetPackage->standard_selling_price,
            'price_diff_sen' => $targetPackage->cost_price - $order->cost_price,
            'outcome' => 'rejected',
            'note' => $reason,
            'triggered_by' => $triggeredBy,
        ]);
    }

    /**
     * Every hard guard, as [field, message], or null when none applies.
     * ADR-102 decision 1: re-run under the lock at attempt time, since the
     * controller's synchronous call can go stale before the job runs.
     *
     * @return array{0: string, 1: string}|null
     */
    private function blockedBy(Order $order, Package $targetPackage): ?array
    {
        if (! in_array($order->delivery_status, [DeliveryStatus::Failed, DeliveryStatus::NeedsReview], true)) {
            return ['delivery_status', 'Only an order with a failed or needs-review delivery can be resent.'];
        }

        if ($order->isAlreadyCompensated()) {
            return ['delivery_status', 'This order has already been compensated (voucher issued or wallet refunded) — it cannot be resent.'];
        }

        if ($targetPackage->game_id !== $order->game_id) {
            return ['package_id', 'The selected package must belong to the same game as this order.'];
        }

        if (! $targetPackage->is_active) {
            return ['package_id', 'This package is not currently active.'];
        }

        // ADR-094 decision 10: a package swap copies one
        // supplier_product_ref onto the order — meaningless for a combo.
        // A combo uses the ordinary Retry instead.
        if ($order->package?->is_combo || $targetPackage->is_combo) {
            return ['package_id', 'A combo order cannot be resent to a different package — use the ordinary Resend Delivery retry instead.'];
        }

        // ADR-102 2026-10-05 addendum, decision 8.
        if ($order->blocksPackageSwapTo($targetPackage)) {
            return ['package_id', Order::PACKAGE_SWAP_BLOCKED_MESSAGE];
        }

        return null;
    }

    /**
     * Decision #6: reuses the exact same `player_validations` check
     * CheckoutController::assertPlayerIdIsValidated() already enforces
     * at checkout — same data-tuple match (game/player_id/server_id),
     * same rolling window — rather than a session/token mechanism this
     * architecture doesn't have (ADR-005's newest addendum's own
     * reasoning, unchanged here).
     */
    private function assertPlayerIdIsValidatedIfRequired(Order $order): void
    {
        $game = $order->game;

        if ($game === null || ! $game->player_validator_enabled || $game->player_validator_profile_id === null) {
            return;
        }

        $windowMinutes = (int) config('services.player_validation.checkout_window_minutes', 30);

        $validated = PlayerValidation::query()
            ->where('game_id', $game->id)
            ->where('player_id', $order->player_id)
            ->where('server_id', $order->server_id)
            ->where('status', 'valid')
            ->where('validated_at', '>=', now()->subMinutes($windowMinutes))
            ->exists();

        if (! $validated) {
            throw ValidationException::withMessages([
                'player_id' => ['Please re-validate this Player ID before resending.'],
            ]);
        }
    }
}
