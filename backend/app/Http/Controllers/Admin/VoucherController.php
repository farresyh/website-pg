<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Voucher\CreateVoucherRequest;
use App\Http\Requests\Voucher\MergeVouchersRequest;
use App\Http\Requests\Voucher\StoreVoucherFromOrderRequest;
use App\Models\Order;
use App\Models\Voucher;
use App\Services\Fulfillment\OrderFulfillmentException;
use App\Services\Fulfillment\OrderSettlementService;
use App\Services\Notification\CustomerNotificationService;
use App\Services\Voucher\InvalidVoucherException;
use App\Services\Voucher\VoucherService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
 * MVP has no Affiliate model yet, so affiliate-wallet issuance (the
 * founder's other stated option) is Phase 2.
 */
class VoucherController extends Controller
{
    public function __construct(
        private readonly VoucherService $vouchers,
        private readonly CustomerNotificationService $notifications,
    ) {}

    public function index(): JsonResponse
    {
        $vouchers = Voucher::query()
            ->with('affiliate:id,business_name')
            ->orderBy('created_at', 'desc')
            ->get();

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

    /**
     * ADR-024 decision #9 — the voucher detail page: usage history is
     * `voucher_redemptions` rows (real audit trail, not reconstructed
     * from `remaining` alone), each with its own order link. Stat
     * cards are computed here rather than client-side so the admin
     * panel never has to duplicate this arithmetic.
     */
    public function show(Voucher $voucher): JsonResponse
    {
        $voucher->load([
            'affiliate:id,business_name',
            'redemptions.order:id,order_number',
            'sourceOrder:id,order_number',
            // ADR-036 decision 6 — a merged voucher's real provenance:
            // which source codes it traces back to (mergesAsTarget), or
            // which merged code consumed this one (mergeAsSource).
            'mergesAsTarget.sourceVoucher:id,code',
            'mergeAsSource.targetVoucher:id,code',
            // ADR-116 decision 9 — did the customer get the code on WhatsApp.
            'customerNotifications' => fn ($query) => $query->select(['id', 'voucher_id', 'event', 'phone', 'status', 'attempts', 'error', 'sent_at', 'created_at'])->oldest(),
        ]);

        // A partly restored redemption (ADR-094 decision 34) still spent its unreturned share.
        $totalUsed = (int) $voucher->redemptions->sum(fn ($r) => $r->amount - ($r->restored_amount ?? 0));
        $restored = (int) $voucher->redemptions->where('status', 'restored')->sum('restored_amount');
        $pending = $voucher->redemptions->where('status', 'reserved')->count();
        $resolved = $voucher->redemptions->whereIn('status', ['committed', 'restored'])->count();
        $committed = $voucher->redemptions->where('status', 'committed')->count();

        return response()->json([
            'voucher' => $voucher,
            'stats' => [
                'original' => $voucher->amount,
                'remaining' => $voucher->remaining,
                'total_used' => $totalUsed,
                'restored' => $restored,
                'pending' => $pending,
                // Of every redemption that's actually been resolved one
                // way or the other (committed or restored), what share
                // stuck (committed) rather than bounced back — undefined
                // (0) until at least one has resolved, never a divide-
                // by-zero.
                'success_rate' => $resolved > 0 ? (int) round($committed / $resolved * 100) : 0,
            ],
        ]);
    }

    /**
     * ADR-035 — Path A double-submission guard. Two-layer shape,
     * identical to CheckoutController's own idempotency handling: a
     * fast-path lookup by idempotency_key first (cheap, friendly), the
     * DB unique constraint on vouchers.idempotency_key is the real
     * serialization point for a genuine concurrent double-submit. A
     * caught duplicate is a silent idempotent success — never a
     * warning to the admin — since only one Voucher genuinely exists
     * either way.
     */
    public function store(CreateVoucherRequest $request): JsonResponse
    {
        $data = $request->validated();
        $admin = $request->user();
        $threshold = config('vouchers.maker_checker_threshold_sen');

        $existing = Voucher::query()->where('idempotency_key', $data['idempotency_key'])->first();
        if ($existing !== null) {
            return response()->json($existing, 201);
        }

        if ($data['amount'] >= $threshold && $admin->role !== 'super_admin') {
            throw ValidationException::withMessages([
                'amount' => ['Vouchers at or above the maker-checker threshold require a Super Admin to create them.'],
            ]);
        }

        try {
            $voucher = $this->vouchers->issue(
                customerEmail: $data['customer_email'],
                amount: $data['amount'],
                reason: $data['reason'],
                expiresAt: $data['expires_at'] ?? null,
                createdBy: $admin->id,
                approvedBy: $data['amount'] >= $threshold ? $admin->id : null,
                // ADR-060 PR-4d: the brand this promo voucher is scoped to —
                // a required picker on the form, defaulting to the primary.
                affiliateId: $data['affiliate_id'],
                customerPhone: $data['customer_phone'] ?? null,
                idempotencyKey: $data['idempotency_key'],
            );
        } catch (UniqueConstraintViolationException) {
            // Lost a genuine race — a concurrent request with the same
            // key won the INSERT between our lookup above and now.
            $voucher = Voucher::query()->where('idempotency_key', $data['idempotency_key'])->firstOrFail();
        }

        // ADR-116: only with a phone; a standalone voucher without one stays admin-delivered.
        if ($voucher->customer_phone !== null) {
            $this->notifications->voucherIssued($voucher);
        }

        return response()->json($voucher, 201);
    }

    /**
     * ADR-036 — admin-triggered, opt-in merge of two or more active
     * vouchers belonging to the same customer into one new code. Not
     * maker-checker-gated (decision 7): a merge creates zero new
     * liability, a materially different risk profile from issuing a
     * genuinely new voucher — the mandatory `reason` field is the
     * audit control, not a second approver.
     */
    public function merge(MergeVouchersRequest $request): JsonResponse
    {
        $data = $request->validated();

        try {
            $voucher = $this->vouchers->merge(
                voucherIds: $data['voucher_ids'],
                reason: $data['reason'],
                mergedBy: $request->user()->id,
                expiresAt: $data['expires_at'] ?? null,
            );
        } catch (InvalidVoucherException $e) {
            throw ValidationException::withMessages([
                'voucher_ids' => [$e->getMessage()],
            ]);
        }

        return response()->json($voucher, 201);
    }

    /**
     * Issue Voucher for a Failed or PartiallyDelivered retail order — a
     * thin caller of OrderSettlementService (ADR-094 2026-10-04 addendum),
     * which owns the gate, the formula amount, restoring the voucher the
     * order paid with (ADR-024 decision 7, proportionally for a partial
     * delivery), the lock and the customer message. A wallet order is
     * compensated by Refund to Wallet instead (ADR-073 decision 7).
     */
    public function storeFromOrder(StoreVoucherFromOrderRequest $request, Order $order, OrderSettlementService $settlement): JsonResponse
    {
        if ($order->wallet_reseller_id !== null) {
            throw ValidationException::withMessages([
                'order' => ['This order belongs to a Reseller wallet — use Refund to Wallet instead of Issue Voucher.'],
            ]);
        }

        if (! $order->delivery_status->isCompensable()) {
            throw ValidationException::withMessages([
                'order' => ['A voucher can only be issued for an order with a failed or partial delivery.'],
            ]);
        }

        // ADR-024 addendum (2026-09-17): a restore-only order (nothing to
        // mint) is safe to click again — repeat the same answer, and the
        // (de-duplicated) message, which revives one skipped earlier.
        if ($order->voucher === null && $order->isVoucherRestored()) {
            $this->notifications->voucherRestored($order);

            return response()->json(['restored_only' => true, 'voucher' => null], 201);
        }

        try {
            $result = $settlement->settle($order, $request->user()->id, $request->validated('reason'));
        } catch (OrderFulfillmentException|UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'order' => ['A voucher has already been issued for this order.'],
            ]);
        }

        return response()->json([
            'restored_only' => $result->voucher === null,
            'voucher' => $result->voucher,
        ], 201);
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
}
