<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * §16 item 79 (ADR-024 2026-10-12 addendum). Before the fix, issue() wrote a
 * platform `voucher_issued` debit for a compensation voucher too (one issued
 * for an order: the customer's own cash already held, not a platform cost).
 *
 * The ledger is append-only (ADR-002), so each such debit gets one reasoned
 * reversal of the same type for the opposite amount — the ADR-105 order-15
 * correction convention. The amount comes from the debit row itself.
 * `idempotency_key` is unique, so a reversal can exist at most once per
 * voucher, and a second run writes nothing. Admin-issued vouchers (no order)
 * and zero-amount debits are left alone.
 *
 * Query builder, not LedgerService: a migration must not depend on app code
 * that may change after it has run.
 */
return new class extends Migration
{
    public function up(): void
    {
        $debits = DB::table('ledger_entries')
            ->join('vouchers', 'vouchers.id', '=', 'ledger_entries.reference_id')
            ->where('ledger_entries.owner_type', 'platform')
            ->where('ledger_entries.type', 'voucher_issued')
            ->where('ledger_entries.reference_type', 'voucher')
            ->whereNull('ledger_entries.reason')
            ->where('ledger_entries.amount', '<', 0)
            ->whereNotNull('vouchers.order_id')
            ->get(['ledger_entries.reference_id', 'ledger_entries.amount']);

        foreach ($debits as $debit) {
            $key = "item79-reversal:voucher:{$debit->reference_id}";

            if (DB::table('ledger_entries')->where('idempotency_key', $key)->exists()) {
                continue;
            }

            DB::table('ledger_entries')->insert([
                'owner_type' => 'platform',
                'owner_id' => null,
                'type' => 'voucher_issued',
                'amount' => -(int) $debit->amount,
                'reference_type' => 'voucher',
                'reference_id' => $debit->reference_id,
                'created_by' => null,
                'reason' => 'Reversal: a compensation voucher is the customer\'s own cash, not a platform cost (PRD §16 item 79, ADR-024 2026-10-12 addendum)',
                'idempotency_key' => $key,
                'created_at' => now(),
            ]);
        }
    }

    /** A ledger correction: append-only, never removed. */
    public function down(): void {}
};
