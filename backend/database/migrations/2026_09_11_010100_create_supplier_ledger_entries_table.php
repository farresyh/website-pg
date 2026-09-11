<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ADR-083 decision 2: the supplier funding ledger — a mirror of
     * `ledger_entries` (ADR-002) but deliberately separate, since this one
     * tracks each supplier's prepaid balance in *its own* currency
     * (`amount`/`currency` here, never MYR sen) rather than platform
     * profit. Append-only: no `UPDATE`/`DELETE` from application code,
     * enforced at the model layer (`SupplierLedgerEntry::booted()`), not
     * just by convention — stricter than `ledger_entries`, which is
     * append-only by discipline only.
     *
     * `type` is one of TOPUP (+) / ORDER_DRAWDOWN (-) / REFUND (+) /
     * MANUAL_ADJUSTMENT (+/-) — see
     * `App\Services\Accounting\SupplierLedgerEntryType`. `amount` is
     * signed, matching `ledger_entries.amount`'s convention. `reference_type`
     * / `reference_id` is the same loosely-typed pair every other ledger
     * table already uses (e.g. `reference_type = 'supplier_transfer'` for
     * a TOPUP, `'order'` for an ORDER_DRAWDOWN/REFUND) — not a real
     * Eloquent polymorphic relation, just a label + id.
     */
    public function up(): void
    {
        Schema::create('supplier_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->string('type'); // TOPUP | ORDER_DRAWDOWN | REFUND | MANUAL_ADJUSTMENT
            $table->decimal('amount', 18, 4); // signed, in `currency` below — never MYR sen
            $table->string('currency', 3);
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->text('reason')->nullable(); // required by app logic for MANUAL_ADJUSTMENT
            $table->timestamp('created_at')->useCurrent();

            $table->index('supplier_id');
            $table->index(['reference_type', 'reference_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_ledger_entries');
    }
};
