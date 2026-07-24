<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * WTH-1..5. `owner_type`/`owner_id` follow the same loose convention
     * as `ledger_entries` (no FK — MVP only ever uses 'platform'/null).
     * The ledger debit itself is written at approval time via
     * LedgerService::withdraw(), not here — this table is the request/
     * approval workflow record, not the source of truth for balance.
     */
    public function up(): void
    {
        Schema::create('withdrawals', function (Blueprint $table) {
            $table->id();
            $table->string('owner_type');
            $table->unsignedBigInteger('owner_id')->nullable();
            $table->unsignedBigInteger('amount'); // sen
            $table->string('bank_name');
            $table->string('bank_account_no');
            $table->string('bank_account_holder');
            $table->string('status')->default('pending'); // pending|approved|rejected|completed
            $table->text('admin_note')->nullable();
            $table->unsignedBigInteger('requested_by');
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['owner_type', 'owner_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('withdrawals');
    }
};
