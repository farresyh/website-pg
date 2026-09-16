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
     * ADR-094 decision 9 (2026-09-15 Phase 4): a combo order's
     * delivery_status can land in NeedsReview for two structurally
     * different reasons — real leg-level ambiguity (a leg itself is
     * NeedsReview, e.g. a Gamevion duplicate_reference, or a leg still
     * Pending) versus a genuine partial delivery (some legs Delivered,
     * some cleanly Failed, nothing ambiguous/in-flight left). Only the
     * second is decision 9's carve-out from ADR-026 decision 4c's
     * "Issue Voucher is blocked from needs_review" rule — the first
     * case still requires a human Retry/Resend/Mark Delivered call,
     * never a refund. Non-combo orders (`deliveryLegs` empty) are
     * always false here — their own needs_review path is unchanged.
     */
    public function isPartialComboDelivery(): bool
    {
        if ($this->delivery_status !== DeliveryStatus::NeedsReview) {
            return false;
        }

        $statuses = $this->deliveryLegs->pluck('status');

        if ($statuses->isEmpty()) {
            return false;
        }

        if ($statuses->contains(DeliveryStatus::NeedsReview) || $statuses->contains(DeliveryStatus::Pending)) {
            return false;
        }

        return $statuses->contains(DeliveryStatus::Delivered) && $statuses->contains(DeliveryStatus::Failed);
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
     * 4's reclassification) forever. A combo order keeps checking the
     * flag regardless of Failed/NeedsReview, unchanged from today —
     * decision 9 is explicitly non-combo-only (ADR-103 covers the
     * combo case), so a combo order can still be genuinely futile even
     * while sitting at Failed. In practice this combo branch is
     * currently quiet — a real combo order never populates this
     * Order's own `supplier_response` (only its legs carry
     * `failure_reason`), so the raw signal above is always false for
     * one today. It stays here, correctly scoped, so it activates the
     * moment ADR-103's per-leg signal rolls up onto it, rather than
     * needing a second pass to re-derive this rule later.
     */
    public function resendUnsafeToOverride(): bool
    {
        if (! $this->deliveryRetryUnsafeWithSameReference()) {
            return false;
        }

        if ($this->deliveryLegs->isNotEmpty()) {
            return true;
        }

        return $this->delivery_status === DeliveryStatus::NeedsReview;
    }

    /**
     * Decision 9's prefill: the sum of every Failed leg's own component
     * price — an admin-adjustable starting point for Issue Voucher's
     * custom amount, not the final word (`standard_selling_price` may
     * have moved since this order was placed).
     */
    public function suggestedPartialVoucherAmount(): ?int
    {
        if (! $this->isPartialComboDelivery()) {
            return null;
        }

        return (int) $this->deliveryLegs
            ->where('status', DeliveryStatus::Failed)
            ->sum(fn (OrderDeliveryLeg $leg) => $leg->componentPackage?->standard_selling_price ?? 0);
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
        return $this->voucher()->exists() || $this->isAlreadyRefundedToWallet();
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
