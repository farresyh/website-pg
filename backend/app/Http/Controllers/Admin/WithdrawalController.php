<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Withdrawal\CreateWithdrawalRequest;
use App\Http\Requests\Withdrawal\RejectWithdrawalRequest;
use App\Models\Withdrawal;
use App\Services\Ledger\InsufficientBalanceException;
use App\Services\Ledger\LedgerOwnerType;
use App\Services\Ledger\LedgerService;
use App\Services\Withdrawal\WithdrawalStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * WTH-1..5. MVP only ever operates on the single internal platform
 * owner (owner_type='platform', owner_id=null — see LedgerEntry's own
 * convention). Affiliate-owned withdrawals are a Phase 2 concern.
 */
class WithdrawalController extends Controller
{
    public function __construct(private readonly LedgerService $ledger) {}

    public function index(): JsonResponse
    {
        $withdrawals = Withdrawal::query()->orderBy('created_at', 'desc')->get();

        $stats = collect(WithdrawalStatus::cases())->mapWithKeys(function (WithdrawalStatus $status) use ($withdrawals) {
            $matching = $withdrawals->where('status', $status);

            return [$status->value => [
                'count' => $matching->count(),
                'total' => (int) $matching->sum('amount'),
            ]];
        });

        $withdrawals = $withdrawals->map(fn (Withdrawal $w) => [
            ...$w->toArray(),
            'bank_details_changed_since_last_approval' => $this->bankDetailsChangedSinceLastApproval($w),
        ]);

        return response()->json([
            'stats' => $stats,
            'available_balance' => $this->ledger->balance(LedgerOwnerType::Platform, null),
            'withdrawals' => $withdrawals,
        ]);
    }

    /**
     * ADR-059 addendum, 2026-09-26: a real "did the payout destination just
     * change" signal for the admin approving/reviewing a request — compares
     * against the owner's most recently admin-approved (or completed)
     * withdrawal, never the current profile (which a withdrawal always
     * snapshots at request time anyway, so it could never differ from
     * itself). `null` when there's no prior approved withdrawal to compare
     * against (this owner's first-ever payout) or this isn't an
     * affiliate-owned withdrawal (the platform owner has no bank-detail
     * override risk to warn about).
     */
    private function bankDetailsChangedSinceLastApproval(Withdrawal $withdrawal): ?bool
    {
        if ($withdrawal->owner_type !== LedgerOwnerType::Affiliate->value) {
            return null;
        }

        $lastApproved = Withdrawal::query()
            ->where('owner_type', $withdrawal->owner_type)
            ->where('owner_id', $withdrawal->owner_id)
            ->whereIn('status', [WithdrawalStatus::Approved->value, WithdrawalStatus::Completed->value])
            ->where('id', '<', $withdrawal->id)
            ->orderByDesc('id')
            ->first();

        if ($lastApproved === null) {
            return null;
        }

        return $lastApproved->bank_name !== $withdrawal->bank_name
            || $lastApproved->bank_account_no !== $withdrawal->bank_account_no
            || $lastApproved->bank_account_holder !== $withdrawal->bank_account_holder;
    }

    public function store(CreateWithdrawalRequest $request): JsonResponse
    {
        $data = $request->validated();
        $availableBalance = $this->ledger->balance(LedgerOwnerType::Platform, null);

        if ($data['amount'] > $availableBalance) {
            throw ValidationException::withMessages([
                'amount' => ["Amount exceeds the available balance ({$availableBalance} sen)."],
            ]);
        }

        $withdrawal = Withdrawal::query()->create([
            'owner_type' => LedgerOwnerType::Platform->value,
            'owner_id' => null,
            'amount' => $data['amount'],
            'bank_name' => $data['bank_name'],
            'bank_account_no' => $data['bank_account_no'],
            'bank_account_holder' => $data['bank_account_holder'],
            'status' => WithdrawalStatus::Pending,
            'requested_by' => $request->user()->id,
        ]);

        return response()->json($withdrawal, 201);
    }

    /**
     * WTH-5: at/above the configured threshold, approval requires a
     * Super Admin who did not request this withdrawal. Below threshold,
     * any admin.role user may approve — including their own request.
     *
     * Two admins can call this for the same Withdrawal at nearly the
     * same time (e.g. both viewing the same pending row). Without a
     * lock, both would read status=Pending before either commits, both
     * pass the checks, and both write a ledger debit for one request —
     * same double-processing risk class as the fix already applied to
     * OrderFulfillmentService::fulfill(). The Withdrawal row itself is
     * locked here (not just the ledger_accounts row LedgerService
     * already locks inside withdraw()) because the thing that must be
     * serialized per-request is "has this specific withdrawal already
     * been approved," not just "is there enough balance."
     */
    public function approve(Request $request, Withdrawal $withdrawal): JsonResponse
    {
        $admin = $request->user();

        $withdrawal = DB::transaction(function () use ($withdrawal, $admin) {
            $locked = Withdrawal::query()->lockForUpdate()->findOrFail($withdrawal->id);

            if ($locked->status !== WithdrawalStatus::Pending) {
                throw ValidationException::withMessages([
                    'status' => ['Only a pending withdrawal can be approved.'],
                ]);
            }

            $threshold = config('withdrawals.maker_checker_threshold_sen');

            if ($locked->amount >= $threshold) {
                if ($admin->role !== 'super_admin') {
                    throw ValidationException::withMessages([
                        'amount' => ['Withdrawals at or above the maker-checker threshold require Super Admin approval.'],
                    ]);
                }

                if ($admin->id === $locked->requested_by) {
                    throw ValidationException::withMessages([
                        'approved_by' => ['A different Super Admin must approve this withdrawal.'],
                    ]);
                }
            }

            try {
                $this->ledger->withdraw(
                    $locked->owner_type,
                    $locked->owner_id,
                    $locked->amount,
                    referenceType: 'withdrawal',
                    referenceId: $locked->id,
                    createdBy: $admin->id,
                );
            } catch (InsufficientBalanceException $e) {
                throw ValidationException::withMessages(['amount' => [$e->getMessage()]]);
            }

            $locked->update([
                'status' => WithdrawalStatus::Approved,
                'approved_by' => $admin->id,
            ]);

            return $locked;
        });

        return response()->json($withdrawal->fresh());
    }

    /**
     * Only valid from Pending for MVP — an Approved withdrawal already
     * has its ledger debit written; reversing that is a deliberately
     * deferred edge case (see docs/prd.md §14).
     */
    public function reject(RejectWithdrawalRequest $request, Withdrawal $withdrawal): JsonResponse
    {
        if ($withdrawal->status !== WithdrawalStatus::Pending) {
            throw ValidationException::withMessages([
                'status' => ['Only a pending withdrawal can be rejected.'],
            ]);
        }

        $withdrawal->update([
            'status' => WithdrawalStatus::Rejected,
            'approved_by' => $request->user()->id,
            'admin_note' => $request->validated('admin_note'),
        ]);

        return response()->json($withdrawal);
    }

    public function complete(Request $request, Withdrawal $withdrawal): JsonResponse
    {
        if ($withdrawal->status !== WithdrawalStatus::Approved) {
            throw ValidationException::withMessages([
                'status' => ['Only an approved withdrawal can be marked completed.'],
            ]);
        }

        $withdrawal->update([
            'status' => WithdrawalStatus::Completed,
            'processed_at' => now(),
        ]);

        return response()->json($withdrawal);
    }
}
