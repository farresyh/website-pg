<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAffiliate;
use App\Services\Ledger\LedgerOwnerType;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\PaymentStatus;
use App\Services\Pricing\PricingBasis;
use App\Services\Supplier\Digiflazz\DigiflazzAdapter;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Order extends Model
{
    /** ADR-057: tenant-scoped to the current affiliate under the affiliate guard. */
    use BelongsToAffiliate;

    /** @use HasFactory<OrderFactory> */
    use HasFactory;

    protected $fillable = [
        'order_number',
        'checkout_idempotency_key',
        'reseller_api_idempotency_payload_hash',
        'reference_number',
        'is_test',
        'customer_email',
        'customer_name',
        'customer_phone',
        'player_id',
        'server_id',
        'game_id',
        'package_id',
        'supplier_id',
        'supplier_product_ref',
        'affiliate_id',
        'wallet_reseller_id',
        'voucher_id',
        'pricing_basis',
        'membership_id',
        'member_discount_percent',
        'markup_percent',
        'normal_selling_price',
        'cost_price',
        'standard_selling_price',
        'affiliate_markup_pct',
        'wholesale_markup_pct',
        'selling_price',
        'voucher_discount',
        'transaction_fee',
        'final_amount',
        'platform_profit',
        'affiliate_profit',
        'real_cost_price_sen',
        'profit_reconciled_flagged',
        'payment_status',
        'paid_at',
        'delivery_status',
        'delivered_at',
        'payment_method',
        'payment_gateway',
        'channel_code',
        'payment_ref',
        'supplier_ref',
        'supplier_response',
    ];

    protected $casts = [
        'is_test' => 'boolean',
        'pricing_basis' => PricingBasis::class,
        'member_discount_percent' => 'decimal:2',
        'markup_percent' => 'decimal:2',
        'normal_selling_price' => 'integer',
        'cost_price' => 'integer',
        'standard_selling_price' => 'integer',
        'affiliate_markup_pct' => 'decimal:2',
        'wholesale_markup_pct' => 'decimal:2',
        'selling_price' => 'integer',
        'voucher_discount' => 'integer',
        'transaction_fee' => 'integer',
        'final_amount' => 'integer',
        'platform_profit' => 'integer',
        'affiliate_profit' => 'integer',
        'real_cost_price_sen' => 'integer',
        'profit_reconciled_flagged' => 'boolean',
        'payment_status' => PaymentStatus::class,
        'paid_at' => 'datetime',
        'delivery_status' => DeliveryStatus::class,
        'delivered_at' => 'datetime',
        'supplier_response' => 'array',
    ];

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * ADR-094 decision 7: populated only for a combo order (`package`
     * resolves to a `Package` with `is_combo=true`) — one row per real
     * outbound supplier call, in leg order. Empty for every ordinary
     * single-supplier order.
     */
    public function deliveryLegs(): HasMany
    {
        return $this->hasMany(OrderDeliveryLeg::class)->orderBy('leg_number');
    }

    /**
     * ADR-094 decision 9 (2026-09-15 Phase 4), widened by ADR-094's
     * 2026-09-21 addendum decision 25: a combo order's delivery_status
     * can land in NeedsReview for two structurally different reasons —
     * real leg-level ambiguity with NOTHING delivered yet (no Delivered
     * leg present at all) versus a genuine partial delivery (at least
     * one leg Delivered, alongside at least one leg that is Failed or
     * itself NeedsReview — a Gamevion duplicate_reference, an
     * unexpected exception mid-attempt, or a rare Digiflazz malformed-
     * envelope case; confirmed supplier-agnostic, not Gamevion-only).
     * Only the second is this decision's carve-out from ADR-026
     * decision 4c's "Issue Voucher is blocked from needs_review" rule —
     * the first case still requires a human Retry/Resend call, never a
     * refund (Mark Delivered is no longer a candidate here either,
     * decision 24 blocks it for every combo order). Pending is still
     * excluded — defensive, and structurally unreachable at this point
     * anyway: `resolveComboOutcome()`'s own status-priority ordering
     * means no leg can still be Pending once the order itself has
     * reached NeedsReview. Non-combo orders (`deliveryLegs` empty) are
     * always false here — their own needs_review path is unchanged.
     */
    /**
     * ADR-111 decision 7 — replaces ADR-107 decision 3's combo-only,
     * negative-only `hasNegativeComboProfit()`: a persisted, universal
     * signal (any order type, not just combo) that fires on any negative
     * reconciled `platform_profit` OR a material drift from the
     * pre-reconciliation estimate (more than RM1 AND more than 1% of
     * `selling_price`). Written by `OrderFulfillmentService` at the
     * moment real-cost reconciliation actually runs — reading it here is
     * a plain column read, not a re-derivation, so it stays accurate even
     * after the order's `platform_profit` is later viewed again.
     */
    public function hasReconciledProfitFlag(): bool
    {
        return $this->profit_reconciled_flagged;
    }

    /**
     * ADR-111 addendum (2026-09-22, founder-requested): a single
     * "Cost Price" figure for Order Detail/CSV export to show — never
     * two competing cost columns (`cost_price` catalog snapshot vs
     * `real_cost_price_sen`), which reads as ambiguous to an accountant
     * auditing off this export (the documented source of truth for
     * order-level P&L). Reverse-engineers WHICH cost basis
     * `OrderFulfillmentService` actually used to arrive at the
     * currently-stored `platform_profit` (the real-cost-reconciliation
     * feature flag can be off, or the FX rate genuinely unavailable at
     * capture time, so `real_cost_price_sen` being non-null does NOT by
     * itself mean it was used) rather than guessing — the residual
     * formula is basis-agnostic (ADR-105 decision 8 / ADR-111 decision
     * 3), so checking which cost input satisfies it is exact, not a
     * heuristic. `Selling Price − Cost Price − Affiliate Profit` always
     * equals `Platform Profit` for whatever this returns.
     */
    public function effectiveCostPriceSen(): int
    {
        return $this->costReconciliation()['cost'];
    }

    /**
     * 'real' — every cost figure that went into `platform_profit` was
     * the supplier's own real per-transaction price. 'mixed' — combo
     * only: some legs' real cost was known, at least one leg fell back
     * to its catalog `cost_price` (a genuinely unavailable FX rate at
     * that leg's own delivery moment, decision 6). 'estimated' — the
     * catalog snapshot was used, either because real-cost reconciliation
     * was off at delivery time, or this order hasn't delivered yet.
     */
    public function costBasis(): string
    {
        return $this->costReconciliation()['basis'];
    }

    /**
     * @return array{cost: int, basis: string}
     */
    private function costReconciliation(): array
    {
        if ($this->deliveryLegs->isNotEmpty()) {
            return $this->comboCostReconciliation();
        }

        if ($this->real_cost_price_sen !== null
            && $this->platform_profit === $this->selling_price - $this->real_cost_price_sen - $this->affiliate_profit) {
            return ['cost' => $this->real_cost_price_sen, 'basis' => 'real'];
        }

        return ['cost' => $this->cost_price, 'basis' => 'estimated'];
    }

    /**
     * @return array{cost: int, basis: string}
     */
    private function comboCostReconciliation(): array
    {
        $legs = $this->deliveryLegs;
        $catalogTotal = (int) $legs->sum(fn (OrderDeliveryLeg $leg) => $leg->componentPackage?->cost_price ?? 0);
        $realTotal = (int) $legs->sum(fn (OrderDeliveryLeg $leg) => $leg->real_cost_price_sen ?? $leg->componentPackage?->cost_price ?? 0);
        $allLegsReal = $legs->every(fn (OrderDeliveryLeg $leg) => $leg->real_cost_price_sen !== null);

        if ($this->platform_profit === $this->selling_price - $realTotal - $this->affiliate_profit) {
            return ['cost' => $realTotal, 'basis' => $allLegsReal ? 'real' : 'mixed'];
        }

        return ['cost' => $catalogTotal, 'basis' => 'estimated'];
    }

    public function isPartialComboDelivery(): bool
    {
        if ($this->delivery_status !== DeliveryStatus::NeedsReview) {
            return false;
        }

        $statuses = $this->deliveryLegs->pluck('status');

        if ($statuses->isEmpty()) {
            return false;
        }

        if ($statuses->contains(DeliveryStatus::Pending)) {
            return false;
        }

        if (! $statuses->contains(DeliveryStatus::Delivered)) {
            return false;
        }

        return $statuses->contains(DeliveryStatus::Failed) || $statuses->contains(DeliveryStatus::NeedsReview);
    }

    /**
     * ADR-026 addendum (2026-09-16, found shipping ADR-098), renamed by
     * ADR-102 decision 3/5 — the raw "resubmitting this reference is
     * unsafe" signal (never routing — see SupplierResponse's own
     * doc comment for the split from outcomeConfirmedFailed). Drives
     * the admin panel's "Resending is unlikely to change this outcome"
     * warning text. Reconstructs the same classification
     * `ReconcilePendingDeliveriesCommand`'s catch-up queries use, from
     * the persisted `error_code` alone (the original `SupplierResponse`
     * itself is long gone by the time an admin is looking at this
     * order) — never a second hand-copied rc list,
     * `DigiflazzAdapter::resendUnsafeWithSameReference()` stays the
     * single source of truth.
     */
    public function deliveryRetryUnsafeWithSameReference(): bool
    {
        $errorCode = $this->supplier_response['error_code'] ?? null;

        if ($errorCode === null) {
            return false;
        }

        if ($errorCode === 'duplicate_reference') {
            return true;
        }

        return $this->supplier?->slug === 'digiflazz'
            && DigiflazzAdapter::resendUnsafeWithSameReference((string) $errorCode);
    }

    /**
     * ADR-102 decision 3 — the SCOPED rule that actually disables the
     * Resend/Retry button (as opposed to deliveryRetryUnsafeWithSameReference()
     * above, the raw underlying signal): a non-combo order only
     * disables from NeedsReview, since a non-combo Failed order always
     * gets a fresh reference on resend (decision 9) and is therefore
     * NEVER genuinely futile there — consulting the flag for a Failed
     * non-combo order would wrongly disable a perfectly resendable
     * order (e.g. one that just correctly landed on Failed via decision
     * 4's reclassification) forever.
     *
     * ADR-103 decision 8 — a combo order's own "combo" branch (this
     * method used to short-circuit true for ANY combo order whose own
     * `supplier_response` looked unsafe, which in practice was always
     * false — a real combo order never populates that column, only its
     * legs do) is retired and folded into the same NeedsReview-only
     * rule via an OR-rollup: unsafe if ANY leg is currently NeedsReview
     * with its own `resend_unsafe_with_same_reference` flag set. One
     * Retry click retries every outstanding leg together (decision 7 —
     * no per-leg admin UI), so one genuinely unsafe leg is enough to
     * warrant the same disable+override-reason treatment. A Failed leg
     * is never checked here, same reasoning as the non-combo case above
     * — decision 3 always mints it a fresh reference on retry.
     */
    public function resendUnsafeToOverride(): bool
    {
        if ($this->deliveryLegs->isNotEmpty()) {
            return $this->deliveryLegs
                ->where('status', DeliveryStatus::NeedsReview)
                ->contains(fn (OrderDeliveryLeg $leg) => $leg->resend_unsafe_with_same_reference === true);
        }

        if (! $this->deliveryRetryUnsafeWithSameReference()) {
            return false;
        }

        return $this->delivery_status === DeliveryStatus::NeedsReview;
    }

    /**
     * Decision 9's prefill: an admin-adjustable starting point for Issue
     * Voucher's custom amount, not the final word.
     *
     * ADR-107 decision 4 (build-time revision — see the ADR's own build
     * addendum): apportions what the customer actually paid for the
     * failed portion — `(final_amount − transaction_fee)` × each Failed
     * leg's frozen `selling_price_sen` weight — rather than summing each
     * Failed leg's CURRENT `componentPackage->standard_selling_price`
     * (a live catalog read, the original bug this ADR found: no longer
     * reflects what was actually collected if that price moved since
     * checkout). Proportional so the suggestion correctly scales down
     * when the order was voucher-discounted or affiliate-marked-up
     * relative to guest retail. Still just a prefill —
     * `StoreVoucherFromOrderRequest`'s `final_amount` hard cap and the
     * admin-typed `amount` field are unchanged, this only fixes the
     * suggestion's accuracy.
     */
    public function suggestedPartialVoucherAmount(): ?int
    {
        if (! $this->isPartialComboDelivery()) {
            return null;
        }

        $legs = $this->deliveryLegs;
        $totalSellingPriceSen = (int) $legs->sum('selling_price_sen');

        // Guards a leg seeded before this column existed (backfilled
        // approximately by the owning migration, so should never
        // actually be 0/null post-migration) — no correct proportion is
        // derivable from an all-zero denominator, so fall back to no
        // suggestion rather than a divide-by-zero or a silently-wrong one.
        if ($totalSellingPriceSen <= 0) {
            return null;
        }

        // ADR-094's 2026-09-21 addendum decision 25: isPartialComboDelivery()
        // now also recognizes a NeedsReview leg (not just Failed) as
        // part of a genuine partial delivery — this numerator has to
        // widen the same way, or a NeedsReview-caused partial would
        // suggest RM0 instead of a correct proportional share.
        $uncompensatedSellingPriceSen = (int) $legs
            ->whereIn('status', [DeliveryStatus::Failed, DeliveryStatus::NeedsReview])
            ->sum('selling_price_sen');
        $compensableAmount = $this->final_amount - $this->transaction_fee;

        return (int) round($compensableAmount * $uncompensatedSellingPriceSen / $totalSellingPriceSen);
    }

    /**
     * ADR-024 addendum (2026-09-17, restore-only) — true once this
     * order's own voucher redemption has been given back (Path B's
     * "restore" trigger), independent of whether a compensation Voucher
     * row was also minted. A full-cover-by-voucher order that later
     * fails delivery restores the original voucher but issues no new
     * one (its cash portion is 0) — `voucher()` alone stays null
     * forever for that order, so `isAlreadyCompensated()` below folds
     * this in too, or a resend/retry could still slip through after
     * the original voucher's balance was already given back.
     */
    public function isVoucherRestored(): bool
    {
        return $this->voucherRedemption()->where('status', 'restored')->exists();
    }

    /**
     * ADR-102 decision 1 — the single source of truth for "this order
     * is already settled, leave delivery alone": true when a
     * compensation voucher exists for it (`voucher()`, VCH-7) OR a
     * reseller-wallet refund has already been paid out
     * (`isAlreadyRefundedToWallet()`). Every guard that used to
     * hand-roll `Voucher::where('order_id', ...)->exists()` alone
     * (`OrderController::retryDelivery()`/`resend()`/`markDelivered()`/
     * `confirmFailed()`, `OrderResendService::assertResendable()`)
     * reads this instead — the wallet-refund half was a real gap none
     * of them checked before (order `PG-CGDZOLEHAIR8`, refunded to
     * wallet, `delivery_status=failed`, nothing stopped a further
     * resend from delivering the goods on top of the refund already
     * given).
     */
    public function isAlreadyCompensated(): bool
    {
        return $this->voucher()->exists() || $this->isAlreadyRefundedToWallet() || $this->isVoucherRestored();
    }

    /**
     * ADR-108 decision 1 — the query-level mirror of isAlreadyCompensated()
     * above: every order genuinely still needing admin action (paid,
     * delivery failed, and not already settled). `OrderController::index()`'s
     * `need_action` filter and `summary()`'s KPI count both call this
     * instead of hand-rolling `payment_status`+`delivery_status` alone, so
     * the two can never drift apart from each other or from the guard this
     * mirrors. Confirmed live on production 2026-09-18: without this
     * exclusion, the "Need Action" KPI counted 4 orders that were all
     * already compensated — real actionable count was 0.
     */
    public function scopeNeedsAction(Builder $query): Builder
    {
        return $query
            ->where('payment_status', PaymentStatus::Paid->value)
            ->where('delivery_status', DeliveryStatus::Failed->value)
            ->whereDoesntHave('voucher')
            ->whereDoesntHave('voucherRedemption', fn (Builder $q) => $q->where('status', 'restored'))
            ->whereNotExists(function ($q) {
                $q->selectRaw('1')
                    ->from('ledger_entries')
                    ->where('ledger_entries.owner_type', LedgerOwnerType::ResellerWallet->value)
                    ->where('ledger_entries.type', 'wallet_refund')
                    ->where('ledger_entries.reference_type', 'order')
                    ->whereColumn('ledger_entries.reference_id', 'orders.id')
                    ->whereColumn('ledger_entries.owner_id', 'orders.wallet_reseller_id');
            });
    }

    /**
     * ADR-073: true once a `wallet_refund` ledger entry exists for
     * this order's reseller-wallet account. Always false for a
     * non-wallet order (`wallet_reseller_id` null). Promoted out of
     * `OrderController::alreadyRefundedToWallet()` (ADR-102 decision 1)
     * so both the admin-detail response and every resend/retry guard
     * share the exact same check, computed fresh (never cached/stored)
     * since it's a cheap indexed lookup and must never go stale.
     */
    public function isAlreadyRefundedToWallet(): bool
    {
        return $this->walletRefundQuery()->exists();
    }

    /**
     * ADR-102 decision 11 (b) — the underlying `LedgerEntry` itself,
     * not just the boolean above: the admin Order Detail "Wallet
     * Refund" card needs the actual `amount`/`created_at`, not just a
     * yes/no. Same query `isAlreadyRefundedToWallet()` already runs,
     * shared so the two can never drift on what counts as "the" refund
     * entry for this order.
     */
    public function walletRefundLedgerEntry(): ?LedgerEntry
    {
        return $this->walletRefundQuery()->first();
    }

    private function walletRefundQuery(): Builder
    {
        if ($this->wallet_reseller_id === null) {
            return LedgerEntry::query()->whereRaw('1 = 0');
        }

        return LedgerEntry::query()
            ->where('owner_type', LedgerOwnerType::ResellerWallet->value)
            ->where('owner_id', $this->wallet_reseller_id)
            ->where('type', 'wallet_refund')
            ->where('reference_type', 'order')
            ->where('reference_id', $this->id);
    }

    /**
     * ADR-073 decision 5: which `Reseller` (wallet) account placed this
     * order, distinct from `affiliate()` (which brand's storefront it
     * belongs to — always the primary brand for a wallet order). Null
     * for every non-wallet order.
     */
    public function walletReseller(): BelongsTo
    {
        return $this->belongsTo(Reseller::class, 'wallet_reseller_id');
    }

    /**
     * ADR-027 Phase 6: which membership (if any) funded this order at
     * the member price — null for every guest/standard order. Snapshot
     * for audit/traceability, not a live pricing dependency (ORD-9's
     * discipline: `member_discount_percent`/`normal_selling_price` are
     * already frozen onto the order itself).
     */
    public function membership(): BelongsTo
    {
        return $this->belongsTo(Membership::class);
    }

    /**
     * ADR-017 decision #4: "Delivery Logs" history — every admin
     * resend attempt, most recent first is left to the caller
     * (OrderController::show() orders it explicitly).
     */
    public function resendAttempts(): HasMany
    {
        return $this->hasMany(OrderResendAttempt::class);
    }

    /**
     * VCH-7 (ORD-7's other resolution path): at most one, enforced by
     * the unique index on vouchers.order_id, not just app logic — see
     * VoucherController::storeFromOrder().
     */
    public function voucher(): HasOne
    {
        return $this->hasOne(Voucher::class);
    }

    /**
     * ADR-102 decision 11 (a) — the voucher this order itself was PAID
     * WITH (`orders.voucher_id`, set by `VoucherService::redeem()` at
     * checkout), not the compensation voucher `voucher()` above
     * represents. Never eager-loaded/exposed anywhere before this —
     * the admin Order Detail "Voucher Used to Pay" card needs it.
     */
    public function paidWithVoucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class, 'voucher_id');
    }

    /**
     * ADR-024 decision #2 — the inverse relation: which Voucher (if
     * any) this order itself spent to pay for part of its price. At
     * most one, enforced by voucher_redemptions.order_id's unique
     * index. Distinct from voucher() above, which is Path B's "a
     * voucher was issued because this order failed."
     */
    public function voucherRedemption(): HasOne
    {
        return $this->hasOne(VoucherRedemption::class);
    }

    /**
     * ADR-053 (REV-1..5): at most one, enforced by reviews.order_id's
     * unique index — the primary spam control on submission.
     */
    public function review(): HasOne
    {
        return $this->hasOne(Review::class);
    }

    /**
     * Scope a public storefront proof-of-ownership lookup (track-order,
     * review submission — ADR-011 / ADR-053) to the brand whose
     * storefront the request came in on (ADR-060 `X-Storefront-Host` →
     * `StorefrontBrand`). Without this an order number placed on one
     * affiliate's storefront resolves on any other's — a cross-tenant
     * leak of the buyer's game / masked contact / that brand's retail
     * price.
     *
     * An order placed on the primary brand's own storefront may carry
     * `affiliate_id = null` (legacy) or the primary affiliate's id;
     * a third-party affiliate sees only rows tagged with its own id.
     * Same shape as `ReviewCatalogController::gameReviews()`.
     */
    public function scopeForStorefrontBrand(Builder $query, Affiliate $brand): Builder
    {
        return $query->where(function (Builder $q) use ($brand) {
            $q->where('affiliate_id', $brand->id);

            if ($brand->is_primary) {
                $q->orWhereNull('affiliate_id');
            }
        });
    }
}
