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
 * the reseller wallet ledger. `recordTransfer()` is currently the only
 * writer; `ORDER_DRAWDOWN`/`REFUND` capture (from the Digiflazz webhook
 * and Gamevion response path) lands here too once built.
 */
final class SupplierFundingService
{
    /**
     * Stores the optional receipt first (if any), then the transfer row
     * and its `TOPUP` ledger entry — one transaction, so a failed insert
     * never leaves an orphan receipt file referenced by nothing, and the
     * ledger entry always has a `supplier_transfers` row to point back to.
     *
     * `effective_rate` (MYR per 1 unit of `currency`) is derived here from
     * the two actual amounts on the receipt — never looked up from a live
     * FX rate, per ADR-083 decision 2.
     */
    public function recordTransfer(
        Supplier $supplier,
        string $sourceChannel,
        int $amountMyrSent,
        int $feeMyr,
        string $currency,
        string $amountForeignReceived,
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
            $referenceNo,
            $receipt,
            $adminUserId,
        ) {
            $receiptPath = null;

            if ($receipt !== null) {
                $receiptPath = $receipt->store('accounting/supplier-transfers', config('filesystems.accounting_disk'));
            }

            $transfer = SupplierTransfer::query()->create([
                'supplier_id' => $supplier->id,
                'source_channel' => $sourceChannel,
                'amount_myr_sent' => $amountMyrSent,
                'fee_myr' => $feeMyr,
                'currency' => $currency,
                'amount_foreign_received' => $amountForeignReceived,
                'effective_rate' => $this->effectiveRate($amountMyrSent, $amountForeignReceived),
                'receipt_path' => $receiptPath,
                'reference_no' => $referenceNo,
                'created_by' => $adminUserId,
            ]);

            SupplierLedgerEntry::query()->create([
                'supplier_id' => $supplier->id,
                'type' => SupplierLedgerEntryType::Topup->value,
                'amount' => $amountForeignReceived,
                'currency' => $currency,
                'reference_type' => 'supplier_transfer',
                'reference_id' => $transfer->id,
                'created_by' => $adminUserId,
            ]);

            return $transfer;
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
     * IDR 3,700,000 received -> ~0.00027027 MYR per IDR. Null when the
     * foreign amount is zero — never divide by zero for a malformed row.
     */
    private function effectiveRate(int $amountMyrSent, string $amountForeignReceived): ?string
    {
        $foreign = (float) $amountForeignReceived;

        if ($foreign <= 0.0) {
            return null;
        }

        return number_format(($amountMyrSent / 100) / $foreign, 8, '.', '');
    }
}
