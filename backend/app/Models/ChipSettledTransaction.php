<?php

namespace App\Models;

use App\Services\Accounting\ChipTransactionMatchType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR-110 PR-B addendum — one row per CHIP transaction actually
 * matched/recorded during a settlement `.xlsx` ingest. `transaction_id`
 * is the dedup key that makes a re-uploaded or date-overlapping file
 * safe — see the migration's own doc comment.
 */
class ChipSettledTransaction extends Model
{
    protected $fillable = [
        'transaction_id',
        'matched_type',
        'matched_id',
        'amount_sen',
        'fee_sen',
        'net_amount_sen',
        'acquirer',
        'settled_on',
        'payment_settlement_id',
    ];

    protected $casts = [
        'matched_type' => ChipTransactionMatchType::class,
        'amount_sen' => 'integer',
        'fee_sen' => 'integer',
        'net_amount_sen' => 'integer',
        'settled_on' => 'date',
    ];

    /**
     * Explicit resolution, never `morphTo()` (see this model's own
     * migration doc comment) — null when this transaction was settled
     * but never matched to a local record.
     */
    public function matchedRecord(): Order|MembershipCheckoutAttempt|WalletTopupAttempt|null
    {
        if ($this->matched_type === null || $this->matched_id === null) {
            return null;
        }

        return match ($this->matched_type) {
            ChipTransactionMatchType::Order => Order::query()->find($this->matched_id),
            ChipTransactionMatchType::MembershipCheckoutAttempt => MembershipCheckoutAttempt::query()->find($this->matched_id),
            ChipTransactionMatchType::WalletTopupAttempt => WalletTopupAttempt::query()->find($this->matched_id),
        };
    }

    public function paymentSettlement(): BelongsTo
    {
        return $this->belongsTo(PaymentSettlement::class);
    }
}
