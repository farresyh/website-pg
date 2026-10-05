<?php

namespace App\Services\Accounting;

use App\Models\Order;
use App\Models\OrderDeliveryLeg;
use App\Models\Supplier;
use App\Models\SupplierLedgerEntry;
use App\Models\SupplierTransfer;
use App\Models\SupplierTransferCorrection;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * ADR-083 decision 2/3: the sole writer of `supplier_transfers` and
 * `supplier_ledger_entries` — mirrors `ResellerWalletService`'s role for
 * the reseller wallet ledger. `recordTransfer()` (TOPUP),
 * `recordOrderDrawdown()` (ORDER_DRAWDOWN), `recordManualAdjustment()`/
 * `voidTransfer()` (MANUAL_ADJUSTMENT/VOID_REVERSAL, 2026-09-15 addendum),
 * and `recordCorrection()` (metadata-only, 2026-09-28 addendum) are the
 * only writers; `REFUND` capture (from the Digiflazz webhook path) lands
 * here too once built.
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
        ?PaidFrom $paidBy = null,
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
            $paidBy,
        ) {
            $receiptPath = null;

            if ($receipt !== null) {
                $receiptPath = $receipt->store('accounting/supplier-transfers', config('filesystems.accounting_disk'));
            }

            $netForeignReceived = number_format((float) $amountForeignReceived - (float) ($supplierFee ?? 0), 4, '.', '');

            $transfer = SupplierTransfer::query()->create([
                'supplier_id' => $supplier->id,
                'source_channel' => $sourceChannel,
                'paid_by' => $paidBy?->value,
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
     * the supplier's own deposit fee at entry time. Never edits/deletes
     * the original `TOPUP` row (`SupplierLedgerEntry` enforces this at
     * the model layer) — always a new, signed `MANUAL_ADJUSTMENT` entry
     * referencing it, so the ledger's full history stays legible.
     * `$signedAmount` is in the transfer's own currency; `$reason` is
     * required.
     *
     * 2026-09-28 addendum: wrapped in its own transaction with
     * `lockForUpdate()` on the transfer row — cheap insurance against a
     * concurrent Adjust/Void race on the same transfer (realistically
     * rare, single-admin usage — not worth a full subprocess-concurrency
     * test the way `LedgerService::withdraw()` gets).
     */
    public function recordManualAdjustment(
        SupplierTransfer $transfer,
        string $signedAmount,
        string $reason,
        int $adminUserId,
    ): SupplierLedgerEntry {
        return DB::transaction(function () use ($transfer, $signedAmount, $reason, $adminUserId) {
            $locked = $this->lockNotVoided($transfer);

            return $this->writeLedgerCorrection($locked, SupplierLedgerEntryType::ManualAdjustment, $signedAmount, $reason, $adminUserId);
        });
    }

    /**
     * ADR-083 2026-09-15 addendum: the other correction shape — the
     * money behind this transfer never actually reached the supplier
     * at all. Marks the transfer `voided_at`/`void_reason` and writes a
     * full-reversal `VOID_REVERSAL` ledger entry (distinct type from a
     * partial `MANUAL_ADJUSTMENT`, since 2026-09-28) — the linked
     * ledger entry stays immutable, only the transfer itself (never
     * append-only) gains void metadata.
     *
     * 2026-09-28 addendum, real bug fix: previously reversed only the
     * transfer's *original* net (`netForeignReceived()`), never its
     * *current cumulative* net (original + every `MANUAL_ADJUSTMENT`
     * already posted against it) — an Adjust-then-Void sequence left a
     * permanent, silent residual in the supplier's ledger balance.
     * Fixed to reverse `netForeignReceived() + SUM(prior adjustments)`.
     * Verified against production before deciding a backfill was
     * unneeded: 2 voided transfers existed, neither had a prior
     * adjustment — the bug was real but never manifested, so this is a
     * forward-looking fix only. Same `lockForUpdate()` treatment as
     * `recordManualAdjustment()`, for the same reason.
     */
    public function voidTransfer(SupplierTransfer $transfer, string $reason, int $adminUserId): SupplierLedgerEntry
    {
        return DB::transaction(function () use ($transfer, $reason, $adminUserId) {
            $locked = $this->lockNotVoided($transfer);

            $priorAdjustments = (float) $locked->adjustments()->sum('amount');
            $cumulativeNet = (float) $locked->netForeignReceived() + $priorAdjustments;

            $reversal = $this->writeLedgerCorrection(
                $locked,
                SupplierLedgerEntryType::VoidReversal,
                number_format(-$cumulativeNet, 4, '.', ''),
                $reason,
                $adminUserId,
            );

            $locked->update([
                'voided_at' => now(),
                'void_reason' => $reason,
            ]);

            return $reversal;
        });
    }

    /**
     * 2026-09-29 audit M-10: the "already voided?" check used to run in
     * the controller, before the lock — a double-clicked Void could see
     * `voided_at = null` twice and write two `VOID_REVERSAL` entries.
     * Re-read under the lock, so the second caller sees the first's void.
     */
    private function lockNotVoided(SupplierTransfer $transfer): SupplierTransfer
    {
        $locked = SupplierTransfer::query()->whereKey($transfer->id)->lockForUpdate()->firstOrFail();

        if ($locked->voided_at !== null) {
            throw ValidationException::withMessages([
                'transfer' => ['This transfer has already been voided.'],
            ]);
        }

        return $locked;
    }

    /** Shared writer for both correction shapes — only the `type` differs, so the register (2026-09-28 addendum) can tell a partial correction from a full void reversal. */
    private function writeLedgerCorrection(
        SupplierTransfer $transfer,
        SupplierLedgerEntryType $type,
        string $signedAmount,
        string $reason,
        int $adminUserId,
    ): SupplierLedgerEntry {
        return SupplierLedgerEntry::query()->create([
            'supplier_id' => $transfer->supplier_id,
            'type' => $type->value,
            'amount' => $signedAmount,
            'currency' => $transfer->currency,
            'reference_type' => 'supplier_transfer',
            'reference_id' => $transfer->id,
            'created_by' => $adminUserId,
            'reason' => $reason,
        ]);
    }

    /**
     * ADR-083 2026-09-28 addendum: a metadata-only correction — never
     * touches the ledger. Only `source_channel`/`amount_myr_sent`/
     * `fee_myr`/`reference_no` (+ an optional replacement receipt) are
     * accepted; `amount_foreign_received`/`supplier_fee` are never even
     * read out of `$changes` here — those two, and only those two, feed
     * `netForeignReceived()` -> `SupplierLedgerEntry.amount` directly
     * and must stay Adjust/Void-only. Diff-computed against the current
     * row inside the lock; a no-op submit (nothing actually changed, no
     * new receipt) is rejected rather than writing an empty audit row.
     * A newly-uploaded receipt is only deleted-if-replaced (the old
     * file) AFTER the DB transaction commits — never inside it, so a
     * rolled-back write never leaves the real, still-referenced old
     * file deleted.
     */
    public function recordCorrection(
        SupplierTransfer $transfer,
        array $changes,
        string $reason,
        int $adminUserId,
        ?UploadedFile $receipt = null,
    ): SupplierTransferCorrection {
        $allowed = ['source_channel', 'paid_by', 'amount_myr_sent', 'fee_myr', 'reference_no'];
        $changes = array_intersect_key($changes, array_flip($allowed));

        $newReceiptPath = null;
        if ($receipt !== null) {
            $newReceiptPath = $receipt->store('accounting/supplier-transfers', config('filesystems.accounting_disk'));
        }

        $oldReceiptPathToDelete = null;

        try {
            $correction = DB::transaction(function () use ($transfer, $changes, $reason, $adminUserId, $newReceiptPath, &$oldReceiptPathToDelete) {
                $locked = $this->lockNotVoided($transfer);

                $diff = [];
                foreach ($changes as $field => $newValue) {
                    $oldValue = $locked->getAttribute($field);
                    // `paid_by` is cast to the PaidFrom enum — a BackedEnum
                    // has no __toString(), so comparing/diffing it directly
                    // against the request's raw string value throws. Every
                    // other correctable field here is a plain string, so
                    // this normalization is a no-op for them.
                    $oldValueForDiff = $oldValue instanceof \BackedEnum ? $oldValue->value : $oldValue;
                    if ((string) $oldValueForDiff !== (string) $newValue) {
                        $diff[$field] = [$oldValueForDiff, $newValue];
                    }
                }

                $updates = $changes;
                if ($newReceiptPath !== null) {
                    $diff['receipt_path'] = [$locked->receipt_path, $newReceiptPath];
                    $oldReceiptPathToDelete = $locked->receipt_path;
                    $updates['receipt_path'] = $newReceiptPath;
                }

                // 2026-10-01 fix: `effective_rate` is derived from
                // `amount_myr_sent` (the only side of the rate this
                // correction path can touch — `amount_foreign_received`/
                // `supplier_fee` stay Adjust/Void-only) but was never
                // recomputed here, leaving it silently stale after any
                // "Edit Details" correction to the sent amount. Found
                // live: two real corrections this session left the
                // Funding History page's own "Rate" column wrong, though
                // with zero effect on any real total —
                // `MonthlyAccountingSummaryService::weightedAverageRate()`
                // always recomputes fresh from `amount_myr_sent` directly,
                // never reads this stored column.
                if (array_key_exists('amount_myr_sent', $updates)) {
                    $newRate = $this->effectiveRate((int) $updates['amount_myr_sent'], $locked->netForeignReceived());
                    $diff['effective_rate'] = [$locked->effective_rate, $newRate];
                    $updates['effective_rate'] = $newRate;
                }

                if ($diff === []) {
                    throw ValidationException::withMessages([
                        'changes' => ['Nothing was actually changed.'],
                    ]);
                }

                $locked->update($updates);

                return SupplierTransferCorrection::query()->create([
                    'supplier_transfer_id' => $locked->id,
                    'changes' => $diff,
                    'reason' => $reason,
                    'admin_user_id' => $adminUserId,
                ]);
            });
        } catch (\Throwable $e) {
            // The write failed/rolled back — don't leave an orphan newly-uploaded receipt behind.
            if ($newReceiptPath !== null) {
                Storage::disk(config('filesystems.accounting_disk'))->delete($newReceiptPath);
            }

            throw $e;
        }

        if ($oldReceiptPathToDelete !== null) {
            Storage::disk(config('filesystems.accounting_disk'))->delete($oldReceiptPathToDelete);
        }

        return $correction;
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
     * ADR-083 2026-09-28 addendum: `$from`/`$to` scope on `created_at`,
     * `$voided` (`true` = voided only, `false` = active only, `null` =
     * both) — backs the new dedicated Funding History page's filters.
     * Eager-loads `corrections` alongside the existing `adjustments`.
     *
     * @return LengthAwarePaginator<int, SupplierTransfer>
     */
    public function transfers(
        Supplier $supplier,
        int $perPage = 20,
        ?CarbonInterface $from = null,
        ?CarbonInterface $toExclusive = null,
        ?bool $voided = null,
    ): LengthAwarePaginator {
        return $supplier->transfers()
            ->with(['adjustments', 'corrections'])
            ->when($from, fn ($q) => $q->where('created_at', '>=', $from))
            ->when($toExclusive, fn ($q) => $q->where('created_at', '<', $toExclusive))
            ->when($voided === true, fn ($q) => $q->whereNotNull('voided_at'))
            ->when($voided === false, fn ($q) => $q->whereNull('voided_at'))
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
