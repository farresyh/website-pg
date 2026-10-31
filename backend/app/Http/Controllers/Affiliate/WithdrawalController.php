<?php

namespace App\Http\Controllers\Affiliate;

use App\Http\Controllers\Controller;
use App\Models\Withdrawal;
use App\Services\Affiliate\AffiliateEarningsService;
use App\Services\Affiliate\AffiliateWithdrawalService;
use App\Services\Ledger\LedgerOwnerType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ADR-059 59c / ADR-132: the affiliate-portal side of WTH-1..5 — read only.
 * The platform opens the ticket each month (`AffiliateWithdrawalService`);
 * approval, rejection, completion and the maker-checker threshold stay
 * admin-side (`Admin\WithdrawalController`), unchanged — the ledger debit is
 * still only written at admin approval time.
 *
 * `owner_type = 'affiliate'`, `owner_id = <affiliate id>`. A system ticket has
 * `requested_by` and `affiliate_user_id` null.
 */
class WithdrawalController extends Controller
{
    public function __construct(
        private readonly AffiliateEarningsService $earnings,
        private readonly AffiliateWithdrawalService $payouts,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $affiliate = $request->user()->affiliateOwner();

        $withdrawals = Withdrawal::query()
            ->where('owner_type', LedgerOwnerType::Affiliate->value)
            ->where('owner_id', $affiliate->id)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Withdrawal $w): array => [
                'id' => $w->id,
                'amount' => $w->amount,
                'bank_name' => $w->bank_name,
                'bank_account_no' => $w->bank_account_no,
                'bank_account_holder' => $w->bank_account_holder,
                'status' => $w->status->value,
                'admin_note' => $w->admin_note,
                'created_at' => $w->created_at?->toIso8601String(),
                'processed_at' => $w->processed_at?->toIso8601String(),
            ]);

        $plan = $this->payouts->plan($affiliate);

        return response()->json([
            'balance' => $this->earnings->balance($affiliate),
            // ADR-132 decision 11: computed once, server-side. Only the fee
            // amount and date — never the tier's markup.
            'payout' => [
                'min_payout_sen' => AffiliateWithdrawalService::MIN_PAYOUT_SEN,
                'reserved_for_fee_sen' => $plan['reserve'],
                'fee_due_at' => $plan['fee_due_at'],
                'has_bank_details' => $affiliate->hasBankDetails(),
            ],
            'prefill' => [
                'bank_name' => $affiliate->bank_name,
                'bank_account_no' => $affiliate->bank_account_no,
                'bank_account_holder' => $affiliate->bank_account_holder,
            ],
            'withdrawals' => $withdrawals,
        ]);
    }
}
