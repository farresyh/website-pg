<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAffiliate;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\PaymentStatus;
use App\Services\Pricing\PricingBasis;
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
