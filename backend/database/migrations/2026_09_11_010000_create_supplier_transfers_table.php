<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ADR-083 decision 2: one row per capital transfer of company funds
     * into a supplier's prepaid account (Wise/Airwallex/bank). This is the
     * *actual* funding event — `amount_myr_sent` is what left our bank,
     * `amount_foreign_received` is what actually landed at the supplier in
     * its own currency, both read off the transfer receipt Farres uploads.
     * `effective_rate` (MYR per 1 unit of `currency`) is derived from the
     * two and persisted for audit/reporting rather than recomputed ad hoc.
     *
     * `receipt_path` deliberately isn't named `receipt_url` (despite that
     * being the ADR's own field name) — it is a relative path on the
     * private `local` disk (`storage/app/private/accounting/...`, decision
     * 10), served only via a temporary signed URL, never a real public URL.
     * Naming it "_path" avoids a future caller assuming it's safe to embed
     * directly as a `<a href>`.
     */
    public function up(): void
    {
        Schema::create('supplier_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->string('source_channel'); // wise | airwallex | bank
            $table->bigInteger('amount_myr_sent'); // sen, what left our bank
            $table->bigInteger('fee_myr')->default(0); // sen
            $table->string('currency', 3); // supplier's own currency, e.g. IDR, MYR
            $table->decimal('amount_foreign_received', 18, 4); // what landed at the supplier
            $table->decimal('effective_rate', 18, 8)->nullable(); // MYR per 1 unit of `currency`, derived
            $table->string('receipt_path')->nullable();
            $table->string('reference_no')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamps();

            $table->index('supplier_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_transfers');
    }
};
