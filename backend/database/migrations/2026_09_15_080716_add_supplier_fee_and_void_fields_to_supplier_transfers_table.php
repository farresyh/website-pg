<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ADR-083 2026-09-15 addendum, found against a real transfer
     * (founder's own Wise receipt): `amount_foreign_received` was
     * always being read as the literal "total to supplier" figure
     * printed on the receipt (gross — what Wise/Airwallex delivered to
     * the supplier's own bank account), but the supplier then deducts
     * its OWN deposit-side fee (e.g. Digiflazz: 15,000 IDR) before
     * crediting the wallet — a real fee category with no field at all
     * until now, silently overstating the funding ledger by exactly
     * that amount on every real transfer.
     *
     * `supplier_fee` — the supplier's own cut, in the transfer's own
     * `currency` (same decimal shape as `amount_foreign_received`),
     * optional (not every channel/supplier has one). The ledger's
     * TOPUP amount becomes `amount_foreign_received - supplier_fee`
     * (net, the real wallet credit); `amount_foreign_received` itself
     * stays the gross, receipt-verifiable figure — no schema/meaning
     * change to any existing row.
     *
     * `voided_at`/`void_reason` — the other half of this addendum: a
     * transfer whose money never actually reached the supplier at all
     * (a genuinely failed send, not a typo) gets marked here so the
     * transfer history is never confused with a real successful one,
     * alongside a `MANUAL_ADJUSTMENT` ledger entry (SupplierFundingService::
     * voidTransfer()) that fully reverses its ledger contribution.
     * `SupplierTransfer` was always documented as "not append-only
     * itself" (its own model doc comment) for exactly this kind of
     * correction — the linked ledger entry is what stays immutable.
     */
    public function up(): void
    {
        Schema::table('supplier_transfers', function (Blueprint $table) {
            $table->decimal('supplier_fee', 18, 4)->nullable()->after('amount_foreign_received');
            $table->timestamp('voided_at')->nullable()->after('reference_no');
            $table->text('void_reason')->nullable()->after('voided_at');
        });
    }

    public function down(): void
    {
        Schema::table('supplier_transfers', function (Blueprint $table) {
            $table->dropColumn(['supplier_fee', 'voided_at', 'void_reason']);
        });
    }
};
