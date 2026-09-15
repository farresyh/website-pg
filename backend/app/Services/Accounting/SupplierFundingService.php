<?php

namespace App\Services\Accounting;

use App\Models\Order;
use App\Models\OrderDeliveryLeg;
use App\Models\Supplier;
use App\Models\SupplierLedgerEntry;
use App\Models\SupplierTransfer;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * ADR-083 decision 2/3: the sole writer of `supplier_transfers` and
 * `supplier_ledger_entries` — mirrors `ResellerWalletService`'s role for
 * the reseller wallet ledger. `recordTransfer()` (TOPUP),
 * `recordOrderDrawdown()` (ORDER_DRAWDOWN), and — since the 2026-09-15
 * addendum — `recordManualAdjustment()`/`voidTransfer()`
 * (MANUAL_ADJUSTMENT) are the only writers; `REFUND` capture (from the
 * Digiflazz webhook path) lands here too once built.
 */
final class SupplierFundingService
{
    /**
     * Stores the optional receipt first (if any), then the transfer row
     * and its `TOPUP` ledger entry — one transaction, so a failed insert
     * never leaves an orphan receipt file referenced by nothing, and the
     * ledger entry always has a `supplier_transfers` row to point back to.
     *
     * ADR-083 2026-09-15 addendum: `$supplierFee` (optional, the
     * supplier's own deposit-side cut, e.g. Digiflazz's flat IDR fee —
     * distinct from `$feeMyr`, which is Wise/Airwallex's own fee, a
     * different party on a different currency axis) is stored on the
     * transfer as-is (gross `amountForeignReceived` stays the literal,
     * receipt-verifiable figure), but the TOPUP ledger entry now
     * credits the *net* — `amountForeignReceived - supplierFee`, the
     * real wallet credit — never the gross. `effective_rate` follows
     * the same net figure (decision: "true all-in cost per unit of
     * currency actually usable", not "per unit that technically left
     * the sending bank").
     */
    public function recordTransfer(
        Supplier $supplier,
        string $sourceChannel,
        int $amountMyrSent,
        int $feeMyr,
        string $currency,
        string $amountForeignReceived,
        ?string $supplierFee,
        ?string $referenceNo,
        ?UploadedFile $receipt,
        int $adminUserId,
    ): SupplierTransfer {
        return DB::transaction(function () use (
            $supplier,
            $sourceChannel,
            $amountMyrSent,
            $feeMyr,
            $currency,
            $amountForeignReceived,
            $supplierFee,
            $referenceNo,
            $receipt,
            $adminUserId,
        ) {
            $receiptPath = null;

            if ($receipt !== null) {
                $receiptPath = $receipt->store('accounting/supplier-transfers', config('filesystems.accounting_disk'));
            }

            $netForeignReceived = number_format((float) $amountForeignReceived - (float) ($supplierFee ?? 0), 4, '.', '');

            $transfer = SupplierTransfer::query()->create([
                'supplier_id' => $supplier->id,
                'source_channel' => $sourceChannel,
                'amount_myr_sent' => $amountMyrSent,
                'fee_myr' => $feeMyr,
                'currency' => $currency,
                'amount_foreign_received' => $amountForeignReceived,
                'supplier_fee' => $supplierFee,
                'effective_rate' => $this->effectiveRate($amountMyrSent, $netForeignReceived),
                'receipt_path' => $receiptPath,
                'reference_no' => $referenceNo,
                'created_by' => $adminUserId,
            ]);

            SupplierLedgerEntry::query()->create([
                'supplier_id' => $supplier->id,
                'type' => SupplierLedgerEntryType::Topup->value,
                'amount' => $netForeignReceived,
                'currency' => $currency,
                'reference_type' => 'supplier_transfer',
                'reference_id' => $transfer->id,
                'created_by' => $adminUserId,
            ]);

            return $transfer;
        });
    }

    /**
     * ADR-083 2026-09-15 addendum: a partial correction against an
     * already-recorded transfer — e.g. the founder forgot to capture
     * the supplier's own deposit fee at entry time (the exact gap this
     * addendum closes going forward, for a transfer recorded before
     * the fix). Never edits/deletes the original `TOPUP` row
     * (`SupplierLedgerEntry` enforces this at the model layer) —
     * always a new, signed `MANUAL_ADJUSTMENT` entry referencing it,
     * so the ledger's full history stays legible: what was recorded,
     * and why it was later corrected. `$signedAmount` is in the
     * transfer's own currency, positive or negative depending on
     * which way the correction goes; `$reason` is required (the
     * column itself is documented "required by app logic for
     * MANUAL_ADJUSTMENT" since the original ADR-083 migration).
     */
    public function recordManualAdjustment(
        SupplierTransfer $transfer,
        string $signedAmount,
        string $reason,
        int $adminUserId,
    ): SupplierLedgerEntry {
        return SupplierLedgerEntry::query()->create([
            'supplier_id' => $transfer->supplier_id,
            'type' => SupplierLedgerEntryType::ManualAdjustment->value,
            'amount' => $signedAmount,
            'currency' => $transfer->currency,
            'reference_type' => 'supplier_transfer',
            'reference_id' => $transfer->id,
            'created_by' => $adminUserId,
            'reason' => $reason,
        ]);
    }

    /**
     * ADR-083 2026-09-15 addendum: the other correction shape — the
     * money behind this transfer never actually reached the supplier
     * at all (a genuinely failed send, not a typo or a missed fee).
     * Reverses the transfer's *entire* ledger contribution in one
     * `MANUAL_ADJUSTMENT` entry (the net amount the original TOPUP
     * actually credited, negated) and marks the transfer itself
     * `voided_at`/`void_reason` — `SupplierTransfer` was always
     * documented as "not append-only itself" for exactly this kind of
     * correction, so the transfer history never shows a failed send
     * as if it were a real successful one, while the linked ledger
     * entry stays immutable. One transaction: both writes succeed
     * together or neither does.
     */
    public function voidTransfer(SupplierTransfer $transfer, string $reason, int $adminUserId): SupplierLedgerEntry
    {
        return DB::transaction(function () use ($transfer, $reason, $adminUserId) {
            $reversal = $this->recordManualAdjustment(
                $transfer,
                number_format(-(float) $transfer->netForeignReceived(), 4, '.', ''),
                $reason,
                $adminUserId,
            );

            $transfer->update([
                'voided_at' => now(),
                'void_reason' => $reason,
            ]);

            return $reversal;
        });
    }

    /**
     * ADR-083 decision 3: captures a supplier's real per-order charge
     * from its OWN response (`data.price` — Digiflazz's `Sukses`
     * response/webhook, Gamevion's synchronous createOrder response),
     * never from `orders.cost_price` (decision 4 — that stays the
     * customer-facing COGS figure, untouched).
     *
     * Grilled decision, 2026-09-11: a `Pending` Digiflazz order that
     * later resolves `Gagal` writes NOTHING here — its own `Pending`
     * response never carries a `price`, so nothing was ever recorded as
     * drawn down in *our* ledger for it, and Digiflazz's own saldo is
     * assumed untouched until a `Sukses` confirms (not deducted-then-
     * restored). If a real Digiflazz account is later found to behave
     * otherwise, this needs a `REFUND` branch added deliberately, not
     * assumed here.
     *
     * Deliberately called AFTER `OrderFulfillmentService::fulfill()`'s
     * own `DB::transaction()` commits, never from inside it (ADR-083
     * decision 3 — keeps this ledger off the money-critical fulfillment
     * lock). That ordering means a crash between commit and this call
     * is a real, accepted gap — `app:refresh-supplier-balances`'s drift
     * check (decision 6) is the backstop that surfaces it, not a retry
     * here. Never throws: a failure to record the drawdown must never
     * make an already-delivered order look failed.
     *
     * ADR-094 decision 18 (2026-09-15 addendum): the optional `$leg`
     * makes the dedup key leg-aware — a combo order genuinely draws
     * down the supplier balance once per leg (decision 11), and this
     * method's own dedup guard (`exists()` below) would otherwise be
     * satisfied by the *first* leg's row, silently swallowing every
     * later leg's real drawdown for the same order. Passing a leg also
     * resolves the supplier from the leg's own `supplier_id` — a combo
     * `Order` has no `supplier_id`/`supplier` of its own (ADR-094
     * decision 3), so the plain `$order->supplier` lookup below would
     * always bail before a combo leg's drawdown was ever recorded.
     */
    public function recordOrderDrawdown(Order $order, float $price, ?OrderDeliveryLeg $leg = null): void
    {
        $supplier = $leg?->supplier ?? $order->supplier;

        if ($supplier === null) {
            return;
        }

        $referenceType = $leg !== null ? 'order_delivery_leg' : 'order';
        $referenceId = $leg?->id ?? $order->id;

        try {
            $alreadyRecorded = SupplierLedgerEntry::query()
                ->where('reference_type', $referenceType)
                ->where('reference_id', $referenceId)
                ->where('type', SupplierLedgerEntryType::OrderDrawdown->value)
                ->exists();

            if ($alreadyRecorded) {
                return;
            }

            SupplierLedgerEntry::query()->create([
                'supplier_id' => $supplier->id,
                'type' => SupplierLedgerEntryType::OrderDrawdown->value,
                'amount' => -$price,
                'currency' => $supplier->currency,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to record supplier ledger drawdown — order delivery stands, drift check will catch the gap', [
                'order_id' => $order->id,
                'order_delivery_leg_id' => $leg?->id,
                'supplier_id' => $supplier->id,
                'price' => $price,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return LengthAwarePaginator<int, SupplierTransfer>
     */
    public function transfers(Supplier $supplier, int $perPage = 20): LengthAwarePaginator
    {
        return $supplier->transfers()
            ->with('adjustments')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /** Streams the receipt file back — super_admin only, enforced at the route. */
    public function downloadReceipt(SupplierTransfer $transfer)
    {
        if ($transfer->receipt_path === null) {
            abort(404);
        }

        return Storage::disk(config('filesystems.accounting_disk'))->download(
            $transfer->receipt_path,
            "supplier-transfer-{$transfer->id}-receipt",
        );
    }

    /**
     * MYR per 1 unit of `currency`, e.g. Digiflazz (IDR): RM 1,000 sent,
     * IDR 3,700,000 net received -> ~0.00027027 MYR per IDR. Null when
     * the foreign amount is zero — never divide by zero for a
     * malformed row.
     *
     * ADR-083 2026-09-15 addendum: the caller passes the *net* figure
     * (after the supplier's own deposit fee, if any) — this is meant
     * to answer "what did this transfer truly cost us per unit of
     * currency we can actually spend at the supplier", not "per unit
     * that technically left the sending bank". A gross-based rate
     * would silently understate the real cost by exactly the
     * supplier's own cut.
     */
    private function effectiveRate(int $amountMyrSent, string $netForeignReceived): ?string
    {
        $foreign = (float) $netForeignReceived;

        if ($foreign <= 0.0) {
            return null;
        }

        return number_format(($amountMyrSent / 100) / $foreign, 8, '.', '');
    }
}
