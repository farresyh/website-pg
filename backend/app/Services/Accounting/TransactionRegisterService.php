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

/**
 * ADR-083 decision 9: the "nothing is ever lost" artifact the year-end
 * professional works from — one row per money-moving event across four
 * otherwise-separate sources (paid orders, supplier funding transfers,
 * supplier REFUND entries, issued vouchers). Read-only: this never writes
 * anything, only projects existing rows into one flat, exportable shape.
 *
 * A single flat schema can't carry every source's native columns
 * (an order's gross/fee/cost/net is MYR sen; a transfer's amount is a
 * foreign-currency decimal) — every row carries every column, unused
 * ones left null, rather than forcing a lossy common unit.
 */
final class TransactionRegisterService
{
    /**
     * @return list<array{date: string, type: string, reference: string, description: string, supplier: ?string, currency: string, gross_sen: ?int, fee_sen: ?int, cost_sen: ?int, net_sen: ?int, amount_foreign: ?string}>
     */
    public function rows(?CarbonInterface $from, ?CarbonInterface $to): array
    {
        $rows = [
            ...$this->orderRows($from, $to),
            ...$this->supplierTransferRows($from, $to),
            ...$this->supplierRefundRows($from, $to),
            ...$this->voucherRows($from, $to),
        ];

        usort($rows, fn (array $a, array $b) => $b['date'] <=> $a['date']);

        return $rows;
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
            ])
            ->all();
    }

    /** @return list<array<string, mixed>> */
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
            ])
            ->all();
    }
}
