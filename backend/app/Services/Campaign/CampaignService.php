<?php

namespace App\Services\Campaign;

use App\Models\Order;
use App\Models\PlatformSettings;
use App\Models\PromoCode;
use App\Models\PromoCodeRedemption;
use App\Models\Reseller;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\PaymentStatus;
use App\Services\Pricing\PricingBasis;
use App\Services\Pricing\PricingResolution;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ADR-118 (2026-11-04 addendum V1–V20): the one owner of KOL promo codes
 * on the money path.
 *
 * - quote(): read-only. Validates a code for one order and returns the
 *   lowered pricing (V3, V7, V10). Checkout applies it before
 *   computeTotal(), so `selling_price` and `platform_profit` are both
 *   after the promo (R1) and every existing profit writer stays correct.
 * - reserve(): the third instrument in CheckoutService::reserveAll()
 *   (V8), under the brand-level lock that serialises the new-customer rule.
 * - commit() / restore(): the delivery and failure seams (V9).
 *
 * The budget used is never a counter: it is Σ(amount − restored) over the
 * code's redemptions, read under the code's row lock.
 */
final class CampaignService
{
    /**
     * @throws InvalidPromoCodeException
     */
    public function quote(string $code, int $brandId, PricingResolution $pricing, PromoCustomer $customer): PromoQuote
    {
        $promo = PromoCode::query()->with('campaign')->where('code', PromoCode::normalise($code))->first();

        if ($promo === null || $promo->campaign->affiliate_id !== $brandId) {
            throw new InvalidPromoCodeException(PromoRejection::Invalid);
        }

        if (! $promo->isRedeemableNow()) {
            throw new InvalidPromoCodeException(PromoRejection::Ended);
        }

        $quote = $this->priced($promo, $pricing);

        if ($this->usedSen($promo) + $quote->discountSen > $promo->budget_sen) {
            throw new InvalidPromoCodeException(PromoRejection::Exhausted);
        }

        // Read-only, so a same-customer abandoned checkout does not block:
        // checkout cancels it first (V8), and reserve() re-checks under lock.
        if (! $this->isNewCustomer($brandId, $customer, ignoreAbandonedOf: $customer)) {
            throw new InvalidPromoCodeException(PromoRejection::NotNewCustomer);
        }

        return $quote;
    }

    /**
     * V8: an unpaid Pending checkout on this brand holding a promo
     * redemption, from a customer identical on email, phone and player
     * account — the one a fresh checkout may cancel and replace.
     */
    public function abandonedCheckoutOf(int $brandId, PromoCustomer $customer): ?Order
    {
        return $this->liveRedemptions($brandId)
            ->map(fn (PromoCodeRedemption $r) => $r->order)
            ->first(fn (Order $order) => $order->payment_status === PaymentStatus::Pending
                && PromoCustomer::forOrder($order)->identicalTo($customer));
    }

    /**
     * Reserves the order's promo or returns false (the order then fails
     * closed, CheckoutService::reserveOrClose()). Callers hold the Order
     * lock; lock order Order → Voucher → Membership → the brand's codes.
     * Idempotent per order, like VoucherService::redeem().
     */
    public function reserve(Order $order): bool
    {
        if ($order->promo_code_id === null) {
            return true;
        }

        if (PromoCodeRedemption::query()->where('order_id', $order->id)->exists()) {
            return true;
        }

        return DB::transaction(function () use ($order) {
            // V5: one lock per brand, so two first orders with two KOLs' codes serialise.
            // ponytail: locks every active code of the brand; a per-brand lock row once codes number in the dozens.
            $codes = PromoCode::query()
                ->whereHas('campaign', fn (Builder $q) => $q->where('affiliate_id', $order->affiliate_id))
                ->where('is_active', true)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $promo = $codes->firstWhere('id', $order->promo_code_id);

            if ($promo === null || ! $promo->isRedeemableNow()) {
                return false;
            }

            // Locking reads from here on: under MySQL's REPEATABLE READ a plain
            // read would still see this transaction's first snapshot, taken
            // before the lock above was granted, and miss the redemption the
            // previous holder just committed (found by the concurrency test).
            if ($this->usedSen($promo, locking: true) + $order->promo_discount_sen > $promo->budget_sen) {
                return false;
            }

            if (! $this->isNewCustomer($order->affiliate_id, PromoCustomer::forOrder($order->loadMissing('game:id,family')), exceptOrderId: $order->id, locking: true)) {
                return false;
            }

            PromoCodeRedemption::query()->create([
                'promo_code_id' => $promo->id,
                'order_id' => $order->id,
                'amount_sen' => $order->promo_discount_sen,
                'status' => PromoCodeRedemption::STATUS_RESERVED,
            ]);

            return true;
        });
    }

    /**
     * Delivered: the redemption is permanent. V9: a redemption already
     * restored (a Paid-after-Failed order an admin then delivered) is
     * re-reserved unconditionally — the discount was given, so it never
     * blocks a delivery; going past the budget is flagged, not refused.
     */
    public function commit(int $orderId): void
    {
        $redemption = PromoCodeRedemption::query()->where('order_id', $orderId)->lockForUpdate()->first();

        if ($redemption === null || $redemption->status === PromoCodeRedemption::STATUS_COMMITTED) {
            return;
        }

        if ($redemption->status === PromoCodeRedemption::STATUS_RESERVED) {
            $redemption->update(['status' => PromoCodeRedemption::STATUS_COMMITTED]);

            return;
        }

        $redemption->update(['status' => PromoCodeRedemption::STATUS_COMMITTED, 'restored_sen' => 0, 'restored_at' => null]);

        $promo = $redemption->promoCode()->firstOrFail();
        $overCap = $this->usedSen($promo) > $promo->budget_sen;
        $redemption->update(['over_cap' => $overCap]);

        Log::warning('Promo redemption re-reserved after a restore', [
            'order_id' => $orderId,
            'promo_code_id' => $promo->id,
            'over_cap' => $overCap,
        ]);
    }

    /**
     * Payment failed (whole) or the order was compensated (the undelivered
     * share, mirroring VoucherService::restore()). Null is the whole
     * redemption. A restored redemption frees the customer's slot (V5).
     */
    public function restore(int $orderId, ?int $amountSen = null): void
    {
        $redemption = PromoCodeRedemption::query()
            ->where('order_id', $orderId)
            ->where('status', PromoCodeRedemption::STATUS_RESERVED)
            ->lockForUpdate()
            ->first();

        if ($redemption === null) {
            return;
        }

        $restored = min($amountSen ?? $redemption->amount_sen, $redemption->amount_sen);

        if ($restored <= 0) {
            $redemption->update(['status' => PromoCodeRedemption::STATUS_COMMITTED]);

            return;
        }

        $redemption->update(['status' => PromoCodeRedemption::STATUS_RESTORED, 'restored_sen' => $restored, 'restored_at' => now()]);
    }

    /**
     * V6: the code a new order on this brand counts for — the one the
     * customer redeemed and had delivered. Null for a customer no KOL
     * brought in. Stamped once at order creation, never rewritten.
     */
    public function attributionFor(int $brandId, PromoCustomer $customer): ?int
    {
        if (! $this->brandHasCampaigns($brandId)) {
            return null;
        }

        return $this->redemptionsOnBrand($brandId)
            ->where('status', PromoCodeRedemption::STATUS_COMMITTED)
            ->get()
            ->first(fn (PromoCodeRedemption $r) => PromoCustomer::forOrder($r->order)->sameAs($customer))
            ?->promo_code_id;
    }

    public function usedSen(PromoCode $promo, bool $locking = false): int
    {
        return (int) PromoCodeRedemption::query()
            ->where('promo_code_id', $promo->id)
            ->when($locking, fn (Builder $q) => $q->sharedLock())
            ->get(['amount_sen', 'restored_sen'])
            ->sum(fn (PromoCodeRedemption $r) => $r->heldSen());
    }

    /** V10: the code form's list — would this (unsaved) code be refused on this price? */
    public function refusedOn(PromoCode $promo, PricingResolution $pricing): bool
    {
        try {
            $this->priced($promo, $pricing);

            return false;
        } catch (InvalidPromoCodeException) {
            return true;
        }
    }

    /** V3 + V10: the discount on this order, refused below the panel margin floor. */
    private function priced(PromoCode $promo, PricingResolution $pricing): PromoQuote
    {
        // V2: Standard only. Member is turned off by checkout before pricing; Fixed is joki.
        if ($pricing->basis !== PricingBasis::Standard) {
            throw new InvalidPromoCodeException(PromoRejection::NotApplicable);
        }

        $discount = intdiv($pricing->sellingPriceSen * (int) round((float) $promo->discount_percent * 100), 10_000);

        if ($promo->max_discount_sen !== null) {
            $discount = min($discount, $promo->max_discount_sen);
        }

        $selling = $pricing->sellingPriceSen - $discount;
        $profit = $pricing->platformProfitSen - $discount;
        $floor = (float) PlatformSettings::current()->campaign_min_margin_percent;

        if ($discount <= 0 || $profit * 100 < $floor * $selling) {
            throw new InvalidPromoCodeException(PromoRejection::NotApplicable);
        }

        return new PromoQuote($promo->id, $discount, new PricingResolution(
            costPriceSen: $pricing->costPriceSen,
            standardSellingPriceSen: $pricing->standardSellingPriceSen,
            sellingPriceSen: $selling,
            platformProfitSen: $profit,
            affiliateProfitSen: $pricing->affiliateProfitSen,
            basis: $pricing->basis,
            normalSellingPriceSen: $pricing->normalSellingPriceSen,
            membershipId: $pricing->membershipId,
            memberDiscountPercent: $pricing->memberDiscountPercent,
            wholesaleMarkupPct: $pricing->wholesaleMarkupPct,
            markupPercent: $pricing->markupPercent,
        ));
    }

    /**
     * V5: no delivered order on the brand (wallet orders excluded), no live
     * redemption of any of the brand's codes, and not a reseller's own
     * email or phone.
     *
     * ponytail: scans the brand's delivered orders in PHP, because
     * customer_phone is stored as typed; add a normalised phone column when
     * this shows in Pulse.
     */
    private function isNewCustomer(int $brandId, PromoCustomer $customer, ?int $exceptOrderId = null, ?PromoCustomer $ignoreAbandonedOf = null, bool $locking = false): bool
    {
        $boughtBefore = Order::query()
            ->with('game:id,family')
            ->where('affiliate_id', $brandId)
            ->whereNull('wallet_reseller_id')
            ->where('is_test', false)
            ->whereIn('delivery_status', [DeliveryStatus::Delivered->value, DeliveryStatus::PartiallyDelivered->value])
            ->when($exceptOrderId, fn (Builder $q) => $q->whereKeyNot($exceptOrderId))
            ->get(['id', 'game_id', 'customer_email', 'customer_phone', 'player_id', 'server_id'])
            ->contains(fn (Order $o) => PromoCustomer::forOrder($o)->sameAs($customer));

        if ($boughtBefore) {
            return false;
        }

        $holdsOne = $this->liveRedemptions($brandId, $locking)
            ->reject(fn (PromoCodeRedemption $r) => $r->order_id === $exceptOrderId)
            ->reject(fn (PromoCodeRedemption $r) => $ignoreAbandonedOf !== null
                && $r->order->payment_status === PaymentStatus::Pending
                && PromoCustomer::forOrder($r->order)->identicalTo($ignoreAbandonedOf))
            ->contains(fn (PromoCodeRedemption $r) => PromoCustomer::forOrder($r->order)->sameAs($customer));

        if ($holdsOne) {
            return false;
        }

        return ! Reseller::query()->get(['email', 'phone'])
            ->contains(fn (Reseller $r) => $customer->matchesContact($r->email, $r->phone));
    }

    /** @return Collection<int, PromoCodeRedemption> */
    private function liveRedemptions(int $brandId, bool $locking = false): Collection
    {
        if (! $this->brandHasCampaigns($brandId)) {
            return collect();
        }

        return $this->redemptionsOnBrand($brandId)
            ->whereIn('status', [PromoCodeRedemption::STATUS_RESERVED, PromoCodeRedemption::STATUS_COMMITTED])
            ->when($locking, fn (Builder $q) => $q->sharedLock())
            ->get();
    }

    private function redemptionsOnBrand(int $brandId): Builder
    {
        return PromoCodeRedemption::query()
            ->with('order:id,game_id,customer_email,customer_phone,player_id,server_id,payment_status,payment_ref,payment_gateway,order_number', 'order.game:id,family')
            ->whereHas('promoCode.campaign', fn (Builder $q) => $q->where('affiliate_id', $brandId));
    }

    private function brandHasCampaigns(int $brandId): bool
    {
        return DB::table('campaigns')->where('affiliate_id', $brandId)->exists();
    }
}
