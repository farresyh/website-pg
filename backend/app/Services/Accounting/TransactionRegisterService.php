<?php

namespace App\Services\Accounting;

use App\Models\Affiliate;
use App\Models\LedgerEntry;
use App\Models\MembershipCheckoutAttempt;
use App\Models\MembershipFeeRecord;
use App\Models\Order;
use App\Models\SupplierLedgerEntry;
use App\Models\SupplierTransfer;
use App\Models\Voucher;
use App\Models\WalletTopupAttempt;
use App\Models\Withdrawal;
use App\Services\Ledger\LedgerOwnerType;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\PaymentStatus;
use App\Services\Reseller\WalletTopupAttemptStatus;
use App\Services\Withdrawal\WithdrawalStatus;
use Carbon\CarbonInterface;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * ADR-083 decision 9 (+ 2026-09-28 addenda): the "nothing is ever lost"
 * artifact the year-end professional works from — one row per
 * money-moving event across eight otherwise-separate sources (paid
 * orders, supplier funding transfers, supplier manual corrections,
 * supplier REFUND entries, issued vouchers, membership fee payments,
 * reseller wallet top-ups, and completed withdrawal payouts). Read-only:
 * this never writes anything, only projects existing rows into one flat,
 * exportable shape.
 *
 * The last three sources were a real gap, not a deliberate omission:
 * membership/wallet-topup cash inflows and affiliate withdrawal payouts
 * are real money moving through the company bank account, but had no
 * row here at all — only aggregated in the Monthly Summary (membership)
 * or matched per-transaction in CHIP Settlements (membership + wallet
 * top-up), never listed in the one screen meant to be a complete,
 * downloadable record for the year-end professional. Found during a
 * founder walkthrough of exactly this question ("if an auditor asks why
 * a reseller paid us RM300, what does the register show?").
 *
 * A single flat schema can't carry every source's native columns
 * (an order's gross/fee/cost/net is MYR sen; a transfer's amount is a
 * foreign-currency decimal) — every row carries every column, unused
 * ones left null, rather than forcing a lossy common unit.
 */
final class TransactionRegisterService
{
    /**
     * @return list<array{date: string, type: string, reference: string, description: string, supplier: ?string, currency: string, gross_sen: ?int, fee_sen: ?int, cost_sen: ?int, net_sen: ?int, amount_foreign: ?string, status: string, funding_source: ?string}>
     */
    public function rows(?CarbonInterface $from, ?CarbonInterface $toExclusive, ?string $type = null): array
    {
        $rows = [
            ...$this->orderRows($from, $toExclusive),
            ...$this->supplierTransferRows($from, $toExclusive),
            ...$this->supplierAdjustmentRows($from, $toExclusive),
            ...$this->supplierRefundRows($from, $toExclusive),
            ...$this->voucherRows($from, $toExclusive),
            ...$this->membershipRows($from, $toExclusive),
            ...$this->walletTopupRows($from, $toExclusive),
            ...$this->walletRefundRows($from, $toExclusive),
            ...$this->withdrawalRows($from, $toExclusive),
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
    public function paginate(?CarbonInterface $from, ?CarbonInterface $toExclusive, ?string $type, int $page, int $perPage = 20): LengthAwarePaginator
    {
        $all = $this->rows($from, $toExclusive, $type);

        return new LengthAwarePaginator(
            array_slice($all, ($page - 1) * $perPage, $perPage),
            count($all),
            $perPage,
            $page,
        );
    }

    /** @return list<array<string, mixed>> */
    private function orderRows(?CarbonInterface $from, ?CarbonInterface $toExclusive): array
    {
        $orders = Order::query()
            ->with('supplier')
            ->where('is_test', false)
            ->where('payment_status', PaymentStatus::Paid->value)
            ->when($from, fn ($q) => $q->where('paid_at', '>=', $from))
            ->when($toExclusive, fn ($q) => $q->where('paid_at', '<', $toExclusive))
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
                // 2026-09-30 audit fix: `cost_price` is a checkout-time
                // catalog snapshot, not proof the supplier was actually
                // charged — `SupplierFundingService::recordOrderDrawdown()`
                // only ever runs on a *successful* delivery
                // (`OrderFulfillmentService::fulfill()`'s success branch).
                // A paid-but-undelivered/failed order never drew down real
                // supplier cost, so showing `cost_price` here would invent
                // a COGS figure that was never actually spent — the same
                // `delivery_status = Delivered` gate
                // `MonthlyAccountingSummaryService::cogs()` already uses.
                // ADR-094 decision 41: a partial delivery drew down only its delivered legs.
                'cost_sen' => match ($order->delivery_status) {
                    DeliveryStatus::Delivered => $order->cost_price,
                    DeliveryStatus::PartiallyDelivered => $order->effectiveCostPriceSen(),
                    default => null,
                },
                'net_sen' => (int) ($realizedProfitByOrderId[$order->id] ?? 0),
                'amount_foreign' => null,
                'status' => 'active',
                // 2026-09-30 audit fix: a reseller wallet-paid order's
                // `gross_sen` is spend from an *already-collected* wallet
                // balance, not a fresh bank inflow the way a CHIP-paid
                // order's is — summing the Gross column across both kinds
                // double-counts the same cash. This surfaces the
                // distinction as its own machine-readable field (mirrors
                // `status` already being a column, not text baked into
                // `description`) rather than a free-text label.
                'funding_source' => $order->wallet_reseller_id !== null ? 'reseller_wallet' : 'chip',
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
    private function supplierTransferRows(?CarbonInterface $from, ?CarbonInterface $toExclusive): array
    {
        return SupplierTransfer::query()
            ->with('supplier')
            ->when($from, fn ($q) => $q->where('created_at', '>=', $from))
            ->when($toExclusive, fn ($q) => $q->where('created_at', '<', $toExclusive))
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
                'funding_source' => null,
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
    private function supplierAdjustmentRows(?CarbonInterface $from, ?CarbonInterface $toExclusive): array
    {
        return SupplierLedgerEntry::query()
            ->with('supplier')
            ->whereIn('type', [SupplierLedgerEntryType::ManualAdjustment->value, SupplierLedgerEntryType::VoidReversal->value])
            ->where('reference_type', 'supplier_transfer')
            ->when($from, fn ($q) => $q->where('created_at', '>=', $from))
            ->when($toExclusive, fn ($q) => $q->where('created_at', '<', $toExclusive))
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
                'funding_source' => null,
            ])
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function supplierRefundRows(?CarbonInterface $from, ?CarbonInterface $toExclusive): array
    {
        return SupplierLedgerEntry::query()
            ->with('supplier')
            ->where('type', SupplierLedgerEntryType::Refund->value)
            ->when($from, fn ($q) => $q->where('created_at', '>=', $from))
            ->when($toExclusive, fn ($q) => $q->where('created_at', '<', $toExclusive))
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
                'funding_source' => null,
            ])
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function voucherRows(?CarbonInterface $from, ?CarbonInterface $toExclusive): array
    {
        return Voucher::query()
            // ADR-004 Path B only — a compensation voucher IS a
            // money-moving liability event; a standalone admin-issued
            // (Path A) voucher is a business decision, not a
            // checkout-generated one.
            ->compensation()
            ->when($from, fn ($q) => $q->where('created_at', '>=', $from))
            ->when($toExclusive, fn ($q) => $q->where('created_at', '<', $toExclusive))
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
                'funding_source' => null,
            ])
            ->all();
    }

    /**
     * 2026-09-28 register-completeness addendum: a real customer-membership
     * fee payment (`MembershipFeeRecord`, ADR-027) — the Monthly Summary
     * already sums these into "Membership revenue" (decision 8), but no
     * row for one ever existed here.
     *
     * 2026-09-30 audit fix: `net_sen` used to just equal `gross_sen`
     * (`amount_sen` — the plan price, e.g. RM19.90) because no fee was
     * tracked here at all. The real CHIP transaction fee (e.g. RM1.00,
     * making the customer's actual charge RM20.90) already exists on
     * `MembershipCheckoutAttempt.total_charged_sen`/`fee_sen` (confusingly
     * named — `fee_sen` there is the *plan* fee, not CHIP's fee; the real
     * CHIP fee is `total_charged_sen - fee_sen`, exactly the computation
     * `SettlementReconciliationService::resolveMatch()` already does).
     * `MembershipFeeService::recordFeePaid()` writes the attempt's own
     * `subscription_number` into `MembershipFeeRecord.idempotency_key`
     * (see `MembershipSubscriptionService::completePaidAttempt()`) — the
     * reliable join key between the two tables. An admin-issued/free fee
     * record (no real CHIP checkout, e.g. a comped membership) has no
     * matching attempt — `gross_sen`/`fee_sen` fall back to the
     * fee-less shape rather than guessing.
     *
     * @return list<array<string, mixed>>
     */
    private function membershipRows(?CarbonInterface $from, ?CarbonInterface $toExclusive): array
    {
        $records = MembershipFeeRecord::query()
            ->with(['membership', 'membershipPlan'])
            ->when($from, fn ($q) => $q->where('created_at', '>=', $from))
            ->when($toExclusive, fn ($q) => $q->where('created_at', '<', $toExclusive))
            ->get();

        $attemptsBySubscriptionNumber = MembershipCheckoutAttempt::query()
            ->whereIn('subscription_number', $records->pluck('idempotency_key')->filter()->unique())
            ->get(['subscription_number', 'total_charged_sen', 'fee_sen'])
            ->keyBy('subscription_number');

        return $records
            ->map(function (MembershipFeeRecord $record) use ($attemptsBySubscriptionNumber) {
                $attempt = $attemptsBySubscriptionNumber->get($record->idempotency_key);
                $grossSen = $attempt?->total_charged_sen ?? $record->amount_sen;
                $chipFeeSen = $attempt !== null ? $attempt->total_charged_sen - $attempt->fee_sen : null;

                return [
                    'date' => $record->created_at->toIso8601String(),
                    'type' => 'membership_payment',
                    'reference' => "Membership Fee #{$record->id}",
                    'description' => 'Membership fee — '.($record->membership?->email ?? 'unknown member').' ('.($record->membershipPlan?->name ?? 'unknown plan').')'.($record->reason ? " — {$record->reason}" : ''),
                    'supplier' => null,
                    'currency' => 'MYR',
                    'gross_sen' => $grossSen,
                    'fee_sen' => $chipFeeSen,
                    'cost_sen' => null,
                    'net_sen' => $record->amount_sen,
                    'amount_foreign' => null,
                    'status' => 'active',
                    'funding_source' => null,
                ];
            })
            ->all();
    }

    /**
     * 2026-09-28 register-completeness addendum: a completed reseller
     * wallet top-up (`WalletTopupAttempt`, ADR-073) — real cash into the
     * company bank account via CHIP, but a **liability** (usable reseller
     * spend credit), never sales revenue. CHIP Settlements already
     * matches these per-transaction; this is the first time one gets a
     * row in the "nothing is ever lost" register itself. Only `Paid`
     * attempts represent real money — a `pending`/`failed`/`expired`
     * attempt never touched the bank account.
     *
     * @return list<array<string, mixed>>
     */
    private function walletTopupRows(?CarbonInterface $from, ?CarbonInterface $toExclusive): array
    {
        return WalletTopupAttempt::query()
            ->with('reseller')
            ->where('status', WalletTopupAttemptStatus::Paid->value)
            ->when($from, fn ($q) => $q->where('created_at', '>=', $from))
            ->when($toExclusive, fn ($q) => $q->where('created_at', '<', $toExclusive))
            ->get()
            ->map(fn (WalletTopupAttempt $topup) => [
                'date' => $topup->created_at->toIso8601String(),
                'type' => 'reseller_wallet_topup',
                'reference' => $topup->reference,
                'description' => 'Reseller wallet top-up — '.($topup->reseller?->business_name ?? 'unknown reseller'),
                'supplier' => null,
                'currency' => 'MYR',
                'gross_sen' => $topup->total_charged_sen,
                'fee_sen' => $topup->total_charged_sen - $topup->amount_sen,
                'cost_sen' => null,
                'net_sen' => $topup->amount_sen,
                'amount_foreign' => null,
                'status' => 'active',
                // `funding_source` exists to flag which *orders* were
                // paid from an already-collected wallet balance (the
                // double-count risk) — this row's own `type` already
                // says "reseller_wallet_topup" unambiguously, so it adds
                // nothing here.
                'funding_source' => null,
            ])
            ->all();
    }

    /**
     * 2026-09-30 audit fix: a failed order that already drew from a
     * reseller's wallet gets refunded via a `wallet_refund` ledger entry
     * (`Order::isAlreadyRefundedToWallet()`, ADR-073) — real money moving
     * back into the reseller's usable balance. `orderRows()` deliberately
     * keeps the original order's `gross_sen`/`cost_sen` untouched (the
     * sale/attempt genuinely happened, same "never rewrite history in
     * place" discipline `supplierTransferRows()` already follows for a
     * voided transfer) — this is the row that makes the refund itself
     * visible, mirroring `supplierRefundRows()`'s own shape. Before this,
     * a failed wallet-paid order looked exactly like a normal completed
     * sale here, with no trace of the money going back.
     *
     * @return list<array<string, mixed>>
     */
    private function walletRefundRows(?CarbonInterface $from, ?CarbonInterface $toExclusive): array
    {
        $entries = LedgerEntry::query()
            ->where('owner_type', LedgerOwnerType::ResellerWallet->value)
            ->where('type', 'wallet_refund')
            ->where('reference_type', 'order')
            ->when($from, fn ($q) => $q->where('created_at', '>=', $from))
            ->when($toExclusive, fn ($q) => $q->where('created_at', '<', $toExclusive))
            ->get();

        $orderNumbersById = Order::query()
            ->whereIn('id', $entries->pluck('reference_id')->unique())
            ->pluck('order_number', 'id');

        return $entries
            ->map(fn (LedgerEntry $entry) => [
                'date' => $entry->created_at->toIso8601String(),
                'type' => 'reseller_wallet_refund',
                'reference' => 'Ledger entry #'.$entry->id,
                'description' => 'Reseller wallet refund — Order '.($orderNumbersById[$entry->reference_id] ?? "#{$entry->reference_id}"),
                'supplier' => null,
                'currency' => 'MYR',
                'gross_sen' => null,
                'fee_sen' => null,
                'cost_sen' => null,
                'net_sen' => $entry->amount,
                'amount_foreign' => null,
                'status' => 'active',
                'funding_source' => null,
            ])
            ->all();
    }

    /**
     * 2026-09-28 register-completeness addendum: a completed withdrawal
     * payout (`Withdrawal`) — real cash leaving the company bank account
     * to an affiliate. Only `Completed` (money actually sent, `processed_at`
     * set) counts; `Pending`/`Approved`/`Rejected` never moved real money.
     * Dated at `processed_at` (when the payout actually happened), not
     * `created_at` (when it was merely requested) — same "date it by the
     * real cash event" convention `orderRows()` uses (`paid_at`, not
     * `created_at`). Affiliate names are batch-fetched to avoid an N+1,
     * matching `orderRows()`'s own realized-profit batching.
     *
     * @return list<array<string, mixed>>
     */
    private function withdrawalRows(?CarbonInterface $from, ?CarbonInterface $toExclusive): array
    {
        $withdrawals = Withdrawal::query()
            ->where('status', WithdrawalStatus::Completed->value)
            ->whereNotNull('processed_at')
            ->when($from, fn ($q) => $q->where('processed_at', '>=', $from))
            ->when($toExclusive, fn ($q) => $q->where('processed_at', '<', $toExclusive))
            ->get();

        $affiliateNamesById = Affiliate::query()
            ->whereIn('id', $withdrawals->where('owner_type', LedgerOwnerType::Affiliate->value)->pluck('owner_id')->unique())
            ->pluck('business_name', 'id');

        return $withdrawals
            ->map(fn (Withdrawal $withdrawal) => [
                'date' => $withdrawal->processed_at->toIso8601String(),
                'type' => 'withdrawal_payout',
                'reference' => "Withdrawal #{$withdrawal->id}",
                'description' => 'Withdrawal payout — '.($withdrawal->owner_type === LedgerOwnerType::Affiliate->value
                    ? ($affiliateNamesById[$withdrawal->owner_id] ?? 'unknown affiliate')
                    : $withdrawal->owner_type),
                'supplier' => null,
                'currency' => 'MYR',
                'gross_sen' => null,
                'fee_sen' => null,
                'cost_sen' => null,
                'net_sen' => -$withdrawal->amount,
                'amount_foreign' => null,
                'status' => 'active',
                'funding_source' => null,
            ])
            ->all();
    }
}
