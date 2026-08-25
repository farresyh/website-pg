<?php

namespace App\Services\Voucher;

use App\Models\Voucher;
use App\Models\VoucherRedemption;
use App\Services\Ledger\LedgerService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * ADR-024. issue() books the voucher's full liability once, at
 * creation (a `voucher_issued` debit) — preview()/redeem()/commit()/
 * restore() never write a `ledger_entries` row themselves; they only
 * move bookkeeping about which part of that already-booked liability
 * is currently spent.
 */
final class VoucherService
{
    public function __construct(private readonly LedgerService $ledger)
    {
    }

    /**
     * The Voucher row and its ledger debit are two separate writes —
     * without a transaction, a crash/connection-drop between them
     * leaves a Voucher with no matching ledger entry, quietly breaking
     * ADR-002's "ledger is the sole source of truth" guarantee for this
     * one path. Found during the 2026-07-25 codebase audit; moved here
     * from VoucherController (which held this money-write inline)
     * during the 2026-08-24 audit, matching every other money path in
     * this class.
     */
    public function issue(
        string $customerEmail,
        int $amount,
        string $reason,
        ?string $expiresAt,
        int $createdBy,
        ?int $approvedBy,
        ?int $orderId = null,
        ?string $customerPhone = null,
        ?string $idempotencyKey = null,
    ): Voucher {
        return DB::transaction(function () use ($customerEmail, $customerPhone, $amount, $reason, $expiresAt, $createdBy, $approvedBy, $orderId, $idempotencyKey) {
            $voucher = Voucher::query()->create([
                'order_id' => $orderId,
                'code' => $this->generateCode(),
                'idempotency_key' => $idempotencyKey,
                'customer_email' => $customerEmail,
                'customer_phone' => $customerPhone,
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

    /**
     * Read-only — never locks, never mutates. The Apply-button check
     * (ADR-024 decision #1): validates the code is usable for this
     * customer and this order's price, without committing to anything.
     * The real, locked spend only ever happens in redeem(), called
     * later from CheckoutService::requestPayment() after the gateway
     * confirms success (or immediately, for a full-cover order).
     *
     * Every failure reason (not found, inactive, expired, wrong
     * customer) surfaces through the identical InvalidVoucherException
     * message — deliberately indistinguishable from the caller's side,
     * matching this codebase's own blacklist/velocity-guard discipline
     * (foundation-security.md §4): a fraudster probing codes must never
     * be able to tell "wrong owner" from "doesn't exist" apart.
     */
    public function preview(string $code, string $customerEmail, ?string $customerPhone, int $sellingPriceSen): VoucherPreview
    {
        $voucher = $this->findUsableVoucher($code, $customerEmail, $customerPhone);

        $discount = min($voucher->remaining, $sellingPriceSen);

        return new VoucherPreview(
            voucherId: $voucher->id,
            discountSen: $discount,
            remainingAfterSen: $voucher->remaining - $discount,
        );
    }

    /**
     * The real, locked spend (ADR-024 decision #1/#2). Idempotent by
     * design — safe to call more than once for the same order (a
     * belt-and-suspenders match for CheckoutService::requestPayment()
     * being reachable from both initiate() and resume()): the
     * existence check is the friendly fast path, voucher_redemptions'
     * unique index on order_id is the real serialization point for a
     * genuine concurrent double-call, exactly the same two-layer
     * pattern VoucherController::storeFromOrder() already established
     * for the same reason.
     */
    public function redeem(int $voucherId, int $orderId, int $amount, string $customerEmail, ?string $customerPhone): void
    {
        if (VoucherRedemption::query()->where('order_id', $orderId)->exists()) {
            return;
        }

        try {
            DB::transaction(function () use ($voucherId, $orderId, $amount, $customerEmail, $customerPhone) {
                $voucher = Voucher::query()->lockForUpdate()->findOrFail($voucherId);

                $this->assertUsable($voucher, $customerEmail, $customerPhone);

                if ($voucher->remaining < $amount) {
                    throw new InvalidVoucherException(
                        "Voucher {$voucher->code} has insufficient remaining balance: has {$voucher->remaining}, requested {$amount}",
                    );
                }

                $voucher->remaining -= $amount;

                if ($voucher->remaining === 0) {
                    $voucher->status = 'exhausted';
                }

                $voucher->save();

                VoucherRedemption::query()->create([
                    'voucher_id' => $voucher->id,
                    'order_id' => $orderId,
                    'amount' => $amount,
                    'status' => 'reserved',
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            // Lost a genuine race against a concurrent duplicate redeem
            // call for the same order (e.g. requestPayment() reached
            // twice under a byzantine double-success race) — the
            // transaction above already rolled back this call's own
            // `remaining` decrement, so nothing was double-spent. The
            // winning call's row is what stands; not an error from
            // this caller's point of view.
        }
    }

    /**
     * Marks a reserved redemption permanent — the order it paid for
     * was delivered (ADR-024 decision #6: both payment and delivery
     * succeeded). No-op if this order never redeemed a voucher, so
     * callers (OrderFulfillmentService::creditProfit()) can call this
     * unconditionally for every delivered order.
     */
    public function commit(int $orderId): void
    {
        VoucherRedemption::query()
            ->where('order_id', $orderId)
            ->where('status', 'reserved')
            ->update(['status' => 'committed']);
    }

    /**
     * Gives a reserved redemption's amount back to the voucher's
     * `remaining` balance — ADR-024 decision #6's two triggers
     * (payment never completed, or delivery abandoned via admin's
     * "Issue Voucher"). No-op if this order never redeemed a voucher
     * (most orders), so every restore-trigger call site can invoke
     * this unconditionally without checking first.
     *
     * Deliberately only reactivates an `exhausted` voucher back to
     * `active` — a voucher an admin separately `revoke()`d or that
     * expired in the meantime stays exactly that; the balance is still
     * credited back for accounting correctness (the liability is real
     * regardless of whether the code is currently redeemable), but
     * `redeem()`'s own status guard keeps it un-spendable, respecting
     * whatever the admin/expiry already decided.
     */
    public function restore(int $orderId): void
    {
        DB::transaction(function () use ($orderId) {
            $redemption = VoucherRedemption::query()
                ->where('order_id', $orderId)
                ->where('status', 'reserved')
                ->lockForUpdate()
                ->first();

            if ($redemption === null) {
                return;
            }

            $voucher = Voucher::query()->lockForUpdate()->findOrFail($redemption->voucher_id);

            $voucher->remaining += $redemption->amount;

            if ($voucher->status === 'exhausted') {
                $voucher->status = 'active';
            }

            $voucher->save();

            $redemption->update(['status' => 'restored']);
        });
    }

    private function findUsableVoucher(string $code, string $customerEmail, ?string $customerPhone): Voucher
    {
        $voucher = Voucher::query()->where('code', $code)->first();

        if ($voucher === null) {
            throw new InvalidVoucherException('This voucher code is not valid for this order.');
        }

        $this->assertUsable($voucher, $customerEmail, $customerPhone);

        return $voucher;
    }

    /**
     * ADR-024 decision #3 — a voucher is compensation for a specific
     * customer, never a public bearer coupon: it must match the
     * checkout's own customer_email OR (if the voucher has one)
     * customer_phone. A single identical, generic message covers every
     * failure reason — see preview()'s own doc comment for why.
     */
    private function assertUsable(Voucher $voucher, string $customerEmail, ?string $customerPhone): void
    {
        $genericMessage = 'This voucher code is not valid for this order.';

        if ($voucher->status !== 'active') {
            throw new InvalidVoucherException($genericMessage);
        }

        if ($voucher->expires_at !== null && $voucher->expires_at->isPast()) {
            throw new InvalidVoucherException($genericMessage);
        }

        $emailMatches = Str::lower($voucher->customer_email) === Str::lower($customerEmail);
        $phoneMatches = $voucher->customer_phone !== null
            && $customerPhone !== null
            && $voucher->customer_phone === $customerPhone;

        if (! $emailMatches && ! $phoneMatches) {
            throw new InvalidVoucherException($genericMessage);
        }
    }
}
