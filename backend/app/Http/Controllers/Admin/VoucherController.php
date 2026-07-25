<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Voucher\CreateVoucherRequest;
use App\Models\Order;
use App\Models\Voucher;
use App\Services\Ledger\LedgerService;
use App\Services\Order\DeliveryStatus;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * VCH-1..6. Two creation paths (see docs/prd.md §14 for the founder
 * decisions behind this split):
 * - Path A (store): standalone, admin-chosen amount, threshold-gated
 *   (VCH-6) — only a Super Admin may create one at/above the threshold.
 * - Path B (storeFromOrder): auto-computed refund for a failed order,
 *   never threshold-gated — the amount is bounded by what the customer
 *   actually paid (ORD-9 principle applied to refunds), not an open
 *   admin choice.
 * Both debit the 'platform' ledger at issuance (type=voucher_issued).
 * MVP has no Reseller model yet, so reseller-wallet issuance (the
 * founder's other stated option) is Phase 2.
 */
class VoucherController extends Controller
{
    public function __construct(private readonly LedgerService $ledger)
    {
    }

    public function index(): JsonResponse
    {
        $vouchers = Voucher::query()->orderBy('created_at', 'desc')->get();

        return response()->json([
            'stats' => [
                'active' => [
                    'count' => $vouchers->where('status', 'active')->count(),
                    'total' => (int) $vouchers->where('status', 'active')->sum('remaining'),
                ],
                'total_issued' => [
                    'count' => $vouchers->count(),
                    'total' => (int) $vouchers->sum('amount'),
                ],
                'total_used' => [
                    'count' => $vouchers->filter(fn (Voucher $v) => $v->remaining < $v->amount)->count(),
                    'total' => (int) $vouchers->sum(fn (Voucher $v) => $v->amount - $v->remaining),
                ],
                'expired' => [
                    'count' => $vouchers->where('status', 'expired')->count(),
                    'total' => (int) $vouchers->where('status', 'expired')->sum('remaining'),
                ],
            ],
            'vouchers' => $vouchers,
        ]);
    }

    public function store(CreateVoucherRequest $request): JsonResponse
    {
        $data = $request->validated();
        $admin = $request->user();
        $threshold = config('vouchers.maker_checker_threshold_sen');

        if ($data['amount'] >= $threshold && $admin->role !== 'super_admin') {
            throw ValidationException::withMessages([
                'amount' => ['Vouchers at or above the maker-checker threshold require a Super Admin to create them.'],
            ]);
        }

        $voucher = $this->issue(
            customerEmail: $data['customer_email'],
            amount: $data['amount'],
            reason: $data['reason'],
            expiresAt: $data['expires_at'] ?? null,
            createdBy: $admin->id,
            approvedBy: $data['amount'] >= $threshold ? $admin->id : null,
        );

        return response()->json($voucher, 201);
    }

    public function storeFromOrder(Request $request, Order $order): JsonResponse
    {
        if ($order->delivery_status !== DeliveryStatus::Failed) {
            throw ValidationException::withMessages([
                'order' => ['A voucher can only be issued for an order with a failed delivery.'],
            ]);
        }

        if (Voucher::query()->where('order_id', $order->id)->exists()) {
            throw ValidationException::withMessages([
                'order' => ['A voucher has already been issued for this order.'],
            ]);
        }

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $amount = $order->final_amount - $order->transaction_fee;

        // The existence check above is a friendly-error fast path, not
        // the real guarantee — two concurrent requests for the same
        // order could both pass it before either commits. The unique
        // index on vouchers.order_id (migration 2026_07_25_140000) is
        // the actual serialization point; a second request that loses
        // the race hits it here instead of double-issuing a voucher.
        try {
            $voucher = $this->issue(
                customerEmail: $order->customer_email,
                amount: $amount,
                reason: $validated['reason'] ?? "Delivery failed - refund voucher for order {$order->order_number}",
                expiresAt: null,
                createdBy: $request->user()->id,
                approvedBy: null,
                orderId: $order->id,
            );
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'order' => ['A voucher has already been issued for this order.'],
            ]);
        }

        return response()->json($voucher, 201);
    }

    public function revoke(Request $request, Voucher $voucher): JsonResponse
    {
        if ($voucher->status !== 'active') {
            throw ValidationException::withMessages([
                'status' => ['Only an active voucher can be revoked.'],
            ]);
        }

        $voucher->update(['status' => 'revoked']);

        return response()->json($voucher);
    }

    /**
     * The Voucher row and its ledger debit are two separate writes —
     * without a transaction, a crash/connection-drop between them
     * leaves a Voucher with no matching ledger entry, quietly breaking
     * ADR-002's "ledger is the sole source of truth" guarantee for this
     * one path. Found during the 2026-07-25 codebase audit.
     */
    private function issue(
        string $customerEmail,
        int $amount,
        string $reason,
        ?string $expiresAt,
        int $createdBy,
        ?int $approvedBy,
        ?int $orderId = null,
    ): Voucher {
        return DB::transaction(function () use ($customerEmail, $amount, $reason, $expiresAt, $createdBy, $approvedBy, $orderId) {
            $voucher = Voucher::query()->create([
                'order_id' => $orderId,
                'code' => $this->generateCode(),
                'customer_email' => $customerEmail,
                'amount' => $amount,
                'remaining' => $amount,
                'status' => 'active',
                'expires_at' => $expiresAt,
                'reason' => $reason,
                'created_by' => $createdBy,
                'approved_by' => $approvedBy,
            ]);

            $this->ledger->credit('platform', null, -$amount, 'voucher_issued', 'voucher', $voucher->id, $createdBy);

            return $voucher;
        });
    }

    private function generateCode(): string
    {
        do {
            $code = 'VC-'.Str::upper(Str::random(8));
        } while (Voucher::query()->where('code', $code)->exists());

        return $code;
    }
}
