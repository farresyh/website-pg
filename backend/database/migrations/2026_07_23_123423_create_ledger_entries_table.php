<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Immutable, append-only (ADR-002/D2) — application code never updates
     * or deletes a row here, only inserts. `amount` is signed sen: positive
     * = credit, negative = debit. Balance for an owner is always
     * SUM(amount) — never cached/stored anywhere else as a source of truth.
     */
    public function up(): void
    {
        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->string('owner_type');
            $table->unsignedBigInteger('owner_id')->nullable();
            $table->string('type'); // order_profit | withdrawal | voucher_issued | adjustment
            $table->bigInteger('amount'); // signed sen
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->text('reason')->nullable(); // required by app logic for 'adjustment'
            $table->timestamp('created_at')->useCurrent();

            $table->index(['owner_type', 'owner_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ledger_entries');
    }
};
