<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ADR-134 decision 15: a pooled transfer's stored rate includes its fee, as
 * the pool does, so the transfers page and the pool agree. Restates the
 * rows already recorded from the pool's opening (production: the two
 * October bank transfers). Display only: nothing reads this column for
 * money. Idempotent — a second run writes the same values.
 */
return new class extends Migration
{
    public function up(): void
    {
        $rows = DB::table('supplier_transfers')
            ->join('suppliers', 'suppliers.id', '=', 'supplier_transfers.supplier_id')
            ->where('supplier_transfers.transferred_on', '>=', '2026-10-01')
            ->where(fn ($q) => $q->where('suppliers.driver', '!=', 'manual')->orWhere('suppliers.funding_mode', '!=', 'pay_after')->orWhereNull('suppliers.funding_mode'))
            ->get(['supplier_transfers.id', 'amount_myr_sent', 'fee_myr', 'amount_foreign_received', 'supplier_fee']);

        foreach ($rows as $row) {
            $net = (float) $row->amount_foreign_received - (float) ($row->supplier_fee ?? 0);
            if ($net > 0) {
                DB::table('supplier_transfers')->where('id', $row->id)->update([
                    'effective_rate' => number_format((($row->amount_myr_sent + $row->fee_myr) / 100) / $net, 8, '.', ''),
                ]);
            }
        }
    }

    public function down(): void
    {
        // The fee-exclusive figure is recomputable from the same columns; nothing to undo.
    }
};
