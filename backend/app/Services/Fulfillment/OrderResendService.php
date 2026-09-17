<?php

namespace App\Services\Fulfillment;

use App\Models\Order;
use App\Models\OrderResendAttempt;
use App\Models\Package;
use App\Models\PlayerValidation;
use App\Services\Order\DeliveryStatus;
use App\Services\Pricing\InvalidPricingConfigException;
use App\Services\Pricing\MembershipPricingService;
use App\Services\Pricing\PricingBasis;
use App\Services\Pricing\PricingService;
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
        private readonly PricingService $pricing,
        private readonly MembershipPricingService $membershipPricing,
    ) {}

    /**
     * @param  $overrideReason  ADR-105 decision 4 — required only when
     *                          this attempt's live cost would sell below
     *                          what the customer already paid (Standard/
     *                          lapsed-affiliate basis only; see the guard
     *                          below). Reuses the same field/mechanism
     *                          ADR-102 decision 3 already threads through
     *                          this call for its own, unrelated guard.
     *
     * @throws ValidationException when the target package isn't a
     *                             same-game swap (decision #1), isn't active, the order
     *                             isn't currently in a resendable state, the game
     *                             requires player-ID validation that hasn't happened
     *                             recently (decision #6), or this attempt's live cost
     *                             would sell below the order's frozen price with no
     *                             override reason supplied (decision #4).
     */
    public function resend(Order $order, Package $targetPackage, ?string $note, ?string $triggeredBy, ?string $playerId = null, ?string $serverId = null, ?string $overrideReason = null): Order
    {
        $this->assertResendable($order);
        $this->assertSameGamePackage($order, $targetPackage);

        // ADR-102 decision 10: applied in-memory (not yet persisted)
        // BEFORE the player-ID validation check below, so a corrected
        // ID is what actually gets validated and, further down, what
        // actually gets sent to the supplier — not the original
        // customer-typo'd value. An empty string clears server_id
        // (some games have none); an empty player_id is treated as "no
        // correction given" (a blank Player ID is never a legitimate
        // value to submit).
        if ($playerId !== null && $playerId !== '') {
            $order->player_id = $playerId;
        }
        if ($serverId !== null) {
            $order->server_id = $serverId !== '' ? $serverId : null;
        }

        $this->assertPlayerIdIsValidatedIfRequired($order);

        // Decision #3: live cost is the one figure this reconciliation
        // ever reads off the *target* package — the order's own frozen
        // cost_price/standard_selling_price (decision #2, untouched) is
        // what everything else below reconciles against.
        //
        // ADR-105: `$liveStandardSellingPrice` is still recorded on the
        // `order_resend_attempts` audit row below (what this package's
        // own retail price happened to be at this moment) but is
        // deliberately never fed into a profit formula — that field
        // drifts independently of this resend (routine Digiflazz
        // price-syncs), so using it there decoupled recorded profit
        // from what the customer actually paid (found live on order 15
        // — see ADR-105's Context). Every profit formula below
        // reconciles live cost against the order's own frozen figures
        // instead.
        $liveCostPrice = $targetPackage->cost_price;
        $liveStandardSellingPrice = $targetPackage->standard_selling_price;
        $priceDiff = $liveCostPrice - $order->cost_price;

        // Decision #5: platform_profit/affiliate_profit are recomputed
        // from this attempt's live cost — same formula PricingService
        // already applies at checkout, reused here rather than invented
        // fresh — and set on Order *before* calling fulfill(), so that
        // if (and only if) this attempt is the one that actually
        // succeeds, creditProfit() inside fulfill() credits the ledger
        // with these exact figures. final_amount/selling_price/
        // transaction_fee are never part of this update — decision
        // #2/#5's immutability line.
        //
        // ADR-027 Phase 6 / ADR-105 decision 3: a member-priced order
        // recomputes via the member formula instead — live cost_price
        // (things a resync can change), the order's own frozen
        // member_discount_percent (never a live tier lookup), AND the
        // order's own frozen markup_percent (the *package's*
        // markup_percent at checkout time — falls back to the target
        // package's current value only for a pre-ADR-105 order that was
        // never backfilled, since no frozen value exists to read).
        //
        // ADR-060 PR-4b / ADR-105 decisions 1-2: every non-member basis —
        // Standard, Affiliate and ResellerWallet — computes
        // `affiliateProfit` through the supplier-cost chain
        // (`calculateForAffiliate`). The frozen `wholesale_markup_pct` is
        // null for a plain Standard order (so `calculateForAffiliate`
        // delegates to `calculate`, reconciling live cost against the
        // order's own frozen `standard_selling_price` rather than the
        // target package's live one — ADR-105 decision 1) and the
        // snapshotted tier markup for an Affiliate or ResellerWallet
        // order (unchanged by ADR-105 — decision 2 keeps this basis's
        // live-cost-proportional-margin `affiliateProfit` formula on
        // purpose).
        //
        // ADR-105 decision 8 (addendum, found while explaining decision
        // 2 to the founder): `platformProfit` itself is NOT read off
        // `calculateForAffiliate()`'s own breakdown for either branch —
        // it's always derived afterward as one money-conservation
        // identity, `order.selling_price (frozen total) - liveCostPrice
        // - affiliateProfit`. The pre-addendum code computed
        // platformProfit independently for the tier branch
        // (`wholesaleBase - liveCostPrice`), which is never reconciled
        // against what was actually collected — `wholesaleBase >=
        // liveCostPrice` always holds by construction for a
        // non-negative tier%, so that formula could never "sell below
        // cost," but nothing stopped `liveCostPrice + affiliateProfit +
        // platformProfit` from exceeding `order.selling_price`, silently
        // crediting both the platform and the affiliate more than the
        // order ever actually collected. The residual formula below
        // can't do that: it's derived FROM the frozen total, so the
        // three pieces always sum back to exactly it.
        if ($order->pricing_basis === PricingBasis::Member) {
            $liveMemberPrice = $this->membershipPricing->calculateMemberPrice(
                $liveCostPrice,
                $order->markup_percent !== null ? (float) $order->markup_percent : (float) $targetPackage->markup_percent,
                (float) $order->member_discount_percent,
            );
            $platformProfit = $liveMemberPrice - $liveCostPrice;
            $affiliateProfit = 0;
        } else {
            // `calculateForAffiliate()`'s own `standardSellingPrice <
            // costPrice` guard runs unconditionally, before it even
            // branches on `$tierMarkupPct` — so which value is safe to
            // pass here differs by basis. For a tier-affiliate/
            // ResellerWallet order, the target package's own live
            // standard price is always >= its own live cost by package
            // curation (a package-data-integrity check, unrelated to
            // decision 8's residual below) — passing it here keeps this
            // branch's `affiliateProfit` formula byte-identical to
            // pre-ADR-105 behavior. For a Standard/lapsed-affiliate
            // order, the order's own frozen standard_selling_price is
            // what both this guard AND (via `calculate()`) the real
            // `affiliateProfit` formula reconcile against instead
            // (decision 1).
            $standardSellingPriceForGuard = $order->wholesale_markup_pct !== null
                ? $liveStandardSellingPrice
                : $order->standard_selling_price;

            try {
                $affiliateProfit = $this->pricing->calculateForAffiliate(
                    $liveCostPrice,
                    $standardSellingPriceForGuard,
                    $order->wholesale_markup_pct !== null ? (float) $order->wholesale_markup_pct : null,
                    (float) $order->affiliate_markup_pct,
                )->affiliateProfit;
            } catch (InvalidPricingConfigException) {
                // Standard/lapsed-affiliate basis only — the tier
                // branch's own guard argument is always safe, per the
                // note above. This package's live cost already exceeds
                // the order's frozen standard_selling_price before
                // affiliateProfit is even subtracted, so affiliateProfit
                // is computed the same way `calculate()` would have: a
                // flat percentage of the order's own frozen
                // standard_selling_price, untouched by live cost.
                $affiliateProfit = (int) round($order->standard_selling_price * (float) $order->affiliate_markup_pct / 100);
            }

            $platformProfit = $order->selling_price - $liveCostPrice - $affiliateProfit;
        }

        // ADR-105 decision 4 (Standard/lapsed-affiliate) + decision 8
        // addendum (extended to Affiliate/ResellerWallet): a resend
        // whose live cost drives platformProfit negative is a genuine
        // loss — on top of whatever's already been absorbed, or landing
        // straight on a loss for the first time. A manual admin resend
        // may still proceed and take it, but only with an explicit
        // override reason; the automatic reconcile-driven retry path
        // never reaches this method with a package swap at all
        // (ResendOrderDeliveryJob is admin-dispatched only), so there is
        // no unattended path that can hit this silently. Never reachable
        // for Member (memberPrice = cost × (1 + markup%) with markup% ≥
        // 0 can never sell below cost, decision 3, unchanged).
        if ($order->pricing_basis !== PricingBasis::Member && $platformProfit < 0) {
            if ($overrideReason === null || trim($overrideReason) === '') {
                throw ValidationException::withMessages([
                    'override_reason' => ['This resend would result in a loss — live cost now exceeds what was actually collected for this order. Provide an override reason to proceed anyway and accept the loss.'],
                ]);
            }

            Log::warning('Admin resend accepted a loss below cost', [
                'order_id' => $order->id,
                'pricing_basis' => $order->pricing_basis?->value,
                'live_cost_price' => $liveCostPrice,
                'affiliate_profit' => $affiliateProfit,
                'resulting_platform_profit' => $platformProfit,
                'override_reason' => $overrideReason,
            ]);
        }

        $order->update([
            'package_id' => $targetPackage->id,
            'supplier_id' => $targetPackage->supplier_id,
            'supplier_product_ref' => $targetPackage->supplier_package_ref,
            // ADR-102 decision 10 — already applied in-memory above;
            // listed explicitly here (rather than left to the
            // already-dirty attribute) so this update() call remains
            // the one place that documents every field a resend can
            // change.
            'player_id' => $order->player_id,
            'server_id' => $order->server_id,
            'platform_profit' => $platformProfit,
            'affiliate_profit' => $affiliateProfit,
        ]);

        $result = $this->fulfillment->fulfill($order->fresh());

        OrderResendAttempt::query()->create([
            'order_id' => $result->id,
            // ADR-106 decision 2 — this write site only ever produces a
            // genuine admin-triggered resend; 'initial'/'manual_confirm'
            // are written from inside OrderFulfillmentService instead.
            'attempt_type' => 'resend',
            'package_id' => $targetPackage->id,
            'cost_price_sen' => $liveCostPrice,
            'standard_selling_price_sen' => $liveStandardSellingPrice,
            'price_diff_sen' => $priceDiff,
            // 2026-09-15 bugfix: a genuinely still-Pending async result
            // (Digiflazz rc=03) is no longer coerced into a hard
            // 'failed' — it self-corrects the moment the real outcome
            // resolves, via OrderFulfillmentService::resolvePendingResendAttempt()
            // (called from finalizePendingDelivery(), the one shared
            // path a webhook/reconcile-poll/manual-check all use).
            'outcome' => match ($result->delivery_status) {
                DeliveryStatus::Delivered => 'success',
                DeliveryStatus::Pending => 'pending',
                default => 'failed',
            },
            'supplier_response' => $result->supplier_response,
            'note' => $note,
            'triggered_by' => $triggeredBy,
        ]);

        return $result;
    }

    /**
     * Mirrors OrderController::retryDelivery()'s own guard exactly —
     * ADR-017 doesn't loosen it, it only adds package-swap/reconciliation
     * on top of the same "only a failed (or, per ADR-026, needs_review)
     * delivery can be resent" rule.
     */
    /**
     * ADR-102 decision 1: this is the actual attempt-time re-check —
     * `OrderController::resend()`'s own guard is only a fast, friendly
     * pre-check that can pass and then go stale before this job
     * actually runs (an admin issues a voucher for this order in the
     * gap between the click and the queue picking it up). Found this
     * was a real, previously-unguarded gap here specifically: this
     * method never checked `Voucher::exists()`/wallet-refund at all
     * before ADR-102 — only the controller did.
     */
    private function assertResendable(Order $order): void
    {
        if (! in_array($order->delivery_status, [DeliveryStatus::Failed, DeliveryStatus::NeedsReview], true)) {
            throw ValidationException::withMessages([
                'delivery_status' => ['Only an order with a failed or needs-review delivery can be resent.'],
            ]);
        }

        if ($order->isAlreadyCompensated()) {
            throw ValidationException::withMessages([
                'delivery_status' => ['This order has already been compensated (voucher issued or wallet refunded) — it cannot be resent.'],
            ]);
        }
    }

    private function assertSameGamePackage(Order $order, Package $targetPackage): void
    {
        if ($targetPackage->game_id !== $order->game_id) {
            throw ValidationException::withMessages([
                'package_id' => ['The selected package must belong to the same game as this order.'],
            ]);
        }

        if (! $targetPackage->is_active) {
            throw ValidationException::withMessages([
                'package_id' => ['This package is not currently active.'],
            ]);
        }

        // ADR-094 decision 10: this "swap to a different package/supplier"
        // tool assumes exactly one supplier_product_ref to copy onto the
        // Order below — nonsensical for a combo (multi-leg) entity, and
        // doing nothing here would silently write a null/garbage
        // supplier_product_ref onto a real order. The ordinary "Resend
        // Delivery" retry (no package swap, OrderController::retryDelivery())
        // stays fully usable for a combo order — it just calls fulfill()
        // again, unaffected by this guard.
        if ($order->package?->is_combo || $targetPackage->is_combo) {
            throw ValidationException::withMessages([
                'package_id' => ['A combo order cannot be resent to a different package — use the ordinary Resend Delivery retry instead.'],
            ]);
        }
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
