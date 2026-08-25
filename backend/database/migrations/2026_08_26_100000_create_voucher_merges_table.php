<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ADR-036. One row per source voucher consumed by a merge (never a
     * JSON array) — surfaced on the target voucher's own detail page so
     * an admin can see which vouchers/orders a merged code traces back
     * to. Append-only, same convention as price_change_logs/
     * deactivation_logs/order_resend_attempts — never edited or
     * deleted once written. `merged_at` is `created_at` under this
     * table's own name, matching how every other audit table in this
     * codebase already relies on `timestamps()` rather than a second,
     * redundant domain timestamp column.
     *
     * Both FKs point at `vouchers` — cascadeOnDelete matches
     * voucher_redemptions' own choice for the identical relationship
     * shape (this table has no meaning once either voucher is gone).
     * `merged_by` is a plain unsignedBigInteger, not FK-constrained,
     * matching vouchers.created_by/approved_by's own existing
     * convention.
     */
    public function up(): void
    {
        Schema::create('voucher_merges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_voucher_id')->constrained('vouchers')->cascadeOnDelete();
            $table->foreignId('target_voucher_id')->constrained('vouchers')->cascadeOnDelete();
            $table->text('reason');
            $table->unsignedBigInteger('merged_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voucher_merges');
    }
};
