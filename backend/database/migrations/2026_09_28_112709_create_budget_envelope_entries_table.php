<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-083 2026-09-28 "Envelope Ledger" addendum — append-only, same
 * discipline as `supplier_ledger_entries` (ADR-083 decision 2): no
 * `UPDATE`/`DELETE` from application code, enforced at the model layer
 * (`BudgetEnvelopeEntry::booted()`). `amount_sen` is signed (positive =
 * money in, negative = money out) — a category is informational/
 * validated-in-the-service, never the authoritative sign, matching
 * `ledger_entries.amount`'s own convention.
 *
 * Correction model: a mistaken entry is never edited/deleted — a "void"
 * inserts a NEW entry with the negated amount, `reverses_entry_id`
 * pointing back at the original (which stays visible, untouched,
 * forever). This makes an envelope's balance a plain
 * `SUM(amount_sen)` — a reversal always nets itself out automatically,
 * no "exclude voided rows" filter needed anywhere balance is computed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budget_envelope_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('budget_envelope_id')->constrained('budget_envelopes')->restrictOnDelete();
            $table->string('category'); // BudgetEnvelopeEntryCategory
            $table->integer('amount_sen'); // signed — positive=in, negative=out
            $table->text('description');
            $table->string('receipt_path')->nullable();
            $table->foreignId('reverses_entry_id')->nullable()->constrained('budget_envelope_entries')->restrictOnDelete();
            $table->text('void_reason')->nullable(); // set only on the reversal entry
            $table->foreignId('created_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index('budget_envelope_id');
            $table->index('category');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_envelope_entries');
    }
};
