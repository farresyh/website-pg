<?php

namespace App\Http\Controllers\Affiliate;

use App\Http\Controllers\Controller;
use App\Http\Requests\Affiliate\CreateAffiliateWithdrawalRequest;
use App\Models\Withdrawal;
use App\Services\Affiliate\AffiliateEarningsService;
use App\Services\Affiliate\AffiliateWithdrawalService;
use App\Services\Ledger\LedgerOwnerType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * ADR-059 59c: the affiliate-portal side of WTH-1..5 — an affiliate
 * *requests* a payout against its own earnings balance. Approval,
 * rejection, completion, and the maker-checker threshold all stay
 * admin-side (`Admin\WithdrawalController`), unchanged — the ledger
 * debit is still only written at admin approval time.
 *
 * `owner_type = 'affiliate'`, `owner_id = <affiliate id>`. `requested_by`
 * stays null (that column is an `admin_users` id); `affiliate_user_id`
 * records who asked.
 */
class WithdrawalController extends Controller
{
    public function __construct(
        private readonly AffiliateEarningsService $earnings,
        private readonly AffiliateWithdrawalService $withdrawals,
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

        return response()->json([
            'balance' => $this->earnings->balance($affiliate),
            'prefill' => [
                'bank_name' => $affiliate->bank_name,
                'bank_account_no' => $affiliate->bank_account_no,
                'bank_account_holder' => $affiliate->bank_account_holder,
            ],
            'withdrawals' => $withdrawals,
        ]);
    }

    public function store(CreateAffiliateWithdrawalRequest $request): JsonResponse
    {
        $affiliate = $request->user()->affiliateOwner();
        $data = $request->validated();

        $bankName = $data['bank_name'] ?? $affiliate->bank_name;
        $bankAccountNo = $data['bank_account_no'] ?? $affiliate->bank_account_no;
        $bankAccountHolder = $data['bank_account_holder'] ?? $affiliate->bank_account_holder;

        if ($bankName === null || $bankAccountNo === null || $bankAccountHolder === null) {
            throw ValidationException::withMessages([
                'bank_name' => ['Add your bank details in Profile first, or enter them on this request.'],
            ]);
        }

        // Balance check, "already has an open request" check, and the
        // create itself all happen inside AffiliateWithdrawalService's
        // one locked transaction — see its docblock for the race this
        // closes.
        $withdrawal = $this->withdrawals->request($affiliate, [
            'amount' => $data['amount'],
            'bank_name' => $bankName,
            'bank_account_no' => $bankAccountNo,
            'bank_account_holder' => $bankAccountHolder,
        ], $request->user()->id);

        return response()->json([
            'id' => $withdrawal->id,
            'amount' => $withdrawal->amount,
            'status' => $withdrawal->status->value,
        ], 201);
    }
}
