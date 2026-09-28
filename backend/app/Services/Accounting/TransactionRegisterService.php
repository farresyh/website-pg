<?php

namespace App\Services\Accounting;

use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\SupplierLedgerEntry;
use App\Models\SupplierTransfer;
use App\Models\Voucher;
use App\Services\Ledger\LedgerOwnerType;
use App\Services\Order\PaymentStatus;
use Carbon\CarbonInterface;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * ADR-083 decision 9 (+ 2026-09-28 addendum): the "nothing is ever lost"
 * artifact the year-end professional works from — one row per
 * money-moving event across five otherwise-separate sources (paid
 * orders, supplier funding transfers, supplier manual corrections,
 * supplier REFUND entries, issued vouchers). Read-only: this never
 * writes anything, only projects existing rows into one flat,
 * exportable shape.
 *
 * A single flat schema can't carry every source's native columns
 * (an order's gross/fee/cost/net is MYR sen; a transfer's amount is a
 * foreign-currency decimal) — every row carries every column, unused
 * ones left null, rather than forcing a lossy common unit.
 */
final class TransactionRegisterService
{
    /**
     * @return list<array{date: string, type: string, reference: string, description: string, supplier: ?string, currency: string, gross_sen: ?int, fee_sen: ?int, cost_sen: ?int, net_sen: ?int, amount_foreign: ?string, status: string}>
     */
    public function rows(?CarbonInterface $from, ?CarbonInterface $to, ?string $type = null): array
    {
        $rows = [
            ...$this->orderRows($from, $to),
            ...$this->supplierTransferRows($from, $to),
            ...$this->supplierAdjustmentRows($from, $to),
            ...$this->supplierRefundRows($from, $to),
            ...$this->voucherRows($from, $to),
        ];

        usort($rows, fn (array $a, array $b) => $b['date'] <=> $a['date']);

        if ($type !== null) {
            $rows = array_values(array_filter($rows, fn (array $row) => $row['type'] === $type));
        }

        return $rows;
    }

    /**
     * ADR-083 2026-09-28 addendum: real backend pagination for the
     * admin screen (the CSV export keeps using the unpaginated `rows()`
     * above — a year-end export must cover the whole filtered range,
     * never just the page on screen).
     *
     * `ponytail`: this pages an already-fully-built, already-sorted PHP
     * array (`array_slice`), not a SQL-level `UNION` across the five
     * sources — verified fine against real production volume before
     * shipping (27 paid orders + 5 supplier transfers + 0 refunds + 4
     * Path-B vouchers ≈ 36 total rows as of 2026-09-28). Revisit with a
     * real `UNION` query once paid-order volume approaches ~10k rows —
     * don't just trust this note indefinitely, re-verify the row count
     * first.
     *
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function paginate(?CarbonInterface $from, ?CarbonInterface $to, ?string $type, int $page, int $perPage = 20): LengthAwarePaginator
    {
        $all = $this->rows($from, $to, $type);

        return new LengthAwarePaginator(
            array_slice($all, ($page - 1) * $perPage, $perPage),
            count($all),
            $perPage,
            $page,
        );
    }

    /** @return list<array<string, mixed>> */
    private function orderRows(?CarbonInterface $from, ?CarbonInterface $to): array
    {
        $orders = Order::query()
            ->with('supplier')
            ->where('is_test', false)
            ->where('payment_status', PaymentStatus::Paid->value)
            ->when($from, fn ($q) => $q->where('paid_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('paid_at', '<=', $to))
            ->get();

        // `Order.platform_profit` is stamped at checkout time, before the
        // delivery outcome is known — reading it directly here would be
        // the exact anti-pattern ReportService::profitTotals() and
        // DashboardService::summary() both exist to avoid (a
        // paid-but-undelivered — or never-delivered — order would show a
        // "net" it never actually earned). `ledger_entries` (type
        // order_profit, credited only on delivery, ADR-024) is the real
        // source of truth; batch-fetched here rather than N+1 queries.
        $realizedProfitByOrderId = LedgerEntry::query()
            ->where('type', 'order_profit')
            ->where('owner_type', LedgerOwnerType::Platform->value)
            ->where('reference_type', 'order')
            ->whereIn('reference_id', $orders->pluck('id'))
            ->pluck('amount', 'reference_id');

        return $orders
            ->map(fn (Order $order) => [
                'date' => $order->paid_at?->toIso8601String() ?? $order->created_at->toIso8601String(),
                'type' => 'order',
                'reference' => $order->order_number,
                'description' => 'Order '.$order->order_number,
                'supplier' => $order->supplier?->name,
                'currency' => 'MYR',
                'gross_sen' => $order->selling_price,
                'fee_sen' => $order->transaction_fee,
                'cost_sen' => $order->cost_price,
                'net_sen' => (int) ($realizedProfitByOrderId[$order->id] ?? 0),
                'amount_foreign' => null,
                'status' => 'active',
            ])
            ->all();
    }

    /**
     * ADR-083 2026-09-28 addendum: a voided transfer's own row **keeps
     * its original recorded figures** (`net_sen` computed exactly as
     * before, never zeroed/blanked) — only `status` changes to
     * `'voided'`. Real double-entry practice never rewrites a historical
     * entry in place; the actual cancellation is the `VOID_REVERSAL`
     * ledger entry, which shows up as its own row via
     * `supplierAdjustmentRows()` below, dated when the void happened.
     * Zeroing the original here would erase the fact that RM X was
     * once genuinely recorded as moving.
     *
     * @return list<array<string, mixed>>
     */
    private function supplierTransferRows(?CarbonInterface $from, ?CarbonInterface $to): array
    {
        return SupplierTransfer::query()
            ->with('supplier')
            ->when($from, fn ($q) => $q->where('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('created_at', '<=', $to))
            ->get()
            ->map(fn (SupplierTransfer $transfer) => [
                'date' => $transfer->created_at->toIso8601String(),
                'type' => 'supplier_transfer',
                'reference' => $transfer->reference_no ?? "Transfer #{$transfer->id}",
                'description' => 'Supplier top-up — '.($transfer->supplier?->name ?? 'unknown supplier'),
                'supplier' => $transfer->supplier?->name,
                'currency' => $transfer->currency,
                'gross_sen' => null,
                'fee_sen' => $transfer->fee_myr,
                'cost_sen' => null,
                'net_sen' => -($transfer->amount_myr_sent + $transfer->fee_myr),
                'amount_foreign' => $transfer->amount_foreign_received,
                'status' => $transfer->voided_at !== null ? 'voided' : 'active',
            ])
            ->all();
    }

    /**
     * ADR-083 2026-09-28 addendum: every `MANUAL_ADJUSTMENT`/
     * `VOID_REVERSAL` ledger entry against a transfer becomes its own
     * register row, dated at the entry's **own** `created_at` — not the
     * original transfer's date. A correction made this month against a
     * three-month-old transfer belongs in this month's range, not
     * silently attributed to the original transfer's period. This is
     * the fix for decision 9's "nothing is ever lost" premise not
     * previously holding for the one row-type that actually gets
     * corrected after the fact.
     *
     * `net_sen` stays null — this is an FX-side ledger correction, never
     * a new RM outflow, so inventing an RM figure here would be wrong.
     *
     * @return list<array<string, mixed>>
     */
    private function supplierAdjustmentRows(?CarbonInterface $from, ?CarbonInterface $to): array
    {
        return SupplierLedgerEntry::query()
            ->with('supplier')
            ->whereIn('type', [SupplierLedgerEntryType::ManualAdjustment->value, SupplierLedgerEntryType::VoidReversal->value])
            ->where('reference_type', 'supplier_transfer')
            ->when($from, fn ($q) => $q->where('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('created_at', '<=', $to))
            ->get()
            ->map(fn (SupplierLedgerEntry $entry) => [
                'date' => $entry->created_at->toIso8601String(),
                'type' => 'supplier_adjustment',
                'reference' => "Adjustment #{$entry->id} (Transfer #{$entry->reference_id})",
                'description' => ($entry->type === SupplierLedgerEntryType::VoidReversal->value ? 'Void reversal' : 'Manual adjustment').' — '.$entry->reason,
                'supplier' => $entry->supplier?->name,
                'currency' => $entry->currency,
                'gross_sen' => null,
                'fee_sen' => null,
                'cost_sen' => null,
                'net_sen' => null,
                'amount_foreign' => (string) $entry->amount,
                'status' => 'active',
            ])
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function supplierRefundRows(?CarbonInterface $from, ?CarbonInterface $to): array
    {
        return SupplierLedgerEntry::query()
            ->with('supplier')
            ->where('type', SupplierLedgerEntryType::Refund->value)
            ->when($from, fn ($q) => $q->where('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('created_at', '<=', $to))
            ->get()
            ->map(fn (SupplierLedgerEntry $entry) => [
                'date' => $entry->created_at->toIso8601String(),
                'type' => 'supplier_refund',
                'reference' => "Ledger entry #{$entry->id}",
                'description' => 'Supplier refund — '.($entry->supplier?->name ?? 'unknown supplier').($entry->reason ? " ({$entry->reason})" : ''),
                'supplier' => $entry->supplier?->name,
                'currency' => $entry->currency,
                'gross_sen' => null,
                'fee_sen' => null,
                'cost_sen' => null,
                'net_sen' => null,
                'amount_foreign' => $entry->amount,
                'status' => 'active',
            ])
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function voucherRows(?CarbonInterface $from, ?CarbonInterface $to): array
    {
        return Voucher::query()
            // ADR-004 Path B only — a compensation voucher IS a
            // money-moving liability event; a standalone admin-issued
            // (Path A) voucher is a business decision, not a
            // checkout-generated one. Exact same scope as
            // DashboardService::vouchersIssued(), is_test exclusion
            // included.
            ->whereNotNull('order_id')
            ->whereHas('sourceOrder', fn ($q) => $q->where('is_test', false))
            ->when($from, fn ($q) => $q->where('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('created_at', '<=', $to))
            ->get()
            ->map(fn (Voucher $voucher) => [
                'date' => $voucher->created_at->toIso8601String(),
                'type' => 'voucher_issued',
                'reference' => $voucher->code,
                'description' => 'Voucher issued'.($voucher->reason ? " — {$voucher->reason}" : ''),
                'supplier' => null,
                'currency' => 'MYR',
                'gross_sen' => null,
                'fee_sen' => null,
                'cost_sen' => null,
                'net_sen' => -$voucher->amount,
                'amount_foreign' => null,
                'status' => 'active',
            ])
            ->all();
    }
}
