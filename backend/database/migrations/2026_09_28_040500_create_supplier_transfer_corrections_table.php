<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ADR-083 2026-09-28 addendum: the audit trail for a **metadata-only**
     * correction to a `supplier_transfers` row (`source_channel`,
     * `amount_myr_sent`, `fee_myr`, `reference_no`, `receipt_path`) — never
     * `amount_foreign_received`/`supplier_fee`, which stay Adjust/Void-only
     * since those two feed `supplier_ledger_entries.amount` directly.
     * Append-only, enforced at the model layer (mirrors
     * `SupplierLedgerEntry`'s own `booted()` guard) — one row per edit
     * *action*, not per field, so a single submit correcting several
     * fields at once with one shared reason is one row, not several.
     */
    public function up(): void
    {
        Schema::create('supplier_transfer_corrections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_transfer_id')->constrained()->restrictOnDelete();
            $table->json('changes'); // {field: [old, new], ...} for every field actually changed
            $table->text('reason');
            $table->foreignId('admin_user_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index('supplier_transfer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_transfer_corrections');
    }
};
