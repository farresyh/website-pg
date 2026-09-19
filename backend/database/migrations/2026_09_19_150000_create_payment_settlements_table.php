<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ADR-110 PR-B, fills ADR-083 decision 7 — one row per uploaded CHIP
     * settlement `.xlsx`. `expected_*` is computed from this platform's
     * own records (orders/membership_checkout_attempts/wallet_topup_attempts
     * paid via CHIP inside `[date_from, date_to]`); `file_*` is CHIP's own
     * reported totals (the uploaded file's "Summary" sheet, verbatim);
     * `actual_bank_amount` is the founder's own manually-entered real bank
     * figure — three independent numbers, deliberately never conflated.
     * All money columns are integer sen (ADR-002).
     */
    public function up(): void
    {
        Schema::create('payment_settlements', function (Blueprint $table) {
            $table->id();
            $table->date('date_from');
            $table->date('date_to');
            $table->unsignedBigInteger('expected_gross_sen');
            $table->unsignedBigInteger('expected_fee_sen');
            $table->unsignedBigInteger('expected_net_sen');
            $table->unsignedBigInteger('file_gross_sen');
            $table->unsignedBigInteger('file_fee_sen');
            $table->unsignedBigInteger('file_net_sen');
            $table->unsignedBigInteger('actual_bank_amount_sen')->nullable();
            $table->string('status')->default('pending'); // pending | matched | variance
            $table->text('variance_note')->nullable();
            $table->string('original_filename');
            $table->foreignId('admin_user_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamps();

            $table->index(['date_from', 'date_to']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_settlements');
    }
};
